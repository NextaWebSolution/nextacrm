<?php
declare(strict_types=1);

namespace App\Core\Storage;

defined('NX_ROOT') || exit;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/** SFTP driver (pure PHP via phpseclib — no ssh2 extension needed). */
final class SftpDriver implements StorageDriver
{
    private ?SFTP $sftp = null;

    public function __construct(private array $c) {}

    private function connect(): SFTP
    {
        if ($this->sftp) {
            return $this->sftp;
        }
        if (!class_exists(SFTP::class)) {
            throw new \RuntimeException('phpseclib is missing from vendor/.');
        }
        $sftp = new SFTP((string) ($this->c['host'] ?? ''), (int) ($this->c['port'] ?? 0) ?: 22, 15);
        $user = (string) ($this->c['username'] ?? '');
        $keyText = trim((string) ($this->c['private_key'] ?? ''));
        $password = (string) ($this->c['password'] ?? '');
        if ($keyText !== '') {
            $key = PublicKeyLoader::load($keyText, $password !== '' ? $password : false);
            $ok = $sftp->login($user, $key);
        } else {
            $ok = $sftp->login($user, $password);
        }
        if (!$ok) {
            throw new \RuntimeException('SFTP login failed. Check the credentials.');
        }
        return $this->sftp = $sftp;
    }

    private function remote(string $key): string
    {
        StorageManager::assertSafeKey($key);
        $root = rtrim((string) ($this->c['root'] ?? ''), '/');
        return ($root === '' ? '.' : $root) . '/' . $key;
    }

    public function putFile(string $key, string $localPath, string $mime): void
    {
        $remote = $this->remote($key);
        $s = $this->connect();
        $s->mkdir(dirname($remote), -1, true);
        if (!$s->put($remote, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
            throw new \RuntimeException('SFTP upload failed. Check write permissions on the root directory.');
        }
    }

    public function readStream(string $key)
    {
        $sink = fopen('php://temp/maxmemory:4194304', 'w+b');
        $ok = $this->connect()->get($this->remote($key), $sink);
        if ($ok === false) {
            fclose($sink);
            throw new \RuntimeException('SFTP download failed.');
        }
        rewind($sink);
        return $sink;
    }

    public function delete(string $key): void
    {
        $this->connect()->delete($this->remote($key));
    }

    public function exists(string $key): bool
    {
        return $this->connect()->file_exists($this->remote($key));
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
            return [$ok, $ok ? 'Connected. Upload, download and delete succeeded.' : 'Read-back failed.'];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        } finally {
            @unlink($tmp);
        }
    }
}
