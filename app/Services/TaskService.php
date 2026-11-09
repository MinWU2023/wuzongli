<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Str;

final class TaskService
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $city = trim((string) ($input['city'] ?? ''));
        if ($city === '') {
            throw HttpException::validation('请输入目标城市，例如：武汉');
        }
        if (mb_strlen($city) > 100) {
            throw HttpException::validation('城市名称过长');
        }
        $targetMarket = trim((string) ($input['target_market'] ?? '全球')) ?: '全球';
        $keywordCount = (int) ($input['keyword_count'] ?? 0);
        if ($keywordCount <= 0) {
            $keywordCount = (int) setting('keyword_count', 24);
        }
        $keywordCount = max(6, min(60, $keywordCount));
        // 0 = 自动翻页：每个关键词一直翻到 Google 没有更多结果为止（受安全上限约束）。
        $pagesTarget = isset($input['pages_target']) && $input['pages_target'] !== null
            ? (int) $input['pages_target']
            : (int) setting('serp_pages', 0);
        $pagesTarget = $pagesTarget <= 0 ? 0 : min(PipelineService::AUTO_PAGE_CAP, $pagesTarget);
        $language = preg_match('/^[a-z\-]{2,10}$/', (string) ($input['language'] ?? 'en')) ? (string) $input['language'] : 'en';

        $categories = CategoryService::sanitize($input['categories'] ?? []);
        $options = [
            'keyword_count' => $keywordCount,
            'pages_target' => $pagesTarget,
            'target_market' => Str::limit($targetMarket, 100, ''),
            'categories' => $categories,
        ];
        $id = Db::instance()->insert('tasks', [
            'city' => $city,
            'country' => '中国',
            'target_market' => Str::limit($targetMarket, 100, ''),
            'language' => $language,
            'stage' => 'keywords',
            'status' => 'active',
            'progress' => 0,
            'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->log($id, 'keywords', 'info', '已创建城市任务：' . $city . ($categories !== [] ? '（产品分类：' . CategoryService::label($categories) . '）' : '（未选分类，仅按城市生成）'), $options);
        return $this->find($id);
    }

    /**
     * Light edit of task meta without wiping pipeline data.
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input): array
    {
        $task = $this->find($id);
        $city = array_key_exists('city', $input) ? trim((string) $input['city']) : (string) $task['city'];
        if ($city === '') {
            throw HttpException::validation('城市名称不能为空');
        }
        if (mb_strlen($city) > 100) {
            throw HttpException::validation('城市名称过长');
        }
        $targetMarket = array_key_exists('target_market', $input)
            ? (trim((string) $input['target_market']) ?: '全球')
            : (string) ($task['target_market'] ?? '全球');

        $options = is_array($task['options'] ?? null) ? $task['options'] : [];
        $keywordCount = (int) ($input['keyword_count'] ?? $options['keyword_count'] ?? $task['keyword_count'] ?? 24);
        $pagesTarget = (int) ($input['pages_target'] ?? $options['pages_target'] ?? $task['pages_target'] ?? 3);
        $keywordCount = max(6, min(60, $keywordCount));
        $pagesTarget = $pagesTarget <= 0 ? 0 : min(PipelineService::AUTO_PAGE_CAP, $pagesTarget);
        $options['keyword_count'] = $keywordCount;
        $options['pages_target'] = $pagesTarget;
        $options['target_market'] = Str::limit($targetMarket, 100, '');
        if (array_key_exists('categories', $input)) {
            $options['categories'] = CategoryService::sanitize($input['categories']);
        }

        Db::instance()->update('tasks', [
            'city' => $city,
            'target_market' => Str::limit($targetMarket, 100, ''),
            'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ], ['id' => $id]);
        $this->log($id, (string) $task['stage'], 'info', '已更新任务资料：' . $city, $options);
        return $this->find($id);
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $task = Db::instance()->table('tasks')->where('id', $id)->first();
        if ($task === null) {
            throw HttpException::notFound('任务不存在');
        }
        return $this->decorate($task);
    }

    /** @return array<string, mixed> */
    public function decorate(array $task): array
    {
        $task['id'] = (int) $task['id'];
        $task['progress'] = (int) $task['progress'];
        $options = json_decode((string) ($task['options_json'] ?? '{}'), true);
        $task['options'] = is_array($options) ? $options : [];
        $task['keyword_count'] = (int) ($task['options']['keyword_count'] ?? setting('keyword_count', 24));
        $task['pages_target'] = (int) ($task['options']['pages_target'] ?? setting('serp_pages', 0));
        $task['categories'] = CategoryService::sanitize($task['options']['categories'] ?? []);
        $task['category_items'] = CategoryService::describe($task['categories']);
        $task['category_label'] = CategoryService::label($task['categories']);
        return $task;
    }

    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 8): array
    {
        $rows = Db::instance()->table('tasks')->latest('id')->limit($limit)->get();
        return array_map(fn (array $row): array => $this->decorate($row), $rows);
    }

    /** @return array<string, int> */
    public function counts(int $taskId): array
    {
        $kw = Db::instance()->fetch(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN `status` = \'done\' THEN 1 ELSE 0 END), 0) AS done,
                    COALESCE(SUM(`pages_target`), 0) AS pages_target,
                    COALESCE(SUM(LEAST(`pages_done`, `pages_target`)), 0) AS pages_done,
                    COALESCE(SUM(CASE WHEN `attempts` > 0 OR `error_pages` > 0 THEN 1 ELSE 0 END), 0) AS with_errors
             FROM `' . Db::instance()->tableName('keywords') . '` WHERE `task_id` = ?',
            [$taskId]
        ) ?: [];

        $web = Db::instance()->fetch(
            'SELECT COUNT(*) AS total,
                    COALESCE(SUM(CASE WHEN `classify_status` <> \'pending\' THEN 1 ELSE 0 END), 0) AS classified,
                    COALESCE(SUM(CASE WHEN `classify_status` = \'approved\' THEN 1 ELSE 0 END), 0) AS approved,
                    COALESCE(SUM(CASE WHEN `classify_status` = \'error\' THEN 1 ELSE 0 END), 0) AS classify_errors,
                    COALESCE(SUM(CASE WHEN `classify_status` = \'approved\' AND `page_status` IN (\'done\', \'error\') THEN 1 ELSE 0 END), 0) AS pages_done,
                    COALESCE(SUM(CASE WHEN `classify_status` = \'approved\' AND `page_status` = \'error\' THEN 1 ELSE 0 END), 0) AS page_errors,
                    COALESCE(SUM(CASE WHEN `classify_status` = \'approved\' AND `page_status` = \'done\' THEN 1 ELSE 0 END), 0) AS extract_ready,
                    COALESCE(SUM(CASE WHEN `classify_status` = \'approved\' AND `extract_status` IN (\'done\', \'error\') THEN 1 ELSE 0 END), 0) AS extracted,
                    COALESCE(SUM(CASE WHEN `classify_status` = \'approved\' AND `extract_status` = \'error\' THEN 1 ELSE 0 END), 0) AS extract_errors
             FROM `' . Db::instance()->tableName('websites') . '` WHERE `task_id` = ?',
            [$taskId]
        ) ?: [];

        $companies = (int) (Db::instance()->value(
            'SELECT COUNT(*) FROM `' . Db::instance()->tableName('companies') . '` WHERE `task_id` = ?',
            [$taskId]
        ) ?? 0);

        $counts = [
            'keywords_total' => (int) ($kw['total'] ?? 0),
            'keywords_done' => (int) ($kw['done'] ?? 0),
            'serp_pages_target' => (int) ($kw['pages_target'] ?? 0),
            'serp_pages_done' => (int) ($kw['pages_done'] ?? 0),
            'keyword_errors' => (int) ($kw['with_errors'] ?? 0),
            'websites_total' => (int) ($web['total'] ?? 0),
            'websites_classified' => (int) ($web['classified'] ?? 0),
            'websites_approved' => (int) ($web['approved'] ?? 0),
            'classify_errors' => (int) ($web['classify_errors'] ?? 0),
            'pages_done' => (int) ($web['pages_done'] ?? 0),
            'page_errors' => (int) ($web['page_errors'] ?? 0),
            'extract_ready' => (int) ($web['extract_ready'] ?? 0),
            'companies_extracted' => (int) ($web['extracted'] ?? 0),
            'extract_errors' => (int) ($web['extract_errors'] ?? 0),
            'companies' => $companies,
        ];
        $counts['pending_classify'] = max(0, $counts['websites_total'] - $counts['websites_classified']);
        $counts['pending_crawl'] = max(0, $counts['websites_approved'] - $counts['pages_done']);
        $counts['pending_extract'] = max(0, $counts['extract_ready'] - $counts['companies_extracted']);
        $counts['pending_phone'] = 0;
        $counts['phones_done'] = 0;
        if (PhoneService::ready()) {
            $phone = Db::instance()->fetch(
                'SELECT COALESCE(SUM(CASE WHEN `phone_status` = \'pending\' THEN 1 ELSE 0 END), 0) AS pending,
                        COALESCE(SUM(CASE WHEN `phone_status` IN (\'done\', \'skipped\') THEN 1 ELSE 0 END), 0) AS done
                 FROM `' . Db::instance()->tableName('companies') . '` WHERE `task_id` = ?',
                [$taskId]
            ) ?: [];
            $counts['pending_phone'] = (int) ($phone['pending'] ?? 0);
            $counts['phones_done'] = (int) ($phone['done'] ?? 0);
        }

        return $counts;
    }

    /** @param array<string, int> $counts */
    public function progress(array $task, array $counts): int
    {
        if ((string) $task['stage'] === 'done' || (string) $task['status'] === 'completed') {
            return 100;
        }
        $stage = (string) $task['stage'];
        $base = ['keywords' => 0, 'serp' => 10, 'classify' => 35, 'discover' => 55, 'extract' => 75, 'phone' => 90][$stage] ?? 0;
        $span = ['keywords' => 10, 'serp' => 25, 'classify' => 20, 'discover' => 20, 'extract' => 15, 'phone' => 9][$stage] ?? 0;
        $ratio = 0.0;
        if ($stage === 'keywords') {
            $ratio = $counts['keywords_total'] > 0 ? 0.7 : 0.0;
        } elseif ($stage === 'serp') {
            if ((int) ($task['pages_target'] ?? 1) === 0) {
                // 自动翻页：每个关键词的总页数事先未知，按已搜完的关键词比例估算。
                $ratio = $counts['keywords_total'] > 0 ? $counts['keywords_done'] / $counts['keywords_total'] : 0.0;
            } else {
                $ratio = $counts['serp_pages_target'] > 0 ? $counts['serp_pages_done'] / $counts['serp_pages_target'] : 0.0;
            }
        } elseif ($stage === 'classify') {
            $ratio = $counts['websites_total'] > 0 ? $counts['websites_classified'] / $counts['websites_total'] : 0.0;
        } elseif ($stage === 'discover') {
            $ratio = $counts['websites_approved'] > 0 ? $counts['pages_done'] / $counts['websites_approved'] : 0.0;
        } elseif ($stage === 'extract') {
            $ratio = $counts['extract_ready'] > 0 ? $counts['companies_extracted'] / $counts['extract_ready'] : 0.0;
        } elseif ($stage === 'phone') {
            $ratio = $counts['companies'] > 0 ? $counts['phones_done'] / $counts['companies'] : 0.0;
        }
        $ratio = max(0.0, min(1.0, $ratio));
        return (int) max(0, min(99, round($base + $span * $ratio)));
    }

    /** @param array<string, int> $counts */
    public function refresh(array $task, array $counts): int
    {
        $progress = $this->progress($task, $counts);
        if ($progress !== (int) $task['progress']) {
            Db::instance()->update('tasks', ['progress' => $progress], ['id' => (int) $task['id']]);
            $task['progress'] = $progress;
        }
        return $progress;
    }

    public function setStage(int $id, string $stage, string $message = ''): void
    {
        $data = ['stage' => $stage];
        if ($stage === 'done') {
            $data['status'] = 'completed';
            $data['progress'] = 100;
            $data['finished_at'] = now();
        } else {
            $data['finished_at'] = null;
        }
        Db::instance()->update('tasks', $data + ['updated_at' => now()], ['id' => $id]);
        if ($message !== '') {
            $this->log($id, $stage, 'info', $message);
        }
    }

    public function setStatus(int $id, string $status, string $stage = 'system', string $message = ''): void
    {
        Db::instance()->update('tasks', [
            'status' => $status,
            'updated_at' => now(),
        ], ['id' => $id]);
        if ($message !== '') {
            $this->log($id, $stage, $status === 'failed' ? 'error' : 'info', $message);
        }
    }

    public function setError(int $id, ?string $message): void
    {
        Db::instance()->update('tasks', [
            'error_message' => $message === null ? null : Str::limit($message, 950, ''),
            'updated_at' => now(),
        ], ['id' => $id]);
    }

    /** 任务是否已 100% 完成（可清理抓取缓存）。 */
    public function isFullyCompleted(array $task): bool
    {
        return (string) ($task['status'] ?? '') === 'completed'
            || ((string) ($task['stage'] ?? '') === 'done' && (int) ($task['progress'] ?? 0) >= 100);
    }

    /**
     * 统计该任务仍保留的抓取缓存条数（非完整 HTML；为提取中间结果 / 页面 URL 列表）。
     * @return array{companies:int,websites:int,total:int}
     */
    public function crawlCacheStats(int $taskId): array
    {
        $db = Db::instance();
        $prefix = $db->prefix();
        $companies = (int) ($db->value(
            "SELECT COUNT(*) FROM `{$prefix}companies`
             WHERE `task_id` = ?
               AND `raw_json` IS NOT NULL
               AND TRIM(`raw_json`) NOT IN ('', 'null', '{}')",
            [$taskId]
        ) ?? 0);
        $websites = (int) ($db->value(
            "SELECT COUNT(*) FROM `{$prefix}websites`
             WHERE `task_id` = ?
               AND `pages_json` IS NOT NULL
               AND TRIM(`pages_json`) NOT IN ('', 'null', '[]')",
            [$taskId]
        ) ?? 0);

        return [
            'companies' => $companies,
            'websites' => $websites,
            'total' => $companies + $websites,
        ];
    }

    /**
     * 清理已完成任务的抓取缓存：companies.raw_json、websites.pages_json。
     * 不删除企业线索与网站记录本身。
     * @return array{companies:int,websites:int,total:int}
     */
    public function clearCrawlCache(int $taskId): array
    {
        $task = $this->find($taskId);
        if (!$this->isFullyCompleted($task)) {
            throw HttpException::validation('仅 100% 完成的任务可清理抓取缓存数据');
        }

        $before = $this->crawlCacheStats($taskId);
        if ($before['total'] === 0) {
            return $before;
        }

        $db = Db::instance();
        $prefix = $db->prefix();
        $now = now();
        $db->query(
            "UPDATE `{$prefix}companies`
             SET `raw_json` = NULL, `updated_at` = ?
             WHERE `task_id` = ?
               AND `raw_json` IS NOT NULL
               AND TRIM(`raw_json`) NOT IN ('', 'null', '{}')",
            [$now, $taskId]
        );
        $db->query(
            "UPDATE `{$prefix}websites`
             SET `pages_json` = NULL, `updated_at` = ?
             WHERE `task_id` = ?
               AND `pages_json` IS NOT NULL
               AND TRIM(`pages_json`) NOT IN ('', 'null', '[]')",
            [$now, $taskId]
        );

        $this->log($taskId, 'system', 'info', '已清理抓取缓存数据（提取中间结果 / 页面列表）', $before);

        return $before;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(int $taskId, string $stage, string $level, string $message, array $context = []): void
    {
        try {
            Db::instance()->insert('task_logs', [
                'task_id' => $taskId,
                'stage' => Str::limit($stage, 20, ''),
                'level' => in_array($level, ['info', 'success', 'warning', 'error'], true) ? $level : 'info',
                'message' => Str::limit($message, 950, ''),
                'context_json' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Unable to write task log', ['task_id' => $taskId, 'error' => $e->getMessage()]);
        }
        Logger::log($level === 'error' ? 'error' : 'info', '[task ' . $taskId . '] ' . $message, $context);
    }

    /** @return array<int, array<string, mixed>> */
    public function logs(int $taskId, int $limit = 80): array
    {
        $rows = Db::instance()->table('task_logs')->where('task_id', $taskId)->latest('id')->limit($limit)->get();
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['task_id'] = (int) $row['task_id'];
            $context = json_decode((string) ($row['context_json'] ?? 'null'), true);
            $row['context'] = is_array($context) ? $context : [];
            return $row;
        }, $rows);
    }
}
