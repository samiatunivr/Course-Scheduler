<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimal router with {param} placeholders and per-route middleware
 * (permission codes resolved via Auth/RBAC).
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, handler:callable, permission:?string}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler, ?string $permission = null): void
    {
        $regex = '#^' . preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', rtrim($pattern, '/') ?: '/') . '$#';
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'handler' => $handler,
            'permission' => $permission,
        ];
    }

    public function get(string $p, callable $h, ?string $perm = null): void { $this->add('GET', $p, $h, $perm); }
    public function post(string $p, callable $h, ?string $perm = null): void { $this->add('POST', $p, $h, $perm); }
    public function put(string $p, callable $h, ?string $perm = null): void { $this->add('PUT', $p, $h, $perm); }
    public function delete(string $p, callable $h, ?string $perm = null): void { $this->add('DELETE', $p, $h, $perm); }

    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (!preg_match($route['regex'], rtrim($request->path, '/') ?: '/', $matches)) {
                continue;
            }
            $request->params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            if ($route['permission'] !== null) {
                $user = Auth::user();
                if ($user === null) {
                    $request->wantsJson()
                        ? Response::error('Unauthenticated', 401)
                        : Response::redirect('/login');
                }
                if (!Auth::can($route['permission'])) {
                    $request->wantsJson()
                        ? Response::error('Forbidden: missing permission ' . $route['permission'], 403)
                        : Response::html(View::render('errors/403', ['permission' => $route['permission']], null), 403);
                }
            }

            ($route['handler'])($request);

            return;
        }

        $request->wantsJson()
            ? Response::error('Not found', 404)
            : Response::html(View::render('errors/404', [], null), 404);
    }
}
