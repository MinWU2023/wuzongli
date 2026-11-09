<?php
declare(strict_types=1);

use App\Core\Migration;

/**
 * 企业联系电话备注：每个企业 + 号码 + 日期一条备注记录。
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('company_phone_notes')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `company_id` BIGINT UNSIGNED NOT NULL,
            `phone` VARCHAR(80) NOT NULL,
            `note` VARCHAR(1000) NOT NULL DEFAULT '',
            `note_date` DATE NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_company_phone_date` (`company_id`, `phone`, `note_date`),
            KEY `idx_notes_date` (`note_date`, `id`),
            KEY `idx_notes_phone` (`phone`),
            KEY `idx_notes_company` (`company_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$this->table('company_phone_notes')}");
    }
};
