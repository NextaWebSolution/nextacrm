<?php
declare(strict_types=1);

namespace App\Core\Storage;

defined('NX_ROOT') || exit;

/** FTP / explicit FTPS driver using PHP's ftp extension. */
final class FtpDriver implements StorageDriver
{
    /** @var \FTP\Connection|resource|null */
    private $conn = null;

    public function __construct(private array $c) {}

    private function connect()
    {
        if ($this->conn) {
            return $this->conn;
        }
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('The PHP ftp extension is not installed on this server.');
        }
        $host = (string) ($this->c['host'] ?? '');
        $port = (int) ($this->c['port'] ?? 0) ?: 21;
        $ssl = in_array($this->c['ssl'] ?? false, [true, 1, '1', 'on'], true);
        $conn = $ssl ? @ftp_ssl_connect($host, $port, 15) : @ftp_connect($host, $port, 15);
        if (!$conn) {
            throw new \RuntimeException("Could not connect to FTP server $host:$port.");
        }
        if (!@ftp_login($conn, (string) ($this->c['username'] ?? ''), (string) ($this->c['password'] ?? ''))) {
            throw new \RuntimeException('FTP login failed. Check the username and password.');
        }
        $passive = ($this->c['passive'] ?? '1') === '' ? true : in_array($this->c['passive'], [true, 1, '1', 'on'], true);
        @ftp_pasv($conn, $passive);
        return $this->conn = $conn;
    }

    private function remote(string $key): string
    {
        StorageManager::assertSafeKey($key);
        $root = '/' . trim((string) ($this->c['root'] ?? ''), '/');
        return rtrim($root, '/') . '/' . $key;
    }

    private function mkdirs(string $dir): void
    {
        $conn = $this->connect();
        $parts = array_filter(explode('/', trim($dir, '/')));
        $path = '';
        foreach ($parts as $p) {
            $path .= '/' . $p;
            if (!@ftp_chdir($conn, $path)) {
                @ftp_mkdir($conn, $path);
            }
        }
        @ftp_chdir($conn, '/');
    }

    public function putFile(string $key, string $localPath, string $mime): void
    {
        $remote = $this->remote($key);
        $this->mkdirs(dirname($remote));
        if (!@ftp_put($this->connect(), $remote, $localPath, FTP_BINARY)) {
            throw new \RuntimeException('FTP upload failed. Check write permissions on the root directory.');
        }
    }

    public function readStream(string $key)
    {
        $sink = fopen('php://temp/maxmemory:4194304', 'w+b');
        if (!@ftp_fget($this->connect(), $sink, $this->remote($key), FTP_BINARY)) {
            fclose($sink);
            throw new \RuntimeException('FTP download failed.');
        }
        rewind($sink);
        return $sink;
    }

    public function delete(string $key): void
    {
        @ftp_delete($this->connect(), $this->remote($key));
    }

    public function exists(string $key): bool
    {
        return @ftp_size($this->connect(), $this->remote($key)) >= 0;
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

    public function __destruct()
    {
        if ($this->conn) {
            @ftp_close($this->conn);
        }
    }
}
