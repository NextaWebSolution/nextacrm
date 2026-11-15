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
}
