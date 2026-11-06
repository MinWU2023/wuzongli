<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\HttpException;
use App\Services\CategoryService;

final class CategoriesController extends AdminController
{
    public function index(): void
    {
        $ready = true;
        $groups = [];
        try {
            $groups = CategoryService::adminTree();
        } catch (\Throwable) {
            $ready = false;
        }
        $this->renderAdmin('categories/index', [
            'groups' => $groups,
            'ready' => $ready,
            'max' => CategoryService::MAX_SELECTED,
        ], '产品分类', 'categories');
    }

    public function saveGroup(): never
    {
        $this->run(fn () => CategoryService::saveGroup([
            'id' => $this->request->int('id', 0),
            'name_zh' => $this->request->string('name_zh'),
            'name_en' => $this->request->string('name_en'),
            'sort_order' => (string) $this->request->input('sort_order', ''),
            'is_active' => $this->request->input('is_active') ? 1 : 0,
        ]));
    }

    public function deleteGroup(string $id): never
    {
        $this->run(fn () => CategoryService::deleteGroup((int) $id));
    }

    public function saveItem(): never
    {
        $this->run(fn () => CategoryService::saveItem([
            'id' => $this->request->int('id', 0),
            'group_id' => $this->request->int('group_id', 0),
            'name_zh' => $this->request->string('name_zh'),
            'name_en' => $this->request->string('name_en'),
            'sort_order' => (string) $this->request->input('sort_order', ''),
            'is_active' => $this->request->input('is_active') ? 1 : 0,
        ]));
    }

    public function bulkItems(): never
    {
        $this->run(fn () => CategoryService::bulkItems(
            $this->request->int('group_id', 0),
            (string) $this->request->input('lines', '')
        ));
    }

    public function deleteItem(string $id): never
    {
        $this->run(fn () => CategoryService::deleteItem((int) $id));
    }

    /** @param callable():string $action */
    private function run(callable $action): never
    {
        $this->guardCsrf();
        try {
            flash('success', $action());
        } catch (HttpException $e) {
            flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            flash('error', '操作失败：' . $e->getMessage());
        }
        $this->go('/admin/categories');
    }
}
