<?php
declare(strict_types=1);

namespace App\Core\Storage;

defined('NX_ROOT') || exit;

/**
 * S3 driver with a self-contained AWS Signature Version 4 implementation.
 * Works with Amazon S3, Wasabi, Cloudflare R2, DigitalOcean Spaces, Backblaze B2 (S3 API), MinIO
 * and other S3-compatible services. Requires only the curl extension.
 */
final class S3Driver implements StorageDriver
{
    private string $key;
    private string $secret;
    private string $region;
    private string $bucket;
    private string $prefix;
    private string $endpoint;
    private bool $pathStyle;

    public function __construct(array $c)
    {
        $this->key = trim((string) ($c['key'] ?? ''));
        $this->secret = trim((string) ($c['secret'] ?? ''));
        $this->region = trim((string) ($c['region'] ?? '')) ?: 'us-east-1';
        $this->bucket = trim((string) ($c['bucket'] ?? ''));
        $this->prefix = trim((string) ($c['prefix'] ?? ''), '/');
        $this->endpoint = rtrim(trim((string) ($c['endpoint'] ?? '')), '/');
        $this->pathStyle = in_array($c['path_style'] ?? false, [true, 1, '1', 'on', 'true'], true);
        if ($this->endpoint !== '' && !preg_match('#^https?://#i', $this->endpoint)) {
            $this->endpoint = 'https://' . $this->endpoint;
        }
    }

    private function objectKey(string $key): string
    {
        StorageManager::assertSafeKey($key);
        return StorageManager::joinPath($this->prefix, $key);
    }

    /** @return array{0:string,1:string} [url, host] */
    private function target(string $objectKey): array
    {
        $path = '/' . implode('/', array_map('rawurlencode', explode('/', $objectKey)));
        if ($this->endpoint === '') {
            $host = $this->bucket . '.s3.' . $this->region . '.amazonaws.com';
            return ['https://' . $host . $path, $host, $path];
        }
        $p = parse_url($this->endpoint);
        $scheme = $p['scheme'] ?? 'https';
        $hostOnly = $p['host'] ?? '';
        $port = isset($p['port']) ? ':' . $p['port'] : '';
        if ($this->pathStyle) {
            $path = '/' . rawurlencode($this->bucket) . $path;
            return [$scheme . '://' . $hostOnly . $port . $path, $hostOnly . $port, $path];
        }
        $host = $this->bucket . '.' . $hostOnly . $port;
        return [$scheme . '://' . $host . $path, $host, $path];
    }

    private function signingKey(string $date): string
    {
        $k = hash_hmac('sha256', $date, 'AWS4' . $this->secret, true);
        $k = hash_hmac('sha256', $this->region, $k, true);
        $k = hash_hmac('sha256', 's3', $k, true);
        return hash_hmac('sha256', 'aws4_request', $k, true);
    }

    private function signedHeaders(string $method, string $path, string $host, string $payloadHash, array $extra = [], ?string $amzDate = null): array
    {
        $amzDate ??= gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $headers = array_change_key_case($extra, CASE_LOWER) + ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $amzDate];
        ksort($headers);
        $canonHeaders = '';
        foreach ($headers as $k => $v) {
            $canonHeaders .= $k . ':' . trim((string) $v) . "\n";
        }
        $signed = implode(';', array_keys($headers));
        $canonical = "$method\n$path\n\n$canonHeaders\n$signed\n$payloadHash";
        $scope = "$date/{$this->region}/s3/aws4_request";
        $sts = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
        $sig = hash_hmac('sha256', $sts, $this->signingKey($date));
        $out = [];
        foreach ($headers as $k => $v) {
            if ($k !== 'host') {
                $out[] = $k . ': ' . $v;
            }
        }
        $out[] = "Authorization: AWS4-HMAC-SHA256 Credential={$this->key}/$scope, SignedHeaders=$signed, Signature=$sig";
        return $out;
    }

    /** @return array{0:int,1:string} status, error body */
    private function send(string $method, string $objectKey, ?string $file = null, string $mime = 'application/octet-stream', $sink = null): array
    {
        [$url, $host, $path] = $this->target($objectKey);
        $payloadHash = $file !== null ? hash_file('sha256', $file) : hash('sha256', '');
        $extra = $file !== null ? ['content-type' => $mime] : [];
        $headers = $this->signedHeaders($method, $path, $host, $payloadHash, $extra);
        if ($file !== null) {
            $headers[] = 'Content-Length: ' . filesize($file);
        }
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ];
        $errBody = '';
        $fh = null;
        if ($file !== null) {
            $fh = fopen($file, 'rb');
            $opts[CURLOPT_UPLOAD] = true;
            $opts[CURLOPT_INFILE] = $fh;
            $opts[CURLOPT_INFILESIZE] = filesize($file);
            $opts[CURLOPT_RETURNTRANSFER] = true;
        } elseif ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
            $opts[CURLOPT_RETURNTRANSFER] = true;
        } elseif ($sink !== null) {
            $opts[CURLOPT_FILE] = $sink;
        } else {
            $opts[CURLOPT_RETURNTRANSFER] = true;
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($fh) {
            fclose($fh);
        }
        if ($res === false) {
            return [0, $curlErr ?: 'Connection failed'];
        }
        if (is_string($res)) {
            $errBody = $res;
        }
        return [$status, $errBody];
    }

    private static function errorMessage(int $status, string $body): string
    {
        if (preg_match('#<Code>([^<]+)</Code>.*?<Message>([^<]*)</Message>#s', $body, $m)) {
            return "S3 error $status: {$m[1]} — {$m[2]}";
        }
        return "S3 request failed (HTTP $status)" . ($status === 0 && $body ? ': ' . $body : '');
    }

    public function putFile(string $key, string $localPath, string $mime): void
    {
        [$s, $b] = $this->send('PUT', $this->objectKey($key), $localPath, $mime);
        if ($s < 200 || $s >= 300) {
            throw new \RuntimeException(self::errorMessage($s, $b));
        }
    }

    public function readStream(string $key)
    {
        $sink = fopen('php://temp/maxmemory:4194304', 'w+b');
        [$s, $b] = $this->send('GET', $this->objectKey($key), null, '', $sink);
        if ($s < 200 || $s >= 300) {
            fclose($sink);
            throw new \RuntimeException(self::errorMessage($s, $b));
        }
        rewind($sink);
        return $sink;
    }

    public function delete(string $key): void
    {
        $this->send('DELETE', $this->objectKey($key));
    }

    public function exists(string $key): bool
    {
        [$s] = $this->send('HEAD', $this->objectKey($key));
        return $s === 200;
    }

    public function temporaryUrl(string $key, int $ttl, string $filename, string $mime): ?string
    {
        [$url, $host, $path] = $this->target($this->objectKey($key));
        $amzDate = gmdate('Ymd\THis\Z');
        $date = substr($amzDate, 0, 8);
        $scope = "$date/{$this->region}/s3/aws4_request";
        $q = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->key . '/' . $scope,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) max(60, min(604800, $ttl)),
            'X-Amz-SignedHeaders' => 'host',
            'response-content-disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) . '"',
            'response-content-type' => $mime,
        ];
        ksort($q);
        $qs = implode('&', array_map(fn ($k, $v) => rawurlencode($k) . '=' . rawurlencode($v), array_keys($q), $q));
        $canonical = "GET\n$path\n$qs\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
        $sts = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
        $sig = hash_hmac('sha256', $sts, $this->signingKey($date));
        return strtok($url, '?') . '?' . $qs . '&X-Amz-Signature=' . $sig;
    }

    public function test(): array
    {
        if ($this->key === '' || $this->secret === '' || $this->bucket === '') {
            return [false, 'Access key, secret and bucket are required.'];
        }
        $key = '_nx_test/' . bin2hex(random_bytes(6)) . '.txt';
        $tmp = tempnam(sys_get_temp_dir(), 'nxt');
        file_put_contents($tmp, 'nextacrm-storage-test');
        try {
            $this->putFile($key, $tmp, 'text/plain');
            $data = stream_get_contents($this->readStream($key));
            $this->delete($key);
            return $data === 'nextacrm-storage-test' ? [true, 'Connected. Upload, download and delete succeeded.'] : [false, 'Uploaded but read-back returned different content.'];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        } finally {
            @unlink($tmp);
        }
    }

    /** Exposed for unit tests of the signer. */
    public function debugSign(string $method, string $path, string $host, string $hash, array $extra = [], ?string $amzDate = null): array
    {
        return $this->signedHeaders($method, $path, $host, $hash, $extra, $amzDate);
    }
}
