<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Str;
use App\Core\Url;

/**
 * The single source of truth for pipeline stage transitions and next actions.
 * The Chrome extension only asks "what next?" and performs browser-side work.
 */
final class PipelineService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly AiService $ai,
        private readonly WebsiteService $websites
    ) {
    }

    /** @return array<string, mixed> */
    public function next(int $taskId): array
    {
        $task = $this->tasks->find($taskId);
        if ((string) $task['status'] === 'paused') {
            return $this->result($task, 'paused', null, '任务已暂停，点击继续后恢复。');
        }
        if ((string) $task['status'] === 'failed') {
            return $this->result($task, 'failed', null, '任务处于异常状态，请重试当前阶段。');
        }
        if ((string) $task['stage'] === 'done' || (string) $task['status'] === 'completed') {
            return $this->result($task, 'done', null, '全部流程已完成。');
        }

        $task = $this->syncStage($task);
        if ((string) $task['stage'] === 'done') {
            return $this->result($task, 'done', null, '全部流程已完成。');
        }

        for ($guard = 0; $guard < 8; $guard++) {
            $counts = $this->tasks->counts($taskId);
            $stage = (string) $task['stage'];
            $delay = max(500, min(15000, Settings::int('request_delay_ms', 2500)));

            if ($stage === 'keywords') {
                if ($counts['keywords_total'] > 0) {
                    $this->tasks->setStage($taskId, 'serp', '关键词已存在，进入搜索阶段');
                    $task = $this->tasks->find($taskId);
                    continue;
                }
                return $this->result($task, 'generate_keywords', [
                    'count' => (int) ($task['options']['keyword_count'] ?? setting('keyword_count', 24)),
                    'city' => $task['city'],
                ], '需要 AI 生成城市外贸关键词。');
            }

            if ($stage === 'serp') {
                $keyword = Db::instance()->fetch(
                    'SELECT * FROM `' . Db::instance()->tableName('keywords') . '`
                     WHERE `task_id` = ? AND `pages_done` < `pages_target` AND `status` <> \'done\'
                     ORDER BY `id` ASC LIMIT 1',
                    [$taskId]
                );
                if ($keyword === null) {
                    $this->tasks->setStage($taskId, 'classify', '所有关键词搜索页已处理，进入 AI 企业筛选');
                    $task = $this->tasks->find($taskId);
                    continue;
                }
                $page = (int) $keyword['pages_done'] + 1;
                $job = [
                    'keyword_id' => (int) $keyword['id'],
                    'keyword' => (string) $keyword['keyword'],
                    'page' => $page,
                    'pages_target' => (int) $keyword['pages_target'],
                    'google_url' => Url::googleSearch(
                        (string) $keyword['keyword'],
                        $page,
                        (string) $task['language'],
                        (string) setting('google_domain', 'www.google.com')
                    ),
                    'delay_ms' => $delay,
                ];
                if (SerperService::effectiveProvider() === 'serper') {
                    $job['provider'] = 'serper';
                    $job['serper_endpoint'] = SerperService::endpoint();
                    $job['gl'] = (string) setting('serper_gl', 'us');
                    $job['hl'] = (string) setting('serper_hl', (string) ($task['language'] ?: 'en'));
                    $job['delay_ms'] = 300;
                    return $this->result($task, 'search_serper', $job, '通过 Serper.dev 获取 Google 搜索结果。');
                }
                return $this->result($task, 'fetch_serp', $job, '请用浏览器抓取 Google 搜索结果页。');
            }

            if ($stage === 'classify') {
                $pending = Db::instance()->table('websites')->where('task_id', $taskId)->where('classify_status', 'pending')->count();
                if ($pending === 0) {
                    $this->tasks->setStage($taskId, 'discover', '域名企业属性筛选完成，进入官网页面识别');
                    $task = $this->tasks->find($taskId);
                    continue;
                }
                return $this->result($task, 'classify_domains', [
                    'batch_size' => max(5, min(50, Settings::int('classify_batch', 30))),
                    'pending_count' => $pending,
                ], '请调用 AI 批量判断域名是否为中国外贸企业官网。');
            }

            if ($stage === 'discover') {
                $website = Db::instance()->table('websites')
                    ->where('task_id', $taskId)
                    ->where('classify_status', 'approved')
                    ->where('page_status', 'pending')
                    ->orderBy('id')
                    ->first();
                if ($website === null) {
                    $this->tasks->setStage($taskId, 'extract', '官网关键页面识别完成，进入联系方式提取');
                    $task = $this->tasks->find($taskId);
                    continue;
                }
                return $this->result($task, 'crawl_website', [
                    'website_id' => (int) $website['id'],
                    'domain' => (string) $website['domain'],
                    'url' => (string) $website['root_url'],
                    'attempt' => (int) $website['attempts'] + 1,
                    'delay_ms' => max(800, $delay),
                ], '请抓取官网首页 HTML。');
            }

            if ($stage === 'extract') {
                $website = Db::instance()->table('websites')
                    ->where('task_id', $taskId)
                    ->where('classify_status', 'approved')
                    ->where('page_status', 'done')
                    ->where('extract_status', 'pending')
                    ->orderBy('id')
                    ->first();
                if ($website === null) {
                    $counts = $this->tasks->counts($taskId);
                    $this->tasks->setStage($taskId, 'done', '全部流程完成，共提取 ' . $counts['companies'] . ' 家企业');
                    $task = $this->tasks->find($taskId);
                    return $this->result($task, 'done', null, '全部流程已完成。');
                }
                $pages = $this->websitePages($website);
                return $this->result($task, 'extract_contacts', [
                    'website_id' => (int) $website['id'],
                    'domain' => (string) $website['domain'],
                    'pages' => $pages,
                    'delay_ms' => max(500, $delay),
                ], '请抓取关键页面并提取企业联系方式。');
            }

            $this->tasks->setStage($taskId, 'done', '流程结束');
            $task = $this->tasks->find($taskId);
        }

        return $this->result($task, 'done', null, '流程已结束。');
    }

    /**
     * Advance every stage that is provably complete. This is what guarantees
     * "finish this step before starting the next one".
     */
    public function syncStage(array $task): array
    {
        for ($guard = 0; $guard < 8; $guard++) {
            if (in_array((string) $task['status'], ['paused', 'failed', 'completed'], true)) {
                break;
            }
            $counts = $this->tasks->counts((int) $task['id']);
            $stage = (string) $task['stage'];
            $complete = false;
            $next = null;
            $message = '';

            if ($stage === 'keywords') {
                $complete = $counts['keywords_total'] > 0;
                $next = 'serp';
                $message = '关键词已生成，进入搜索阶段';
            } elseif ($stage === 'serp') {
                $complete = $counts['keywords_total'] === 0 || $counts['serp_pages_done'] >= $counts['serp_pages_target'];
                $next = 'classify';
                $message = '搜索页全部处理完成，进入 AI 企业筛选';
            } elseif ($stage === 'classify') {
                $complete = $counts['pending_classify'] === 0;
                $next = 'discover';
                $message = '域名筛选完成，进入官网页面识别';
            } elseif ($stage === 'discover') {
                $complete = $counts['pending_crawl'] === 0;
                $next = 'extract';
                $message = '官网关键页面识别完成，进入联系方式提取';
            } elseif ($stage === 'extract') {
                $complete = $counts['pending_extract'] === 0;
                $next = 'done';
                $message = '全部流程完成，共提取 ' . $counts['companies'] . ' 家企业';
            }

            if (!$complete || $next === null) {
                break;
            }
            $this->tasks->setStage((int) $task['id'], $next, $message);
            $task = $this->tasks->find((int) $task['id']);
        }
        return $task;
    }

    /** @return array<string, mixed> */
    public function generateKeywords(int $taskId): array
    {
        $task = $this->tasks->find($taskId);
        $this->assertRunnable($task);
        $lock = $this->lock($taskId, 'keywords');
        try {
            $existing = Db::instance()->table('keywords')->where('task_id', $taskId)->count();
            if ($existing > 0) {
                if ((string) $task['stage'] === 'keywords') {
                    $this->tasks->setStage($taskId, 'serp', '关键词已存在，进入搜索阶段');
                }
                return ['generated' => 0, 'existing' => $existing, 'counts' => $this->tasks->counts($taskId)];
            }

            $count = (int) ($task['options']['keyword_count'] ?? setting('keyword_count', 24));
            $count = max(6, min(60, $count));
            try {
                $keywords = $this->ai->generateKeywords($task, $count);
            } catch (HttpException $e) {
                Db::instance()->ensureConnected();
                $this->tasks->setError($taskId, $e->getMessage());
                $this->tasks->log($taskId, 'keywords', 'error', '关键词生成失败：' . $e->getMessage());
                throw $e;
            } catch (\Throwable $e) {
                Db::instance()->ensureConnected();
                $raw = $e->getMessage();
                $message = preg_match('/maximum execution time|max_execution_time|timed out|timeout/i', $raw)
                    ? '关键词生成超时。免费主机通常限制约 60 秒：请把关键词数量降到 12、换更快模型，或缩短中转站超时。'
                    : ('关键词生成失败：' . Str::limit($raw, 300));
                $this->tasks->setError($taskId, $message);
                $this->tasks->log($taskId, 'keywords', 'error', $message);
                throw new HttpException(502, $message, 'keyword_generation_failed');
            }

            Db::instance()->ensureConnected();
            $inserted = 0;
            Db::instance()->transaction(function (Db $db) use ($task, $taskId, $keywords, &$inserted): void {
                foreach ($keywords as $keyword) {
                    $ok = $db->insertIgnore('keywords', [
                        'task_id' => $taskId,
                        'keyword' => $keyword['keyword'],
                        'intent' => $keyword['intent'],
                        'language' => $keyword['language'],
                        'status' => 'pending',
                        'pages_target' => (int) $task['pages_target'],
                        'pages_done' => 0,
                        'attempts' => 0,
                        'results_count' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    if ($ok) {
                        $inserted++;
                    }
                }
            });
            if ($inserted === 0) {
                throw new HttpException(502, 'AI 生成的关键词全部重复，请重试或调整提示词。', 'ai_duplicate_keywords');
            }

            $this->tasks->setError($taskId, null);
            $this->tasks->setStage($taskId, 'serp', 'AI 已生成 ' . $inserted . ' 个关键词，进入搜索阶段');
            $this->tasks->refresh($this->tasks->find($taskId), $this->tasks->counts($taskId));
            return [
                'generated' => $inserted,
                'keywords' => Db::instance()->table('keywords')->where('task_id', $taskId)->orderBy('id')->limit(100)->get(),
                'counts' => $this->tasks->counts($taskId),
            ];
        } finally {
            Db::instance()->releaseLock($lock);
        }
    }

    /**
     * @param array<int, array{title?:string,url?:string,snippet?:string,position?:int}> $results
     * @return array<string, mixed>
     */
    public function handleSerp(
        int $taskId,
        int $keywordId,
        int $page,
        array $results,
        ?string $error = null,
        bool $blocked = false,
        string $source = 'google'
    ): array {
        $task = $this->tasks->find($taskId);
        $this->assertRunnable($task);
        $keyword = Db::instance()->table('keywords')
            ->where('id', $keywordId)
            ->where('task_id', $taskId)
            ->first();
        if ($keyword === null) {
            throw HttpException::notFound('关键词不存在或不属于当前任务');
        }
        $pagesTarget = (int) $keyword['pages_target'];
        if ($page < 1 || $page > $pagesTarget) {
            throw HttpException::validation('页码超出任务范围');
        }

        $clean = $this->sanitizeResults($results);
        $attempts = (int) $keyword['attempts'];
        if ($clean === [] && ($error !== null || $blocked)) {
            return $this->serpFailure($task, $keyword, $page, $attempts, $blocked
                ? 'Google 返回验证页或空结果，可能触发人机验证'
                : ($error ?: '未解析到搜索结果'));
        }
        if ($clean === []) {
            $message = $source === 'serper'
                ? 'Serper 未返回可用搜索结果'
                : '未解析到搜索结果（Google 页面结构变化或结果为空）';
            return $this->serpFailure($task, $keyword, $page, $attempts, $message);
        }

        $stored = 0;
        $newDomains = 0;
        Db::instance()->transaction(function (Db $db) use ($taskId, $keywordId, $keyword, $page, $clean, &$stored, &$newDomains): void {
            foreach ($clean as $result) {
                $url = Str::limit((string) $result['url'], 990, '');
                $domain = (string) $result['domain'];
                $inserted = $db->insertIgnore('search_results', [
                    'task_id' => $taskId,
                    'keyword_id' => $keywordId,
                    'keyword' => (string) $keyword['keyword'],
                    'page' => $page,
                    'position' => (int) $result['position'],
                    'title' => Str::limit((string) $result['title'], 490, ''),
                    'url' => $url,
                    'url_hash' => sha1($url),
                    'domain' => $domain,
                    'snippet' => Str::limit((string) $result['snippet'], 2000, ''),
                    'created_at' => now(),
                ]);
                if ($inserted) {
                    $stored++;
                }
                $root = Url::rootUrl($url);
                if ($root === null) {
                    continue;
                }
                $before = $db->table('websites')->where('task_id', $taskId)->where('domain', $domain)->first();
                $db->upsert('websites', [
                    'task_id' => $taskId,
                    'domain' => Str::limit($domain, 250, ''),
                    'root_url' => Str::limit($root, 490, ''),
                    'sample_url' => $url,
                    'title' => Str::limit((string) $result['title'], 490, ''),
                    'snippet' => Str::limit((string) $result['snippet'], 2000, ''),
                    'source_keyword_id' => $keywordId,
                    'classify_status' => 'pending',
                    'page_status' => 'pending',
                    'extract_status' => 'pending',
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], ['sample_url', 'title', 'snippet', 'source_keyword_id', 'updated_at']);
                if ($before === null) {
                    $newDomains++;
                }
            }
        });

        $pagesDone = max((int) $keyword['pages_done'], $page);
        $status = $pagesDone >= $pagesTarget ? 'done' : 'pending';
        $resultCount = (int) (Db::instance()->value(
            'SELECT COUNT(*) FROM `' . Db::instance()->tableName('search_results') . '` WHERE `keyword_id` = ?',
            [$keywordId]
        ) ?? 0);
        Db::instance()->update('keywords', [
            'pages_done' => $pagesDone,
            'status' => $status,
            'attempts' => 0,
            'results_count' => $resultCount,
            'last_error' => null,
            'updated_at' => now(),
        ], ['id' => $keywordId]);

        $this->tasks->setError($taskId, null);
        $this->tasks->log($taskId, 'serp', 'success', sprintf(
            '%s 第 %d 页采集完成：解析 %d 条，域名去重后新增 %d 个',
            $source === 'serper' ? 'Serper' : 'Google',
            $page,
            $stored,
            $newDomains
        ), [
            'keyword_id' => $keywordId,
            'page' => $page,
            'stored' => $stored,
            'new_domains' => $newDomains,
            'provider' => $source,
        ]);
        $counts = $this->tasks->counts($taskId);
        $this->tasks->refresh($this->tasks->find($taskId), $counts);

        return [
            'stored' => $stored,
            'new_domains' => $newDomains,
            'parsed' => count($clean),
            'page' => $page,
            'keyword_done' => $status === 'done',
            'attempts' => 0,
            'counts' => $counts,
        ];
    }

    /**
     * Perform one SERP page through Serper.dev. The API key stays on the
     * server; the extension only receives the normalised result counts.
     *
     * @return array<string, mixed>
     */
    public function searchSerper(int $taskId, int $keywordId, int $page, ?string $overrideApiKey = null): array
    {
        $task = $this->tasks->find($taskId);
        $this->assertRunnable($task);
        $keyword = Db::instance()->table('keywords')
            ->where('id', $keywordId)
            ->where('task_id', $taskId)
            ->first();
        if ($keyword === null) {
            throw HttpException::notFound('关键词不存在或不属于当前任务');
        }
        $pagesTarget = (int) $keyword['pages_target'];
        if ($page < 1 || $page > $pagesTarget) {
            throw HttpException::validation('页码超出任务范围');
        }

        try {
            $response = (new SerperService())->search(
                (string) $keyword['keyword'],
                $page,
                (string) $task['language'],
                $overrideApiKey
            );
        } catch (HttpException $e) {
            // Configuration and quota errors are not transient; pause instead
            // of silently skipping every remaining page.
            if (in_array($e->errorCode, ['serper_not_configured', 'serper_unauthorized', 'serper_rate_limited'], true)) {
                $this->tasks->setError($taskId, $e->getMessage());
                $this->tasks->log($taskId, 'serp', 'error', $e->getMessage(), [
                    'provider' => 'serper',
                    'page' => $page,
                ]);
                $this->tasks->setStatus($taskId, 'paused', 'serp', 'Serper 已暂停：' . $e->getMessage());
                throw $e;
            }

            $this->tasks->log($taskId, 'serp', 'error', 'Serper 请求失败：' . $e->getMessage(), [
                'provider' => 'serper',
                'page' => $page,
            ]);
            $failure = $this->serpFailure($task, $keyword, $page, (int) $keyword['attempts'], $e->getMessage());
            $failure['provider'] = 'serper';
            return $failure;
        }

        $data = $this->handleSerp($taskId, $keywordId, $page, $response['results'], null, false, 'serper');
        $data['provider'] = 'serper';
        $data['credits'] = $response['credits'];
        $data['serper_account_id'] = $response['account_id'] ?? null;
        $data['serper_account_name'] = $response['account_name'] ?? null;
        if (!empty($response['account_name'])) {
            $this->tasks->log($taskId, 'serp', 'info', 'Serper 使用账号：' . $response['account_name'], [
                'account_id' => $response['account_id'] ?? null,
                'credits' => $response['credits'],
            ]);
        }
        if ($response['credits'] !== null && $response['credits'] < 100) {
            $this->tasks->log($taskId, 'serp', 'warning', 'Serper 余额不足 100 次，请及时充值或添加备用账号。', [
                'credits' => $response['credits'],
                'account_id' => $response['account_id'] ?? null,
            ]);
        }
        return $data;
    }

    /** @return array<string, mixed> */
    private function serpFailure(array $task, array $keyword, int $page, int $attempts, string $message): array
    {
        $taskId = (int) $task['id'];
        $attempts++;
        $maxAttempts = $this->maxAttempts();
        $pagesTarget = (int) $keyword['pages_target'];
        $pagesDone = (int) $keyword['pages_done'];
        $skipped = $attempts >= $maxAttempts;
        $errorPages = (int) ($keyword['error_pages'] ?? 0);
        if ($skipped) {
            $pagesDone = max($pagesDone, $page);
            $errorPages++;
        }
        $status = $pagesDone >= $pagesTarget ? 'done' : 'pending';
        Db::instance()->update('keywords', [
            'attempts' => $skipped ? 0 : $attempts,
            'error_pages' => $errorPages,
            'pages_done' => $pagesDone,
            'status' => $status,
            'last_error' => Str::limit($message, 490, ''),
            'updated_at' => now(),
        ], ['id' => (int) $keyword['id']]);
        $this->tasks->setError($taskId, $message);
        $this->tasks->log(
            $taskId,
            'serp',
            $skipped ? 'warning' : 'error',
            $skipped
                ? sprintf('关键词「%s」第 %d 页连续失败 %d 次，已跳过', $keyword['keyword'], $page, $maxAttempts)
                : sprintf('关键词「%s」第 %d 页采集失败（第 %d 次）：%s', $keyword['keyword'], $page, $attempts, $message),
            ['keyword_id' => (int) $keyword['id'], 'page' => $page, 'attempts' => $attempts]
        );
        $counts = $this->tasks->counts($taskId);
        $this->tasks->refresh($this->tasks->find($taskId), $counts);
        return [
            'stored' => 0,
            'parsed' => 0,
            'page' => $page,
            'attempts' => $skipped ? 0 : $attempts,
            'skipped' => $skipped,
            'retry' => !$skipped,
            'message' => $message,
            'counts' => $counts,
        ];
    }

    /**
     * @param array<int, array{title?:string,url?:string,snippet?:string,position?:int}> $results
     * @return array<int, array{title:string,url:string,domain:string,snippet:string,position:int}>
     */
    private function sanitizeResults(array $results): array
    {
        $clean = [];
        $seen = [];
        $position = 0;
        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['url'] ?? ''));
            $domain = Url::domain($url);
            if (!Url::isHttp($url) || $domain === null) {
                continue;
            }
            if ($domain === 'google.com' || str_starts_with($domain, 'google.') || str_ends_with($domain, '.google.com')) {
                continue;
            }
            $url = Str::limit($url, 990, '');
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $position++;
            $clean[] = [
                'title' => trim((string) ($row['title'] ?? '')),
                'url' => $url,
                'domain' => $domain,
                'snippet' => trim((string) ($row['snippet'] ?? '')),
                'position' => (int) ($row['position'] ?? $position) ?: $position,
            ];
            if (count($clean) >= 100) {
                break;
            }
        }
        return $clean;
    }

    /** @return array<string, mixed> */
    public function classifyBatch(int $taskId): array
    {
        $task = $this->tasks->find($taskId);
        $this->assertRunnable($task);
        $lock = $this->lock($taskId, 'classify');
        try {
            $batchSize = max(5, min(50, Settings::int('classify_batch', 30)));
            $websites = Db::instance()->table('websites')
                ->where('task_id', $taskId)
                ->where('classify_status', 'pending')
                ->orderBy('id')
                ->limit($batchSize)
                ->get();
            if ($websites === []) {
                return ['processed' => 0, 'approved' => 0, 'rejected' => 0, 'counts' => $this->tasks->counts($taskId)];
            }

            $aiList = [];
            $prefiltered = 0;
            foreach ($websites as $website) {
                if (Url::isPlatform((string) $website['domain']) || Url::isSocial((string) $website['domain'])) {
                    Db::instance()->update('websites', [
                        'classify_status' => 'rejected',
                        'is_chinese_enterprise' => 0,
                        'ai_confidence' => 0,
                        'ai_reason' => '平台、搜索引擎、目录或社交媒体，非企业独立站',
                        'updated_at' => now(),
                    ], ['id' => (int) $website['id']]);
                    $prefiltered++;
                } else {
                    $aiList[] = $website;
                }
            }

            $approved = 0;
            $rejected = $prefiltered;
            $errors = 0;
            if ($aiList !== []) {
                try {
                    $judgements = $this->ai->classifyDomains($task, $aiList);
                } catch (\Throwable $e) {
                    Db::instance()->ensureConnected();
                    $this->tasks->setError($taskId, $e->getMessage());
                    $this->tasks->log($taskId, 'classify', 'error', 'AI 企业筛选失败：' . $e->getMessage(), [
                        'batch' => count($aiList),
                    ]);
                    throw $e;
                }
                Db::instance()->ensureConnected();
                $byIndex = [];
                foreach ($judgements as $judgement) {
                    $byIndex[(int) $judgement['n']] = $judgement;
                }
                foreach ($aiList as $index => $website) {
                    $n = $index + 1;
                    if (!isset($byIndex[$n])) {
                        $errors++;
                        Db::instance()->update('websites', [
                            'classify_status' => 'error',
                            'last_error' => 'AI 未返回该域名的判断结果',
                            'updated_at' => now(),
                        ], ['id' => (int) $website['id']]);
                        continue;
                    }
                    $judgement = $byIndex[$n];
                    $isTarget = (bool) $judgement['is_target'];
                    Db::instance()->update('websites', [
                        'classify_status' => $isTarget ? 'approved' : 'rejected',
                        'is_chinese_enterprise' => $isTarget ? 1 : 0,
                        'ai_confidence' => (float) $judgement['confidence'],
                        'ai_reason' => Str::limit((string) $judgement['reason'], 490, ''),
                        'last_error' => null,
                        'updated_at' => now(),
                    ], ['id' => (int) $website['id']]);
                    if ($isTarget) {
                        $approved++;
                    } else {
                        $rejected++;
                    }
                }
            }

            $this->tasks->setError($taskId, null);
            $this->tasks->log($taskId, 'classify', 'success', sprintf(
                'AI 企业筛选：本批 %d 个域名，通过 %d 个，排除 %d 个%s',
                count($websites),
                $approved,
                $rejected,
                $errors > 0 ? '，' . $errors . ' 个待重试' : ''
            ), ['processed' => count($websites), 'approved' => $approved, 'rejected' => $rejected, 'errors' => $errors]);
            $counts = $this->tasks->counts($taskId);
            $this->tasks->refresh($this->tasks->find($taskId), $counts);

            return [
                'processed' => count($websites),
                'approved' => $approved,
                'rejected' => $rejected,
                'errors' => $errors,
                'pending' => $counts['pending_classify'],
                'counts' => $counts,
            ];
        } finally {
            Db::instance()->releaseLock($lock);
        }
    }

    /** @return array<string, mixed> */
    public function crawlWebsite(int $taskId, int $websiteId, string $html, string $url = ''): array
    {
        $task = $this->tasks->find($taskId);
        $this->assertRunnable($task);
        $website = Db::instance()->table('websites')->where('id', $websiteId)->where('task_id', $taskId)->first();
        if ($website === null) {
            throw HttpException::notFound('网站不存在');
        }
        if ((string) $website['classify_status'] !== 'approved') {
            throw HttpException::validation('该网站尚未通过 AI 企业筛选');
        }
        if ((string) $website['page_status'] === 'done') {
            return ['pages' => $this->websitePages($website), 'already_done' => true];
        }

        $lock = $this->lock($taskId, 'pages:' . $websiteId);
        try {
            try {
                $result = $this->websites->discoverPages($task, $website, $html, $url);
            } catch (\Throwable $e) {
                Db::instance()->ensureConnected();
                $this->websiteFailure($taskId, $website, 'page_status', $e->getMessage(), 'discover');
                throw $e instanceof HttpException ? $e : new HttpException(502, '官网页面识别失败：' . $e->getMessage(), 'page_discovery_failed');
            }
            Db::instance()->ensureConnected();
            $this->tasks->setError($taskId, null);
            $this->tasks->log($taskId, 'discover', 'success', sprintf(
                '已识别 %s 的关键页面（About: %s / Contact: %s）',
                $website['domain'],
                $result['about'] ? '有' : '无',
                $result['contact'] ? '有' : '无'
            ), ['website_id' => $websiteId, 'pages' => $result['pages']]);
            $counts = $this->tasks->counts($taskId);
            $this->tasks->refresh($this->tasks->find($taskId), $counts);
            return $result + ['counts' => $counts];
        } finally {
            Db::instance()->releaseLock($lock);
        }
    }

    /**
     * @param array<int, array{type?:string,url?:string,html?:string}> $pages
     * @return array<string, mixed>
     */
    public function extractWebsite(int $taskId, int $websiteId, array $pages): array
    {
        $task = $this->tasks->find($taskId);
        $this->assertRunnable($task);
        $website = Db::instance()->table('websites')->where('id', $websiteId)->where('task_id', $taskId)->first();
        if ($website === null) {
            throw HttpException::notFound('网站不存在');
        }
        if ((string) $website['classify_status'] !== 'approved' || (string) $website['page_status'] !== 'done') {
            throw HttpException::validation('该网站尚未完成关键页面识别');
        }
        if ((string) $website['extract_status'] === 'done') {
            $company = Db::instance()->table('companies')->where('task_id', $taskId)->where('website_id', $websiteId)->first();
            return ['company' => $company, 'already_done' => true];
        }
        $cleanPages = [];
        foreach (array_slice($pages, 0, 5) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $cleanPages[] = [
                'type' => (string) ($page['type'] ?? 'page'),
                'url' => (string) ($page['url'] ?? ''),
                'html' => (string) ($page['html'] ?? ''),
            ];
        }
        if ($cleanPages === []) {
            throw HttpException::validation('没有可解析的页面 HTML');
        }

        $lock = $this->lock($taskId, 'extract:' . $websiteId);
        try {
            try {
                $company = $this->websites->extractCompany($task, $website, $cleanPages);
            } catch (\Throwable $e) {
                Db::instance()->ensureConnected();
                $this->websiteFailure($taskId, $website, 'extract_status', $e->getMessage(), 'extract');
                throw $e instanceof HttpException ? $e : new HttpException(502, '联系方式提取失败：' . $e->getMessage(), 'contact_extraction_failed');
            }
            Db::instance()->ensureConnected();
            $this->tasks->setError($taskId, null);
            $this->tasks->log($taskId, 'extract', 'success', sprintf(
                '已提取 %s 的企业信息：公司名%s，电话%s，LinkedIn%s',
                $website['domain'],
                $company['company_name'] ? '有' : '无',
                $company['phone'] ? '有' : '无',
                $company['linkedin'] ? '有' : '无'
            ), [
                'website_id' => $websiteId,
                'company_id' => $company['id'] ?? null,
                'phone' => $company['phone'] ?? null,
            ]);
            $counts = $this->tasks->counts($taskId);
            $this->tasks->refresh($this->tasks->find($taskId), $counts);
            return ['company' => $company, 'counts' => $counts];
        } finally {
            Db::instance()->releaseLock($lock);
        }
    }

    private function websiteFailure(int $taskId, array $website, string $statusColumn, string $message, string $stage): void
    {
        $attempts = (int) $website['attempts'] + 1;
        $max = $this->maxAttempts();
        $terminal = $attempts >= $max;
        $data = [
            'attempts' => $terminal ? 0 : $attempts,
            'last_error' => Str::limit($message, 990, ''),
            'updated_at' => now(),
        ];
        if ($terminal) {
            $data[$statusColumn] = 'error';
        }
        Db::instance()->update('websites', $data, ['id' => (int) $website['id']]);
        $this->tasks->setError($taskId, $message);
        $this->tasks->log(
            $taskId,
            $stage,
            $terminal ? 'warning' : 'error',
            $terminal
                ? sprintf('%s 连续失败 %d 次，已跳过：%s', $website['domain'], $max, $message)
                : sprintf('%s 处理失败（第 %d 次）：%s', $website['domain'], $attempts, $message),
            ['website_id' => (int) $website['id'], 'attempts' => $attempts]
        );
    }

    /** @return array<int, array{type:string,url:string}> */
    public function websitePages(array $website): array
    {
        $pages = json_decode((string) ($website['pages_json'] ?? '[]'), true);
        $output = [];
        if (is_array($pages)) {
            foreach ($pages as $page) {
                if (is_array($page) && !empty($page['url'])) {
                    $output[] = ['type' => (string) ($page['type'] ?? 'page'), 'url' => (string) $page['url']];
                }
            }
        }
        if ($output === []) {
            if (!empty($website['home_url'])) {
                $output[] = ['type' => 'home', 'url' => (string) $website['home_url']];
            } elseif (!empty($website['root_url'])) {
                $output[] = ['type' => 'home', 'url' => (string) $website['root_url']];
            }
            if (!empty($website['about_url'])) {
                $output[] = ['type' => 'about', 'url' => (string) $website['about_url']];
            }
            if (!empty($website['contact_url'])) {
                $output[] = ['type' => 'contact', 'url' => (string) $website['contact_url']];
            }
        }
        return $output;
    }

    /** @return array<string, mixed> */
    public function pause(int $taskId): array
    {
        $task = $this->tasks->find($taskId);
        if ((string) $task['status'] === 'completed') {
            throw HttpException::validation('已完成的任务不能暂停');
        }
        $this->tasks->setStatus($taskId, 'paused', (string) $task['stage'], '任务已暂停');
        return $this->tasks->find($taskId);
    }

    /** @return array<string, mixed> */
    public function resume(int $taskId): array
    {
        $task = $this->tasks->find($taskId);
        if ((string) $task['status'] === 'completed') {
            return $task;
        }
        $this->tasks->setStatus($taskId, 'active', (string) $task['stage'], '任务已继续');
        return $this->tasks->find($taskId);
    }

    /** @return array<string, mixed> */
    public function retry(int $taskId): array
    {
        $task = $this->tasks->find($taskId);
        $db = Db::instance();
        $kwTable = $db->tableName('keywords');
        $webTable = $db->tableName('websites');
        $db->query(
            "UPDATE `{$kwTable}` SET `attempts` = 0, `last_error` = NULL, `status` = 'pending', `updated_at` = ?
             WHERE `task_id` = ? AND `pages_done` < `pages_target`",
            [now(), $taskId]
        );
        // A page that exhausted its retries was skipped by advancing pages_done.
        // Rewind those pages so the user can retry the SERP instead of silently
        // keeping the gap forever.
        $db->query(
            "UPDATE `{$kwTable}` SET `pages_done` = GREATEST(0, `pages_done` - `error_pages`),
                    `attempts` = 0, `error_pages` = 0, `last_error` = NULL, `status` = 'pending', `updated_at` = ?
             WHERE `task_id` = ? AND `error_pages` > 0",
            [now(), $taskId]
        );
        $db->query(
            "UPDATE `{$webTable}` SET `classify_status` = 'pending', `last_error` = NULL, `updated_at` = ?
             WHERE `task_id` = ? AND `classify_status` = 'error'",
            [now(), $taskId]
        );
        $db->query(
            "UPDATE `{$webTable}` SET `page_status` = 'pending', `attempts` = 0, `last_error` = NULL, `updated_at` = ?
             WHERE `task_id` = ? AND `classify_status` = 'approved' AND `page_status` = 'error'",
            [now(), $taskId]
        );
        $db->query(
            "UPDATE `{$webTable}` SET `extract_status` = 'pending', `attempts` = 0, `last_error` = NULL, `updated_at` = ?
             WHERE `task_id` = ? AND `classify_status` = 'approved' AND `extract_status` = 'error'",
            [now(), $taskId]
        );
        $counts = $this->tasks->counts($taskId);
        $stage = (string) $task['stage'];
        if ($counts['keywords_total'] === 0) {
            $stage = 'keywords';
        } elseif ($counts['serp_pages_done'] < $counts['serp_pages_target']) {
            $stage = 'serp';
        } elseif ($counts['pending_classify'] > 0) {
            $stage = 'classify';
        } elseif ($counts['pending_crawl'] > 0) {
            $stage = 'discover';
        } elseif ($counts['pending_extract'] > 0) {
            $stage = 'extract';
        } elseif ((string) $task['stage'] === 'done') {
            $stage = 'done';
        }
        $db->update('tasks', [
            'status' => $stage === 'done' ? 'completed' : 'active',
            'stage' => $stage,
            'error_message' => null,
            'finished_at' => $stage === 'done' ? $task['finished_at'] : null,
            'updated_at' => now(),
        ], ['id' => $taskId]);
        $this->tasks->log($taskId, $stage, 'info', '已重置异常项并重试当前阶段');
        $fresh = $this->tasks->find($taskId);
        $this->tasks->refresh($fresh, $this->tasks->counts($taskId));
        return $this->tasks->find($taskId);
    }

    /**
     * Rewind a task to a specific stage and optionally update task parameters.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function rewind(int $taskId, string $targetStage, array $options = []): array
    {
        $allowedStages = ['keywords', 'serp', 'classify', 'discover', 'extract'];
        if (!in_array($targetStage, $allowedStages, true)) {
            throw HttpException::validation('无效的回退阶段：' . $targetStage);
        }

        $task = $this->tasks->find($taskId);
        $db = Db::instance();
        $now = now();

        $city = isset($options['city']) ? trim((string) $options['city']) : (string) $task['city'];
        if ($city === '' || mb_strlen($city) > 100) {
            $city = (string) $task['city'];
        }
        $targetMarket = isset($options['target_market']) ? trim((string) $options['target_market']) : (string) $task['target_market'];
        if ($targetMarket === '') {
            $targetMarket = (string) ($task['target_market'] ?: '全球');
        }
        $keywordCount = isset($options['keyword_count']) && (int) $options['keyword_count'] > 0
            ? max(6, min(60, (int) $options['keyword_count']))
            : (int) ($task['keyword_count'] ?: 24);
        $pagesTarget = isset($options['pages_target']) && (int) $options['pages_target'] > 0
            ? max(1, min(10, (int) $options['pages_target']))
            : (int) ($task['pages_target'] ?: 3);

        $taskOptions = is_array($task['options'] ?? null) ? $task['options'] : [];
        $taskOptions['keyword_count'] = $keywordCount;
        $taskOptions['pages_target'] = $pagesTarget;
        $taskOptions['target_market'] = Str::limit($targetMarket, 100, '');

        $kwTable = $db->tableName('keywords');
        $serpTable = $db->tableName('serp_results');
        $webTable = $db->tableName('websites');
        $compTable = $db->tableName('companies');

        $stageLabels = [
            'keywords' => '01 AI 关键词生成',
            'serp' => '02 Google 搜索抓取',
            'classify' => '03 AI 企业域名筛选',
            'discover' => '04 官网关键页面识别',
            'extract' => '05 企业联系方式提取',
        ];

        $db->transaction(function (Db $tx) use (
            $taskId,
            $targetStage,
            $city,
            $targetMarket,
            $pagesTarget,
            $taskOptions,
            $kwTable,
            $serpTable,
            $webTable,
            $compTable,
            $now
        ): void {
            if ($targetStage === 'keywords') {
                $tx->query("DELETE FROM `{$compTable}` WHERE `task_id` = ?", [$taskId]);
                $tx->query("DELETE FROM `{$webTable}` WHERE `task_id` = ?", [$taskId]);
                $tx->query("DELETE FROM `{$serpTable}` WHERE `task_id` = ?", [$taskId]);
                $tx->query("DELETE FROM `{$kwTable}` WHERE `task_id` = ?", [$taskId]);
            } elseif ($targetStage === 'serp') {
                $tx->query("DELETE FROM `{$compTable}` WHERE `task_id` = ?", [$taskId]);
                $tx->query("DELETE FROM `{$webTable}` WHERE `task_id` = ?", [$taskId]);
                $tx->query("DELETE FROM `{$serpTable}` WHERE `task_id` = ?", [$taskId]);
                $tx->query(
                    "UPDATE `{$kwTable}` SET `pages_target` = ?, `pages_done` = 0, `results_count` = 0, `attempts` = 0, `error_pages` = 0, `last_error` = NULL, `status` = 'pending', `updated_at` = ? WHERE `task_id` = ?",
                    [$pagesTarget, $now, $taskId]
                );
            } elseif ($targetStage === 'classify') {
                $tx->query("DELETE FROM `{$compTable}` WHERE `task_id` = ?", [$taskId]);
                $tx->query(
                    "UPDATE `{$webTable}` SET `classify_status` = 'pending', `is_chinese_enterprise` = NULL, `ai_confidence` = NULL, `ai_reason` = NULL, `home_url` = NULL, `about_url` = NULL, `contact_url` = NULL, `pages_json` = NULL, `page_status` = 'pending', `extract_status` = 'pending', `attempts` = 0, `last_error` = NULL, `updated_at` = ? WHERE `task_id` = ?",
                    [$now, $taskId]
                );
            } elseif ($targetStage === 'discover') {
                $tx->query("DELETE FROM `{$compTable}` WHERE `task_id` = ?", [$taskId]);
                $tx->query(
                    "UPDATE `{$webTable}` SET `home_url` = NULL, `about_url` = NULL, `contact_url` = NULL, `pages_json` = NULL, `page_status` = 'pending', `extract_status` = 'pending', `attempts` = 0, `last_error` = NULL, `updated_at` = ? WHERE `task_id` = ? AND `classify_status` = 'approved'",
                    [$now, $taskId]
                );
            } elseif ($targetStage === 'extract') {
                $tx->query("DELETE FROM `{$compTable}` WHERE `task_id` = ?", [$taskId]);
                $tx->query(
                    "UPDATE `{$webTable}` SET `extract_status` = 'pending', `attempts` = 0, `last_error` = NULL, `updated_at` = ? WHERE `task_id` = ? AND `classify_status` = 'approved'",
                    [$now, $taskId]
                );
            }

            $tx->update('tasks', [
                'city' => $city,
                'target_market' => Str::limit($targetMarket, 100, ''),
                'options_json' => json_encode($taskOptions, JSON_UNESCAPED_UNICODE),
                'status' => 'active',
                'stage' => $targetStage,
                'error_message' => null,
                'finished_at' => null,
                'updated_at' => $now,
            ], ['id' => $taskId]);
        });

        $this->tasks->log($taskId, $targetStage, 'info', sprintf(
            '已将任务回退至「%s」阶段重新开始',
            $stageLabels[$targetStage] ?? $targetStage
        ), [
            'rewind_to' => $targetStage,
            'city' => $city,
            'target_market' => $targetMarket,
            'keyword_count' => $keywordCount,
            'pages_target' => $pagesTarget,
        ]);

        $fresh = $this->tasks->find($taskId);
        $this->tasks->refresh($fresh, $this->tasks->counts($taskId));
        return $this->tasks->find($taskId);
    }

    /** @return array<string, mixed> */
    private function result(array $task, string $action, ?array $job, string $message): array
    {
        $counts = $this->tasks->counts((int) $task['id']);
        $this->tasks->refresh($task, $counts);
        $task = $this->tasks->find((int) $task['id']);
        return [
            'task' => $task,
            'counts' => $counts,
            'action' => $action,
            'job' => $job,
            'message' => $message,
        ];
    }

    private function assertRunnable(array $task): void
    {
        if ((string) $task['status'] === 'paused') {
            throw new HttpException(409, '任务已暂停', 'task_paused');
        }
        if ((string) $task['status'] === 'completed' || (string) $task['stage'] === 'done') {
            throw new HttpException(409, '任务已完成', 'task_completed');
        }
    }

    private function maxAttempts(): int
    {
        return max(1, min(10, Settings::int('max_attempts', 3)));
    }

    private function lock(int $taskId, string $suffix): string
    {
        $name = 'gle_task_' . $taskId . '_' . preg_replace('/[^a-zA-Z0-9_]/', '', $suffix);
        if (!Db::instance()->acquireLock($name, 0)) {
            throw new HttpException(409, '该任务正在执行中，请稍后重试', 'task_busy');
        }
        return $name;
    }
}
