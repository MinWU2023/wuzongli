<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\HttpException;
use App\Services\SerperAccountService;
use App\Services\SerperService;

final class SerperAccountsController extends AdminController
{
    public function index(): void
    {
        $service = new SerperAccountService();
        $this->renderAdmin('serper/index', [
            'accounts' => $service->all(),
            'current' => $service->current(),
        ], 'Serper 账号', 'serper');
    }

    public function create(): void
    {
        $this->renderAdmin('serper/form', [
            'account' => [
                'id' => null,
                'name' => '',
                'account' => '',
                'endpoint' => '',
                'developer_info' => '',
                'notes' => '',
                'priority' => 10,
                'is_enabled' => 1,
                'is_current' => 0,
            ],
            'isEdit' => false,
        ], '添加 Serper 账号', 'serper');
    }

    public function store(): never
    {
        $this->guardCsrf();
        try {
            (new SerperAccountService())->create([
                'name' => $this->request->string('name'),
                'account' => $this->request->string('account'),
                'password' => (string) $this->request->input('password', ''),
                'api_key' => $this->request->string('api_key'),
                'endpoint' => $this->request->string('endpoint'),
                'developer_info' => $this->request->string('developer_info'),
                'notes' => $this->request->string('notes'),
                'priority' => $this->request->int('priority', 10),
                'is_enabled' => $this->request->input('is_enabled') ? 1 : 0,
                'is_current' => $this->request->input('is_current') ? 1 : 0,
            ]);
            flash('success', 'Serper 账号已添加。');
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
            $this->go('/admin/serper/create');
        }
        $this->go('/admin/serper');
    }

    public function edit(string $id): void
    {
        $account = (new SerperAccountService())->find((int) $id);
        $this->renderAdmin('serper/form', [
            'account' => $account,
            'isEdit' => true,
        ], '编辑 Serper 账号', 'serper');
    }

    public function update(string $id): never
    {
        $this->guardCsrf();
        try {
            (new SerperAccountService())->update((int) $id, [
                'name' => $this->request->string('name'),
                'account' => $this->request->string('account'),
                'password' => (string) $this->request->input('password', ''),
                'api_key' => $this->request->string('api_key'),
                'endpoint' => $this->request->string('endpoint'),
                'developer_info' => $this->request->string('developer_info'),
                'notes' => $this->request->string('notes'),
                'priority' => $this->request->int('priority', 10),
                'is_enabled' => $this->request->input('is_enabled') ? 1 : 0,
                'is_current' => $this->request->input('is_current') ? 1 : 0,
            ]);
            flash('success', 'Serper 账号已更新。');
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
            $this->go('/admin/serper/' . (int) $id . '/edit');
        }
        $this->go('/admin/serper');
    }

    public function delete(string $id): never
    {
        $this->guardCsrf();
        try {
            (new SerperAccountService())->delete((int) $id);
            flash('success', 'Serper 账号已删除。');
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
        }
        $this->go('/admin/serper');
    }

    public function activate(string $id): never
    {
        $this->guardCsrf();
        try {
            (new SerperAccountService())->activate((int) $id);
            flash('success', '已切换为当前启用账号。');
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
        }
        $this->go('/admin/serper');
    }

    public function test(string $id): never
    {
        $this->guardCsrf();
        try {
            $account = (new SerperAccountService())->find((int) $id);
            $result = (new SerperService())->search(
                'machine tools',
                1,
                'en',
                (string) $account['api_key']
            );
            $credits = $result['credits'];
            (new SerperAccountService())->markSuccess((int) $id, $credits);
            flash('success', '测试成功，返回 ' . count($result['results']) . ' 条' . ($credits !== null ? '，余额 ' . $credits : '') . '。');
        } catch (HttpException $e) {
            flash('error', '测试失败：' . $e->getMessage());
        }
        $this->go('/admin/serper');
    }
}
