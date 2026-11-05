<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Lightweight migration runner. Tracks applied files in {prefix}migrations.
 */
final class Migrator
{
    public function __construct(
        private readonly Db $db,
        private readonly string $path
    ) {
    }

    public function ensureTable(): void
    {
        $table = '`' . $this->db->tableName('migrations') . '`';
        $this->db->query("CREATE TABLE IF NOT EXISTS {$table} (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `migration` VARCHAR(190) NOT NULL,
            `batch` INT UNSIGNED NOT NULL DEFAULT 1,
            `applied_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_migration` (`migration`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return array<int, string> */
    public function files(): array
    {
        $files = glob(rtrim($this->path, '/\\') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        return array_map('basename', $files);
    }

    /** @return array<string, array<string, mixed>> */
    private function applied(): array
    {
        $this->ensureTable();
        $rows = $this->db->fetchAll('SELECT * FROM `' . $this->db->tableName('migrations') . '` ORDER BY `id` ASC');
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['migration']] = $row;
        }
        return $map;
    }

    /** @return array<int, string> */
    public function pending(): array
    {
        $applied = $this->applied();
        return array_values(array_filter($this->files(), static fn (string $file): bool => !isset($applied[$file])));
    }

    /** @return array<int, string> */
    public function up(): array
    {
        $this->ensureTable();
        $applied = $this->applied();
        $pending = $this->pending();
        if ($pending === []) {
            return [];
        }
        $batch = (int) ($this->db->value('SELECT COALESCE(MAX(`batch`), 0) FROM `' . $this->db->tableName('migrations') . '`') ?? 0) + 1;
        $done = [];
        foreach ($pending as $file) {
            $migration = $this->load($file);
            try {
                $migration->up();
            } catch (\Throwable $e) {
                throw new \RuntimeException("Migration failed [{$file}]: " . $e->getMessage(), 0, $e);
            }
            $this->db->insert('migrations', [
                'migration' => $file,
                'batch' => $batch,
                'applied_at' => date('Y-m-d H:i:s'),
            ]);
            $done[] = $file;
        }
        return $done;
    }

    /**
     * Roll back the most recent N migrations.
     * @return array<int, string>
     */
    public function down(int $steps = 1): array
    {
        $this->ensureTable();
        $rows = $this->db->fetchAll('SELECT * FROM `' . $this->db->tableName('migrations') . '` ORDER BY `id` DESC LIMIT ' . max(1, $steps));
        $done = [];
        foreach ($rows as $row) {
            $file = (string) $row['migration'];
            $migration = $this->load($file);
            try {
                $migration->down();
            } catch (\Throwable $e) {
                throw new \RuntimeException("Rollback failed [{$file}]: " . $e->getMessage(), 0, $e);
            }
            $this->db->delete('migrations', ['id' => (int) $row['id']]);
            $done[] = $file;
        }
        return $done;
    }

    /** @return array<int, array<string, mixed>> */
    public function status(): array
    {
        $applied = $this->applied();
        $status = [];
        foreach ($this->files() as $file) {
            $status[] = [
                'migration' => $file,
                'applied' => isset($applied[$file]),
                'batch' => $applied[$file]['batch'] ?? null,
                'applied_at' => $applied[$file]['applied_at'] ?? null,
            ];
        }
        return $status;
    }

    private function load(string $file): Migration
    {
        $path = rtrim($this->path, '/\\') . '/' . $file;
        if (!is_file($path)) {
            throw new \RuntimeException('Migration file missing: ' . $file);
        }
        $migration = require $path;
        if (!$migration instanceof Migration) {
            throw new \RuntimeException('Migration file must return a Migration instance: ' . $file);
        }
        return $migration;
    }
}
