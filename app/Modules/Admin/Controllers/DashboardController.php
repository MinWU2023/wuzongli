<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Db;
use App\Services\TaskService;

final class DashboardController extends AdminController
{
    public function index(): void
    {
        $db = Db::instance();
        $taskService = new TaskService();
        $stats = [
            'tasks' => (int) ($db->value('SELECT COUNT(*) FROM `' . $db->tableName('tasks') . '`') ?? 0),
            'active' => (int) ($db->value('SELECT COUNT(*) FROM `' . $db->tableName('tasks') . '` WHERE `status` = \'active\'') ?? 0),
            'websites' => (int) ($db->value('SELECT COUNT(*) FROM `' . $db->tableName('websites') . '`') ?? 0),
            'approved' => (int) ($db->value('SELECT COUNT(*) FROM `' . $db->tableName('websites') . '` WHERE `classify_status` = \'approved\'') ?? 0),
            'companies' => (int) ($db->value('SELECT COUNT(*) FROM `' . $db->tableName('companies') . '`') ?? 0),
            'today' => (int) ($db->value('SELECT COUNT(*) FROM `' . $db->tableName('companies') . '` WHERE `created_at` >= ?', [date('Y-m-d 00:00:00')]) ?? 0),
            'relays' => (int) ($db->value('SELECT COUNT(*) FROM `' . $db->tableName('ai_relays') . '` WHERE `is_active` = 1') ?? 0),
        ];
        $recent = $taskService->recent(7);
        foreach ($recent as &$task) {
            $task['counts'] = $taskService->counts((int) $task['id']);
        }
        unset($task);

        $logs = $db->fetchAll(
            'SELECT l.*, t.city FROM `' . $db->tableName('task_logs') . '` l
             LEFT JOIN `' . $db->tableName('tasks') . '` t ON t.id = l.task_id
             ORDER BY l.id DESC LIMIT 10'
        );

        $this->renderAdmin('dashboard/index', [
            'stats' => $stats,
            'recent' => $recent,
            'logs' => $logs,
            'apiBase' => absolute_url(''),
        ], '总览', 'dashboard');
    }
}
