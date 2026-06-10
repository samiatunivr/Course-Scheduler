<?php

declare(strict_types=1);

/** Front controller — all requests route through here. */

// Application code lives outside the web root in ../scheduler
$root = dirname(__DIR__) . '/scheduler';

// PSR-4 autoload: composer if available, tiny fallback otherwise.
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\')) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}
require_once $root . '/src/Core/View.php'; // for the e() helper

$config = require $root . '/config/config.php';
date_default_timezone_set($config['app']['timezone']);

if ($config['app']['debug']) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => str_starts_with($config['app']['url'], 'https'),
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;

View::setBasePath($root . '/src/Views');

try {
    Database::connect($config['database']);
} catch (\Throwable $e) {
    $message = $config['app']['debug'] ? $e->getMessage() : 'Database connection failed.';
    http_response_code(503);
    exit("<h1>Service unavailable</h1><p>$message</p><p>Run <code>php bin/migrate.php --seed</code> after configuring <code>.env</code>.</p>");
}

$router = new Router();
(require $root . '/routes/web.php')($router, $config);
(require $root . '/routes/api.php')($router, $config);

$request = new Request();

try {
    $router->dispatch($request);
} catch (\App\Core\NotFoundForTenantException $e) {
    $request->wantsJson()
        ? Response::error($e->getMessage(), 404)
        : Response::html(View::render('errors/404', [], null), 404);
} catch (\Throwable $e) {
    error_log($e->getMessage() . "\n" . $e->getTraceAsString());
    $detail = $config['app']['debug'] ? $e->getMessage() : 'Internal server error';
    $request->wantsJson()
        ? Response::error($detail, 500)
        : Response::html(View::render('errors/500', ['detail' => $detail], null), 500);
}
