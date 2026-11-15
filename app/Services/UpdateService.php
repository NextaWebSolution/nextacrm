<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\HttpClient;
use App\Core\Settings;

/**
 * One-click updater with Ed25519 signature verification, pre-update backups and migrations.
 * Release metadata (latest.json): {version, url, sha256, signature, notes, requires_php}
 * Signature = Ed25519 over "nextacrm|<version>|<sha256>" using the vendor key whose public half is in manifest.json.
 */
final class UpdateService
{
    public const PRESERVE = ['config/', 'storage/', '.user.ini', 'php.ini'];

    public static function check(): array
    {
        $m = App::manifest();
        $server = rtrim((string) ($m['update']['server'] ?? ''), '/');
        if ($server === '') {
            return ['ok' => false, 'message' => 'No update server configured in manifest.json.'];
        }
        $res = (new HttpClient(false, 15))->request('GET', $server . '/latest.json?v=' . rawurlencode(App::version()) . '&php=' . PHP_VERSION, ['Accept: application/json']);
        if ($res['error'] || $res['status'] !== 200) {
            return ['ok' => false, 'message' => 'Could not reach the update server' . ($res['error'] ? ': ' . $res['error'] : ' (HTTP ' . $res['status'] . ')')];
        }
        $info = json_decode($res['body'], true);
        if (!is_array($info) || empty($info['version']) || empty($info['url']) || empty($info['sha256']) || empty($info['signature'])) {
            return ['ok' => false, 'message' => 'The update server returned invalid release information.'];
        }
        Settings::set('update.last_check', ['at' => time(), 'info' => $info]);
        $available = version_compare((string) $info['version'], App::version(), '>');
        return ['ok' => true, 'available' => $available, 'info' => $info, 'message' => $available ? 'Version ' . $info['version'] . ' is available.' : 'You are running the latest version.'];
    }

    public static function verifySignature(string $version, string $sha256, string $signatureB64): bool
    {
        $pub = base64_decode((string) (App::manifest()['update']['public_key'] ?? ''), true);
        $sig = base64_decode($signatureB64, true);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig, 'nextacrm|' . $version . '|' . strtolower($sha256), $pub);
    }

    /** Download, verify and install the release described by $info. */
    public static function apply(array $info): array
    {
        $version = (string) $info['version'];
        if (!self::verifySignature($version, (string) $info['sha256'], (string) $info['signature'])) {
            return ['ok' => false, 'message' => 'Signature verification failed. The update was NOT installed.'];
        }
        if (!empty($info['requires_php']) && version_compare(PHP_VERSION, (string) $info['requires_php'], '<')) {
            return ['ok' => false, 'message' => 'This update requires PHP ' . $info['requires_php'] . '.'];
        }
        $res = (new HttpClient(false, 300, 200 * 1048576))->request('GET', (string) $info['url']);
        if ($res['error'] || $res['status'] !== 200) {
            return ['ok' => false, 'message' => 'Download failed' . ($res['error'] ? ': ' . $res['error'] : '')];
        }
        $zipPath = NX_ROOT . '/storage/tmp/update-' . preg_replace('/[^0-9a-z.\-]/i', '', $version) . '.zip';
        file_put_contents($zipPath, $res['body']);
        return self::installPackage($zipPath, $version, (string) $info['sha256']);
    }

    public static function installPackage(string $zipPath, string $version, string $expectedSha): array
    {
        if (!hash_equals(strtolower($expectedSha), hash_file('sha256', $zipPath))) {
            @unlink($zipPath);
            return ['ok' => false, 'message' => 'Checksum mismatch. The update was NOT installed.'];
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'message' => 'The update package is not a valid ZIP file.'];
        }
        // Validate every entry before touching the installation.
        $prefix = self::commonPrefix($zip);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
                $zip->close();
                return ['ok' => false, 'message' => 'The update package contains unsafe paths.'];
            }
        }
        @touch(NX_ROOT . '/storage/maintenance.flag');
        try {
            $backup = BackupService::create('pre-update-' . App::version());
            $written = 0;
            $shipped = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $rel = $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name;
                if ($rel === '' || str_ends_with($rel, '/')) {
                    continue;
                }
                foreach (self::PRESERVE as $keep) {
                    if (str_starts_with($rel, $keep)) {
                        continue 2;
                    }
                }
                $shipped[$rel] = true;
                $dest = NX_ROOT . '/' . $rel;
                if (!is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0755, true);
                }
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    throw new \RuntimeException("Could not read $name from the package.");
                }
                $tmp = $dest . '.nxnew';
                file_put_contents($tmp, $stream);
                fclose($stream);
                rename($tmp, $dest);
                $written++;
            }
            $zip->close();
            $removed = self::removeStaleFiles($shipped);
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            $migrations = Migrator::run();
            Audit::log('system.updated', null, null, ['to' => $version, 'files' => $written, 'migrations' => $migrations, 'backup' => $backup, 'removed' => $removed]);
            return ['ok' => true, 'message' => "Updated to $version ($written files, $removed obsolete files removed, " . count($migrations) . ' migrations). Backup: ' . $backup];
        } catch (\Throwable $e) {
            \App\Core\ErrorHandler::report($e);
            return ['ok' => false, 'message' => 'Update failed: ' . $e->getMessage() . '. Restore from the backup in storage/backups if needed.'];
        } finally {
            @unlink(NX_ROOT . '/storage/maintenance.flag');
            @unlink($zipPath);
        }
    }

    /** Code folders owned by the application; files here that a release no longer ships are deleted. */
    public const MANAGED = ['app', 'vendor', 'assets', 'tools'];

    /**
     * Removes files from previous versions that the new package no longer contains (e.g. removed libraries
     * or scripts), so an upgrade cannot leave outdated or vulnerable code behind. Never touches config/ or storage/.
     */
    private static function removeStaleFiles(array $shipped): int
    {
        if (count($shipped) < 50) {
            return 0; // partial/patch package: do not prune
        }
        $removed = 0;
        foreach (self::MANAGED as $dir) {
            $base = NX_ROOT . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(NX_ROOT) + 1));
                if ($f->isDir()) {
                    @rmdir($f->getPathname()); // only succeeds when empty
                } elseif (!isset($shipped[$rel]) && !str_ends_with($rel, '.nxnew')) {
                    @unlink($f->getPathname()) && $removed++;
                }
            }
        }
        return $removed;
    }

    private static function commonPrefix(\ZipArchive $zip): string
    {
        $first = (string) $zip->getNameIndex(0);
        $prefix = str_contains($first, '/') ? explode('/', $first)[0] . '/' : '';
        if ($prefix === '' || is_file(NX_ROOT . '/' . rtrim($prefix, '/'))) {
            return '';
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (!str_starts_with((string) $zip->getNameIndex($i), $prefix)) {
                return '';
            }
        }
        // Only strip a wrapping folder (e.g. "nextacrm/") — not a real top-level app folder.
        return in_array(rtrim($prefix, '/'), ['app', 'assets', 'vendor', 'config', 'storage', 'docs', 'tools'], true) ? '' : $prefix;
    }
}
