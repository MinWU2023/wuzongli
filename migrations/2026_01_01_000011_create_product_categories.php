<?php
declare(strict_types=1);

use App\Core\Migration;
use App\Services\CategoryService;

/**
 * 产品分类库入库：大类表 + 细分类表，并把 app/Data/product_categories.php 的内容一次性导入。
 * 细分类 code 与此前文件生成的 code 完全一致，已创建任务保存的分类不受影响。
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('product_category_groups')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(64) NOT NULL,
            `name_zh` VARCHAR(100) NOT NULL,
            `name_en` VARCHAR(150) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_cat_group_code` (`code`),
            KEY `idx_cat_group_sort` (`is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('product_categories')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `group_id` BIGINT UNSIGNED NOT NULL,
            `code` VARCHAR(120) NOT NULL,
            `name_zh` VARCHAR(100) NOT NULL,
            `name_en` VARCHAR(150) NOT NULL,
            `sort_order` INT NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_cat_item_code` (`code`),
            KEY `idx_cat_item_group` (`group_id`, `is_active`, `sort_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $existing = (int) ($this->db->value("SELECT COUNT(*) FROM {$this->table('product_category_groups')}") ?? 0);
        if ($existing > 0) {
            return;
        }

        $now = $this->now();
        foreach (CategoryService::fileTree() as $gi => $group) {
            $groupId = (int) $this->db->insert('product_category_groups', [
                'code' => $group['code'],
                'name_zh' => $group['zh'],
                'name_en' => $group['en'],
                'sort_order' => ($gi + 1) * 10,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($group['items'] as $ii => $item) {
                $this->db->insertIgnore('product_categories', [
                    'group_id' => $groupId,
                    'code' => $item['code'],
                    'name_zh' => $item['zh'],
                    'name_en' => $item['en'],
                    'sort_order' => ($ii + 1) * 10,
                    'is_active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$this->table('product_categories')}");
        $this->db->query("DROP TABLE IF EXISTS {$this->table('product_category_groups')}");
    }
};
