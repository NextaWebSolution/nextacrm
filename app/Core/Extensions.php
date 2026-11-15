<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * Extension points used by built-in features (app/features.php) and by installed modules (modules/<slug>/module.php).
 *
 *  Extensions::nav()              sidebar link in a group (Sales, Delivery, Support, Finance, Team, Marketing…)
 *  Extensions::panel()            card on a record page (main column or sidebar)
 *  Extensions::routes()           register routes: fn (Router $r) => …
 *  Extensions::cron()             scheduled job (every run, or once a day)
 *  Extensions::settingsTab()      link in the Settings navigation
 *  Extensions::permission()       non-entity permission module shown in the role editor
 *  Extensions::workflowAction()   extra workflow action type
 *  Extensions::entityDir()        directory with additional entity definition files
 */
final class Extensions
{
    private static bool $booted = false;
    private static array $nav = [];
    private static array $panels = [];
    private static array $routes = [];
    private static array $cron = [];
    private static array $settings = [];
    private static array $perms = [];
    private static array $actions = [];
    private static array $entityDirs = [];
    private static array $viewDirs = [];

    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        require NX_ROOT . '/app/features.php';
        \App\Services\ModuleManager::bootEnabled();
    }

    // ── Registration ──
    public static function nav(string $group, string $label, string $path, string $icon, ?string $module = null, string $action = 'view', int $order = 50): void
    {
        self::$nav[] = compact('group', 'label', 'path', 'icon', 'module', 'action', 'order');
    }

    public static function panel(string $entity, string $position, callable $render, int $order = 50): void
    {
        self::$panels[$entity][$position][] = ['render' => $render, 'order' => $order];
    }

    public static function routes(callable $fn): void
    {
        self::$routes[] = $fn;
    }

    public static function cron(string $name, callable $fn, bool $daily = false, bool $perCompany = false): void
    {
        self::$cron[$name] = compact('fn', 'daily', 'perCompany');
    }

    public static function settingsTab(string $group, string $key, string $path, string $label, string $icon): void
    {
        self::$settings[$group][$key] = [$path, $label, $icon];
    }

    public static function permission(string $key, string $label): void
    {
        self::$perms[$key] = $label;
    }

    public static function workflowAction(string $type, string $label, callable $run): void
    {
        self::$actions[$type] = ['label' => $label, 'run' => $run];
    }

    public static function entityDir(string $dir): void
    {
        self::$entityDirs[] = $dir;
    }

    public static function viewDir(string $prefix, string $dir): void
    {
        self::$viewDirs[$prefix] = $dir;
    }

    // ── Consumption ──
    /** Sidebar items visible to the current user, grouped. */
    public static function navItems(): array
    {
        $out = [];
        $items = self::$nav;
        usort($items, fn ($a, $b) => $a['order'] <=> $b['order']);
        foreach ($items as $it) {
            if ($it['module'] !== null && (!can($it['module'], $it['action']) || !module_enabled($it['module']))) {
                continue;
            }
            $out[$it['group']][] = ['key' => trim($it['path'], '/'), 'label' => $it['label'], 'icon' => $it['icon'], 'url' => url($it['path']), 'match' => $it['path']];
        }
        return $out;
    }

    public static function renderPanels(string $entity, string $position, array $record, $svc): string
    {
        $list = array_merge(self::$panels[$entity][$position] ?? [], self::$panels['*'][$position] ?? []);
        usort($list, fn ($a, $b) => $a['order'] <=> $b['order']);
        $html = '';
        foreach ($list as $p) {
            try {
                $html .= (string) ($p['render'])($record, $svc, $entity);
            } catch (HttpException $e) {
                // A panel the user may not see simply doesn't render.
            } catch (\Throwable $e) {
                ErrorHandler::report($e);
            }
        }
        return $html;
    }

    public static function registerRoutes(Router $router): void
    {
        foreach (self::$routes as $fn) {
            $fn($router);
        }
    }

    public static function cronJobs(): array
    {
        return self::$cron;
    }

    public static function settingsTabs(): array
    {
        return self::$settings;
    }

    public static function permissions(): array
    {
        return self::$perms;
    }

    public static function workflowActions(): array
    {
        return self::$actions;
    }

    public static function entityDirs(): array
    {
        return self::$entityDirs;
    }

    public static function viewPath(string $template): ?string
    {
        foreach (self::$viewDirs as $prefix => $dir) {
            if (str_starts_with($template, $prefix . '/')) {
                $rel = substr($template, strlen($prefix) + 1);
                if (preg_match('#^[a-z0-9_/\-]+$#i', $rel) && !str_contains($rel, '..')) {
                    return $dir . '/' . $rel . '.php';
                }
            }
        }
        return null;
    }
}
