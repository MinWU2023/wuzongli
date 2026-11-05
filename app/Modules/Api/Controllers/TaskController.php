<?php
declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Response;
use App\Core\Str;
use App\Services\AiService;
use App\Services\PipelineService;
use App\Services\SerpParser;
use App\Services\Settings;
use App\Services\TaskService;
use App\Services\WebsiteService;

final class TaskController extends ApiController
{
    private TaskService $tasks;
    private PipelineService $pipeline;

    public function __construct(\App\Core\Request $request)
    {
        parent::__construct($request);
        $this->tasks = new TaskService();
        $ai = new AiService();
        $this->pipeline = new PipelineService($this->tasks, $ai, new WebsiteService($ai));
    }

    public function index(): never
    {
        $status = $this->request->query('status');
        $query = Db::instance()->table('tasks');
        if (is_string($status) && in_array($status, ['active', 'paused', 'completed', 'failed'], true)) {
            $query->where('status', $status);
        }
        $rows = $query->latest('id')->limit(100)->get();
        $this->ok(['tasks' => array_map(fn (array $row): array => $this->tasks->decorate($row), $rows)]);
    }

    public function store(): never
    {
        $task = $this->tasks->create([
            'city' => $this->request->string('city'),
            'target_market' => $this->request->string('target_market', '全球'),
            'keyword_count' => $this->request->int('keyword_count', 0) ?: null,
            'pages_target' => $this->request->int('pages_target', 0) ?: null,
            'language' => $this->request->string('language', 'en'),
        ]);
        $this->ok([
            'task' => $task,
            'counts' => $this->tasks->counts((int) $task['id']),
            'action' => 'generate_keywords',
        ], 201);
    }

    public function show(string $id): never
    {
        $taskId = $this->taskId($id);
        $task = $this->tasks->find($taskId);
        $this->ok([
            'task' => $task,
            'counts' => $this->tasks->counts($taskId),
        ]);
    }

    public function next(string $id): never
    {
        $this->ok($this->pipeline->next($this->taskId($id)));
    }

    public function generateKeywords(string $id): never
    {
        $this->ok($this->pipeline->generateKeywords($this->taskId($id)));
    }

    public function keywords(string $id): never
    {
        $taskId = $this->taskId($id);
        $this->tasks->find($taskId);
        $limit = max(1, min(500, (int) $this->request->query('limit', 200)));
        $rows = Db::instance()->table('keywords')->where('task_id', $taskId)->orderBy('id')->limit($limit)->get();
        $this->ok(['keywords' => $rows]);
    }

    public function websites(string $id): never
    {
        $taskId = $this->taskId($id);
        $this->tasks->find($taskId);
        $limit = max(1, min(2000, (int) $this->request->query('limit', 1000)));
        $status = (string) $this->request->query('status', '');
        $query = Db::instance()->table('websites')->where('task_id', $taskId);
        if (in_array($status, ['approved', 'rejected', 'pending', 'error'], true)) {
            $query->where('classify_status', $status);
        }
        $rows = $query->orderBy('id')->limit($limit)->get();
        $this->ok(['websites' => $rows, 'total' => count($rows)]);
    }

    public function serp(string $id): never
    {
        $taskId = $this->taskId($id);
        $keywordId = $this->request->int('keyword_id');
        $page = $this->request->int('page', 1);
        if ($keywordId <= 0) {
            throw HttpException::validation('缺少 keyword_id');
        }
        $results = $this->request->input('results', []);
        if (!is_array($results)) {
            $results = [];
        }
        $html = $this->request->string('html');
        $blocked = $this->request->boolean('blocked', false);
        $error = $this->request->string('error');
        if ($html !== '' && $results === []) {
            $results = SerpParser::parse($html);
            $blocked = $blocked || SerpParser::looksBlocked($html);
        }
        if ($results === [] && $error === '' && !$blocked) {
            $error = '插件未返回搜索结果';
        }
        $provider = strtolower(trim($this->request->string('provider', 'google')));
        $source = $provider === 'serper' ? 'serper' : 'google';
        $data = $this->pipeline->handleSerp($taskId, $keywordId, $page, $results, $error !== '' ? $error : null, $blocked, $source);
        if ($source === 'serper') {
            $data['provider'] = 'serper';
        }
        $this->ok($data);
    }

    public function serper(string $id): never
    {
        $taskId = $this->taskId($id);
        $keywordId = $this->request->int('keyword_id');
        $page = $this->request->int('page', 1);
        if ($keywordId <= 0) {
            throw HttpException::validation('缺少 keyword_id');
        }
        $overrideKey = trim($this->request->string('serper_api_key'));
        if ($overrideKey !== '') {
            \App\Services\Settings::set('serper_api_key', \App\Core\Crypto::encrypt($overrideKey));
        }
        $this->ok($this->pipeline->searchSerper($taskId, $keywordId, $page, $overrideKey !== '' ? $overrideKey : null));
    }

    public function settings(): never
    {
        $serperKey = \App\Services\SerperService::apiKey();
        $relay = Db::instance()->table('ai_relays')->orderBy('priority')->orderBy('id')->first();
        $aiMasked = '';
        if ($relay !== null) {
            $aiPlain = \App\Core\Crypto::decrypt((string) ($relay['api_key'] ?? ''));
            $aiMasked = \App\Core\Crypto::mask($aiPlain);
        }
        $this->ok([
            'search_provider' => (string) \App\Services\Settings::get('search_provider', 'auto'),
            'serper_configured' => $serperKey !== '',
            'serper_masked' => \App\Core\Crypto::mask($serperKey),
            'serper_gl' => (string) \App\Services\Settings::get('serper_gl', 'us'),
            'serper_hl' => (string) \App\Services\Settings::get('serper_hl', 'en'),
            'ai_relay' => $relay === null ? null : [
                'id' => (int) $relay['id'],
                'name' => (string) $relay['name'],
                'base_url' => (string) $relay['base_url'],
                'model' => (string) $relay['model'],
                'masked_key' => $aiMasked,
                'has_key' => $aiMasked !== '',
            ],
        ]);
    }

    public function updateSettings(): never
    {
        $serperKey = trim($this->request->string('serper_api_key'));
        $provider = strtolower(trim($this->request->string('search_provider', '')));
        $updates = [];
        if ($serperKey !== '') {
            $updates['serper_api_key'] = \App\Core\Crypto::encrypt($serperKey);
        }
        if (in_array($provider, ['auto', 'serper', 'browser'], true)) {
            $updates['search_provider'] = $provider;
        }
        $gl = strtolower(trim($this->request->string('serper_gl', '')));
        if (preg_match('/^[a-z]{2}$/', $gl)) {
            $updates['serper_gl'] = $gl;
        }
        $hl = strtolower(trim($this->request->string('serper_hl', '')));
        if (preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $hl)) {
            $updates['serper_hl'] = $hl;
        }
        if ($updates !== []) {
            \App\Services\Settings::setMany($updates);
        }

        $aiBaseUrl = rtrim(trim($this->request->string('ai_base_url')), '/');
        $aiApiKey = trim($this->request->string('ai_api_key'));
        $aiModel = trim($this->request->string('ai_model'));
        if ($aiBaseUrl !== '' || $aiApiKey !== '' || $aiModel !== '') {
            if ($aiBaseUrl !== '' && (!filter_var($aiBaseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $aiBaseUrl))) {
                throw HttpException::validation('AI 中转站地址格式不正确，必须以 http:// 或 https:// 开头');
            }
            $db = Db::instance();
            $relay = $db->table('ai_relays')->orderBy('priority')->orderBy('id')->first();
            if ($relay === null) {
                if ($aiBaseUrl === '' || $aiApiKey === '') {
                    throw HttpException::validation('首次添加 AI 中转站请同时填写中转站地址与 API Key');
                }
                $db->insert('ai_relays', [
                    'name' => '插件配置 AI 中转站',
                    'base_url' => $aiBaseUrl,
                    'api_key' => \App\Core\Crypto::encrypt($aiApiKey),
                    'model' => $aiModel !== '' ? $aiModel : 'gpt-4o-mini',
                    'temperature' => 0.20,
                    'max_tokens' => 4096,
                    'timeout' => 120,
                    'priority' => 10,
                    'is_active' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $relayUpdate = [
                    'is_active' => 1,
                    'last_error' => null,
                    'updated_at' => now(),
                ];
                if ($aiBaseUrl !== '') {
                    $relayUpdate['base_url'] = $aiBaseUrl;
                }
                if ($aiApiKey !== '') {
                    $relayUpdate['api_key'] = \App\Core\Crypto::encrypt($aiApiKey);
                }
                if ($aiModel !== '') {
                    $relayUpdate['model'] = $aiModel;
                }
                $db->update('ai_relays', $relayUpdate, ['id' => (int) $relay['id']]);
            }
        }

        $effectiveKey = $serperKey !== '' ? $serperKey : \App\Services\SerperService::apiKey();
        $latestRelay = Db::instance()->table('ai_relays')->orderBy('priority')->orderBy('id')->first();
        $aiMasked = '';
        if ($latestRelay !== null) {
            $aiPlain = \App\Core\Crypto::decrypt((string) ($latestRelay['api_key'] ?? ''));
            $aiMasked = \App\Core\Crypto::mask($aiPlain);
        }
        $this->ok([
            'saved' => true,
            'serper_configured' => $effectiveKey !== '',
            'serper_masked' => \App\Core\Crypto::mask($effectiveKey),
            'search_provider' => (string) \App\Services\Settings::get('search_provider', 'auto'),
            'ai_relay' => $latestRelay === null ? null : [
                'id' => (int) $latestRelay['id'],
                'name' => (string) $latestRelay['name'],
                'base_url' => (string) $latestRelay['base_url'],
                'model' => (string) $latestRelay['model'],
                'masked_key' => $aiMasked,
                'has_key' => $aiMasked !== '',
            ],
        ]);
    }

    public function classify(string $id): never
    {
        $this->ok($this->pipeline->classifyBatch($this->taskId($id)));
    }

    public function pages(string $id, string $websiteId): never
    {
        $taskId = $this->taskId($id);
        $wid = (int) $websiteId;
        if ($wid <= 0) {
            throw HttpException::notFound('网站不存在');
        }
        $html = $this->request->string('html');
        if ($html === '') {
            throw HttpException::validation('缺少首页 HTML');
        }
        $url = $this->request->string('url');
        $this->ok($this->pipeline->crawlWebsite($taskId, $wid, $html, $url));
    }

    public function extract(string $id, string $websiteId): never
    {
        $taskId = $this->taskId($id);
        $wid = (int) $websiteId;
        $pages = $this->request->input('pages', []);
        if (!is_array($pages)) {
            throw HttpException::validation('pages 必须是数组');
        }
        $this->ok($this->pipeline->extractWebsite($taskId, $wid, $pages));
    }

    public function update(string $id): never
    {
        $taskId = $this->taskId($id);
        $payload = [];
        if ($this->request->input('city') !== null) {
            $payload['city'] = $this->request->string('city');
        }
        if ($this->request->input('target_market') !== null) {
            $payload['target_market'] = $this->request->string('target_market');
        }
        $keywordCount = $this->request->int('keyword_count', 0);
        if ($keywordCount > 0) {
            $payload['keyword_count'] = $keywordCount;
        }
        $pagesTarget = $this->request->int('pages_target', 0);
        if ($pagesTarget > 0) {
            $payload['pages_target'] = $pagesTarget;
        }
        $task = $this->tasks->update($taskId, $payload);
        $this->ok([
            'task' => $task,
            'counts' => $this->tasks->counts($taskId),
        ]);
    }

    public function stop(string $id): never
    {
        $task = $this->pipeline->pause($this->taskId($id));
        $this->ok(['task' => $task, 'stopped' => true]);
    }

    public function pause(string $id): never
    {
        $this->ok(['task' => $this->pipeline->pause($this->taskId($id))]);
    }

    public function resume(string $id): never
    {
        $this->ok(['task' => $this->pipeline->resume($this->taskId($id))]);
    }

    public function runnerStatus(): never
    {
        $enabled = Settings::bool('extension_runner_enabled', true);
        $active = (int) Db::instance()->table('tasks')->where('status', 'active')->count();
        $paused = (int) Db::instance()->table('tasks')->where('status', 'paused')->count();
        $this->ok([
            'enabled' => $enabled,
            'active_tasks' => $active,
            'paused_tasks' => $paused,
        ]);
    }

    public function runnerUpdate(): never
    {
        $enabled = $this->request->boolean('enabled', true);
        Settings::set('extension_runner_enabled', $enabled ? '1' : '0');
        $this->ok([
            'enabled' => $enabled,
            'message' => $enabled ? '插件将排队执行 active 任务' : '插件将即时停止自动执行',
        ]);
    }

    public function retry(string $id): never
    {
        $taskId = $this->taskId($id);
        $this->ok([
            'task' => $this->pipeline->retry($taskId),
            'counts' => $this->tasks->counts($taskId),
        ]);
    }

    public function rewind(string $id): never
    {
        $taskId = $this->taskId($id);
        $stage = strtolower(trim($this->request->string('stage')));
        if ($stage === '') {
            throw HttpException::validation('请选择要回退到的阶段');
        }
        $task = $this->pipeline->rewind($taskId, $stage, [
            'city' => $this->request->input('city'),
            'target_market' => $this->request->input('target_market'),
            'keyword_count' => $this->request->int('keyword_count', 0),
            'pages_target' => $this->request->int('pages_target', 0),
        ]);
        $this->ok([
            'task' => $task,
            'counts' => $this->tasks->counts($taskId),
        ]);
    }

    public function companies(string $id): never
    {
        $taskId = $this->taskId($id);
        $this->tasks->find($taskId);
        $limit = max(1, min(2000, (int) $this->request->query('limit', 1000)));
        $query = Db::instance()->table('companies')->where('task_id', $taskId);
        $hasContact = $this->request->query('has_contact');
        if ($hasContact === '1') {
            $query->whereRaw('(`phone` IS NOT NULL OR `email` IS NOT NULL OR `linkedin` IS NOT NULL OR `whatsapp` IS NOT NULL)');
        }
        $rows = $query->orderBy('id')->limit($limit)->get();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['task_id'] = (int) $row['task_id'];
            $row['website_id'] = (int) $row['website_id'];
        }
        unset($row);
        $this->ok(['companies' => $rows, 'total' => count($rows)]);
    }

    public function logs(string $id): never
    {
        $taskId = $this->taskId($id);
        $this->tasks->find($taskId);
        $limit = max(1, min(200, (int) $this->request->query('limit', 80)));
        $this->ok(['logs' => $this->tasks->logs($taskId, $limit)]);
    }

    public function export(string $id): never
    {
        $taskId = $this->taskId($id);
        $task = $this->tasks->find($taskId);
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
        $filename = 'companies-' . $taskId . '-' . date('Ymd-His') . '.csv';
        Response::download($filename, $csv, 'text/csv; charset=utf-8');
    }
}
