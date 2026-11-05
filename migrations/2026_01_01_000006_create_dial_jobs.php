<?php
declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('dial_jobs')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` BIGINT UNSIGNED NULL,
            `task_id` BIGINT UNSIGNED NULL,
            `phone` VARCHAR(64) NOT NULL,
            `company_name` VARCHAR(255) NULL,
            `city` VARCHAR(100) NULL,
            `domain` VARCHAR(255) NULL,
            `email` VARCHAR(255) NULL,
            `address` VARCHAR(500) NULL,
            `source_url` VARCHAR(1000) NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `claimed_by` VARCHAR(100) NULL,
            `claimed_at` DATETIME NULL,
            `completed_at` DATETIME NULL,
            `note` VARCHAR(500) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_dial_status_id` (`status`, `id`),
            KEY `idx_dial_company` (`company_id`),
            KEY `idx_dial_phone` (`phone`),
            KEY `idx_dial_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$this->table('dial_jobs')}");
    }
};
