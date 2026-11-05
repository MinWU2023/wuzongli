<?php
declare(strict_types=1);

namespace App\Modules\Api\Controllers;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Str;
use App\Services\DialService;

final class DialController extends ApiController
{
    public function enqueue(): never
    {
        $result = (new DialService())->enqueue([
            'phone' => $this->request->string('phone'),
            'company_id' => $this->request->int('company_id'),
            'task_id' => $this->request->int('task_id'),
            'company_name' => $this->request->string('company_name'),
            'city' => $this->request->string('city'),
            'domain' => $this->request->string('domain'),
            'email' => $this->request->string('email'),
            'address' => $this->request->string('address'),
            'source_url' => $this->request->string('source_url'),
        ]);
        $this->ok($result, $result['duplicated'] ? 200 : 201);
    }

    /** Claim the oldest pending dial job for a mobile device. */
    public function next(): never
    {
        $device = Str::limit($this->request->string('device', 'mobile'), 80, '') ?: 'mobile';
        $job = null;

        Db::instance()->transaction(function (Db $db) use ($device, &$job): void {
            $row = $db->fetch(
                'SELECT * FROM `' . $db->tableName('dial_jobs') . '`
                 WHERE `status` = \'pending\'
                 ORDER BY `id` ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            if ($row === null) {
                return;
            }
            $db->update('dial_jobs', [
                'status' => 'claimed',
                'claimed_by' => $device,
                'claimed_at' => now(),
                'updated_at' => now(),
            ], ['id' => (int) $row['id']]);
            $job = $db->table('dial_jobs')->where('id', (int) $row['id'])->first();
        });

        $pending = (int) Db::instance()->table('dial_jobs')->where('status', 'pending')->count();
        $this->ok([
            'job' => $job ? $this->decorate($job) : null,
            'pending_count' => $pending,
        ]);
    }

    public function complete(string $id): never
    {
        $jobId = (int) $id;
        if ($jobId <= 0) {
            throw HttpException::notFound('拨号任务不存在');
        }
        $job = Db::instance()->table('dial_jobs')->where('id', $jobId)->first();
        if ($job === null) {
            throw HttpException::notFound('拨号任务不存在');
        }

        $status = strtolower($this->request->string('status', 'done'));
        if (!in_array($status, ['done', 'failed', 'cancelled'], true)) {
            throw HttpException::validation('status 仅支持 done / failed / cancelled');
        }
        $note = Str::limit($this->request->string('note'), 480, '');

        Db::instance()->update('dial_jobs', [
            'status' => $status,
            'note' => $note !== '' ? $note : null,
            'completed_at' => now(),
            'updated_at' => now(),
        ], ['id' => $jobId]);

        $fresh = Db::instance()->table('dial_jobs')->where('id', $jobId)->first();
        $this->ok(['job' => $this->decorate($fresh ?? [])]);
    }

    public function stats(): never
    {
        $db = Db::instance();
        $table = $db->tableName('dial_jobs');
        $rows = $db->fetchAll(
            "SELECT `status`, COUNT(*) AS `c` FROM `{$table}` GROUP BY `status`"
        );
        $counts = ['pending' => 0, 'claimed' => 0, 'done' => 0, 'failed' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $key = (string) ($row['status'] ?? '');
            if (isset($counts[$key])) {
                $counts[$key] = (int) $row['c'];
            }
        }
        $this->ok(['counts' => $counts]);
    }

    /** Global company search for the mobile app. */
    public function companies(): never
    {
        $city = trim($this->request->string('city'));
        $company = trim($this->request->string('company'));
        $phone = trim($this->request->string('phone'));
        $q = trim($this->request->string('q'));
        $page = max(1, $this->request->int('page', 1));
        $limit = max(1, min(100, $this->request->int('limit', 30)));
        $offset = ($page - 1) * $limit;

        $db = Db::instance();
        $cTable = $db->tableName('companies');
        $tTable = $db->tableName('tasks');

        $where = ['1=1'];
        $params = [];

        if ($city !== '') {
            $where[] = '(COALESCE(NULLIF(c.`city`, \'\'), t.`city`) LIKE ?)';
            $params[] = '%' . $city . '%';
        }
        if ($company !== '') {
            $where[] = '(c.`company_name` LIKE ? OR c.`domain` LIKE ?)';
            $params[] = '%' . $company . '%';
            $params[] = '%' . $company . '%';
        }
        if ($phone !== '') {
            $where[] = '(c.`phone` LIKE ? OR c.`phone_alt` LIKE ? OR c.`whatsapp` LIKE ?)';
            $params[] = '%' . $phone . '%';
            $params[] = '%' . $phone . '%';
            $params[] = '%' . $phone . '%';
        }
        if ($q !== '') {
            $where[] = '(c.`company_name` LIKE ? OR c.`domain` LIKE ? OR c.`phone` LIKE ? OR c.`phone_alt` LIKE ? OR COALESCE(NULLIF(c.`city`, \'\'), t.`city`) LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $sqlWhere = implode(' AND ', $where);
        $totalRow = $db->fetch(
            "SELECT COUNT(*) AS `c`
             FROM `{$cTable}` c
             LEFT JOIN `{$tTable}` t ON t.`id` = c.`task_id`
             WHERE {$sqlWhere}",
            $params
        );
        $total = (int) ($totalRow['c'] ?? 0);

        $rows = $db->fetchAll(
            "SELECT c.*, COALESCE(NULLIF(c.`city`, ''), t.`city`) AS `city`, t.`city` AS `task_city`
             FROM `{$cTable}` c
             LEFT JOIN `{$tTable}` t ON t.`id` = c.`task_id`
             WHERE {$sqlWhere}
             ORDER BY c.`id` DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );

        $this->ok([
            'companies' => array_map(fn (array $row): array => $this->decorateCompany($row), $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /** @param array<string, mixed> $job */
    private function decorate(array $job): array
    {
        return (new DialService())->decorate($job);
    }

    /** @param array<string, mixed> $row */
    private function decorateCompany(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'task_id' => (int) ($row['task_id'] ?? 0),
            'company_name' => $row['company_name'] ?? null,
            'domain' => $row['domain'] ?? null,
            'city' => $row['city'] ?? ($row['task_city'] ?? null),
            'country' => $row['country'] ?? null,
            'phone' => $row['phone'] ?? null,
            'phone_alt' => $row['phone_alt'] ?? null,
            'email' => $row['email'] ?? null,
            'whatsapp' => $row['whatsapp'] ?? null,
            'address' => $row['address'] ?? null,
            'source_url' => $row['source_url'] ?? null,
            'linkedin' => $row['linkedin'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
