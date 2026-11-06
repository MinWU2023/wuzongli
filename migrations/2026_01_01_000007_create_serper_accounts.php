<?php
declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('serper_accounts')} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(120) NOT NULL,
            `account` VARCHAR(190) NULL COMMENT '登录账号/邮箱',
            `password_enc` MEDIUMTEXT NULL COMMENT '登录密码密文',
            `api_key_enc` MEDIUMTEXT NOT NULL COMMENT 'Serper API Key 密文',
            `endpoint` VARCHAR(500) NULL COMMENT '可选自定义接口',
            `developer_info` TEXT NULL COMMENT '开发者后台信息备注',
            `notes` TEXT NULL COMMENT '其他备注',
            `credits` INT NULL COMMENT '最近一次余额',
            `priority` INT NOT NULL DEFAULT 10,
            `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否参与轮换',
            `is_current` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '当前启用中的账号',
            `status` VARCHAR(32) NOT NULL DEFAULT 'ready' COMMENT 'ready/exhausted/error/disabled',
            `last_used_at` DATETIME NULL,
            `last_error` VARCHAR(1000) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_serper_rotate` (`is_enabled`, `is_current`, `status`, `priority`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // 把旧 settings 里的单 Key 迁入账号表（table() 已含反引号，勿再包一层）
        $settingsTable = $this->table('settings');
        $accountsTable = $this->table('serper_accounts');
        $raw = '';
        try {
            $raw = (string) ($this->db->value(
                "SELECT `setting_value` FROM {$settingsTable} WHERE `setting_key` = ? LIMIT 1",
                ['serper_api_key']
            ) ?? '');
        } catch (\Throwable) {
            $raw = '';
        }

        $plain = '';
        if ($raw !== '') {
            if (str_starts_with($raw, 'enc:v1:')) {
                try {
                    $plain = trim(Crypto::decrypt($raw));
                } catch (\Throwable) {
                    $plain = '';
                }
            } else {
                $plain = trim($raw);
            }
        }

        if ($plain !== '') {
            $exists = (int) ($this->db->value("SELECT COUNT(*) FROM {$accountsTable}") ?? 0);
            if ($exists === 0) {
                $this->db->insert('serper_accounts', [
                    'name' => '默认 Serper 账号',
                    'account' => null,
                    'password_enc' => null,
                    'api_key_enc' => Crypto::encrypt($plain),
                    'endpoint' => null,
                    'developer_info' => '由系统设置 serper_api_key 自动迁移',
                    'notes' => null,
                    'credits' => null,
                    'priority' => 10,
                    'is_enabled' => 1,
                    'is_current' => 1,
                    'status' => 'ready',
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$this->table('serper_accounts')}");
    }
};
