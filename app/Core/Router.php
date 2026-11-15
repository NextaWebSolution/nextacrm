<?php
declare(strict_types=1);

namespace App\Core;

defined('NX_ROOT') || exit;

/**
 * Minimal router with per-route middleware.
 * Middleware names: auth, guest, csrf, admin, api, perm:<module>.<action>
 */
final class Router
{
    private array $routes = [];
    private array $groupStack = [];

    public function get(string $p, array|callable $h, array $mw = []): void { $this->add(['GET'], $p, $h, $mw); }
    public function post(string $p, array|callable $h, array $mw = []): void { $this->add(['POST'], $p, $h, $mw); }
    public function put(string $p, array|callable $h, array $mw = []): void { $this->add(['PUT', 'PATCH'], $p, $h, $mw); }
    public function patch(string $p, array|callable $h, array $mw = []): void { $this->add(['PATCH'], $p, $h, $mw); }
    public function delete(string $p, array|callable $h, array $mw = []): void { $this->add(['DELETE'], $p, $h, $mw); }
    public function any(string $p, array|callable $h, array $mw = []): void { $this->add(['GET', 'POST'], $p, $h, $mw); }

    public function group(array $middleware, callable $fn, string $prefix = ''): void
    {
        $this->groupStack[] = ['mw' => $middleware, 'prefix' => $prefix];
        $fn($this);
        array_pop($this->groupStack);
    }

    public function add(array $methods, string $path, array|callable $handler, array $mw = []): void
    {
        $prefix = '';
        $groupMw = [];
        foreach ($this->groupStack as $g) {
            $prefix .= $g['prefix'];
            $groupMw = array_merge($groupMw, $g['mw']);
        }
        $full = rtrim($prefix . $path, '/') ?: '/';
        $regex = '#^' . preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[A-Za-z0-9_\-]+)', $full) . '$#';
        $this->routes[] = ['methods' => $methods, 'regex' => $regex, 'handler' => $handler, 'mw' => array_merge($groupMw, $mw)];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if (!in_array($request->method, $route['methods'], true) && !($request->method === 'HEAD' && in_array('GET', $route['methods'], true))) {
                $allowed = true;
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            Middleware::run($route['mw'], $request);
            $h = $route['handler'];
            if (is_array($h)) {
                [$class, $method] = $h;
                $controller = new $class();
                $result = $controller->$method($request, ...array_values($request->params));
            } else {
                $result = $h($request, ...array_values($request->params));
            }
            if ($result instanceof Response) {
                return $result;
            }
            return is_array($result) ? Response::json($result) : Response::html((string) $result);
        }
        throw new HttpException($allowed ? 405 : 404);
    }
}
