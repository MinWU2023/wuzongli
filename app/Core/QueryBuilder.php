<?php
declare(strict_types=1);

namespace App\Core;

/**
 * A deliberately small query builder for day-to-day CRUD.
 */
final class QueryBuilder
{
    private string $table;
    /** @var array<int, string> */
    private array $columns = ['*'];
    /** @var array<int, array{sql:string,bindings:array<int,mixed>,boolean:string}> */
    private array $wheres = [];
    /** @var array<int, array{column:string,direction:string}> */
    private array $orders = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    public function __construct(private readonly Db $db, string $table)
    {
        $this->table = $table;
    }

    /** @param array<int, string>|string $columns */
    public function select(array|string $columns): self
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    public function where(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }
        $operator = strtoupper((string) $operator);
        if (!in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'], true)) {
            throw new \InvalidArgumentException('Unsupported where operator: ' . $operator);
        }
        $this->assertIdentifier($column);
        $this->wheres[] = [
            'sql' => $this->db->quoteIdentifier($column) . ' ' . $operator . ' ?',
            'bindings' => [$value],
            'boolean' => 'AND',
        ];
        return $this;
    }

    public function orWhere(string $column, mixed $operator = null, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $this->where($column, $operator);
        } else {
            $this->where($column, $operator, $value);
        }
        $this->wheres[array_key_last($this->wheres)]['boolean'] = 'OR';
        return $this;
    }

    /** @param array<int, mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        $this->assertIdentifier($column);
        if ($values === []) {
            $this->wheres[] = ['sql' => '1 = 0', 'bindings' => [], 'boolean' => 'AND'];
            return $this;
        }
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $this->wheres[] = [
            'sql' => $this->db->quoteIdentifier($column) . ' IN (' . $placeholders . ')',
            'bindings' => array_values($values),
            'boolean' => 'AND',
        ];
        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->assertIdentifier($column);
        $this->wheres[] = [
            'sql' => $this->db->quoteIdentifier($column) . ' IS NULL',
            'bindings' => [],
            'boolean' => 'AND',
        ];
        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->assertIdentifier($column);
        $this->wheres[] = [
            'sql' => $this->db->quoteIdentifier($column) . ' IS NOT NULL',
            'bindings' => [],
            'boolean' => 'AND',
        ];
        return $this;
    }

    /** @param array<int, mixed> $bindings */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->wheres[] = ['sql' => '(' . $sql . ')', 'bindings' => $bindings, 'boolean' => 'AND'];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->assertIdentifier($column);
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->orders[] = ['column' => $column, 'direction' => $direction];
        return $this;
    }

    public function latest(string $column = 'id'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    public function limit(int $limit): self
    {
        $this->limitValue = max(0, $limit);
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offsetValue = max(0, $offset);
        return $this;
    }

    public function forPage(int $page, int $perPage = 20): self
    {
        $page = max(1, $page);
        return $this->limit($perPage)->offset(($page - 1) * $perPage);
    }

    /** @return array<int, array<string, mixed>> */
    public function get(): array
    {
        [$sql, $bindings] = $this->compileSelect();
        return $this->db->fetchAll($sql, $bindings);
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        $this->limit(1);
        $rows = $this->get();
        return $rows[0] ?? null;
    }

    public function value(string $column): mixed
    {
        $this->select([$column]);
        $row = $this->first();
        return $row[$column] ?? null;
    }

    public function count(): int
    {
        $originalColumns = $this->columns;
        $originalOrders = $this->orders;
        $originalLimit = $this->limitValue;
        $originalOffset = $this->offsetValue;
        $this->columns = ['COUNT(*) AS aggregate'];
        $this->orders = [];
        $this->limitValue = null;
        $this->offsetValue = null;
        try {
            [$sql, $bindings] = $this->compileSelect();
            return (int) ($this->db->fetch($sql, $bindings)['aggregate'] ?? 0);
        } finally {
            $this->columns = $originalColumns;
            $this->orders = $originalOrders;
            $this->limitValue = $originalLimit;
            $this->offsetValue = $originalOffset;
        }
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): int
    {
        return $this->db->insert($this->table, $data);
    }

    /** @param array<string, mixed> $data */
    public function update(array $data): int
    {
        if ($this->wheres === []) {
            throw new \RuntimeException('Refusing to update without a where clause.');
        }
        $sets = [];
        $bindings = [];
        foreach ($data as $column => $value) {
            $this->assertIdentifier($column);
            $sets[] = $this->db->quoteIdentifier($column) . ' = ?';
            $bindings[] = $value;
        }
        [$whereSql, $whereBindings] = $this->compileWheres();
        $sql = 'UPDATE ' . $this->db->quoteIdentifier($this->db->tableName($this->table))
            . ' SET ' . implode(', ', $sets) . $whereSql;
        return $this->db->query($sql, array_merge($bindings, $whereBindings))->rowCount();
    }

    public function delete(): int
    {
        if ($this->wheres === []) {
            throw new \RuntimeException('Refusing to delete without a where clause.');
        }
        [$whereSql, $bindings] = $this->compileWheres();
        $sql = 'DELETE FROM ' . $this->db->quoteIdentifier($this->db->tableName($this->table)) . $whereSql;
        return $this->db->query($sql, $bindings)->rowCount();
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function compileSelect(): array
    {
        $columns = [];
        foreach ($this->columns as $column) {
            if ($column === '*') {
                $columns[] = '*';
            } elseif (preg_match('/^([a-zA-Z0-9_]+)\s+AS\s+([a-zA-Z0-9_]+)$/i', $column, $m)) {
                $columns[] = $this->db->quoteIdentifier($m[1]) . ' AS ' . $this->db->quoteIdentifier($m[2]);
            } elseif (preg_match('/^COUNT\(\*\)\s+AS\s+([a-zA-Z0-9_]+)$/i', $column, $m)) {
                $columns[] = 'COUNT(*) AS ' . $this->db->quoteIdentifier($m[1]);
            } elseif (preg_match('/^(SUM|MAX|MIN|AVG)\(`?([a-zA-Z0-9_]+)`?\)\s+AS\s+([a-zA-Z0-9_]+)$/i', $column, $m)) {
                $columns[] = strtoupper($m[1]) . '(' . $this->db->quoteIdentifier($m[2]) . ') AS ' . $this->db->quoteIdentifier($m[3]);
            } else {
                $this->assertIdentifier($column);
                $columns[] = $this->db->quoteIdentifier($column);
            }
        }
        [$whereSql, $bindings] = $this->compileWheres();
        $sql = 'SELECT ' . implode(', ', $columns)
            . ' FROM ' . $this->db->quoteIdentifier($this->db->tableName($this->table))
            . $whereSql;
        if ($this->orders !== []) {
            $orderParts = [];
            foreach ($this->orders as $order) {
                $orderParts[] = $this->db->quoteIdentifier($order['column']) . ' ' . $order['direction'];
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderParts);
        }
        if ($this->limitValue !== null) {
            $sql .= ' LIMIT ' . $this->limitValue;
        }
        if ($this->offsetValue !== null && $this->limitValue !== null) {
            $sql .= ' OFFSET ' . $this->offsetValue;
        }
        return [$sql, $bindings];
    }

    /** @return array{0:string,1:array<int,mixed>} */
    private function compileWheres(): array
    {
        if ($this->wheres === []) {
            return ['', []];
        }
        $parts = [];
        $bindings = [];
        foreach ($this->wheres as $index => $where) {
            $parts[] = ($index === 0 ? '' : $where['boolean'] . ' ') . $where['sql'];
            array_push($bindings, ...$where['bindings']);
        }
        return [' WHERE ' . implode(' ', $parts), $bindings];
    }

    private function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
        }
    }
}
