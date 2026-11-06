<?php
declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(): void
    {
        // 跨任务按域名查“已采集的合格域名”，加索引加速。
        try {
            $exists = $this->db->value(
                'SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
                [$this->db->tableName('websites'), 'idx_websites_domain']
            );
            if ((int) $exists === 0) {
                $this->db->query("ALTER TABLE {$this->table('websites')} ADD INDEX `idx_websites_domain` (`domain`, `classify_status`)");
            }
        } catch (\Throwable) {
            // 索引只是加速，失败不影响功能。
        }
    }

    public function down(): void
    {
        try {
            $this->db->query("ALTER TABLE {$this->table('websites')} DROP INDEX `idx_websites_domain`");
        } catch (\Throwable) {
        }
    }
};
