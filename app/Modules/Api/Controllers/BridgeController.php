<?php
declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Core\Controller;
use App\Core\Response;
use App\Core\View;

/**
 * Same-origin HTML bridge for InfinityFree-style browser checks.
 * Extension / App load this page in a real browser context, pass aes.js,
 * then call APIs with first-party cookies from inside the page.
 */
final class BridgeController extends Controller
{
    public function index(): never
    {
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Robots-Tag: noindex, nofollow');
        }
        $html = View::render('Api::bridge', [
            'basePath' => APP_BASE_PATH === '' ? '' : rtrim((string) APP_BASE_PATH, '/'),
            'version' => APP_VERSION,
        ]);
        Response::html($html);
    }
}
