<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\DB;

/**
 * Versioned migrations in app/Database/migrations/NNNN_name.php.
 * Each file returns: function (App\Core\DB $db): void
 */
final class Migrator
{
    public static function available(): array
    {
        $files = glob(NX_ROOT . '/app/Database/migrations/*.php') ?: [];
        sort($files);
        return array_map(fn ($f) => basename($f, '.php'), $files);
    }

    public static function pending(): array
    {
        $db = App::db();
        $ran = $db->column('SELECT name FROM migrations');
        return array_values(array_diff(self::available(), $ran));
    }

    /** @return string[] names that ran */
    public static function run(): array
    {
        $db = App::db();
        $done = [];
        foreach (self::pending() as $name) {
            if (!preg_match('/^\d{4}_[a-z0-9_]+$/', $name)) {
                continue;
            }
            $fn = require NX_ROOT . '/app/Database/migrations/' . $name . '.php';
            $fn($db);
            $db->insert('migrations', ['name' => $name, 'ran_at' => DB::now()]);
            $done[] = $name;
        }
        return $done;
    }

    /**
     * Brings the live database in line with app/Database/schema.php: creates missing tables and adds missing
     * columns and indexes. Never drops or alters existing columns. Safe to run repeatedly (MySQL and SQLite).
     * @return array{tables:string[], columns:string[], indexes:string[]}
     */
    public static function syncSchema(): array
    {
        $db = App::db();
        $driver = $db->driver;
        $q = $driver === 'sqlite' ? '"' : '`';
        $report = ['tables' => [], 'columns' => [], 'indexes' => []];
        foreach (\App\Core\Schema::load()->tables as $table) {
            if (!$db->tableExists($table->name)) {
                foreach ($table->compile($driver) as $sql) {
                    $db->query($sql);
                }
                $report['tables'][] = $table->name;
                continue;
            }
            $existing = $driver === 'sqlite'
                ? array_column($db->all("PRAGMA table_info({$q}{$table->name}{$q})"), 'name')
                : array_column($db->all("SHOW COLUMNS FROM {$q}{$table->name}{$q}"), 'Field');
            foreach ($table->columns as $col) {
                if (in_array($col->name, $existing, true) || $col->type === 'id') {
                    continue;
                }
                $c = clone $col;
                if (!$c->null && !$c->hasDefault) {
                    // Existing rows need a value: add NOT NULL columns with a neutral default.
                    $c->default(in_array($c->type, ['int', 'bigint', 'bool', 'decimal', 'fk'], true) ? 0 : '');
                }
                $db->query("ALTER TABLE {$q}{$table->name}{$q} ADD COLUMN " . $c->compile($driver));
                $report['columns'][] = $table->name . '.' . $col->name;
            }
            $indexes = $driver === 'sqlite'
                ? array_column($db->all("PRAGMA index_list({$q}{$table->name}{$q})"), 'name')
                : array_unique(array_column($db->all("SHOW INDEX FROM {$q}{$table->name}{$q}"), 'Key_name'));
            foreach ($table->indexes as $ix) {
                $name = substr($ix['name'], 0, 60);
                if (in_array($name, $indexes, true)) {
                    continue;
                }
                $cols = implode(',', array_map(fn ($c) => $q . $c . $q, $ix['cols']));
                $u = $ix['type'] === 'unique' ? 'UNIQUE ' : '';
                $db->query("CREATE {$u}INDEX {$q}{$name}{$q} ON {$q}{$table->name}{$q} ($cols)");
                $report['indexes'][] = $name;
            }
        }
        return $report;
    }
}
