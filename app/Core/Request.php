<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

final class Request
{
    public string $method;
    public string $path;
    public string $basePath;
    public array $query;
    public array $post;
    public array $files;
    public array $server;
    public array $params = [];
    private ?array $json = null;
    public array $attributes = [];

    public function __construct(array $server, array $get, array $post, array $files, private string $rawBody = '')
    {
        $this->server = $server;
        $this->query = $get;
        $this->post = $post;
        $this->files = $files;
        $method = strtoupper($server['REQUEST_METHOD'] ?? 'GET');
        if ($method === 'POST' && isset($post['_method']) && in_array(strtoupper((string) $post['_method']), ['PUT', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper((string) $post['_method']);
        }
        $this->method = $method;

        $script = str_replace('\\', '/', $server['SCRIPT_NAME'] ?? '/index.php');
        $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $this->basePath = $base === '.' ? '' : $base;

        $uri = (string) parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri = rawurldecode($uri);
        if ($this->basePath !== '' && str_starts_with($uri, $this->basePath)) {
            $uri = substr($uri, strlen($this->basePath));
        }
        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, 10);
        }
        $uri = '/' . trim($uri, '/');
        $this->path = $uri;
    }

    public static function capture(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_FILES, (string) file_get_contents('php://input'));
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->json());
    }

    public function str(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $v = $this->input($key);
        return is_numeric($v) ? (int) $v : $default;
    }

    public function json(): array
    {
        if ($this->json === null) {
            $this->json = [];
            $ct = $this->server['CONTENT_TYPE'] ?? $this->server['HTTP_CONTENT_TYPE'] ?? '';
            if (str_contains($ct, 'application/json') && $this->rawBody !== '') {
                $d = json_decode($this->rawBody, true, 64);
                $this->json = is_array($d) ? $d : [];
            }
        }
        return $this->json;
    }

    public function raw(): string
    {
        return $this->rawBody;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'Authorization') {
            return $this->server['HTTP_AUTHORIZATION'] ?? $this->server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        }
        return $this->server[$key] ?? null;
    }

    public function file(string $key): ?array
    {
        $f = $this->files[$key] ?? null;
        if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) {
            return null;
        }
        return $f;
    }

    public function ip(): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        $trusted = (array) App::config('trusted_proxies', []);
        if ($trusted && in_array($ip, $trusted, true)) {
            // Walk X-Forwarded-For from the right and take the first address that is not one of our proxies.
            // The left-most entry is supplied by the client and must never be trusted (rate-limit/lockout bypass).
            $hops = array_reverse(array_filter(array_map('trim', explode(',', (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? '')))));
            foreach ($hops as $hop) {
                if (!filter_var($hop, FILTER_VALIDATE_IP)) {
                    break;
                }
                if (!in_array($hop, $trusted, true)) {
                    return $hop;
                }
            }
        }
        return $ip;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }
        $trusted = (array) App::config('trusted_proxies', []);
        return in_array($this->server['REMOTE_ADDR'] ?? '', $trusted, true)
            && strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function wantsJson(): bool
    {
        return str_contains((string) ($this->server['HTTP_ACCEPT'] ?? ''), 'application/json')
            || str_starts_with($this->path, '/api/')
            || str_starts_with($this->path, '/x/')
            || ($this->server['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    public function host(): string
    {
        return (string) ($this->server['HTTP_HOST'] ?? 'localhost');
    }

    public function baseUrl(): string
    {
        $configured = (string) App::config('url', '');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        return ($this->isSecure() ? 'https' : 'http') . '://' . $this->host() . $this->basePath;
    }
}
