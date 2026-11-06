<?php
declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('domain_blocklist')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `domain` VARCHAR(190) NOT NULL COMMENT '禁止采集的域名（已去 www，小写）',
            `category` VARCHAR(40) NOT NULL DEFAULT 'other' COMMENT 'b2b_platform/marketplace/directory/social/media/gov_org/recruitment/foreign_local/non_enterprise/platform/other',
            `reason` VARCHAR(500) NULL COMMENT '加入原因（AI 判断依据或人工备注）',
            `source` VARCHAR(20) NOT NULL DEFAULT 'ai' COMMENT 'ai/rule/manual',
            `confidence` DECIMAL(4,3) NULL COMMENT 'AI 置信度',
            `task_id` BIGINT UNSIGNED NULL COMMENT '首次判定来源任务',
            `hit_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '之后被拦截的次数',
            `last_hit_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_blocklist_domain` (`domain`),
            KEY `idx_blocklist_source` (`source`, `category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 把已有任务里被判为“排除”的域名一次性导入名单（city_mismatch 之类无法区分，仅导入 AI 理由里明确的平台/目录类）。
        $websites = $this->table('websites');
        $blocklist = $this->table('domain_blocklist');
        try {
            $this->db->query(
                "INSERT IGNORE INTO {$blocklist} (`domain`, `category`, `reason`, `source`, `confidence`, `created_at`, `updated_at`)
                 SELECT `domain`, 'platform', '平台、搜索引擎、目录或社交媒体，非企业独立站', 'rule', NULL, NOW(), NOW()
                 FROM {$websites}
                 WHERE `classify_status` = 'rejected' AND `ai_reason` LIKE '%平台、搜索引擎、目录或社交媒体%'
                 GROUP BY `domain`"
            );
        } catch (\Throwable) {
            // 导入失败不影响建表。
        }
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$this->table('domain_blocklist')}");
    }
};
