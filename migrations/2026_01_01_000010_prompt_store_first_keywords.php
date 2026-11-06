<?php
declare(strict_types=1);

use App\Core\Migration;

/**
 * 把「独立商城站优先」规则追加进数据库里的 keyword_generation 提示词模板。
 * 已包含该规则的不重复追加；不会覆盖后台里手动改过的其他内容。
 */
return new class extends Migration {
    public function up(): void
    {
        $file = BASE_PATH . '/app/Data/keyword_store_rules.php';
        $rules = is_file($file) ? require $file : '';
        if (!is_string($rules) || $rules === '') {
            return;
        }
        $prompts = $this->table('prompts');
        $row = $this->db->fetch("SELECT `id`, `user_prompt` FROM {$prompts} WHERE `code` = ? LIMIT 1", ['keyword_generation']);
        if ($row === null) {
            return;
        }
        $current = (string) $row['user_prompt'];
        if (str_contains($current, '独立商城站优先策略')) {
            return;
        }
        $this->db->query(
            "UPDATE {$prompts} SET `user_prompt` = ?, `updated_at` = ? WHERE `id` = ?",
            [rtrim($current) . "\n\n" . $rules, $this->now(), (int) $row['id']]
        );
    }

    public function down(): void
    {
    }
};
