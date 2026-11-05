<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Base class for migration files. A migration returns an anonymous subclass:
 *
 *   return new class extends Migration {
 *       public function up(): void { ... }
 *       public function down(): void { ... }
 *   };
 */
abstract class Migration
{
    protected Db $db;
    protected string $prefix;

    public function __construct()
    {
        $this->db = Db::instance();
        $this->prefix = $this->db->prefix();
    }

    abstract public function up(): void;

    public function down(): void
    {
    }

    protected function table(string $name): string
    {
        return '`' . $this->db->tableName($name) . '`';
    }

    protected function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
