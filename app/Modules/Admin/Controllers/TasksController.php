<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Response;
use App\Services\DialService;
use App\Services\PipelineService;
use App\Services\TaskService;

final class TasksController extends AdminController
{
    public function index(): void
    {
        $db = Db::instance();
        $page = max(1, (int) $this->request->query('page', 1));
        $perPage = 20;
        $status = (string) $this->request->query('status', '');
        $search = trim((string) $this->request->query('q', ''));
        $where = [];
        $bindings = [];
        if (in_array($status, ['active', 'paused', 'completed', 'failed'], true)) {
            $where[] = 't.`status` = ?';
            $bindings[] = $status;
        }
        if ($search !== '') {
            $where[] = 't.`city` LIKE ?';
            $bindings[] = '%' . $search . '%';
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $prefix = $db->prefix();
        $total = (int) ($db->value('SELECT COUNT(*) FROM `' . $prefix . 'tasks` t' . $whereSql, $bindings) ?? 0);
        $offset = ($page - 1) * $perPage;
        $rows = $db->fetchAll(
            'SELECT t.*,
                (SELECT COUNT(*) FROM `' . $prefix . 'keywords` k WHERE k.task_id = t.id) AS keywords_count,
                (SELECT COUNT(*) FROM `' . $prefix . 'websites` w WHERE w.task_id = t.id) AS websites_count,
                (SELECT COUNT(*) FROM `' . $prefix . 'websites` w WHERE w.task_id = t.id AND w.classify_status = \'approved\') AS approved_count,
                (SELECT COUNT(*) FROM `' . $prefix . 'companies` c WHERE c.task_id = t.id) AS companies_count,
                (
                    (SELECT COUNT(*) FROM `' . $prefix . 'companies` c
                     WHERE c.task_id = t.id AND c.raw_json IS NOT NULL
                       AND TRIM(c.raw_json) NOT IN (\'\', \'null\', \'{}\'))
                    +
                    (SELECT COUNT(*) FROM `' . $prefix . 'websites` w
                     WHERE w.task_id = t.id AND w.pages_json IS NOT NULL
                       AND TRIM(w.pages_json) NOT IN (\'\', \'null\', \'[]\'))
                ) AS crawl_cache_count
             FROM `' . $prefix . 'tasks` t' . $whereSql . '
             ORDER BY t.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings
        );
        $taskService = new TaskService();
        $rows = array_map(static function (array $row) use ($taskService): array {
            $row = $taskService->decorate($row);
            $row['crawl_cache_count'] = (int) ($row['crawl_cache_count'] ?? 0);
            $row['can_clear_crawl_cache'] = $taskService->isFullyCompleted($row);
            return $row;
        }, $rows);

        $this->renderAdmin('tasks/index', [
            'tasks' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'status' => $status,
            'search' => $search,
        ], '城市任务', 'tasks');
    }

    public function show(string $id): void
    {
        $taskId = (int) $id;
        $taskService = new TaskService();
        $task = $taskService->find($taskId);
        $counts = $taskService->counts($taskId);
        $db = Db::instance();
        $keywords = $db->table('keywords')->where('task_id', $taskId)->orderBy('id')->limit(300)->get();
        $websites = $db->fetchAll(
            'SELECT * FROM `' . $db->tableName('websites') . '` WHERE `task_id` = ?
             ORDER BY FIELD(`classify_status`, \'approved\', \'pending\', \'error\', \'rejected\'), `id` ASC LIMIT 300',
            [$taskId]
        );
        $companies = $db->table('companies')->where('task_id', $taskId)->orderBy('id')->limit(300)->get();
        $logs = $taskService->logs($taskId, 80);
        $progress = $taskService->progress($task, $counts);
        $crawlCache = $taskService->crawlCacheStats($taskId);

        $this->renderAdmin('tasks/show', [
            'task' => $task,
            'counts' => $counts,
            'progress' => $progress,
            'keywords' => $keywords,
            'websites' => $websites,
            'companies' => $companies,
            'logs' => $logs,
            'crawlCache' => $crawlCache,
            'canClearCrawlCache' => $taskService->isFullyCompleted($task),
        ], $task['city'] . ' · 任务详情', 'tasks');
    }

    public function action(string $id): never
    {
        $this->guardCsrf();
        $taskId = (int) $id;
        $action = $this->request->string('action');
        $tasks = new TaskService();
        $tasks->find($taskId);
        $ai = new \App\Services\AiService();
        $pipeline = new PipelineService($tasks, $ai, new \App\Services\WebsiteService($ai));
        try {
            if ($action === 'pause') {
                $pipeline->pause($taskId);
                flash('success', '任务已暂停。');
            } elseif ($action === 'resume') {
                $pipeline->resume($taskId);
                flash('success', '任务已继续，插件下一轮轮询会恢复执行。');
            } elseif ($action === 'retry') {
                $pipeline->retry($taskId);
                flash('success', '已重置异常项，插件下一轮轮询会重试。');
            } elseif ($action === 'clear_crawl_cache') {
                $cleared = $tasks->clearCrawlCache($taskId);
                if ($cleared['total'] === 0) {
                    flash('success', '该任务已无抓取缓存数据可清理。');
                } else {
                    flash(
                        'success',
                        '已清理抓取缓存：企业中间结果 ' . $cleared['companies'] . ' 条，网站页面列表 ' . $cleared['websites'] . ' 条。线索数据保留。'
                    );
                }
            } else {
                flash('error', '未知操作。');
            }
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
        }
        $this->go('/admin/tasks/' . $taskId);
    }

    public function enqueueDial(string $id): never
    {
        try {
            $this->guardCsrf();
            $taskId = (int) $id;
            (new TaskService())->find($taskId);
            $result = (new DialService())->enqueue([
                'phone' => $this->request->string('phone'),
                'company_id' => $this->request->int('company_id'),
                'task_id' => $taskId,
                'company_name' => $this->request->string('company_name'),
                'city' => $this->request->string('city'),
                'domain' => $this->request->string('domain'),
                'email' => $this->request->string('email'),
                'address' => $this->request->string('address'),
                'source_url' => $this->request->string('source_url'),
            ]);
            $this->json([
                'ok' => true,
                'duplicated' => $result['duplicated'],
                'job' => $result['job'],
                'message' => $result['duplicated'] ? '该号码已在拨号队列中' : '已推送到手机拨号队列',
            ], $result['duplicated'] ? 200 : 201);
        } catch (HttpException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], $e->status);
        }
    }

    public function export(string $id): never
    {
        $taskId = (int) $id;
        $task = (new TaskService())->find($taskId);
        $rows = Db::instance()->table('companies')->where('task_id', $taskId)->orderBy('id')->get();
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create CSV stream');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['公司名', '域名', '城市', '电话', '备用电话', '邮箱', 'LinkedIn', 'TikTok', 'YouTube', 'Facebook', 'Instagram', 'WhatsApp', '地址', '来源页'], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($stream, [
                csv_safe($row['company_name'] ?? ''),
                $row['domain'] ?? '',
                csv_safe($row['city'] ?? ''),
                $row['phone'] ?? '',
                csv_safe($row['phone_alt'] ?? ''),
                csv_safe($row['email'] ?? ''),
                $row['linkedin'] ?? '',
                $row['tiktok'] ?? '',
                $row['youtube'] ?? '',
                $row['facebook'] ?? '',
                $row['instagram'] ?? '',
                $row['whatsapp'] ?? '',
                csv_safe($row['address'] ?? ''),
                $row['source_url'] ?? '',
            ], ',', '"', '\\');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);
        Response::download('companies-' . $taskId . '-' . date('Ymd-His') . '.csv', $csv);
    }
}
