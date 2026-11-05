<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;

/**
 * PDO connection + tiny active-record friendly database helper.
 *
 * All identifiers are validated before interpolation, values are always bound.
 */
final class Db
{
    private static ?self $instance = null;
    private PDO $pdo;
    private string $prefix;
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    private function __construct(array $config)
    {
        $this->config = $config;
        $this->prefix = (string) ($config['prefix'] ?? '');
        $this->connect();
    }

    private function connect(): void
    {
        $config = $this->config;
        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (int) ($config['port'] ?? 3306);
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $charset = (string) ($config['charset'] ?? 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
        $this->pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $this->pdo->exec("SET NAMES {$charset} COLLATE {$charset}_unicode_ci");
        try {
            $timezone = new \DateTimeZone((string) ($config['timezone'] ?? 'Asia/Shanghai'));
            $offset = $timezone->getOffset(new \DateTimeImmutable('now', $timezone));
            $sign = $offset >= 0 ? '+' : '-';
            $offset = abs($offset);
            $this->pdo->exec(sprintf("SET time_zone = '%s%02d:%02d'", $sign, intdiv($offset, 3600), intdiv($offset % 3600, 60)));
        } catch (\Throwable) {
            // Some shared hosts restrict time_zone; PHP timestamps are still explicit.
        }
        try {
            // Extend session wait_timeout where allowed so long AI calls don't drop the MySQL session.
            $this->pdo->exec('SET SESSION wait_timeout = 600, interactive_timeout = 600');
        } catch (\Throwable) {
            // Shared hosts may block session timeout changes; reconnect handles dropped connections.
        }
    }

    public function reconnect(): void
    {
        $this->connect();
    }

    public function ensureConnected(): void
    {
        if ($this->pdo->inTransaction()) {
            return;
        }
        try {
            $this->pdo->query('SELECT 1');
        } catch (\Throwable) {
            $this->connect();
        }
    }

    private function isDisconnectError(\Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        if (
            str_contains($msg, 'server has gone away') ||
            str_contains($msg, 'lost connection to mysql server') ||
            str_contains($msg, 'error while sending query packet') ||
            str_contains($msg, 'connection timed out') ||
            str_contains($msg, 'broken pipe')
        ) {
            return true;
        }
        if ($e instanceof PDOException && is_array($e->errorInfo)) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if (in_array($driverCode, [2006, 2013], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $config */
    public static function init(array $config): self
    {
        self::$instance = new self($config);
        return self::$instance;
    }

    public static function instance(): self
    {
        if (!self::$instance instanceof self) {
            throw new \RuntimeException('Database is not connected. Run install.php first.');
        }
        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    public function tableName(string $table): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name: ' . $table);
        }
        return $this->prefix . $table;
    }

    /** @param array<string, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $statement = $this->pdo->prepare($sql);
                foreach ($params as $key => $value) {
                    $param = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');
                    $type = match (true) {
                        is_int($value) => PDO::PARAM_INT,
                        is_bool($value) => PDO::PARAM_BOOL,
                        is_null($value) => PDO::PARAM_NULL,
                        default => PDO::PARAM_STR,
                    };
                    $statement->bindValue($param, $value, $type);
                }
                $statement->execute();
                return $statement;
            } catch (\Throwable $e) {
                if ($attempt === 0 && !$this->pdo->inTransaction() && $this->isDisconnectError($e)) {
                    $this->connect();
                    continue;
                }
                throw $e;
            }
        }
        throw new \RuntimeException('Database query failed after reconnect.');
    }

    /** @param array<string, mixed> $params */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $params @return array<int, array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @param array<string, mixed> $params */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Cannot insert an empty row.');
        }
        $columns = array_keys($data);
        $this->assertColumns($columns);
        $quoted = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = 'INSERT INTO ' . $this->quoteIdentifier($this->tableName($table))
            . ' (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $this->query($sql, array_values($data));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @return bool True when a new row was inserted, false when ignored.
     */
    public function insertIgnore(string $table, array $data): bool
    {
        if ($data === []) {
            return false;
        }
        $columns = array_keys($data);
        $this->assertColumns($columns);
        $quoted = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = 'INSERT IGNORE INTO ' . $this->quoteIdentifier($this->tableName($table))
            . ' (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $placeholders) . ')';
        return $this->query($sql, array_values($data))->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $updateColumns
     */
    public function upsert(string $table, array $data, array $updateColumns = []): void
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Cannot upsert an empty row.');
        }
        $columns = array_keys($data);
        $this->assertColumns($columns);
        $quoted = array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = 'INSERT INTO ' . $this->quoteIdentifier($this->tableName($table))
            . ' (' . implode(', ', $quoted) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $updateColumns = $updateColumns ?: array_values(array_diff($columns, ['id', 'created_at']));
        if ($updateColumns !== []) {
            $sets = [];
            foreach ($updateColumns as $column) {
                if (!in_array($column, $columns, true)) {
                    continue;
                }
                $sets[] = $this->quoteIdentifier($column) . ' = VALUES(' . $this->quoteIdentifier($column) . ')';
            }
            if ($sets !== []) {
                $sql .= ' ON DUPLICATE KEY UPDATE ' . implode(', ', $sets);
            }
        }
        $this->query($sql, array_values($data));
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === [] || $where === []) {
            return 0;
        }
        $this->assertColumns(array_keys($data));
        $this->assertColumns(array_keys($where));
        $sets = [];
        $values = [];
        foreach ($data as $column => $value) {
            $sets[] = $this->quoteIdentifier($column) . ' = ?';
            $values[] = $value;
        }
        $conditions = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $conditions[] = $this->quoteIdentifier($column) . ' IS NULL';
            } else {
                $conditions[] = $this->quoteIdentifier($column) . ' = ?';
                $values[] = $value;
            }
        }
        $sql = 'UPDATE ' . $this->quoteIdentifier($this->tableName($table))
            . ' SET ' . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $conditions);
        return $this->query($sql, $values)->rowCount();
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            return 0;
        }
        $this->assertColumns(array_keys($where));
        $conditions = [];
        $values = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $conditions[] = $this->quoteIdentifier($column) . ' IS NULL';
            } else {
                $conditions[] = $this->quoteIdentifier($column) . ' = ?';
                $values[] = $value;
            }
        }
        $sql = 'DELETE FROM ' . $this->quoteIdentifier($this->tableName($table))
            . ' WHERE ' . implode(' AND ', $conditions);
        return $this->query($sql, $values)->rowCount();
    }

    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    public function transaction(callable $callback): mixed
    {
        $started = !$this->pdo->inTransaction();
        if ($started) {
            try {
                $this->pdo->beginTransaction();
            } catch (\Throwable $e) {
                if ($this->isDisconnectError($e)) {
                    $this->connect();
                    $this->pdo->beginTransaction();
                } else {
                    throw $e;
                }
            }
        }
        try {
            $result = $callback($this);
            if ($started) {
                $this->pdo->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($started) {
                try {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                } catch (\Throwable) {
                    // Connection may have dropped; ignore rollback error.
                }
            }
            throw $e;
        }
    }

    /**
     * MySQL advisory lock, useful for preventing duplicate pipeline workers.
     */
    public function acquireLock(string $name, int $timeout = 0): bool
    {
        $row = $this->fetch('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeout]);
        return (int) ($row['acquired'] ?? 0) === 1;
    }

    public function releaseLock(string $name): void
    {
        try {
            $this->query('SELECT RELEASE_LOCK(?)', [$name]);
        } catch (\Throwable) {
            // If connection was dropped and reconnected, the advisory lock is already released by MySQL.
        }
    }

    public function quoteIdentifier(string $identifier): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }
        return '`' . $identifier . '`';
    }

    /** @param array<int, string> $columns */
    private function assertColumns(array $columns): void
    {
        foreach ($columns as $column) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', (string) $column)) {
                throw new \InvalidArgumentException('Invalid SQL column: ' . $column);
            }
        }
    }
}
