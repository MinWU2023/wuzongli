<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Str;

final class DialService
{
    /**
     * @param array{
     *   phone?: string,
     *   company_id?: int,
     *   task_id?: int,
     *   company_name?: string,
     *   city?: string,
     *   domain?: string,
     *   email?: string,
     *   address?: string,
     *   source_url?: string
     * } $input
     * @return array{job: array<string, mixed>, duplicated: bool}
     */
    public function enqueue(array $input): array
    {
        $phone = $this->normalizePhone((string) ($input['phone'] ?? ''));
        if ($phone === '') {
            throw HttpException::validation('请提供有效的电话号码');
        }

        $companyId = (int) ($input['company_id'] ?? 0);
        $taskId = (int) ($input['task_id'] ?? 0);
        $companyName = Str::limit((string) ($input['company_name'] ?? ''), 250, '');
        $city = Str::limit((string) ($input['city'] ?? ''), 100, '');
        $domain = Str::limit((string) ($input['domain'] ?? ''), 250, '');
        $email = Str::limit((string) ($input['email'] ?? ''), 250, '');
        $address = Str::limit((string) ($input['address'] ?? ''), 490, '');
        $sourceUrl = Str::limit((string) ($input['source_url'] ?? ''), 990, '');

        if ($companyId > 0) {
            $company = Db::instance()->table('companies')->where('id', $companyId)->first();
            if ($company !== null) {
                $taskId = $taskId > 0 ? $taskId : (int) ($company['task_id'] ?? 0);
                $companyName = $companyName !== '' ? $companyName : (string) ($company['company_name'] ?? '');
                $city = $city !== '' ? $city : (string) ($company['city'] ?? '');
                $domain = $domain !== '' ? $domain : (string) ($company['domain'] ?? '');
                $email = $email !== '' ? $email : (string) ($company['email'] ?? '');
                $address = $address !== '' ? $address : (string) ($company['address'] ?? '');
                $sourceUrl = $sourceUrl !== '' ? $sourceUrl : (string) ($company['source_url'] ?? '');
                if ($city === '' && $taskId > 0) {
                    $task = Db::instance()->table('tasks')->where('id', $taskId)->first();
                    if ($task !== null) {
                        $city = (string) ($task['city'] ?? '');
                    }
                }
            }
        }

        // Avoid flooding: same pending phone within 10 minutes is reused.
        $existing = Db::instance()->fetch(
            'SELECT * FROM `' . Db::instance()->tableName('dial_jobs') . '`
             WHERE `phone` = ? AND `status` = \'pending\' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
             ORDER BY `id` DESC LIMIT 1',
            [$phone]
        );
        if ($existing !== null) {
            return ['job' => $this->decorate($existing), 'duplicated' => true];
        }

        $id = Db::instance()->insert('dial_jobs', [
            'company_id' => $companyId > 0 ? $companyId : null,
            'task_id' => $taskId > 0 ? $taskId : null,
            'phone' => $phone,
            'company_name' => $companyName !== '' ? $companyName : null,
            'city' => $city !== '' ? $city : null,
            'domain' => $domain !== '' ? $domain : null,
            'email' => $email !== '' ? $email : null,
            'address' => $address !== '' ? $address : null,
            'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = Db::instance()->table('dial_jobs')->where('id', $id)->first();
        return ['job' => $this->decorate($job ?? []), 'duplicated' => false];
    }

    public function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if ($phone === '' || $phone === '+') {
            return '';
        }
        if (str_starts_with($phone, '+')) {
            $phone = '+' . preg_replace('/\D/', '', substr($phone, 1));
        } else {
            $phone = preg_replace('/\D/', '', $phone) ?? '';
        }
        if (strlen(ltrim($phone, '+')) < 5) {
            return '';
        }
        return Str::limit($phone, 64, '');
    }

    /** @param array<string, mixed> $job */
    public function decorate(array $job): array
    {
        return [
            'id' => (int) ($job['id'] ?? 0),
            'company_id' => isset($job['company_id']) ? (int) $job['company_id'] : null,
            'task_id' => isset($job['task_id']) ? (int) $job['task_id'] : null,
            'phone' => (string) ($job['phone'] ?? ''),
            'company_name' => $job['company_name'] ?? null,
            'city' => $job['city'] ?? null,
            'domain' => $job['domain'] ?? null,
            'email' => $job['email'] ?? null,
            'address' => $job['address'] ?? null,
            'source_url' => $job['source_url'] ?? null,
            'status' => (string) ($job['status'] ?? 'pending'),
            'claimed_by' => $job['claimed_by'] ?? null,
            'claimed_at' => $job['claimed_at'] ?? null,
            'completed_at' => $job['completed_at'] ?? null,
            'note' => $job['note'] ?? null,
            'created_at' => $job['created_at'] ?? null,
        ];
    }
}
