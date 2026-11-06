<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Str;

/**
 * 出海外贸产品分类库：读取 app/Data/product_categories.php，
 * 校验任务所选分类，并把所选分类整理成给 AI 的描述。
 */
final class CategoryService
{
    /** 单个任务最多可选的细分类数量，避免关键词被稀释。 */
    public const MAX_SELECTED = 12;

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $tree = null;

    /** @var array<string, array{code:string,zh:string,en:string,group_code:string,group_zh:string,group_en:string}>|null */
    private static ?array $flat = null;

    /**
     * 完整分类树：大类 → 细分类（含稳定 code）。
     *
     * @return array<int, array{code:string,zh:string,en:string,items:array<int, array{code:string,zh:string,en:string}>}>
     */
    public static function tree(): array
    {
        if (self::$tree !== null) {
            return self::$tree;
        }
        // 优先读数据库（后台可编辑）；表不存在或为空时回退到 app/Data/product_categories.php。
        $fromDb = self::treeFromDb();
        if ($fromDb !== null) {
            return self::$tree = $fromDb;
        }
        return self::$tree = self::fileTree();
    }

    /**
     * 文件里的原始分类库（迁移导入和数据库未就绪时的回退数据源）。
     *
     * @return array<int, array{code:string,zh:string,en:string,items:array<int, array{code:string,zh:string,en:string}>}>
     */
    public static function fileTree(): array
    {
        $file = BASE_PATH . '/app/Data/product_categories.php';
        $raw = is_file($file) ? require $file : [];
        $tree = [];
        $used = [];
        foreach (is_array($raw) ? $raw : [] as $group) {
            if (!is_array($group) || empty($group['code'])) {
                continue;
            }
            $items = [];
            foreach ((array) ($group['items'] ?? []) as $item) {
                if (!is_array($item) || count($item) < 2) {
                    continue;
                }
                $base = (string) $group['code'] . '.' . self::slug((string) $item[1]);
                $code = $base;
                $n = 2;
                while (isset($used[$code])) {
                    $code = $base . '_' . $n++;
                }
                $used[$code] = true;
                $items[] = ['code' => $code, 'zh' => (string) $item[0], 'en' => (string) $item[1]];
            }
            $tree[] = [
                'code' => (string) $group['code'],
                'zh' => (string) ($group['zh'] ?? $group['code']),
                'en' => (string) ($group['en'] ?? $group['code']),
                'items' => $items,
            ];
        }
        return $tree;
    }

    /** 清除内存缓存（后台改动分类后调用）。 */
    public static function reset(): void
    {
        self::$tree = null;
        self::$flat = null;
    }

    /** @return array<int, array<string, mixed>>|null 数据库未就绪或没有任何大类时返回 null。 */
    private static function treeFromDb(): ?array
    {
        try {
            $db = Db::instance();
            $groups = $db->fetchAll('SELECT `id`, `code`, `name_zh`, `name_en`, `is_active` FROM `' . $db->tableName('product_category_groups') . '` ORDER BY `sort_order`, `id`');
            $items = $db->fetchAll('SELECT `group_id`, `code`, `name_zh`, `name_en` FROM `' . $db->tableName('product_categories') . '` WHERE `is_active` = 1 ORDER BY `sort_order`, `id`');
        } catch (\Throwable) {
            return null;
        }
        if ($groups === []) {
            return null;
        }
        $by = [];
        foreach ($items as $row) {
            $by[(int) $row['group_id']][] = ['code' => (string) $row['code'], 'zh' => (string) $row['name_zh'], 'en' => (string) $row['name_en']];
        }
        $tree = [];
        foreach ($groups as $group) {
            $list = $by[(int) $group['id']] ?? [];
            if ((int) $group['is_active'] !== 1 || $list === []) {
                continue;
            }
            $tree[] = ['code' => (string) $group['code'], 'zh' => (string) $group['name_zh'], 'en' => (string) $group['name_en'], 'items' => $list];
        }
        return $tree;
    }

    /**
     * 后台用的完整列表（含停用项和 id）。数据表不存在时抛异常。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminTree(): array
    {
        $db = Db::instance();
        $groups = $db->fetchAll('SELECT * FROM `' . $db->tableName('product_category_groups') . '` ORDER BY `sort_order`, `id`');
        $items = $db->fetchAll('SELECT * FROM `' . $db->tableName('product_categories') . '` ORDER BY `sort_order`, `id`');
        $by = [];
        foreach ($items as $row) {
            $by[(int) $row['group_id']][] = [
                'id' => (int) $row['id'],
                'group_id' => (int) $row['group_id'],
                'code' => (string) $row['code'],
                'zh' => (string) $row['name_zh'],
                'en' => (string) $row['name_en'],
                'sort' => (int) $row['sort_order'],
                'active' => (int) $row['is_active'],
            ];
        }
        $out = [];
        foreach ($groups as $group) {
            $out[] = [
                'id' => (int) $group['id'],
                'code' => (string) $group['code'],
                'zh' => (string) $group['name_zh'],
                'en' => (string) $group['name_en'],
                'sort' => (int) $group['sort_order'],
                'active' => (int) $group['is_active'],
                'items' => $by[(int) $group['id']] ?? [],
            ];
        }
        return $out;
    }

    /** @param array<string, mixed> $in */
    public static function saveGroup(array $in): string
    {
        $id = (int) ($in['id'] ?? 0);
        [$zh, $en] = self::names($in);
        $active = !empty($in['is_active']) ? 1 : 0;
        $db = Db::instance();
        if ($id > 0) {
            self::mustExist('product_category_groups', $id, '大类不存在');
            $db->update('product_category_groups', [
                'name_zh' => $zh,
                'name_en' => $en,
                'sort_order' => (int) ($in['sort_order'] ?? 0),
                'is_active' => $active,
                'updated_at' => now(),
            ], ['id' => $id]);
            self::reset();
            return '大类已更新。';
        }
        $sort = ($in['sort_order'] ?? '') !== '' ? (int) $in['sort_order'] : self::nextSort('product_category_groups');
        $db->insert('product_category_groups', [
            'code' => self::uniqueCode('product_category_groups', self::slug($en)),
            'name_zh' => $zh,
            'name_en' => $en,
            'sort_order' => $sort,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::reset();
        return '大类已添加。';
    }

    /** @param array<string, mixed> $in */
    public static function saveItem(array $in): string
    {
        $id = (int) ($in['id'] ?? 0);
        $groupId = (int) ($in['group_id'] ?? 0);
        [$zh, $en] = self::names($in);
        $active = !empty($in['is_active']) ? 1 : 0;
        $db = Db::instance();
        $groupCode = self::groupCode($groupId);
        if ($id > 0) {
            self::mustExist('product_categories', $id, '细分类不存在');
            // code 创建后保持不变，已有任务保存的分类不受改名影响。
            $db->update('product_categories', [
                'group_id' => $groupId,
                'name_zh' => $zh,
                'name_en' => $en,
                'sort_order' => (int) ($in['sort_order'] ?? 0),
                'is_active' => $active,
                'updated_at' => now(),
            ], ['id' => $id]);
            self::reset();
            return '细分类已更新。';
        }
        $sort = ($in['sort_order'] ?? '') !== '' ? (int) $in['sort_order'] : self::nextSort('product_categories', 'group_id', $groupId);
        $db->insert('product_categories', [
            'group_id' => $groupId,
            'code' => self::uniqueCode('product_categories', $groupCode . '.' . self::slug($en)),
            'name_zh' => $zh,
            'name_en' => $en,
            'sort_order' => $sort,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::reset();
        return '细分类已添加。';
    }

    /** 批量添加：每行「中文名 | English name」。 */
    public static function bulkItems(int $groupId, string $text): string
    {
        $groupCode = self::groupCode($groupId);
        $db = Db::instance();
        $table = $db->tableName('product_categories');
        $sort = self::nextSort('product_categories', 'group_id', $groupId);
        $added = 0;
        $skipped = 0;
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s*[|｜\t]\s*/u', $line, 2) ?: [];
            $zh = trim((string) ($parts[0] ?? ''));
            $en = trim((string) ($parts[1] ?? ''));
            if ($zh === '' || $en === '' || mb_strlen($zh) > 100 || mb_strlen($en) > 150) {
                $skipped++;
                continue;
            }
            $dup = (int) ($db->value("SELECT COUNT(*) FROM `{$table}` WHERE `group_id` = ? AND (`name_zh` = ? OR `name_en` = ?)", [$groupId, $zh, $en]) ?? 0);
            if ($dup > 0) {
                $skipped++;
                continue;
            }
            $db->insert('product_categories', [
                'group_id' => $groupId,
                'code' => self::uniqueCode('product_categories', $groupCode . '.' . self::slug($en)),
                'name_zh' => $zh,
                'name_en' => $en,
                'sort_order' => $sort,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sort += 10;
            $added++;
        }
        self::reset();
        if ($added === 0) {
            throw HttpException::validation('没有可添加的内容，请按「中文名 | English name」每行一条填写（重复项会被跳过）。');
        }
        return sprintf('已批量添加 %d 个细分类，跳过 %d 行（格式不对或已存在）。', $added, $skipped);
    }

    public static function deleteGroup(int $id): string
    {
        self::mustExist('product_category_groups', $id, '大类不存在');
        $db = Db::instance();
        $db->delete('product_categories', ['group_id' => $id]);
        $db->delete('product_category_groups', ['id' => $id]);
        self::reset();
        return '大类及其细分类已删除。';
    }

    public static function deleteItem(int $id): string
    {
        self::mustExist('product_categories', $id, '细分类不存在');
        Db::instance()->delete('product_categories', ['id' => $id]);
        self::reset();
        return '细分类已删除。';
    }

    /** @param array<string, mixed> $in @return array{0:string,1:string} */
    private static function names(array $in): array
    {
        $zh = trim((string) ($in['name_zh'] ?? ''));
        $en = trim((string) ($in['name_en'] ?? ''));
        if ($zh === '' || $en === '') {
            throw HttpException::validation('请同时填写中文名和英文名。');
        }
        if (mb_strlen($zh) > 100 || mb_strlen($en) > 150) {
            throw HttpException::validation('名称过长（中文最多 100 字，英文最多 150 字符）。');
        }
        return [$zh, $en];
    }

    private static function mustExist(string $table, int $id, string $message): void
    {
        $db = Db::instance();
        $count = (int) ($db->value('SELECT COUNT(*) FROM `' . $db->tableName($table) . '` WHERE `id` = ?', [$id]) ?? 0);
        if ($count === 0) {
            throw HttpException::notFound($message);
        }
    }

    private static function groupCode(int $groupId): string
    {
        $db = Db::instance();
        $code = $db->value('SELECT `code` FROM `' . $db->tableName('product_category_groups') . '` WHERE `id` = ?', [$groupId]);
        if (!is_string($code) || $code === '') {
            throw HttpException::validation('请选择所属大类。');
        }
        return $code;
    }

    private static function nextSort(string $table, ?string $column = null, int $value = 0): int
    {
        $db = Db::instance();
        $sql = 'SELECT COALESCE(MAX(`sort_order`), 0) + 10 FROM `' . $db->tableName($table) . '`';
        $params = [];
        if ($column !== null) {
            $sql .= ' WHERE `' . $column . '` = ?';
            $params[] = $value;
        }
        return (int) ($db->value($sql, $params) ?? 10);
    }

    private static function uniqueCode(string $table, string $base): string
    {
        $db = Db::instance();
        $name = $db->tableName($table);
        $code = $base;
        $n = 2;
        while ((int) ($db->value("SELECT COUNT(*) FROM `{$name}` WHERE `code` = ?", [$code]) ?? 0) > 0) {
            $code = $base . '_' . $n++;
        }
        return $code;
    }

    /** @return array<string, array{code:string,zh:string,en:string,group_code:string,group_zh:string,group_en:string}> */
    private static function flat(): array
    {
        if (self::$flat !== null) {
            return self::$flat;
        }
        $flat = [];
        foreach (self::tree() as $group) {
            foreach ($group['items'] as $item) {
                $flat[$item['code']] = $item + [
                    'group_code' => $group['code'],
                    'group_zh' => $group['zh'],
                    'group_en' => $group['en'],
                ];
            }
        }
        return self::$flat = $flat;
    }

    /** 统计：大类数、细分类数。 */
    public static function stats(): array
    {
        return ['groups' => count(self::tree()), 'items' => count(self::flat())];
    }

    /**
     * 清洗前端传来的所选分类：只保留库里存在的 code，去重，限制数量。
     * 传入大类 code（如 furniture）时展开为该大类下的所有细分类。
     *
     * @param mixed $input
     * @return array<int, string>
     */
    public static function sanitize(mixed $input): array
    {
        if (is_string($input)) {
            $input = preg_split('/[\s,;]+/', $input) ?: [];
        }
        if (!is_array($input)) {
            return [];
        }
        $flat = self::flat();
        $groups = [];
        foreach (self::tree() as $group) {
            $groups[$group['code']] = array_column($group['items'], 'code');
        }
        $out = [];
        foreach ($input as $code) {
            $code = trim((string) $code);
            if ($code === '') {
                continue;
            }
            if (isset($flat[$code])) {
                $out[$code] = true;
            } elseif (isset($groups[$code])) {
                foreach ($groups[$code] as $child) {
                    $out[$child] = true;
                }
            }
            if (count($out) >= self::MAX_SELECTED) {
                break;
            }
        }
        return array_slice(array_keys($out), 0, self::MAX_SELECTED);
    }

    /**
     * 所选分类的展示信息（用于接口返回、后台显示）。
     *
     * @param array<int, string> $codes
     * @return array<int, array{code:string,zh:string,en:string,group_zh:string,group_en:string}>
     */
    public static function describe(array $codes): array
    {
        $flat = self::flat();
        $out = [];
        foreach ($codes as $code) {
            if (isset($flat[$code])) {
                $row = $flat[$code];
                $out[] = [
                    'code' => $row['code'],
                    'zh' => $row['zh'],
                    'en' => $row['en'],
                    'group_zh' => $row['group_zh'],
                    'group_en' => $row['group_en'],
                ];
            }
        }
        return $out;
    }

    /** 简短文字，如“家具 / 沙发、办公家具”，用于任务标题和日志。 */
    public static function label(array $codes, int $max = 4): string
    {
        $rows = self::describe($codes);
        if ($rows === []) {
            return '';
        }
        $names = array_map(static fn (array $r): string => $r['zh'], array_slice($rows, 0, $max));
        $text = implode('、', $names);
        if (count($rows) > $max) {
            $text .= ' 等 ' . count($rows) . ' 类';
        }
        return $text;
    }

    /**
     * 给 AI 的分类清单（每行：英文名 | 中文名 | 所属大类）。
     *
     * @param array<int, string> $codes
     */
    public static function promptList(array $codes): string
    {
        $lines = [];
        foreach (self::describe($codes) as $i => $row) {
            $lines[] = sprintf('%d. %s | %s | %s', $i + 1, $row['en'], $row['zh'], $row['group_en']);
        }
        return implode("\n", $lines);
    }

    private static function slug(string $text): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $text), '_'));
        return Str::limit($slug !== '' ? $slug : 'item', 60, '');
    }
}
