<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\HttpException;

final class RelaysController extends AdminController
{
    public function index(): void
    {
        $relays = Db::instance()->table('ai_relays')->orderBy('priority')->orderBy('id')->get();
        foreach ($relays as &$relay) {
            $relay['masked_key'] = Crypto::mask(Crypto::decrypt((string) $relay['api_key']));
        }
        unset($relay);
        $this->renderAdmin('relays/index', ['relays' => $relays], 'AI 中转站', 'relays');
    }

    public function create(): void
    {
        $this->renderAdmin('relays/form', [
            'relay' => [
                'id' => null,
                'name' => '主 AI 中转站',
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o-mini',
                'temperature' => '0.20',
                'max_tokens' => 4096,
                'timeout' => 120,
                'priority' => 10,
                'is_active' => 1,
            ],
            'isEdit' => false,
        ], '添加 AI 中转站', 'relays');
    }

    public function store(): never
    {
        $this->guardCsrf();
        $data = $this->validated();
        $key = trim((string) $this->request->input('api_key', ''));
        if ($key === '') {
            flash('error', '新增中转站必须填写 API Key。');
            $this->go('/admin/relays/create');
        }
        $data['api_key'] = Crypto::encrypt($key);
        $data['created_at'] = now();
        $data['updated_at'] = now();
        $db = Db::instance();
        $id = (int) $db->insert('ai_relays', $data);
        if (!empty($data['is_active']) && $this->request->boolean('exclusive')) {
            $this->deactivateOthers($id);
        }
        flash('success', 'AI 中转站已添加。');
        $this->go('/admin/relays');
    }

    public function edit(string $id): void
    {
        $relay = Db::instance()->table('ai_relays')->where('id', (int) $id)->first();
        if ($relay === null) {
            throw HttpException::notFound('中转站不存在');
        }
        $this->renderAdmin('relays/form', ['relay' => $relay, 'isEdit' => true], '编辑 AI 中转站', 'relays');
    }

    public function update(string $id): never
    {
        $this->guardCsrf();
        $relayId = (int) $id;
        $existing = Db::instance()->table('ai_relays')->where('id', $relayId)->first();
        if ($existing === null) {
            throw HttpException::notFound('中转站不存在');
        }
        $data = $this->validated();
        $key = trim((string) $this->request->input('api_key', ''));
        if ($key !== '') {
            $data['api_key'] = Crypto::encrypt($key);
        }
        $data['updated_at'] = now();
        Db::instance()->update('ai_relays', $data, ['id' => $relayId]);
        if (!empty($data['is_active']) && $this->request->boolean('exclusive')) {
            $this->deactivateOthers($relayId);
        }
        flash('success', 'AI 中转站已更新。');
        $this->go('/admin/relays');
    }

    private function deactivateOthers(int $keepId): void
    {
        $db = Db::instance();
        $db->query(
            'UPDATE `' . $db->tableName('ai_relays') . '` SET `is_active` = 0, `updated_at` = ? WHERE `id` <> ?',
            [now(), $keepId]
        );
    }

    public function delete(string $id): never
    {
        $this->guardCsrf();
        $relayId = (int) $id;
        Db::instance()->delete('ai_relays', ['id' => $relayId]);
        flash('success', '中转站已删除。');
        $this->go('/admin/relays');
    }

    /** @return array<string, mixed> */
    private function validated(): array
    {
        $name = trim($this->request->string('name'));
        $baseUrl = rtrim(trim($this->request->string('base_url')), '/');
        $model = trim($this->request->string('model'));
        if ($name === '' || mb_strlen($name) > 100) {
            flash('error', '请填写中转站名称（100 字以内）。');
            $this->back();
        }
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $baseUrl)) {
            flash('error', '接口地址格式不正确。');
            $this->back();
        }
        if ($model === '' || mb_strlen($model) > 120) {
            flash('error', '请填写模型名称。');
            $this->back();
        }
        $temperature = (float) str_replace(',', '.', $this->request->string('temperature', '0.2'));
        $maxTokens = $this->request->int('max_tokens', 4096);
        $timeout = $this->request->int('timeout', 120);
        $priority = $this->request->int('priority', 10);
        return [
            'name' => $name,
            'base_url' => $baseUrl,
            'model' => $model,
            'temperature' => max(0.0, min(2.0, $temperature)),
            'max_tokens' => max(256, min(16000, $maxTokens)),
            'timeout' => max(30, min(300, $timeout)),
            'priority' => max(0, min(999, $priority)),
            'is_active' => $this->request->boolean('is_active') ? 1 : 0,
        ];
    }
}

