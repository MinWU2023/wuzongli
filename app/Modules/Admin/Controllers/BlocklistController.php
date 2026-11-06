<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Db;
use App\Core\Url;
use App\Services\BlocklistService;

final class BlocklistController extends AdminController
{
    public function index(): void
    {
        $db = Db::instance();
        $table = $db->tableName('domain_blocklist');
        $page = max(1, (int) $this->request->query('page', 1));
        $perPage = 50;
        $search = trim((string) $this->request->query('q', ''));
        $category = (string) $this->request->query('category', '');
        $source = (string) $this->request->query('source', '');

        $where = [];
        $bindings = [];
        if ($search !== '') {
            $where[] = '(`domain` LIKE ? OR `reason` LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $bindings[] = $like;
            $bindings[] = $like;
        }
        if (isset(BlocklistService::CATEGORIES[$category])) {
            $where[] = '`category` = ?';
            $bindings[] = $category;
        } else {
            $category = '';
        }
        if (in_array($source, ['ai', 'rule', 'manual'], true)) {
            $where[] = '`source` = ?';
            $bindings[] = $source;
        } else {
            $source = '';
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        $rows = [];
        $total = 0;
        $tableReady = true;
        try {
            $total = (int) ($db->value("SELECT COUNT(*) FROM `{$table}`{$whereSql}", $bindings) ?? 0);
            $offset = ($page - 1) * $perPage;
            $rows = $db->fetchAll(
                "SELECT * FROM `{$table}`{$whereSql} ORDER BY `id` DESC LIMIT {$perPage} OFFSET {$offset}",
                $bindings
            );
        } catch (\Throwable) {
            $tableReady = false;
        }

        $this->renderAdmin('blocklist/index', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'search' => $search,
            'category' => $category,
            'source' => $source,
            'categories' => BlocklistService::CATEGORIES,
            'tableReady' => $tableReady,
        ], '禁止采集名单', 'blocklist');
    }

    public function store(): never
    {
        $this->guardCsrf();
        $raw = (string) $this->request->input('domains', '');
        $category = BlocklistService::normalizeCategory($this->request->string('category', 'other'));
        $reason = trim($this->request->string('reason', '')) ?: '人工加入';
        $added = 0;
        $skipped = 0;
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $domain = Url::normalizeDomain($item);
            if ($domain !== null && BlocklistService::add($domain, $category, $reason, 'manual')) {
                $added++;
            } else {
                $skipped++;
            }
        }
        flash($added > 0 ? 'success' : 'error', sprintf('已加入 %d 个域名，跳过 %d 个（无效或已存在）。', $added, $skipped));
        $this->go('/admin/blocklist');
    }

    public function delete(string $id): never
    {
        $this->guardCsrf();
        Db::instance()->delete('domain_blocklist', ['id' => (int) $id]);
        flash('success', '已从禁止采集名单移除，之后采集到该域名会重新入库。');
        $this->go('/admin/blocklist');
    }
}
