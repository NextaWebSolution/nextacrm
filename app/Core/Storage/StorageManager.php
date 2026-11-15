<?php
declare(strict_types=1);

namespace App\Core\Storage;

defined('NX_ROOT') || exit;

use App\Core\Settings;

/**
 * Provider-independent storage. Each file row records the driver it was written with,
 * so switching the default driver never breaks existing files.
 */
final class StorageManager
{
    public const DRIVERS = [
        'local' => 'Local server (default)',
        's3' => 'Amazon S3',
        'wasabi' => 'Wasabi',
        's3c' => 'S3-compatible (Cloudflare R2, DigitalOcean Spaces, MinIO, Backblaze B2 …)',
        'ftp' => 'FTP / FTPS',
        'sftp' => 'SFTP',
    ];

    private static array $instances = [];

    public static function defaultDriver(): string
    {
        $d = (string) Settings::get('storage.driver', 'local');
        return array_key_exists($d, self::DRIVERS) ? $d : 'local';
    }

    public static function disk(?string $name = null, ?array $override = null): StorageDriver
    {
        $name ??= self::defaultDriver();
        if ($override === null && isset(self::$instances[$name])) {
            return self::$instances[$name];
        }
        $cfg = $override ?? self::config($name);
        $driver = match ($name) {
            'local' => new LocalDriver(NX_ROOT . '/storage/uploads'),
            's3' => new S3Driver($cfg + ['endpoint' => '', 'path_style' => false]),
            'wasabi' => new S3Driver(['endpoint' => 'https://s3.' . ($cfg['region'] ?: 'us-east-1') . '.wasabisys.com', 'path_style' => false] + $cfg),
            's3c' => new S3Driver($cfg),
            'ftp' => new FtpDriver($cfg),
            'sftp' => new SftpDriver($cfg),
            default => throw new \InvalidArgumentException('Unknown storage driver'),
        };
        if ($override === null) {
            self::$instances[$name] = $driver;
        }
        return $driver;
    }

    /** Read driver settings (secrets are decrypted by Settings::get). */
    public static function config(string $name): array
    {
        $keys = match ($name) {
            's3', 'wasabi' => ['key', 'secret', 'region', 'bucket', 'prefix'],
            's3c' => ['key', 'secret', 'region', 'bucket', 'prefix', 'endpoint', 'path_style'],
            'ftp' => ['host', 'port', 'username', 'password', 'root', 'ssl', 'passive'],
            'sftp' => ['host', 'port', 'username', 'password', 'private_key', 'root'],
            default => [],
        };
        $cfg = [];
        foreach ($keys as $k) {
            $cfg[$k] = Settings::get("storage.$name.$k", '');
        }
        return $cfg;
    }

    public static function fields(string $name): array
    {
        return match ($name) {
            's3' => ['key' => 'Access key ID', 'secret' => 'Secret access key', 'region' => 'Region (e.g. ap-south-1)', 'bucket' => 'Bucket', 'prefix' => 'Path prefix (optional)'],
            'wasabi' => ['key' => 'Access key', 'secret' => 'Secret key', 'region' => 'Region (e.g. ap-southeast-1)', 'bucket' => 'Bucket', 'prefix' => 'Path prefix (optional)'],
            's3c' => ['endpoint' => 'Endpoint URL (https://…)', 'key' => 'Access key', 'secret' => 'Secret key', 'region' => 'Region (use "auto" for R2)', 'bucket' => 'Bucket', 'prefix' => 'Path prefix (optional)', 'path_style' => 'Path-style URLs (MinIO)'],
            'ftp' => ['host' => 'Host', 'port' => 'Port (21)', 'username' => 'Username', 'password' => 'Password', 'root' => 'Root directory', 'ssl' => 'Use explicit FTPS (TLS)', 'passive' => 'Passive mode'],
            'sftp' => ['host' => 'Host', 'port' => 'Port (22)', 'username' => 'Username', 'password' => 'Password', 'private_key' => 'Private key (PEM/OpenSSH, optional)', 'root' => 'Root directory'],
            default => [],
        };
    }

    public static function secretFields(): array
    {
        return ['secret', 'password', 'private_key'];
    }

    /**
     * Keys are generated server-side; this is defence in depth against traversal.
     */
    public static function assertSafeKey(string $key): void
    {
        if ($key === '' || strlen($key) > 450 || str_contains($key, "\0") || str_starts_with($key, '/') || str_contains($key, '\\')
            || preg_match('#(^|/)\.\.?(/|$)#', $key) || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/\-]*$#', $key)) {
            throw new \InvalidArgumentException('Unsafe storage key');
        }
    }

    public static function joinPath(string $root, string $key): string
    {
        $root = trim($root, '/');
        return ($root === '' ? '' : $root . '/') . $key;
    }

    public static function reset(): void
    {
        self::$instances = [];
    }
}
