<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

final class Response
{
    public array $headers = [];
    /** @var null|callable */
    private $streamer = null;

    public function __construct(public string $body = '', public int $status = 200, array $headers = [])
    {
        $this->headers = $headers + ['Content-Type' => 'text/html; charset=UTF-8'];
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return new self((string) $body, $status, ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public static function view(string $template, array $data = [], int $status = 200, ?string $layout = 'app'): self
    {
        return new self(View::render($template, $data, $layout), $status);
    }

    /**
     * Redirect to an internal path only. External or protocol-relative targets are rejected
     * to prevent open redirects.
     */
    public static function redirect(string $path, int $status = 302): self
    {
        if (!self::isSafePath($path)) {
            $path = '/';
        }
        $r = new self('', $status);
        $r->headers['Location'] = url($path);
        return $r;
    }

    public static function isSafePath(string $path): bool
    {
        return $path !== '' && $path[0] === '/' && !str_starts_with($path, '//') && !str_starts_with($path, '/\\')
            && !preg_match('/[\r\n\x00]/', $path);
    }

    public static function back(string $fallback = '/'): self
    {
        $ref = (string) (App::request()->server['HTTP_REFERER'] ?? '');
        $base = App::request()->baseUrl();
        if ($ref !== '' && str_starts_with($ref, $base . '/')) {
            $path = substr($ref, strlen($base));
            if (self::isSafePath($path)) {
                $r = new self('', 302);
                $r->headers['Location'] = $ref;
                return $r;
            }
        }
        return self::redirect($fallback);
    }

    public static function download(string $content, string $filename, string $mime = 'application/octet-stream', bool $inline = false): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: 'download';
        return new self($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Stream a resource (file storage) without loading it into memory. */
    public static function stream($resource, string $filename, string $mime, ?int $size, bool $inline = false): self
    {
        $safe = preg_replace('/[^A-Za-z0-9._ -]/', '_', $filename) ?: 'download';
        $r = new self('', 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"; filename*=UTF-8\'\'' . rawurlencode($filename),
            'Cache-Control' => 'private, no-store',
        ]);
        if ($size !== null) {
            $r->headers['Content-Length'] = (string) $size;
        }
        $r->streamer = static function () use ($resource): void {
            while (!feof($resource)) {
                echo fread($resource, 65536);
                flush();
            }
            fclose($resource);
        };
        return $r;
    }

    public function header(string $k, string $v): self
    {
        $this->headers[$k] = $v;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach (SecurityHeaders::forResponse($this) as $k => $v) {
                header($k . ': ' . $v);
            }
            foreach ($this->headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        if ($this->streamer) {
            ($this->streamer)();
            return;
        }
        echo $this->body;
    }
}
