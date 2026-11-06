<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Str;
use App\Core\Url;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Website page discovery, HTML link extraction and contact parsing.
 */
final class WebsiteService
{
    public function __construct(private readonly AiService $ai)
    {
    }

    /**
     * @return array{pages:array<int,array{type:string,url:string}>,about:?string,contact:?string}
     */
    public function discoverPages(array $task, array $website, string $html, string $fetchedUrl = ''): array
    {
        if (trim($html) === '') {
            throw new HttpException(422, '抓取到的首页 HTML 为空', 'empty_html');
        }
        $root = (string) $website['root_url'];
        $domain = (string) $website['domain'];
        $links = $this->extractLinks($html, $fetchedUrl !== '' ? $fetchedUrl : $root, $domain);
        if ($links === []) {
            $links = [['url' => $root, 'text' => 'Home']];
        }
        $picked = $this->ai->discoverPages($task, $website, $links);
        $allowed = [];
        foreach ($links as $link) {
            $allowed[$link['url']] = true;
        }
        $allowed[$root] = true;

        $sanitize = static function (mixed $candidate) use ($domain, $allowed): ?string {
            if (!is_string($candidate) || trim($candidate) === '') {
                return null;
            }
            $candidate = trim($candidate);
            $url = Url::absolute($candidate, $candidate);
            if ($url === null || !Url::sameDomain($url, $domain)) {
                return null;
            }
            return isset($allowed[$url]) ? $url : null;
        };

        $home = $sanitize($picked['home'] ?? null) ?? $root;
        $about = $sanitize($picked['about'] ?? null);
        $contact = $sanitize($picked['contact'] ?? null);

        if ($about === null) {
            $about = $this->heuristicLink($links, ['about', 'about-us', 'company', 'our-company', 'our-story', 'who-we-are', 'profile']);
        }
        if ($contact === null) {
            $contact = $this->heuristicLink($links, ['contact', 'contact-us', 'get-in-touch', 'inquiry', 'enquiry', 'reach-us']);
        }
        if ($home === $about) {
            $about = null;
        }
        if ($home === $contact) {
            $contact = null;
        }
        if ($about !== null && $about === $contact) {
            $contact = null;
        }

        $pages = [['type' => 'home', 'url' => $home]];
        if ($about !== null) {
            $pages[] = ['type' => 'about', 'url' => $about];
        }
        if ($contact !== null) {
            $pages[] = ['type' => 'contact', 'url' => $contact];
        }

        Db::instance()->ensureConnected();
        Db::instance()->update('websites', [
            'home_url' => $home,
            'about_url' => $about,
            'contact_url' => $contact,
            'pages_json' => json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'page_status' => 'done',
            'extract_status' => 'pending',
            'attempts' => 0,
            'last_error' => null,
        ], ['id' => (int) $website['id'], 'task_id' => (int) $task['id']]);

        return ['pages' => $pages, 'about' => $about, 'contact' => $contact];
    }

    /**
     * @param array<int, array{type?:string,url?:string,html?:string}> $pages
     * @return array<string, mixed> The saved company row.
     */
    public function extractCompany(array $task, array $website, array $pages): array
    {
        $domain = (string) $website['domain'];
        $blocked = [];
        $allHtml = '';
        $texts = [];
        $titles = [];
        foreach ($pages as $page) {
            $pageUrl = (string) ($page['url'] ?? '');
            $html = (string) ($page['html'] ?? '');
            $type = (string) ($page['type'] ?? 'page');
            if ($html === '' || !Url::isHttp($pageUrl) || !Url::sameDomain($pageUrl, $domain)) {
                continue;
            }
            $allHtml .= "\n" . $html;
            $title = $this->pageTitle($html);
            if ($title !== '') {
                $titles[] = $title;
            }
            $text = $this->htmlToText($html);
            if ($text !== '') {
                $texts[] = "【{$type}】{$pageUrl}\n" . Str::limit($text, 7500, '');
            }
        }
        $pagesText = trim(implode("\n\n", $texts));
        if ($pagesText === '') {
            throw new HttpException(422, '三个页面均未能提取有效文本', 'empty_page_text');
        }
        $pagesText = Str::limit($pagesText, 20000, '');

        $ai = $this->ai->extractContacts($task, $website, $pagesText);
        $regex = $this->regexContacts($allHtml, $pagesText);

        $companyName = trim((string) ($ai['company_name'] ?? ''));
        if ($companyName === '') {
            $companyName = $this->fallbackCompanyName($titles, $allHtml);
        }
        $phones = $this->mergeList($this->asStringList($ai['phones'] ?? []), $regex['phones']);
        $emails = $this->mergeList($this->asStringList($ai['emails'] ?? []), $regex['emails']);
        $phones = array_slice($this->dedupePhones($phones), 0, 6);
        $emails = array_slice(array_values(array_unique(array_filter($emails, static fn (string $email): bool => (bool) filter_var($email, FILTER_VALIDATE_EMAIL)))), 0, 6);

        $social = [];
        foreach (['linkedin', 'tiktok', 'youtube', 'facebook', 'instagram'] as $network) {
            $value = $this->normalizeSocial((string) ($ai[$network] ?? ''), $network);
            if ($value === null) {
                $value = $regex[$network][0] ?? null;
            }
            $social[$network] = $value;
        }
        $address = Str::limit(trim((string) ($ai['address'] ?? '')), 500, '');
        $whatsapp = trim((string) ($ai['whatsapp'] ?? ''));
        if ($whatsapp === '') {
            $whatsapp = $regex['whatsapp'][0] ?? '';
        }
        if ($whatsapp !== '' && preg_match('#wa\.me/(\d+)#i', $whatsapp, $m)) {
            $whatsapp = '+' . $m[1];
        } elseif ($whatsapp !== '' && preg_match('#api\.whatsapp\.com/send\?phone=(\d+)#i', $whatsapp, $m)) {
            $whatsapp = '+' . $m[1];
        }
        if ($whatsapp !== '' && !preg_match('/^\+?[\d\s\-().]{7,24}$/', $whatsapp)) {
            $whatsapp = '';
        }

        $sourceUrl = (string) ($website['contact_url'] ?: ($website['about_url'] ?: ($website['home_url'] ?: $website['root_url'])));
        $raw = [
            'ai' => $ai,
            'regex' => $regex,
            'page_titles' => $titles,
        ];
        $row = [
            'task_id' => (int) $task['id'],
            'website_id' => (int) $website['id'],
            'company_name' => Str::limit($companyName ?: null, 250, ''),
            'domain' => $domain,
            'country' => '中国',
            'city' => (string) $task['city'],
            'phone' => $phones[0] ?? null,
            'phone_alt' => $phones === [] ? null : implode(', ', array_slice($phones, 1)),
            'email' => $emails[0] ?? null,
            'linkedin' => $social['linkedin'],
            'tiktok' => $social['tiktok'],
            'youtube' => $social['youtube'],
            'facebook' => $social['facebook'],
            'instagram' => $social['instagram'],
            'twitter' => $regex['twitter'][0] ?? null,
            'whatsapp' => $whatsapp !== '' ? Str::limit($whatsapp, 250, '') : null,
            'address' => $address ?: null,
            'source_url' => Str::limit($sourceUrl, 990, ''),
            'raw_json' => json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];
        if (PhoneService::ready()) {
            // 重新提取后，电话需要重新走标准化。
            $row['phone_status'] = 'pending';
            $row['phone_attempts'] = 0;
            $row['phone_raw'] = null;
            $row['phone_type'] = null;
            $row['phone_country'] = null;
            $row['phone_list_json'] = null;
            $row['phone_note'] = null;
            $row['phone_normalized_at'] = null;
        }
        Db::instance()->ensureConnected();
        $existing = Db::instance()->table('companies')->where('task_id', (int) $task['id'])->where('website_id', (int) $website['id'])->first();
        if ($existing === null) {
            $row['created_at'] = now();
            Db::instance()->insert('companies', $row);
            $row['id'] = (int) Db::instance()->pdo()->lastInsertId();
        } else {
            Db::instance()->update('companies', $row, [
                'task_id' => (int) $task['id'],
                'website_id' => (int) $website['id'],
            ]);
            $row['id'] = (int) $existing['id'];
            $row['created_at'] = $existing['created_at'];
        }

        Db::instance()->update('websites', [
            'extract_status' => 'done',
            'attempts' => 0,
            'last_error' => null,
        ], ['id' => (int) $website['id'], 'task_id' => (int) $task['id']]);

        return $row;
    }

    /**
     * Extract same-domain navigation links from a page.
     * @return array<int, array{url:string,text:string}>
     */
    public function extractLinks(string $html, string $baseUrl, string $domain): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        $xpath = new DOMXPath($dom);
        $anchors = $xpath->query('//a[@href]');
        $links = [];
        $seen = [];
        if ($anchors !== false) {
            foreach ($anchors as $anchor) {
                if (!$anchor instanceof DOMElement) {
                    continue;
                }
                $href = $anchor->getAttribute('href');
                $url = Url::absolute($baseUrl, $href);
                if ($url === null || !Url::sameDomain($url, $domain)) {
                    continue;
                }
                $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
                if (preg_match('#\.(?:jpe?g|png|gif|webp|svg|css|js|ico|woff2?|ttf|mp4|mp3|zip|rar|exe)(?:$|\?)#i', $path)) {
                    continue;
                }
                if (preg_match('#/(?:wp-admin|wp-login|login|signin|cart|checkout|account|privacy|terms|sitemap|feed)(?:/|$)#i', $path)) {
                    continue;
                }
                $text = trim(preg_replace('/\s+/u', ' ', (string) $anchor->textContent) ?? '');
                $text = Str::limit($text, 120, '');
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $score = 0;
                $haystack = strtolower($url . ' ' . $text);
                foreach (['about', 'company', 'contact', 'inquiry', 'enquiry', 'profile', 'story', 'who-we-are', 'get-in-touch'] as $needle) {
                    if (str_contains($haystack, $needle)) {
                        $score += 10;
                    }
                }
                if ($path === '/' || $path === '') {
                    $score += 4;
                }
                if ($text === '') {
                    $score -= 2;
                }
                $links[] = ['url' => Str::limit($url, 990, ''), 'text' => $text, 'score' => $score];
            }
        }
        usort($links, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $links = array_slice($links, 0, 160);
        return array_map(static fn (array $link): array => ['url' => $link['url'], 'text' => $link['text']], $links);
    }

    /** @param array<int, array{url:string,text:string}> $links @param array<int, string> $needles */
    private function heuristicLink(array $links, array $needles): ?string
    {
        foreach ($links as $link) {
            $path = strtolower((string) (parse_url($link['url'], PHP_URL_PATH) ?: ''));
            foreach ($needles as $needle) {
                if (str_contains($path, $needle)) {
                    return $link['url'];
                }
            }
        }
        return null;
    }

    public function htmlToText(string $html): string
    {
        $html = preg_replace('#<(?:script|style|noscript|svg|iframe)[^>]*>.*?</(?:script|style|noscript|svg|iframe)>#is', ' ', $html) ?? $html;
        $html = preg_replace('#<(?:br|/p|/div|/li|/h[1-6]|/tr)[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\xc2\xa0", "\u{00A0}"], ' ', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function pageTitle(string $html): string
    {
        if (preg_match('#<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) {
            return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        return '';
    }

    private function fallbackCompanyName(array $titles, string $html): string
    {
        if (preg_match('#<meta[^>]+property=["\']og:site_name["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            return Str::limit(trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')), 250, '');
        }
        foreach ($titles as $title) {
            $parts = preg_split('/\s*[|\-–—]\s*/u', $title) ?: [];
            foreach ($parts as $part) {
                $part = trim($part);
                if (mb_strlen($part) >= 3 && !preg_match('/^(home|about us|contact us|welcome)$/i', $part)) {
                    return Str::limit($part, 250, '');
                }
            }
        }
        return '';
    }

    /** @return array<string, array<int, string>> */
    private function regexContacts(string $html, string $text): array
    {
        $haystack = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $textDecoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,24}/i', $haystack, $emailMatches);
        $emails = array_values(array_unique(array_map('strtolower', $emailMatches[0] ?? [])));
        $emails = array_values(array_filter($emails, static function (string $email): bool {
            return !preg_match('/\.(?:png|jpe?g|gif|webp|svg|css|js)$/i', $email)
                && !str_contains($email, 'example.com')
                && !str_contains($email, 'sentry');
        }));

        $phones = [];
        $phoneSource = $textDecoded . "\n" . strip_tags($haystack);
        $patterns = [
            '/(?:\+?86[\s\-]?)?1[3-9]\d{9}/u',
            '/\+\d{1,3}[\s\-().]{0,3}\d(?:[\s\-().]?\d){6,14}/u',
            '/(?:^|[\s>(])(0\d{2,3}[\s\-]?\d{7,8})(?=$|[\s<).,])/u',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $phoneSource, $matches)) {
                foreach ($matches[0] as $phone) {
                    $phone = trim($phone, " \t\n\r\0\x0B.,;:()[]<>");
                    $digits = preg_replace('/\D+/', '', $phone) ?? '';
                    if (strlen($digits) < 8 || strlen($digits) > 18) {
                        continue;
                    }
                    if (preg_match('/^(?:19|20)\d{2}[01]\d[0-3]\d$/', $digits)) {
                        continue;
                    }
                    $phones[] = preg_replace('/\s+/', ' ', $phone) ?? $phone;
                }
            }
        }

        $social = [
            'linkedin' => $this->matchSocial($haystack, 'linkedin'),
            'tiktok' => $this->matchSocial($haystack, 'tiktok'),
            'youtube' => $this->matchSocial($haystack, 'youtube'),
            'facebook' => $this->matchSocial($haystack, 'facebook'),
            'instagram' => $this->matchSocial($haystack, 'instagram'),
            'twitter' => $this->matchSocial($haystack, 'twitter'),
            'whatsapp' => $this->matchWhatsapp($haystack),
        ];

        return [
            'phones' => $phones,
            'emails' => $emails,
            'linkedin' => $social['linkedin'],
            'tiktok' => $social['tiktok'],
            'youtube' => $social['youtube'],
            'facebook' => $social['facebook'],
            'instagram' => $social['instagram'],
            'twitter' => $social['twitter'],
            'whatsapp' => $social['whatsapp'],
        ];
    }

    /** @return array<int, string> */
    private function matchSocial(string $html, string $network): array
    {
        $patterns = [
            'linkedin' => '#(?:https?:)?//(?:[a-z]{2,3}\.)?linkedin\.com/(?:company|in|school)/[a-z0-9_%\-\./]+#i',
            'tiktok' => '#(?:https?:)?//(?:www\.)?tiktok\.com/@[a-z0-9_.\-]+#i',
            'youtube' => '#(?:https?:)?//(?:www\.)?youtube\.com/(?:@[a-z0-9_.\-]+|channel/[a-z0-9_\-]+|c/[a-z0-9_\-]+|user/[a-z0-9_\-]+)#i',
            'facebook' => '#(?:https?:)?//(?:www\.)?facebook\.com/(?!sharer|share|login|dialog)[a-z0-9_.\-/]+#i',
            'instagram' => '#(?:https?:)?//(?:www\.)?instagram\.com/(?!p/|reel/|explore/)[a-z0-9_.\-]+#i',
            'twitter' => '#(?:https?:)?//(?:www\.)?(?:twitter|x)\.com/(?!intent|share|home)[a-z0-9_]{2,30}#i',
        ];
        if (!isset($patterns[$network])) {
            return [];
        }
        preg_match_all($patterns[$network], $html, $matches);
        $values = [];
        foreach ($matches[0] ?? [] as $match) {
            $match = html_entity_decode(trim($match, " \t\n\r\0\x0B\"'<>"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $match = preg_match('#^https?://#i', $match) ? $match : 'https:' . $match;
            $match = preg_replace('/[?&#].*$/', '', $match) ?? $match;
            $match = rtrim($match, '/');
            if ($match !== '') {
                $values[] = $match;
            }
        }
        return array_values(array_unique($values));
    }

    /** @return array<int, string> */
    private function matchWhatsapp(string $html): array
    {
        preg_match_all('#(?:https?:)?//(?:wa\.me|api\.whatsapp\.com/send)[^\s"\'<>]+#i', $html, $matches);
        $values = [];
        foreach ($matches[0] ?? [] as $match) {
            $match = html_entity_decode(trim($match, " \t\n\r\0\x0B\"'<>"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $match = preg_match('#^https?://#i', $match) ? $match : 'https:' . $match;
            $values[] = $match;
        }
        return array_values(array_unique($values));
    }

    private function normalizeSocial(string $value, string $network): ?string
    {
        $value = trim($value);
        if ($value === '' || !preg_match('#^https?://#i', $value)) {
            return null;
        }
        $domain = Url::domain($value);
        if ($domain === null) {
            return null;
        }
        $valid = match ($network) {
            'linkedin' => in_array($domain, ['linkedin.com'], true) && preg_match('#linkedin\.com/(?:company|in|school)/#i', $value),
            'tiktok' => $domain === 'tiktok.com' && preg_match('#tiktok\.com/@#i', $value),
            'youtube' => in_array($domain, ['youtube.com', 'youtu.be'], true),
            'facebook' => $domain === 'facebook.com',
            'instagram' => $domain === 'instagram.com',
            default => false,
        };
        return $valid ? Str::limit($value, 990, '') : null;
    }

    /** @return array<int, string> */
    private function asStringList(mixed $value): array
    {
        if (is_string($value)) {
            // Phone numbers contain spaces; only split on unambiguous separators.
            $value = preg_split('/[,;|\r\n]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $items = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }
        return $items;
    }

    /** @param array<int, string> ...$lists @return array<int, string> */
    private function mergeList(array ...$lists): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $merged[] = $item;
                }
            }
        }
        return array_values(array_unique($merged));
    }

    /** @param array<int, string> $phones @return array<int, string> */
    private function dedupePhones(array $phones): array
    {
        $output = [];
        $seen = [];
        foreach ($phones as $phone) {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            if ($digits === '' || isset($seen[$digits])) {
                continue;
            }
            $seen[$digits] = true;
            $output[] = Str::limit($phone, 240, '');
        }
        return $output;
    }
}
