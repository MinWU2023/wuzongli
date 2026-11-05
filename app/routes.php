<?php
declare(strict_types=1);

/**
 * Explicit route table. All handlers use "Module/Controller@method".
 * Add your own routes here; modules can grow without touching the kernel.
 */

use App\Core\Router;

/** @var Router $router */

// ---------------------------------------------------------------------------
// API v1 (called by the Chrome extension; X-API-Key required except health)
// ---------------------------------------------------------------------------
$router->get('/api/health', 'Api/Health@index');
$router->get('/api/settings', 'Api/Task@settings');
$router->post('/api/settings', 'Api/Task@updateSettings');

$router->get('/api/runner', 'Api/Task@runnerStatus');
$router->post('/api/runner', 'Api/Task@runnerUpdate');

$router->get('/api/tasks', 'Api/Task@index');
$router->post('/api/tasks', 'Api/Task@store');
$router->get('/api/tasks/{id}', 'Api/Task@show');
$router->post('/api/tasks/{id}/update', 'Api/Task@update');
$router->post('/api/tasks/{id}/stop', 'Api/Task@stop');
$router->get('/api/tasks/{id}/next', 'Api/Task@next');
$router->get('/api/tasks/{id}/keywords', 'Api/Task@keywords');
$router->get('/api/tasks/{id}/websites', 'Api/Task@websites');
$router->post('/api/tasks/{id}/keywords/generate', 'Api/Task@generateKeywords');
$router->post('/api/tasks/{id}/serp', 'Api/Task@serp');
$router->post('/api/tasks/{id}/serp/serper', 'Api/Task@serper');
$router->post('/api/tasks/{id}/classify', 'Api/Task@classify');
$router->post('/api/tasks/{id}/websites/{websiteId}/pages', 'Api/Task@pages');
$router->post('/api/tasks/{id}/websites/{websiteId}/extract', 'Api/Task@extract');
$router->post('/api/tasks/{id}/pause', 'Api/Task@pause');
$router->post('/api/tasks/{id}/resume', 'Api/Task@resume');
$router->post('/api/tasks/{id}/retry', 'Api/Task@retry');
$router->post('/api/tasks/{id}/rewind', 'Api/Task@rewind');
$router->get('/api/tasks/{id}/companies', 'Api/Task@companies');
$router->get('/api/tasks/{id}/logs', 'Api/Task@logs');
$router->get('/api/tasks/{id}/export.csv', 'Api/Task@export');

// Dial queue (Chrome extension enqueues; mobile app claims & dials)
$router->post('/api/dial/enqueue', 'Api/Dial@enqueue');
$router->get('/api/dial/next', 'Api/Dial@next');
$router->post('/api/dial/{id}/complete', 'Api/Dial@complete');
$router->get('/api/dial/stats', 'Api/Dial@stats');
$router->get('/api/companies', 'Api/Dial@companies');

// ---------------------------------------------------------------------------
// Admin web UI
// ---------------------------------------------------------------------------
$router->get('/admin/login', 'Admin/Auth@loginForm');
$router->post('/admin/login', 'Admin/Auth@login');
$router->post('/admin/logout', 'Admin/Auth@logout');

$router->get('/admin', 'Admin/Dashboard@index');
$router->get('/admin/tasks', 'Admin/Tasks@index');
$router->get('/admin/tasks/{id}/export.csv', 'Admin/Tasks@export');
$router->post('/admin/tasks/{id}/dial', 'Admin/Tasks@enqueueDial');
$router->get('/admin/tasks/{id}', 'Admin/Tasks@show');
$router->post('/admin/tasks/{id}/action', 'Admin/Tasks@action');

$router->get('/admin/relays', 'Admin/Relays@index');
$router->get('/admin/relays/create', 'Admin/Relays@create');
$router->post('/admin/relays', 'Admin/Relays@store');
$router->get('/admin/relays/{id}/edit', 'Admin/Relays@edit');
$router->post('/admin/relays/{id}', 'Admin/Relays@update');
$router->post('/admin/relays/{id}/delete', 'Admin/Relays@delete');

$router->get('/admin/prompts', 'Admin/Prompts@index');
$router->get('/admin/prompts/{id}/edit', 'Admin/Prompts@edit');
$router->post('/admin/prompts/{id}', 'Admin/Prompts@update');

$router->get('/admin/settings', 'Admin/Settings@index');
$router->post('/admin/settings', 'Admin/Settings@update');
$router->post('/admin/settings/test-serper', 'Admin/Settings@testSerper');
$router->post('/admin/settings/regenerate-key', 'Admin/Settings@regenerateKey');

// A friendly root route for direct requests that bypass App's redirect.
$router->get('/', static fn (): string => '');
