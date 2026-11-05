<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    public function __construct(protected Request $request)
    {
    }

    /** @param array<string, mixed> $data */
    protected function view(string $view, array $data = [], ?string $layout = null): string
    {
        return View::render($view, $data, $layout);
    }

    /** @param array<string, mixed> $data */
    protected function render(string $view, array $data = [], ?string $layout = null): void
    {
        View::display($view, $data, $layout);
    }

    protected function redirect(string $path): never
    {
        Response::redirect(url($path));
    }

    protected function back(): never
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? url('/admin');
        Response::redirect(is_string($referer) ? $referer : url('/admin'));
    }

    protected function json(mixed $data, int $status = 200): never
    {
        Response::json($data, $status);
    }
}
