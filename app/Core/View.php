<?php
declare(strict_types=1);

namespace App\Core;

/**
 * View resolver supporting module views ("Admin::tasks/index") and global
 * views ("errors/error"), with optional layouts.
 */
final class View
{
    /** @param array<string, mixed> $data */
    public static function render(string $view, array $data = [], ?string $layout = null): string
    {
        $content = self::renderFile($view, $data);
        if ($layout !== null) {
            $data['content'] = $content;
            return self::renderFile($layout, $data);
        }
        return $content;
    }

    /** @param array<string, mixed> $data */
    public static function display(string $view, array $data = [], ?string $layout = null): void
    {
        echo self::render($view, $data, $layout);
    }

    /** @param array<string, mixed> $data */
    private static function renderFile(string $view, array $data): string
    {
        $path = self::resolve($view);
        if (!is_file($path)) {
            throw new \RuntimeException('View not found: ' . $view . ' (' . $path . ')');
        }
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $path;
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }

    private static function resolve(string $view): string
    {
        if (str_contains($view, '::')) {
            [$module, $relative] = explode('::', $view, 2);
            $module = ucfirst(preg_replace('/[^a-zA-Z0-9_]/', '', $module) ?? '');
            $relative = trim(str_replace('..', '', $relative), '/');
            return APP_PATH . '/Modules/' . $module . '/Views/' . $relative . '.php';
        }
        return APP_PATH . '/Views/' . trim(str_replace('..', '', $view), '/') . '.php';
    }
}
