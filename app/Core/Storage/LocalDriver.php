<?php
declare(strict_types=1);

namespace App\Core\Storage;

defined('NX_ROOT') || exit;

final class LocalDriver implements StorageDriver
{
    public function __construct(private string $root)
    {
        if (!is_dir($root)) {
            @mkdir($root, 0750, true);
        }
        $ht = $root . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
    }

    private function path(string $key): string
    {
        StorageManager::assertSafeKey($key);
        $path = $this->root . '/' . $key;
        $realRoot = realpath($this->root);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $realDir = realpath($dir);
        if ($realRoot === false || $realDir === false || !str_starts_with($realDir . '/', rtrim($realRoot, '/') . '/')) {
            throw new \RuntimeException('Path escapes storage root');
        }
        return $path;
    }

    public function putFile(string $key, string $localPath, string $mime): void
    {
        $dest = $this->path($key);
        if (!@copy($localPath, $dest)) {
            throw new \RuntimeException('Could not write file to local storage. Check that storage/uploads is writable.');
        }
        @chmod($dest, 0640);
    }

    public function readStream(string $key)
    {
        $p = $this->path($key);
        $h = @fopen($p, 'rb');
        if ($h === false) {
            throw new \RuntimeException('File not found in local storage');
        }
        return $h;
    }

    public function delete(string $key): void
    {
        $p = $this->path($key);
        if (is_file($p)) {
            @unlink($p);
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function temporaryUrl(string $key, int $ttl, string $filename, string $mime): ?string
    {
        return null;
    }

    public function test(): array
    {
        $key = '_nx_test/' . bin2hex(random_bytes(6)) . '.txt';
        $tmp = tempnam(sys_get_temp_dir(), 'nxt');
        file_put_contents($tmp, 'ok');
        try {
            $this->putFile($key, $tmp, 'text/plain');
            $ok = stream_get_contents($this->readStream($key)) === 'ok';
            $this->delete($key);
            @rmdir($this->root . '/_nx_test');
            return [$ok, $ok ? 'Local storage is writable.' : 'Read-back failed.'];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        } finally {
            @unlink($tmp);
        }
    }
}
