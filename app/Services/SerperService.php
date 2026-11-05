<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Str;
use App\Core\Url;

/**
 * Serper.dev Google Search API client.
 *
 * The API key is stored encrypted in the settings table and is only decrypted
 * in memory. The browser extension never sees or sends this key: it asks the
 * backend for the next SERP page and the backend performs the search.
 */
final class SerperService
{
    public const DEFAULT_ENDPOINT = 'https://google.serper.dev/search';

    public static function configured(): bool
    {
        return self::apiKey() !== '';
    }

    public static function apiKey(): string
    {
        return trim(Settings::secret('serper_api_key'));
    }

    public static function endpoint(): string
    {
        $endpoint = trim((string) Settings::get('serper_base_url', self::DEFAULT_ENDPOINT));
        return $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT;
    }

    /**
     * Resolve "auto" to Serper when a key is available, otherwise use the
     * browser-side Google fetcher.
     */
    public static function effectiveProvider(): string
    {
        $provider = strtolower(trim((string) Settings::get('search_provider', 'auto')));
        if ($provider === 'serper') {
            return 'serper';
        }
        if ($provider === 'browser') {
            return 'browser';
        }
        return self::configured() ? 'serper' : 'browser';
    }

    /**
     * @return array{results: array<int, array{title:string,url:string,snippet:string,position:int}>, credits: int|null}
     */
    public function search(string $query, int $page = 1, string $language = 'en', ?string $overrideApiKey = null): array
    {
        $query = trim($query);
        if ($query === '') {
            throw HttpException::validation('Serper 搜索关键词不能为空');
        }
        $apiKey = $overrideApiKey !== null && trim($overrideApiKey) !== '' ? trim($overrideApiKey) : self::apiKey();
        if ($apiKey === '') {
            throw new HttpException(503, '尚未配置 Serper API Key，请在插件设置或后台系统设置中填写。', 'serper_not_configured');
        }

        $page = max(1, min(10, $page));
        $endpoint = self::endpoint();
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $endpoint)) {
            throw new HttpException(503, 'Serper 接口地址配置不正确。', 'serper_not_configured');
        }

        $gl = strtolower(trim((string) Settings::get('serper_gl', 'us')));
        $hl = strtolower(trim((string) Settings::get('serper_hl', $language ?: 'en')));
        $timeout = max(5, min(60, Settings::int('serper_timeout', 30)));
        $payload = [
            'q' => $query,
            'gl' => preg_match('/^[a-z]{2}$/', $gl) ? $gl : 'us',
            'hl' => preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $hl) ? $hl : 'en',
            'num' => 10,
            'page' => $page,
            'autocorrect' => false,
        ];

        $started = microtime(true);
        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new HttpException(502, '无法初始化 Serper 请求。', 'serper_failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: GLE/1.1',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $duration = (int) round((microtime(true) - $started) * 1000);
        Logger::info('Serper search', [
            'query' => $query,
            'page' => $page,
            'status' => $status,
            'duration_ms' => $duration,
        ]);

        if (!is_string($raw) || $raw === '') {
            throw new HttpException(502, 'Serper 请求失败：' . ($curlError ?: '空响应'), 'serper_failed');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(502, 'Serper 返回了无法解析的响应。', 'serper_failed', ['status' => $status]);
        }

        if ($status === 401 || $status === 403) {
            throw new HttpException(502, 'Serper API Key 无效或已过期，请在后台重新填写。', 'serper_unauthorized', ['status' => $status]);
        }
        if ($status === 429) {
            throw new HttpException(502, 'Serper 请求过于频繁或额度不足，请稍后再试。', 'serper_rate_limited', ['status' => $status]);
        }
        if ($status < 200 || $status >= 300) {
            $message = $decoded['message'] ?? $decoded['error'] ?? ('HTTP ' . $status);
            throw new HttpException(502, 'Serper 请求失败：' . Str::limit((string) $message, 300), 'serper_failed', ['status' => $status]);
        }

        $organic = $decoded['organic'] ?? [];
        if (!is_array($organic)) {
            $organic = [];
        }
        $results = [];
        $position = 0;
        foreach ($organic as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['link'] ?? ''));
            if (!Url::isHttp($url)) {
                continue;
            }
            $position++;
            $results[] = [
                'title' => Str::limit(trim((string) ($item['title'] ?? '')), 490),
                'url' => Str::limit($url, 990),
                'snippet' => Str::limit(trim((string) ($item['snippet'] ?? '')), 1200),
                'position' => $position,
            ];
            if (count($results) >= 100) {
                break;
            }
        }

        return [
            'results' => $results,
            'credits' => isset($decoded['credits']) && is_numeric($decoded['credits']) ? (int) $decoded['credits'] : null,
        ];
    }
}
