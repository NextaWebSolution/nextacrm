<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * Small schema builder that emits MySQL 8 / MariaDB DDL (production) or SQLite DDL (tests).
 * app/Database/schema.php is the single source of truth for table definitions.
 */
final class Schema
{
    /** @var array<string, SchemaTable> */
    public array $tables = [];

    public function create(string $name, callable $fn): void
    {
        $t = new SchemaTable($name);
        $fn($t);
        $this->tables[$name] = $t;
    }

    /** @return string[] SQL statements */
    public function compile(string $driver): array
    {
        $out = [];
        foreach ($this->tables as $t) {
            foreach ($t->compile($driver) as $sql) {
                $out[] = $sql;
            }
        }
        return $out;
    }

    public static function load(): self
    {
        $s = new self();
        (require NX_ROOT . '/app/Database/schema.php')($s);
        return $s;
    }
}

final class SchemaTable
{
    public array $columns = [];
    public array $indexes = [];

    public function __construct(public string $name) {}

    private function col(string $name, string $type, array $extra = []): SchemaColumn
    {
        return $this->columns[$name] = new SchemaColumn($name, $type, $extra);
    }

    public function id(): void { $this->col('id', 'id'); }
    public function string(string $n, int $len = 191): SchemaColumn { return $this->col($n, 'string', ['len' => $len]); }
    public function text(string $n): SchemaColumn { return $this->col($n, 'text')->nullable(); }
    public function longText(string $n): SchemaColumn { return $this->col($n, 'longtext')->nullable(); }
    public function int(string $n): SchemaColumn { return $this->col($n, 'int'); }
    public function bigInt(string $n): SchemaColumn { return $this->col($n, 'bigint'); }
    public function decimal(string $n, int $p = 15, int $s = 2): SchemaColumn { return $this->col($n, 'decimal', ['p' => $p, 's' => $s]); }
    public function bool(string $n): SchemaColumn { return $this->col($n, 'bool')->default(0); }
    public function date(string $n): SchemaColumn { return $this->col($n, 'date')->nullable(); }
    public function datetime(string $n): SchemaColumn { return $this->col($n, 'datetime')->nullable(); }
    public function json(string $n): SchemaColumn { return $this->col($n, 'longtext')->nullable(); }
    public function foreignId(string $n): SchemaColumn { return $this->col($n, 'fk')->nullable(); }

    public function timestamps(): void
    {
        $this->datetime('created_at');
        $this->datetime('updated_at');
    }

    /** Standard columns for business records. */
    public function record(bool $assignable = true): void
    {
        $this->foreignId('company_id');
        if ($assignable) {
            $this->foreignId('assigned_to');
        }
        $this->foreignId('created_by');
        $this->foreignId('updated_by');
        $this->json('custom');
        $this->timestamps();
        $this->datetime('deleted_at');
        $this->index(['company_id', 'deleted_at']);
        if ($assignable) {
            $this->index(['assigned_to']);
        }
        $this->index(['created_at']);
    }

    public function index(array $cols, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'index', 'cols' => $cols, 'name' => $name ?? ('ix_' . $this->name . '_' . implode('_', $cols))];
    }

    public function unique(array $cols, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'unique', 'cols' => $cols, 'name' => $name ?? ('ux_' . $this->name . '_' . implode('_', $cols))];
    }

    public function compile(string $driver): array
    {
        $lines = [];
        foreach ($this->columns as $c) {
            $lines[] = $c->compile($driver);
        }
        $q = $driver === 'sqlite' ? '"' : '`';
        if ($driver === 'sqlite') {
            $sql = "CREATE TABLE IF NOT EXISTS {$q}{$this->name}{$q} (\n  " . implode(",\n  ", $lines) . "\n)";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS {$q}{$this->name}{$q} (\n  " . implode(",\n  ", $lines) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        }
        $out = [$sql];
        foreach ($this->indexes as $ix) {
            $name = substr($ix['name'], 0, 60);
            $cols = implode(',', array_map(fn ($c) => $q . $c . $q, $ix['cols']));
            $u = $ix['type'] === 'unique' ? 'UNIQUE ' : '';
            $out[] = $driver === 'sqlite'
                ? "CREATE {$u}INDEX IF NOT EXISTS {$q}{$name}{$q} ON {$q}{$this->name}{$q} ($cols)"
                : "CREATE {$u}INDEX {$q}{$name}{$q} ON {$q}{$this->name}{$q} ($cols)";
        }
        return $out;
    }
}

final class SchemaColumn
{
    public bool $null = false;
    public mixed $default = null;
    public bool $hasDefault = false;

    public function __construct(public string $name, public string $type, public array $extra = []) {}

    public function nullable(): self { $this->null = true; return $this; }
    public function default(mixed $v): self { $this->default = $v; $this->hasDefault = true; return $this; }

    public function compile(string $d): string
    {
        $q = $d === 'sqlite' ? '"' : '`';
        $n = $q . $this->name . $q;
        if ($this->type === 'id') {
            return $d === 'sqlite' ? "$n INTEGER PRIMARY KEY AUTOINCREMENT" : "$n BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY";
        }
        $t = match ($this->type) {
            'string' => $d === 'sqlite' ? 'TEXT' : 'VARCHAR(' . $this->extra['len'] . ')',
            'text' => $d === 'sqlite' ? 'TEXT' : 'TEXT',
            'longtext' => $d === 'sqlite' ? 'TEXT' : 'LONGTEXT',
            'int' => $d === 'sqlite' ? 'INTEGER' : 'INT',
            'bigint', 'fk' => $d === 'sqlite' ? 'INTEGER' : 'BIGINT UNSIGNED',
            'decimal' => $d === 'sqlite' ? 'NUMERIC' : 'DECIMAL(' . $this->extra['p'] . ',' . $this->extra['s'] . ')',
            'bool' => $d === 'sqlite' ? 'INTEGER' : 'TINYINT(1)',
            'date' => $d === 'sqlite' ? 'TEXT' : 'DATE',
            'datetime' => $d === 'sqlite' ? 'TEXT' : 'DATETIME',
        };
        $sql = "$n $t" . ($this->null ? ' NULL' : ' NOT NULL');
        if ($this->hasDefault) {
            $v = $this->default;
            $sql .= ' DEFAULT ' . (is_int($v) || is_float($v) ? $v : ($v === null ? 'NULL' : "'" . str_replace("'", "''", (string) $v) . "'"));
        }
        return $sql;
    }
}
