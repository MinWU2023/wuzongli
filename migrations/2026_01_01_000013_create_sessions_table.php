<?php
declare(strict_types=1);

use App\Core\Migration;

/**
 * Store PHP sessions in MySQL so login works on free hosts where file sessions
 * are purged, and so multiple devices can stay logged in independently.
 */
return new class extends Migration {
    public function up(): void
    {
        $this->db->query("CREATE TABLE IF NOT EXISTS {$this->table('sessions')} (
            `id` VARCHAR(128) NOT NULL,
            `payload` MEDIUMTEXT NOT NULL,
            `last_activity` INT UNSIGNED NOT NULL,
            `admin_id` BIGINT UNSIGNED NULL,
            `ip` VARCHAR(45) NULL,
            `user_agent` VARCHAR(255) NULL,
            PRIMARY KEY (`id`),
            KEY `idx_sessions_last_activity` (`last_activity`),
            KEY `idx_sessions_admin` (`admin_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        $this->db->query("DROP TABLE IF EXISTS {$this->table('sessions')}");
    }
};
