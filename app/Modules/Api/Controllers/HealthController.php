<?php
declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;

final class HealthController extends Controller
{
    public function index(): never
    {
        $dbOk = false;
        $mysql = null;
        try {
            $mysql = Db::instance()->value('SELECT VERSION()');
            $dbOk = true;
        } catch (\Throwable) {
        }
        Response::json([
            'ok' => true,
            'data' => [
                'app' => 'Google Lead Engine',
                'version' => APP_VERSION,
                'php' => PHP_VERSION,
                'mysql' => $mysql,
                'database' => $dbOk,
                'time' => date('c'),
                'stage' => 'ready',
            ],
        ]);
    }
}
