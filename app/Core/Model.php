<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Optional base model. Domain logic lives in services, but models are handy
 * for simple modules and demonstrate the framework's layered structure.
 */
abstract class Model
{
    protected static string $table = '';

    /** @return array<int, array<string, mixed>> */
    public static function all(int $limit = 100): array
    {
        return Db::instance()->table(static::$table)->latest()->limit($limit)->get();
    }

    /** @return array<string, mixed>|null */
    public static function find(int $id): ?array
    {
        return Db::instance()->table(static::$table)->where('id', $id)->first();
    }

    /** @param array<string, mixed> $data */
    public static function create(array $data): int
    {
        return Db::instance()->insert(static::$table, $data);
    }

    /** @param array<string, mixed> $data */
    public static function updateById(int $id, array $data): int
    {
        return Db::instance()->update(static::$table, $data, ['id' => $id]);
    }

    public static function deleteById(int $id): int
    {
        return Db::instance()->delete(static::$table, ['id' => $id]);
    }

    public static function query(): QueryBuilder
    {
        return Db::instance()->table(static::$table);
    }
}
