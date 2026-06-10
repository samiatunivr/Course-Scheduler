<?php

declare(strict_types=1);

/**
 * Application configuration. Values come from environment variables /
 * a .env file in the project root, with safe defaults for development.
 */

$root = dirname(__DIR__);

// Lightweight .env loader (no dependency required).
$envFile = $root . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

$env = static fn (string $key, mixed $default = null): mixed =>
    getenv($key) !== false ? getenv($key) : $default;

return [
    'app' => [
        'name' => $env('APP_NAME', 'University Course Scheduler'),
        'env' => $env('APP_ENV', 'development'),
        'url' => $env('APP_URL', 'http://localhost:8080'),
        'debug' => filter_var($env('APP_DEBUG', 'true'), FILTER_VALIDATE_BOOL),
        'key' => $env('APP_KEY', 'dev-insecure-key-change-in-production'),
        'timezone' => $env('APP_TIMEZONE', 'UTC'),
    ],
    'database' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => (int) $env('DB_PORT', 3306),
        'database' => $env('DB_DATABASE', 'course_scheduler'),
        'username' => $env('DB_USERNAME', 'root'),
        'password' => $env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],
    'ai' => [
        'provider' => $env('AI_PROVIDER', 'openai'),
        'api_key' => $env('AI_API_KEY', ''),
        'base_url' => rtrim((string) $env('AI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'model' => $env('AI_MODEL', 'gpt-4o'),
        'max_tokens' => (int) $env('AI_MAX_TOKENS', 2048),
    ],
    'mail' => [
        'from' => $env('MAIL_FROM', 'scheduler@example.edu'),
        'from_name' => $env('MAIL_FROM_NAME', 'Course Scheduler'),
        'smtp_host' => $env('SMTP_HOST', ''),
        'smtp_port' => (int) $env('SMTP_PORT', 587),
        'smtp_username' => $env('SMTP_USERNAME', ''),
        'smtp_password' => $env('SMTP_PASSWORD', ''),
    ],
    'integrations' => [
        'slack_webhook' => $env('SLACK_WEBHOOK_URL', ''),
        'teams_webhook' => $env('TEAMS_WEBHOOK_URL', ''),
        'sms_gateway_url' => $env('SMS_GATEWAY_URL', ''),
        'sms_gateway_key' => $env('SMS_GATEWAY_KEY', ''),
    ],
];
