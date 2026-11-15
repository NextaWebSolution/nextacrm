<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

final class ErrorHandler
{
    public static function register(): void
    {
        set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::log('FATAL ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
                if (!headers_sent()) {
                    http_response_code(500);
                    echo self::plainErrorPage();
                }
            }
        });
    }

    public static function report(\Throwable $e): void
    {
        $msg = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
        if (App::config('debug')) {
            $msg .= "\n" . $e->getTraceAsString();
        }
        self::log($msg);
    }

    public static function log(string $message, string $channel = 'app'): void
    {
        $dir = NX_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // Never log secrets that may appear in exception messages (DSNs, passwords).
        $message = preg_replace('/(password|passwd|secret|token|key)=([^;\s&]+)/i', '$1=***', $message) ?? $message;
        $line = '[' . gmdate('Y-m-d H:i:s') . '] [' . App::$requestId . '] ' . $message . PHP_EOL;
        @file_put_contents($dir . '/' . $channel . '-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    public static function renderHttp(HttpException $e, Request $r): Response
    {
        if ($e instanceof RedirectException) {
            return Response::redirect($e->to);
        }
        if ($r->wantsJson()) {
            $body = ['error' => ['code' => $e->status, 'message' => $e->getMessage()]];
            if ($e->errors) {
                $body['error']['fields'] = $e->errors;
            }
            $resp = Response::json($body, $e->status);
            if ($e->status === 429) {
                $resp->header('Retry-After', '60');
            }
            return $resp;
        }
        if ($e->status === 419) {
            Session::flash('error', $e->getMessage());
            return Response::back();
        }
        try {
            $layout = Auth::check() ? 'app' : 'guest';
            return Response::view('errors/http', ['status' => $e->status, 'message' => $e->getMessage()], $e->status, $layout);
        } catch (\Throwable) {
            return Response::html(self::plainErrorPage($e->status, $e->getMessage()), $e->status);
        }
    }

    public static function renderFatal(Request $r): Response
    {
        if ($r->wantsJson()) {
            return Response::json(['error' => ['code' => 500, 'message' => 'Unexpected server error. Reference: ' . App::$requestId]], 500);
        }
        return Response::html(self::plainErrorPage(500, 'Unexpected server error.'), 500);
    }

    public static function plainErrorPage(int $status = 500, string $message = 'Unexpected server error.'): string
    {
        $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $id = htmlspecialchars(App::$requestId, ENT_QUOTES, 'UTF-8');
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Error ' . $status . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;background:#f8fafc;color:#0f172a;display:grid;place-items:center;min-height:100vh;margin:0}'
            . '.c{max-width:420px;padding:32px;background:#fff;border:1px solid #e2e8f0;border-radius:12px}h1{font-size:18px;margin:0 0 8px}p{color:#475569;font-size:14px}code{font-size:12px;color:#64748b}</style>'
            . '</head><body><div class="c"><h1>Error ' . $status . '</h1><p>' . $m . '</p><code>Reference: ' . $id . '</code></div></body></html>';
    }
}
