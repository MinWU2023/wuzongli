<?php
declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('ai_relays')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(100) NOT NULL,
            `base_url` VARCHAR(500) NOT NULL,
            `api_key` MEDIUMTEXT NOT NULL,
            `model` VARCHAR(120) NOT NULL DEFAULT 'gpt-4o-mini',
            `temperature` DECIMAL(3,2) NOT NULL DEFAULT 0.20,
            `max_tokens` INT UNSIGNED NOT NULL DEFAULT 4096,
            `timeout` INT UNSIGNED NOT NULL DEFAULT 120,
            `priority` INT NOT NULL DEFAULT 10,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `last_used_at` DATETIME NULL,
            `last_error` VARCHAR(1000) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_relays_active` (`is_active`, `priority`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('prompts')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(64) NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `description` VARCHAR(500) NULL,
            `system_prompt` MEDIUMTEXT NOT NULL,
            `user_prompt` MEDIUMTEXT NOT NULL,
            `variables_json` JSON NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_prompt_code` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        foreach (['prompts', 'ai_relays'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS {$this->table($table)}");
        }
    }
};
