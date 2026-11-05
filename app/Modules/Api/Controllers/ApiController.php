<?php
declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

abstract class ApiController extends Controller
{
    public function __construct(Request $request)
    {
        parent::__construct($request);
        $key = $request->header('X-API-Key') ?: $request->bearerToken() ?: (string) $request->query('api_key', '');
        $expected = (string) config('app.api_key', '');
        if ($expected === '' || $key === '' || !hash_equals($expected, trim($key))) {
            Response::json([
                'ok' => false,
                'error' => [
                    'code' => 'unauthorized',
                    'message' => 'API Key 不正确，请在插件设置中重新填写。',
                ],
            ], 401);
        }
    }

    protected function ok(mixed $data = [], int $status = 200): never
    {
        Response::json(['ok' => true, 'data' => $data], $status);
    }

    protected function fail(string $message, int $status = 400, string $code = 'bad_request', mixed $details = null): never
    {
        Response::json([
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
        ], $status);
    }

    protected function taskId(string $value): int
    {
        $id = (int) $value;
        if ($id <= 0) {
            throw HttpException::notFound('任务不存在');
        }
        return $id;
    }
}
