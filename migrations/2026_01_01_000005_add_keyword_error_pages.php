<?php
declare(strict_types=1);

use App\Core\Migration;

return new class extends Migration {
    public function up(): void
    {
        $this->db->query("ALTER TABLE {$this->table('keywords')}
            ADD COLUMN `error_pages` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `attempts`");
    }

    public function down(): void
    {
        $this->db->query("ALTER TABLE {$this->table('keywords')} DROP COLUMN `error_pages`");
    }
};
