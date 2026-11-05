<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Db;
use App\Core\HttpException;

final class PromptsController extends AdminController
{
    public function index(): void
    {
        $prompts = Db::instance()->table('prompts')->orderBy('id')->get();
        foreach ($prompts as &$prompt) {
            $variables = json_decode((string) ($prompt['variables_json'] ?? '[]'), true);
            $prompt['variables'] = is_array($variables) ? $variables : [];
        }
        unset($prompt);
        $this->renderAdmin('prompts/index', ['prompts' => $prompts], '提示词模板', 'prompts');
    }

    public function edit(string $id): void
    {
        $prompt = Db::instance()->table('prompts')->where('id', (int) $id)->first();
        if ($prompt === null) {
            throw HttpException::notFound('提示词不存在');
        }
        $variables = json_decode((string) ($prompt['variables_json'] ?? '[]'), true);
        $prompt['variables'] = is_array($variables) ? $variables : [];
        $this->renderAdmin('prompts/edit', ['prompt' => $prompt], '编辑提示词', 'prompts');
    }

    public function update(string $id): never
    {
        $this->guardCsrf();
        $promptId = (int) $id;
        $prompt = Db::instance()->table('prompts')->where('id', $promptId)->first();
        if ($prompt === null) {
            throw HttpException::notFound('提示词不存在');
        }
        $system = trim((string) $this->request->input('system_prompt', ''));
        $user = trim((string) $this->request->input('user_prompt', ''));
        if ($system === '' || $user === '') {
            flash('error', '系统提示词和用户提示词都不能为空。');
            $this->back();
        }
        Db::instance()->update('prompts', [
            'system_prompt' => $system,
            'user_prompt' => $user,
            'is_active' => $this->request->boolean('is_active') ? 1 : 0,
            'updated_at' => now(),
        ], ['id' => $promptId]);
        flash('success', '提示词已保存。');
        $this->go('/admin/prompts');
    }
}
