<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Str;
use App\Core\Url;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Parses Google result HTML (including the basic HTML / gbv=1 variant).
 */
final class SerpParser
{
    /** @return array<int, array{title:string,url:string,domain:string,snippet:string,position:int}> */
    public static function parse(string $html): array
    {
        $html = trim($html);
        if ($html === '' || strlen($html) < 100) {
            return [];
        }
        // Keep libxml quiet and independent from global error state.
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        if (!$loaded) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            return [];
        }
        $xpath = new DOMXPath($dom);
        $anchors = $xpath->query('//a[.//h3]');
        if ($anchors === false || $anchors->length === 0) {
            $anchors = $xpath->query('//a[contains(@href, "/url?q=")]');
        }

        $results = [];
        $seen = [];
        $position = 0;
        foreach ($anchors as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }
            $url = self::resolveGoogleLink($anchor->getAttribute('href'));
            if ($url === null) {
                continue;
            }
            $domain = Url::domain($url);
            if ($domain === null || self::isGoogleDomain($domain) || Url::isPlatform($domain) && self::isSearchEngine($domain)) {
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }
            $title = '';
            $headings = $anchor->getElementsByTagName('h3');
            if ($headings->length > 0) {
                $title = trim((string) $headings->item(0)?->textContent);
            }
            if ($title === '') {
                $title = trim((string) $anchor->textContent);
            }
            if ($title === '' || mb_strlen($title) > 500) {
                $title = Str::limit($title, 490);
            }
            $snippet = self::findSnippet($anchor, $title);
            $seen[$url] = true;
            $position++;
            $results[] = [
                'title' => Str::limit($title, 490),
                'url' => Str::limit($url, 990),
                'domain' => $domain,
                'snippet' => Str::limit($snippet, 1200),
                'position' => $position,
            ];
            if (count($results) >= 100) {
                break;
            }
        }

        if ($results === []) {
            // Last-resort parse for very old / text-only Google variants.
            $links = $xpath->query('//a[@href]');
            if ($links !== false) {
                foreach ($links as $anchor) {
                    if (!$anchor instanceof DOMElement) {
                        continue;
                    }
                    $url = self::resolveGoogleLink($anchor->getAttribute('href'));
                    if ($url === null) {
                        continue;
                    }
                    $domain = Url::domain($url);
                    if ($domain === null || self::isGoogleDomain($domain)) {
                        continue;
                    }
                    $text = trim((string) $anchor->textContent);
                    if (mb_strlen($text) < 12 || isset($seen[$url])) {
                        continue;
                    }
                    $seen[$url] = true;
                    $position++;
                    $results[] = [
                        'title' => Str::limit($text, 490),
                        'url' => Str::limit($url, 990),
                        'domain' => $domain,
                        'snippet' => '',
                        'position' => $position,
                    ];
                    if (count($results) >= 20) {
                        break;
                    }
                }
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $results;
    }

    public static function looksBlocked(string $html): bool
    {
        $needles = [
            'unusual traffic',
            'detected unusual traffic',
            'captcha-form',
            'recaptcha',
            'our systems have detected',
            'enablejs',
            'consent.google.com',
            'before you continue',
        ];
        $lower = mb_strtolower($html, 'UTF-8');
        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function resolveGoogleLink(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '') {
            return null;
        }
        if (str_starts_with($href, '/url?')) {
            $href = 'https://www.google.com' . $href;
        }
        if (str_contains($href, 'google.') && str_contains($href, '/url?')) {
            $query = parse_url($href, PHP_URL_QUERY);
            if (is_string($query)) {
                parse_str($query, $params);
                foreach (['q', 'url', 'u'] as $key) {
                    if (!empty($params[$key]) && is_string($params[$key])) {
                        $href = $params[$key];
                        break;
                    }
                }
            }
        }
        if (!Url::isHttp($href)) {
            return null;
        }
        return Url::absolute('https://www.google.com/', $href);
    }

    private static function isGoogleDomain(string $domain): bool
    {
        return $domain === 'google.com'
            || str_starts_with($domain, 'google.')
            || str_ends_with($domain, '.google.com')
            || in_array($domain, ['gstatic.com', 'googleusercontent.com', 'googleapis.com', 'youtube.com', 'youtu.be'], true);
    }

    private static function isSearchEngine(string $domain): bool
    {
        return in_array($domain, ['bing.com', 'duckduckgo.com', 'yahoo.com', 'baidu.com'], true);
    }

    private static function findSnippet(DOMElement $anchor, string $title): string
    {
        $node = $anchor->parentNode;
        for ($depth = 0; $depth < 5 && $node instanceof DOMNode; $depth++) {
            if ($node instanceof DOMElement) {
                $classes = ' ' . strtolower($node->getAttribute('class')) . ' ';
                if (str_contains($classes, 'vwic3b') || str_contains($classes, 'acopre') || str_contains($classes, 'is zvec') || str_contains($classes, 'is-zvec')) {
                    $text = trim((string) $node->textContent);
                    if ($text !== '') {
                        return self::cleanSnippet($text, $title);
                    }
                }
                foreach (['.VwiC3b', '.aCOpRe', '.IsZvec', '.st', 'span'] as $selector) {
                    $found = (new DOMXPath($node->ownerDocument))->query($selector, $node);
                    if ($found !== false && $found->length > 0) {
                        $text = trim((string) $found->item(0)?->textContent);
                        if ($text !== '' && $text !== $title) {
                            return self::cleanSnippet($text, $title);
                        }
                    }
                }
            }
            $node = $node->parentNode;
        }
        return '';
    }

    private static function cleanSnippet(string $text, string $title): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim(str_replace($title, '', $text));
        return Str::limit($text, 1000);
    }
}
