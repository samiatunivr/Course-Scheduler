<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    private static string $basePath = '';

    public static function setBasePath(string $path): void
    {
        self::$basePath = rtrim($path, '/');
    }

    /** Render a template inside the main layout. */
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null) {
            return $content;
        }

        return self::partial($layout, $data + ['content' => $content]);
    }

    /** Render a template without the layout. */
    public static function partial(string $template, array $data = []): string
    {
        $file = self::$basePath . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }
}

/** HTML-escape helper available inside all templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}
