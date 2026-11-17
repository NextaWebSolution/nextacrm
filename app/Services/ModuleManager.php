<?php
declare(strict_types=1);

namespace App\Services;

defined('NX_ROOT') || exit;

use App\Core\App;
use App\Core\Audit;
use App\Core\DB;
use App\Core\HttpClient;
use App\Core\HttpException;

/**
 * Third-party modules (marketplace). A module is a folder modules/<slug>/ with module.json and module.php, which
 * registers itself through App\Core\Extensions. Modules run PHP with full application privileges, so by default
 * only packages signed with the vendor key (manifest.json → update.public_key) can be installed.
 *
 * Signed message: "nextacrm-module|<slug>|<version>|<sha256 of zip>" (Ed25519, base64).
 */
final class ModuleManager
{
    public const DIR = NX_ROOT . '/modules';
    private const MAX_ZIP = 10 * 1048576;
    private const MAX_FILES = 500;
    private const ALLOWED_EXT = ['php', 'json', 'md', 'txt', 'css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'csv', 'htaccess'];
    private static array $booted = [];

    public static function bootEnabled(): void
    {
        try {
            if (!App::installed() || !App::db()->tableExists('installed_modules')) {
                return;
            }
            $rows = App::db()->all('SELECT slug FROM installed_modules WHERE enabled = 1 ORDER BY id');
        } catch (\Throwable) {
            return;
        }
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            $entry = self::DIR . '/' . $slug . '/module.php';
            if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,39}$/', $slug) || !is_file($entry)) {
                continue;
            }
            try {
                (static function (string $__file): void { require $__file; })($entry);
                self::$booted[] = $slug;
            } catch (\Throwable $e) {
                \App\Core\ErrorHandler::report($e);
                App::db()->update('installed_modules', ['enabled' => 0, 'updated_at' => DB::now()], ['slug' => $slug]);
                \App\Core\ErrorHandler::log("Module $slug was disabled because it failed to load: " . $e->getMessage(), 'modules');
            }
        }
    }

    /** Developer mode is an install-wide switch (company 0 only), so one workspace cannot loosen it for everybody. */
    public static function developerMode(): bool
    {
        $v = App::db()->value("SELECT v FROM settings WHERE company_id = 0 AND k = 'modules.allow_unsigned'");
        return $v !== null && (bool) json_decode((string) $v, true);
    }

    public static function installed(): array
    {
        return App::db()->all('SELECT * FROM installed_modules ORDER BY name');
    }

    public static function verifySignature(string $slug, string $version, string $sha256, string $sigB64): bool
    {
        $pub = base64_decode((string) (App::manifest()['update']['public_key'] ?? ''), true);
        $sig = base64_decode($sigB64, true);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        return sodium_crypto_sign_verify_detached($sig, 'nextacrm-module|' . $slug . '|' . $version . '|' . strtolower($sha256), $pub);
    }

    /** Validates and installs (or upgrades) a module package. */
    public static function install(string $zipPath, ?string $signature): array
    {
        if (!is_file($zipPath) || filesize($zipPath) > self::MAX_ZIP) {
            throw new HttpException(422, 'The module package is missing or larger than 10 MB.');
        }
        $sha = hash_file('sha256', $zipPath);
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new HttpException(422, 'The module package is not a valid ZIP file.');
        }
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > self::MAX_FILES) {
                throw new HttpException(422, 'The module package has an unexpected number of files.');
            }
            $manifestRaw = $zip->getFromName('module.json');
            $prefix = '';
            if ($manifestRaw === false) {
                $first = (string) $zip->getNameIndex(0);
                $prefix = explode('/', $first)[0] . '/';
                $manifestRaw = $zip->getFromName($prefix . 'module.json');
            }
            $m = json_decode((string) $manifestRaw, true);
            if (!is_array($m)) {
                throw new HttpException(422, 'module.json is missing or invalid.');
            }
            $slug = (string) ($m['slug'] ?? '');
            $version = (string) ($m['version'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,39}$/', $slug) || !preg_match('/^\d+\.\d+\.\d+$/', $version) || empty($m['name'])) {
                throw new HttpException(422, 'module.json needs a valid slug, name and version (x.y.z).');
            }
            $min = (string) ($m['requires']['nextacrm'] ?? '0.0.0');
            if (!preg_match('/^\d+\.\d+\.\d+$/', $min) || version_compare(App::version(), $min, '<')) {
                throw new HttpException(422, "This module needs NextaCRM $min or later.");
            }
            $signed = $signature !== null && $signature !== '' && self::verifySignature($slug, $version, $sha, $signature);
            if (!$signed && !self::developerMode()) {
                throw new HttpException(422, 'The module signature is missing or invalid. Only verified modules can be installed (Developer mode allows unsigned modules).');
            }
            // Validate every entry before extracting anything.
            $entries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) $stat['name'];
                if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                    throw new HttpException(422, 'All files must be inside one folder.');
                }
                $rel = substr($name, strlen($prefix));
                if ($rel === '' || str_ends_with($rel, '/')) {
                    continue;
                }
                $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION) ?: (str_starts_with(basename($rel), '.') ? substr(basename($rel), 1) : ''));
                $bad = str_contains($rel, '..') || str_starts_with($rel, '/') || str_contains($rel, '\\') || str_contains($rel, "\0")
                    || !preg_match('#^[A-Za-z0-9_\-./]+$#', $rel) || !in_array($ext, self::ALLOWED_EXT, true);
                // Reject symlinks (Unix mode stored in external attributes).
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) {
                    $bad = true;
                }
                if ($bad) {
                    throw new HttpException(422, 'The package contains a file that is not allowed: ' . mb_substr($rel, 0, 80));
                }
                $entries[$name] = $rel;
            }
            if (!in_array('module.php', $entries, true)) {
                throw new HttpException(422, 'module.php is missing.');
            }
            if (!is_dir(self::DIR)) {
                @mkdir(self::DIR, 0755, true);
            }
            $target = self::DIR . '/' . $slug;
            $staging = self::DIR . '/.' . $slug . '.' . bin2hex(random_bytes(4));
            @mkdir($staging, 0755, true);
            foreach ($entries as $name => $rel) {
                $dest = $staging . '/' . $rel;
                if (!is_dir(dirname($dest))) {
                    @mkdir(dirname($dest), 0755, true);
                }
                file_put_contents($dest, (string) $zip->getFromName($name));
            }
        } finally {
            $zip->close();
        }
        file_put_contents($staging . '/.htaccess', "Require all denied\n");
        $old = is_dir($target) ? $target . '.old-' . time() : null;
        if ($old) {
            rename($target, $old);
        }
        rename($staging, $target);
        if ($old) {
            self::rrmdir($old);
        }
        $db = App::db();
        $exists = $db->one('SELECT * FROM installed_modules WHERE slug = ?', [$slug]);
        $row = ['name' => mb_substr((string) $m['name'], 0, 150), 'version' => $version, 'author' => mb_substr((string) ($m['author'] ?? ''), 0, 150) ?: null, 'signed' => $signed ? 1 : 0, 'manifest' => json_encode($m), 'updated_at' => DB::now()];
        if ($exists) {
            $db->update('installed_modules', $row, ['slug' => $slug]);
        } else {
            $db->insert('installed_modules', $row + ['slug' => $slug, 'enabled' => 0, 'created_at' => DB::now()]);
        }
        if (is_file($target . '/install.php')) {
            $fn = require $target . '/install.php';
            if (is_callable($fn)) {
                $fn($db);
            }
        }
        Audit::log($exists ? 'module.upgraded' : 'module.installed', null, null, ['slug' => $slug, 'version' => $version, 'signed' => $signed]);
        return ['slug' => $slug, 'name' => $m['name'], 'version' => $version, 'signed' => $signed, 'upgraded' => (bool) $exists];
    }

    public static function setEnabled(string $slug, bool $on): void
    {
        $row = App::db()->one('SELECT * FROM installed_modules WHERE slug = ?', [$slug]);
        if (!$row) {
            throw new HttpException(404);
        }
        if ($on && !$row['signed'] && !self::developerMode()) {
            throw new HttpException(422, 'Unsigned modules can only be enabled in Developer mode.');
        }
        App::db()->update('installed_modules', ['enabled' => $on ? 1 : 0, 'updated_at' => DB::now()], ['slug' => $slug]);
        Audit::log($on ? 'module.enabled' : 'module.disabled', null, null, ['slug' => $slug]);
    }

    public static function uninstall(string $slug): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,39}$/', $slug)) {
            throw new HttpException(404);
        }
        $dir = self::DIR . '/' . $slug;
        if (is_file($dir . '/uninstall.php')) {
            try {
                $fn = require $dir . '/uninstall.php';
                if (is_callable($fn)) {
                    $fn(App::db());
                }
            } catch (\Throwable $e) {
                \App\Core\ErrorHandler::report($e);
            }
        }
        if (is_dir($dir)) {
            self::rrmdir($dir);
        }
        App::db()->delete('installed_modules', ['slug' => $slug]);
        Audit::log('module.uninstalled', null, null, ['slug' => $slug]);
    }

    /** Marketplace catalog (JSON list of signed packages). */
    public static function catalog(): array
    {
        $url = (string) (App::manifest()['marketplace']['catalog'] ?? '');
        if ($url === '') {
            return ['ok' => false, 'message' => 'No marketplace configured.', 'modules' => []];
        }
        $res = (new HttpClient(false, 15))->request('GET', $url . '?v=' . rawurlencode(App::version()), ['Accept: application/json']);
        $data = json_decode((string) $res['body'], true);
        if ($res['error'] || $res['status'] !== 200 || !is_array($data)) {
            return ['ok' => false, 'message' => 'The marketplace could not be reached right now.', 'modules' => []];
        }
        $mods = array_values(array_filter((array) ($data['modules'] ?? []), fn ($x) => is_array($x) && !empty($x['slug']) && !empty($x['download_url']) && !empty($x['sha256']) && !empty($x['signature'])));
        return ['ok' => true, 'message' => '', 'modules' => $mods];
    }

    public static function installFromCatalog(string $slug): array
    {
        $item = null;
        foreach (self::catalog()['modules'] as $x) {
            if ($x['slug'] === $slug) {
                $item = $x;
            }
        }
        if (!$item || !preg_match('#^https://#', (string) $item['download_url'])) {
            throw new HttpException(404, 'Module not found in the marketplace.');
        }
        $res = (new HttpClient(false, 120, self::MAX_ZIP))->requestFollowing('GET', (string) $item['download_url']);
        if ($res['error'] || $res['status'] !== 200) {
            throw new HttpException(502, 'Download failed.');
        }
        $tmp = NX_ROOT . '/storage/tmp/module-' . bin2hex(random_bytes(6)) . '.zip';
        file_put_contents($tmp, $res['body']);
        try {
            if (!hash_equals(strtolower((string) $item['sha256']), hash_file('sha256', $tmp))) {
                throw new HttpException(422, 'Checksum mismatch — the module was not installed.');
            }
            return self::install($tmp, (string) $item['signature']);
        } finally {
            @unlink($tmp);
        }
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir) || !str_starts_with(realpath($dir) ?: '', realpath(self::DIR) ?: "\0")) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
