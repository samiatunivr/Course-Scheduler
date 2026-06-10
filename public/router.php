<?php

// Router script for PHP's built-in dev server: serve static files
// directly, everything else through the front controller.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
