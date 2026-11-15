<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * PHP template renderer. Templates live in app/Views and must escape output with e().
 */
final class View
{
    private static array $sections = [];
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    public static function render(string $template, array $data = [], ?string $layout = 'app'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }
        return self::partial('layouts/' . $layout, $data + ['content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        if (!preg_match('#^[a-z0-9_\-/]+$#', $template)) {
            throw new \InvalidArgumentException('Invalid template name');
        }
        $file = NX_ROOT . '/app/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        $__data = self::$shared + $data;
        ob_start();
        try {
            (static function (string $__file, array $__vars): void {
                extract($__vars, EXTR_SKIP);
                require $__file;
            })($file, $__data);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function start(string $name): void
    {
        self::$sections[$name] = '';
        ob_start();
    }

    public static function end(string $name): void
    {
        self::$sections[$name] = (string) ob_get_clean();
    }

    public static function section(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }
}
