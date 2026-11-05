<?php
declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('tasks')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `city` VARCHAR(100) NOT NULL,
            `country` VARCHAR(50) NOT NULL DEFAULT '中国',
            `target_market` VARCHAR(100) NOT NULL DEFAULT '全球',
            `language` VARCHAR(20) NOT NULL DEFAULT 'en',
            `stage` VARCHAR(20) NOT NULL DEFAULT 'keywords',
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `options_json` JSON NULL,
            `error_message` VARCHAR(1000) NULL,
            `started_at` DATETIME NULL,
            `finished_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_tasks_status` (`status`, `stage`),
            KEY `idx_tasks_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('keywords')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `task_id` BIGINT UNSIGNED NOT NULL,
            `keyword` VARCHAR(255) NOT NULL,
            `intent` VARCHAR(30) NOT NULL DEFAULT 'official',
            `language` VARCHAR(10) NOT NULL DEFAULT 'en',
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `pages_target` TINYINT UNSIGNED NOT NULL DEFAULT 3,
            `pages_done` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `results_count` INT UNSIGNED NOT NULL DEFAULT 0,
            `last_error` VARCHAR(500) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_task_keyword` (`task_id`, `keyword`),
            KEY `idx_keywords_task_status` (`task_id`, `status`, `pages_done`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('search_results')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `task_id` BIGINT UNSIGNED NOT NULL,
            `keyword_id` BIGINT UNSIGNED NOT NULL,
            `keyword` VARCHAR(255) NOT NULL,
            `page` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `position` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `title` VARCHAR(500) NULL,
            `url` VARCHAR(1000) NOT NULL,
            `url_hash` CHAR(40) NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `snippet` TEXT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_result_page_url` (`task_id`, `keyword_id`, `page`, `url_hash`),
            KEY `idx_results_domain` (`domain`),
            KEY `idx_results_task` (`task_id`, `keyword_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('websites')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `task_id` BIGINT UNSIGNED NOT NULL,
            `domain` VARCHAR(255) NOT NULL,
            `root_url` VARCHAR(500) NOT NULL,
            `sample_url` VARCHAR(1000) NULL,
            `title` VARCHAR(500) NULL,
            `snippet` TEXT NULL,
            `source_keyword_id` BIGINT UNSIGNED NULL,
            `classify_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `is_chinese_enterprise` TINYINT(1) NULL,
            `ai_confidence` DECIMAL(4,3) NULL,
            `ai_reason` VARCHAR(500) NULL,
            `page_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `home_url` VARCHAR(1000) NULL,
            `about_url` VARCHAR(1000) NULL,
            `contact_url` VARCHAR(1000) NULL,
            `pages_json` JSON NULL,
            `extract_status` VARCHAR(20) NOT NULL DEFAULT 'pending',
            `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `last_error` VARCHAR(1000) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_task_domain` (`task_id`, `domain`),
            KEY `idx_websites_classify` (`task_id`, `classify_status`),
            KEY `idx_websites_pages` (`task_id`, `classify_status`, `page_status`),
            KEY `idx_websites_extract` (`task_id`, `classify_status`, `extract_status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('companies')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `task_id` BIGINT UNSIGNED NOT NULL,
            `website_id` BIGINT UNSIGNED NOT NULL,
            `company_name` VARCHAR(255) NULL,
            `domain` VARCHAR(255) NOT NULL,
            `country` VARCHAR(100) NULL,
            `city` VARCHAR(100) NULL,
            `phone` VARCHAR(255) NULL,
            `phone_alt` VARCHAR(500) NULL,
            `email` VARCHAR(255) NULL,
            `linkedin` VARCHAR(1000) NULL,
            `tiktok` VARCHAR(1000) NULL,
            `youtube` VARCHAR(1000) NULL,
            `facebook` VARCHAR(1000) NULL,
            `instagram` VARCHAR(1000) NULL,
            `twitter` VARCHAR(1000) NULL,
            `whatsapp` VARCHAR(255) NULL,
            `address` VARCHAR(500) NULL,
            `source_url` VARCHAR(1000) NULL,
            `raw_json` JSON NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_task_website_company` (`task_id`, `website_id`),
            KEY `idx_companies_task` (`task_id`),
            KEY `idx_companies_domain` (`domain`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        foreach (['companies', 'websites', 'search_results', 'keywords', 'tasks'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS {$this->table($table)}");
        }
    }
};
