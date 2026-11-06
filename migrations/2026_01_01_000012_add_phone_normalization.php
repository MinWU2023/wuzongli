<?php
declare(strict_types=1);

use App\Core\Migration;

/**
 * 电话标准化：companies 增加标准化字段，并写入 phone_normalization 提示词模板。
 * 可重复执行：字段、索引、提示词都先判断是否已存在。
 */
return new class extends Migration {
    public function up(): void
    {
        $table = $this->table('companies');
        $columns = [
            'phone_raw' => 'VARCHAR(1000) NULL',
            'phone_type' => 'VARCHAR(20) NULL',
            'phone_country' => 'VARCHAR(8) NULL',
            'phone_list_json' => 'JSON NULL',
            'phone_status' => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
            'phone_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
            'phone_note' => 'VARCHAR(255) NULL',
            'phone_normalized_at' => 'DATETIME NULL',
        ];
        foreach ($columns as $name => $definition) {
            $exists = (int) ($this->db->value(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$this->db->tableName('companies'), $name]
            ) ?? 0);
            if ($exists === 0) {
                $this->db->query("ALTER TABLE {$table} ADD COLUMN `{$name}` {$definition}");
            }
        }

        try {
            $indexExists = (int) ($this->db->value(
                'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$this->db->tableName('companies'), 'idx_companies_phone_status']
            ) ?? 0);
            if ($indexExists === 0) {
                $this->db->query("ALTER TABLE {$table} ADD INDEX `idx_companies_phone_status` (`task_id`, `phone_status`)");
            }
        } catch (\Throwable) {
            // 索引仅用于加速。
        }

        $file = BASE_PATH . '/app/Data/phone_prompt.php';
        $prompt = is_file($file) ? require $file : null;
        if (is_array($prompt)) {
            $this->db->insertIgnore('prompts', [
                'code' => $prompt['code'],
                'name' => $prompt['name'],
                'description' => $prompt['description'],
                'variables_json' => json_encode($prompt['variables'], JSON_UNESCAPED_UNICODE),
                'system_prompt' => $prompt['system_prompt'],
                'user_prompt' => $prompt['user_prompt'],
                'is_active' => 1,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ]);
        }
    }

    public function down(): void
    {
        $this->db->delete('prompts', ['code' => 'phone_normalization']);
        foreach (['phone_normalized_at', 'phone_note', 'phone_attempts', 'phone_status', 'phone_list_json', 'phone_country', 'phone_type', 'phone_raw'] as $name) {
            try {
                $this->db->query("ALTER TABLE {$this->table('companies')} DROP COLUMN `{$name}`");
            } catch (\Throwable) {
            }
        }
    }
};
