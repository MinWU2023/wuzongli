<?php
declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('admins')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(60) NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `display_name` VARCHAR(80) NOT NULL DEFAULT '管理员',
            `last_login_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_admin_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('settings')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `setting_key` VARCHAR(80) NOT NULL,
            `setting_value` TEXT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('task_logs')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `task_id` BIGINT UNSIGNED NOT NULL,
            `stage` VARCHAR(20) NOT NULL DEFAULT 'system',
            `level` VARCHAR(20) NOT NULL DEFAULT 'info',
            `message` VARCHAR(1000) NOT NULL,
            `context_json` JSON NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_task_logs_task` (`task_id`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        foreach (['task_logs', 'settings', 'admins'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS {$this->table($table)}");
        }
    }
};
