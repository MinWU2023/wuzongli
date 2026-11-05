<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\View;

abstract class AdminController extends Controller
{
    /** @var array<string, mixed> */
    protected array $admin;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        Auth::requireLogin();
        $this->admin = Auth::user() ?? [];
    }

    /** @param array<string, mixed> $data */
    protected function renderAdmin(string $view, array $data, string $title, string $active): void
    {
        View::display('Admin::' . $view, array_merge([
            'admin' => $this->admin,
            'pageTitle' => $title,
            'activeNav' => $active,
            'version' => APP_VERSION,
        ], $data), 'Admin::layouts/admin');
    }

    protected function guardCsrf(): void
    {
        Csrf::assert($this->request->string('_token'));
    }

    protected function go(string $path): never
    {
        \App\Core\Response::redirect(url($path));
    }
}
