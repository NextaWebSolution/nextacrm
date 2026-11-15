<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;

/** Database (SQL) + application file backups into storage/backups. */
final class BackupService
{
    public static function dir(): string
    {
        $d = NX_ROOT . '/storage/backups';
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        if (!is_file($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        return $d;
    }

    /** Creates <label>-<date>.zip containing database.sql and the application code. Returns the file name. */
    public static function create(string $label = 'manual', bool $includeCode = true): string
    {
        @set_time_limit(600);
        $name = preg_replace('/[^A-Za-z0-9.\-_]/', '_', $label) . '-' . gmdate('Ymd-His') . '.zip';
        $path = self::dir() . '/' . $name;
        $sqlFile = self::dir() . '/db-' . bin2hex(random_bytes(4)) . '.sql';
        self::dumpDatabase($sqlFile);
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFile($sqlFile, 'database.sql');
        if ($includeCode) {
            foreach (['app', 'assets', 'vendor', 'install'] as $dir) {
                self::addDir($zip, NX_ROOT . '/' . $dir, $dir);
            }
            foreach (['index.php', 'cron.php', 'manifest.json', '.htaccess', 'manifest.webmanifest'] as $f) {
                if (is_file(NX_ROOT . '/' . $f)) {
                    $zip->addFile(NX_ROOT . '/' . $f, $f);
                }
            }
        }
        $zip->close();
        @unlink($sqlFile);
        @chmod($path, 0640);
        return $name;
    }

    private static function addDir(\ZipArchive $zip, string $dir, string $base): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile()) {
                $rel = $base . '/' . substr($file->getPathname(), strlen($dir) + 1);
                $zip->addFile($file->getPathname(), str_replace('\\', '/', $rel));
            }
        }
    }

    public static function dumpDatabase(string $file): void
    {
        $db = App::db();
        $fh = fopen($file, 'wb');
        fwrite($fh, "-- NextaCRM database backup " . gmdate('c') . " (v" . App::version() . ")\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
        $tables = $db->driver === 'sqlite'
            ? $db->column("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")
            : $db->column('SHOW TABLES');
        foreach ($tables as $t) {
            if ($db->driver === 'mysql') {
                $create = $db->one('SHOW CREATE TABLE ' . $db->ident($t));
                fwrite($fh, 'DROP TABLE IF EXISTS `' . $t . "`;\n" . ($create['Create Table'] ?? '') . ";\n\n");
            }
            $offset = 0;
            while (true) {
                $rows = $db->all('SELECT * FROM ' . $db->ident($t) . " LIMIT 500 OFFSET $offset");
                if (!$rows) {
                    break;
                }
                foreach ($rows as $r) {
                    $vals = array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $db->pdo->quote((string) $v)), array_values($r));
                    fwrite($fh, 'INSERT INTO `' . $t . '` (`' . implode('`,`', array_keys($r)) . '`) VALUES (' . implode(',', $vals) . ");\n");
                }
                $offset += 500;
            }
            fwrite($fh, "\n");
        }
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.zip') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
        }
        usort($out, fn ($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    public static function path(string $name): ?string
    {
        if (!preg_match('/^[A-Za-z0-9.\-_]+\.zip$/', $name)) {
            return null;
        }
        $p = self::dir() . '/' . $name;
        return is_file($p) ? $p : null;
    }

    /** Keep the most recent N backups. */
    public static function prune(int $keep = 10): void
    {
        foreach (array_slice(self::list(), $keep) as $b) {
            @unlink(self::dir() . '/' . $b['name']);
        }
    }
}
