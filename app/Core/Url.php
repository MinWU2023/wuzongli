<?php
declare(strict_types=1);

namespace App\Core;

final class Url
{
    public static function normalizeDomain(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (str_contains($value, '://') || str_starts_with($value, '//')) {
            $host = parse_url(str_starts_with($value, '//') ? 'https:' . $value : $value, PHP_URL_HOST);
            $value = is_string($host) ? $host : '';
        } else {
            $host = parse_url('http://' . $value, PHP_URL_HOST);
            $value = is_string($host) ? $host : $value;
        }
        $value = strtolower(trim($value, ". \t\n\r\0\x0B"));
        if ($value === '' || str_contains($value, ' ') || str_contains($value, '/')) {
            return null;
        }
        if (function_exists('idn_to_ascii') && preg_match('/[^\x00-\x7f]/', $value)) {
            $ascii = idn_to_ascii($value, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $value = strtolower($ascii);
            }
        }
        if (str_starts_with($value, 'www.')) {
            $value = substr($value, 4);
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
        if (strlen($value) > 253 || !preg_match('/^(?=.{3,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $value)) {
            return null;
        }
        return $value;
    }

    public static function domain(string $url): ?string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = parse_url('http://' . ltrim(trim($url), '/'), PHP_URL_HOST);
        }
        return is_string($host) ? self::normalizeDomain($host) : null;
    }

    public static function isHttp(string $url): bool
    {
        return (bool) preg_match('#^https?://#i', trim($url));
    }

    public static function rootUrl(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . strtolower((string) $parts['host']) . $port . '/';
    }

    /**
     * Resolve a relative href and reject non-http(s), fragments and malformed
     * URLs. Returns a normalised absolute URL or null.
     */
    public static function absolute(string $base, string $href): ?string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($href === '' || str_starts_with($href, '#') || preg_match('#^(?:mailto|tel|javascript|data):#i', $href)) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $baseScheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            $href = $baseScheme . ':' . $href;
        } elseif (!preg_match('#^https?://#i', $href)) {
            $parts = parse_url($base);
            if (!is_array($parts) || empty($parts['host'])) {
                return null;
            }
            $scheme = strtolower((string) ($parts['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';
            $authority = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
            if (str_starts_with($href, '?')) {
                $href = $authority . ($parts['path'] ?? '/') . $href;
            } elseif (str_starts_with($href, '/')) {
                $href = $authority . $href;
            } else {
                $directory = isset($parts['path']) ? rtrim(str_replace('\\', '/', dirname($parts['path'])), '/') : '';
                $href = $authority . ($directory === '' || $directory === '.' ? '/' : $directory . '/') . $href;
            }
        }
        $href = str_replace(' ', '%20', $href);
        $parts = parse_url($href);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        if (!preg_match('/^[a-z0-9.\-]+$/i', $host)) {
            return null;
        }
        $path = isset($parts['path']) ? self::normalizePath($parts['path']) : '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port . $path . $query;
    }

    private static function normalizePath(string $path): string
    {
        $path = preg_replace('#/+#', '/', $path) ?? '/';
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }

    public static function sameDomain(string $url, string $domain): bool
    {
        $candidate = self::domain($url);
        $domain = self::normalizeDomain($domain) ?? '';
        return $candidate !== null && $domain !== '' && $candidate === $domain;
    }

    public static function isPlatform(string $domain): bool
    {
        $domain = strtolower($domain);
        $platforms = [
            'google.com', 'google.com.hk', 'google.co.uk', 'googleusercontent.com', 'gstatic.com',
            'facebook.com', 'instagram.com', 'linkedin.com', 'youtube.com', 'youtu.be',
            'tiktok.com', 'twitter.com', 'x.com', 'pinterest.com', 'reddit.com', 'quora.com',
            'wikipedia.org', 'medium.com', 'blogspot.com', 'wordpress.com', 'tumblr.com',
            'alibaba.com', 'aliexpress.com', 'made-in-china.com', 'globalsources.com',
            'indiamart.com', 'tradeindia.com', 'ec21.com', 'kompass.com', 'europages.com',
            'amazon.com', 'ebay.com', 'walmart.com', 'etsy.com', 'shopify.com', 'wix.com',
            'businesswire.com', 'prnewswire.com', 'crunchbase.com', 'zoominfo.com', 'dnb.com',
            'bloomberg.com', 'reuters.com', 'apnews.com', 'bbc.com', 'cnn.com', 'forbes.com',
            'yelp.com', 'yellowpages.com', 'trustpilot.com', 'glassdoor.com', 'indeed.com',
            'jobstreet.com', '51job.com', 'zhaopin.com', 'liepin.com', 'made-in-china.com',
            'hktdc.com', 'cantonfair.org.cn', 'chinaexhibition.com', 'showguide.com',
            'archive.org', 'web.archive.org', 'maps.google.com', 'translate.google.com',
        ];
        foreach ($platforms as $platform) {
            if ($domain === $platform || str_ends_with($domain, '.' . $platform)) {
                return true;
            }
        }
        foreach (['amazon.', 'ebay.', 'alibaba.', 'aliexpress.', 'tiktok.'] as $prefix) {
            if (str_starts_with($domain, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public static function isSocial(string $domain): bool
    {
        foreach (['linkedin.com', 'tiktok.com', 'youtube.com', 'facebook.com', 'instagram.com', 'twitter.com', 'x.com', 'pinterest.com'] as $social) {
            if ($domain === $social || str_ends_with($domain, '.' . $social)) {
                return true;
            }
        }
        return false;
    }

    /** Build a Google SERP URL for a job. */
    public static function googleSearch(string $keyword, int $page = 1, string $language = 'en', string $googleDomain = 'www.google.com'): string
    {
        $page = max(1, $page);
        $domain = preg_replace('#^https?://#', '', trim($googleDomain)) ?: 'www.google.com';
        $domain = rtrim($domain, '/');
        return 'https://' . $domain . '/search?' . http_build_query([
            'q' => $keyword,
            'num' => 10,
            'start' => ($page - 1) * 10,
            'hl' => $language,
            'gl' => 'us',
            'pws' => '0',
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
