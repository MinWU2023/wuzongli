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
 * Keys live in serper_accounts (encrypted). One account is current; when it is
 * exhausted/invalid the service rotates to the next enabled account.
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
        $fromAccount = (new SerperAccountService())->currentApiKey();
        if ($fromAccount !== '') {
            return $fromAccount;
        }
        // 兼容尚未迁移的旧 settings
        return trim(Settings::secret('serper_api_key'));
    }

    public static function endpoint(?string $override = null): string
    {
        if ($override !== null && trim($override) !== '') {
            return trim($override);
        }
        $current = (new SerperAccountService())->current();
        if ($current !== null && !empty($current['endpoint'])) {
            return (string) $current['endpoint'];
        }
        $endpoint = trim((string) Settings::get('serper_base_url', self::DEFAULT_ENDPOINT));
        return $endpoint !== '' ? $endpoint : self::DEFAULT_ENDPOINT;
    }

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
     * @return array{results: array<int, array{title:string,url:string,snippet:string,position:int}>, credits: int|null, account_id: int|null, account_name: string|null}
     */
    public function search(string $query, int $page = 1, string $language = 'en', ?string $overrideApiKey = null): array
    {
        $query = trim($query);
        if ($query === '') {
            throw HttpException::validation('Serper 搜索关键词不能为空');
        }

        $accounts = new SerperAccountService();
        $tried = [];
        $lastError = null;

        // 显式覆盖 Key（插件旧逻辑）时不做账号轮换
        if ($overrideApiKey !== null && trim($overrideApiKey) !== '') {
            return $this->requestOnce($query, $page, $language, trim($overrideApiKey), self::endpoint(), null, null);
        }

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $current = $accounts->current();
            if ($current === null) {
                break;
            }
            $accountId = (int) $current['id'];
            if (isset($tried[$accountId])) {
                break;
            }
            $tried[$accountId] = true;
            $apiKey = (string) ($current['api_key'] ?? '');
            if ($apiKey === '') {
                $accounts->rotateAfterFailure($accountId, 'API Key 为空', 'error');
                continue;
            }
            try {
                $result = $this->requestOnce(
                    $query,
                    $page,
                    $language,
                    $apiKey,
                    self::endpoint(isset($current['endpoint']) ? (string) $current['endpoint'] : null),
                    $accountId,
                    (string) $current['name']
                );
                $accounts->markSuccess($accountId, $result['credits']);
                return $result;
            } catch (HttpException $e) {
                $lastError = $e;
                if (in_array($e->errorCode, ['serper_unauthorized', 'serper_rate_limited'], true)) {
                    $status = $e->errorCode === 'serper_unauthorized' ? 'error' : 'exhausted';
                    $next = $accounts->rotateAfterFailure($accountId, $e->getMessage(), $status);
                    if ($next === null) {
                        throw $e;
                    }
                    Logger::info('Serper rotated account', [
                        'from' => $accountId,
                        'to' => (int) $next['id'],
                        'reason' => $e->errorCode,
                    ]);
                    continue;
                }
                throw $e;
            }
        }

        if ($lastError instanceof HttpException) {
            throw $lastError;
        }
        throw new HttpException(503, '尚未配置可用的 Serper 账号，请在后台「Serper 账号」中添加并启用。', 'serper_not_configured');
    }

    /**
     * @return array{results: array<int, array{title:string,url:string,snippet:string,position:int}>, credits: int|null, account_id: int|null, account_name: string|null}
     */
    private function requestOnce(
        string $query,
        int $page,
        string $language,
        string $apiKey,
        string $endpoint,
        ?int $accountId,
        ?string $accountName
    ): array {
        $page = max(1, min(PipelineService::AUTO_PAGE_CAP, $page));
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
            'account_id' => $accountId,
        ]);

        if (!is_string($raw) || $raw === '') {
            throw new HttpException(502, 'Serper 请求失败：' . ($curlError ?: '空响应'), 'serper_failed');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(502, 'Serper 返回了无法解析的响应。', 'serper_failed', ['status' => $status]);
        }

        if ($status === 401 || $status === 403) {
            throw new HttpException(502, 'Serper API Key 无效或已过期。', 'serper_unauthorized', ['status' => $status]);
        }
        if ($status === 429) {
            throw new HttpException(502, 'Serper 请求过于频繁或额度不足。', 'serper_rate_limited', ['status' => $status]);
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
            'account_id' => $accountId,
            'account_name' => $accountName,
        ];
    }
}
