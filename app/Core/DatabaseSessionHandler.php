<?php
declare(strict_types=1);

namespace App\Core;

/**
 * MySQL-backed session handler.
 *
 * Each browser/device keeps its own row (id = session cookie), so concurrent
 * logins are independent. File sessions on free hosts are often wiped between
 * GET /admin/login and POST, which surfaces as HTTP 419 CSRF_MISMATCH.
 */
final class DatabaseSessionHandler implements \SessionHandlerInterface
{
    private bool $tableReady = false;

    public function __construct(private readonly int $lifetime = 1209600)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $this->ensureTable();
            $row = Db::instance()->fetch(
                'SELECT `payload`, `last_activity` FROM ' . $this->tableSql() . ' WHERE `id` = ? LIMIT 1',
                [$id]
            );
            if ($row === null) {
                return '';
            }
            if ((int) ($row['last_activity'] ?? 0) < time() - $this->lifetime) {
                $this->destroy($id);
                return '';
            }
            return (string) ($row['payload'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $this->ensureTable();
            $adminId = null;
            if (isset($_SESSION['admin_id']) && is_numeric($_SESSION['admin_id'])) {
                $adminId = (int) $_SESSION['admin_id'];
                if ($adminId <= 0) {
                    $adminId = null;
                }
            }
            $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            Db::instance()->upsert('sessions', [
                'id' => $id,
                'payload' => $data,
                'last_activity' => time(),
                'admin_id' => $adminId,
                'ip' => $ip !== '' ? $ip : null,
                'user_agent' => $ua !== '' ? $ua : null,
            ], ['payload', 'last_activity', 'admin_id', 'ip', 'user_agent']);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $this->ensureTable();
            Db::instance()->delete('sessions', ['id' => $id]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $this->ensureTable();
            $ttl = $max_lifetime > 0 ? $max_lifetime : $this->lifetime;
            $stmt = Db::instance()->query(
                'DELETE FROM ' . $this->tableSql() . ' WHERE `last_activity` < ?',
                [time() - $ttl]
            );
            return $stmt->rowCount();
        } catch (\Throwable) {
            return false;
        }
    }

    private function tableSql(): string
    {
        return Db::instance()->quoteIdentifier(Db::instance()->tableName('sessions'));
    }

    private function ensureTable(): void
    {
        if ($this->tableReady) {
            return;
        }
        $db = Db::instance();
        $table = $db->tableName('sessions');
        $exists = (int) ($db->value(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        ) ?? 0);
        if ($exists === 0) {
            $db->query("CREATE TABLE IF NOT EXISTS {$db->quoteIdentifier($table)} (
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
        $this->tableReady = true;
    }
}
