<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\HttpException;
use App\Core\Response;
use App\Services\CompanyLeadService;
use App\Services\DialService;

final class CompaniesController extends AdminController
{
    public function index(): void
    {
        $companyQ = trim((string) $this->request->query('company', ''));
        $phoneQ = trim((string) $this->request->query('phone', ''));
        $page = max(1, (int) $this->request->query('page', 1));
        $perPage = 30;
        $service = new CompanyLeadService();
        $result = $service->listLeads($companyQ, $phoneQ, $page, $perPage);

        $this->renderAdmin('companies/index', [
            'rows' => $result['rows'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'companyQ' => $companyQ,
            'phoneQ' => $phoneQ,
            'tableReady' => $result['table_ready'],
            'exportDate' => date('Y-m-d'),
            'today' => date('Y-m-d'),
        ], '企业列表', 'companies');
    }

    public function saveNote(): never
    {
        $this->guardCsrf();
        $wantsJson = $this->request->wantsJson();
        $service = new CompanyLeadService();
        try {
            $saved = $service->saveNote(
                $this->request->int('company_id'),
                $this->request->string('phone'),
                $this->request->string('note'),
                $this->request->string('note_date', date('Y-m-d'))
            );
            if ($wantsJson) {
                $this->json([
                    'ok' => true,
                    'message' => '备注已保存',
                    'note' => [
                        'company_id' => (int) ($saved['company_id'] ?? 0),
                        'phone' => (string) ($saved['phone'] ?? ''),
                        'note' => (string) ($saved['note'] ?? ''),
                        'note_date' => (string) ($saved['note_date'] ?? ''),
                        'updated_at' => (string) ($saved['updated_at'] ?? now()),
                    ],
                ]);
            }
            flash('success', '备注已保存。');
        } catch (HttpException $e) {
            if ($wantsJson) {
                $this->json(['ok' => false, 'message' => $e->getMessage()], $e->status);
            }
            flash('error', $e->getMessage());
        }

        $query = http_build_query(array_filter([
            'company' => $this->request->string('return_company'),
            'phone' => $this->request->string('return_phone'),
            'page' => $this->request->int('return_page') > 1 ? $this->request->int('return_page') : null,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== 0));

        $this->go('/admin/companies' . ($query !== '' ? '?' . $query : ''));
    }

    public function enqueueDial(): never
    {
        try {
            $this->guardCsrf();
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
            $this->json([
                'ok' => true,
                'duplicated' => $result['duplicated'],
                'job' => $result['job'],
                'message' => $result['duplicated'] ? '该号码已在拨号队列中' : '已推送到手机拨号队列',
            ], $result['duplicated'] ? 200 : 201);
        } catch (HttpException $e) {
            $this->json(['ok' => false, 'message' => $e->getMessage()], $e->status);
        }
    }

    public function export(): never
    {
        $service = new CompanyLeadService();
        $date = $service->normalizeDate($this->request->string('date', date('Y-m-d'))) ?? date('Y-m-d');
        try {
            $rows = $service->exportNotesByDate($date);
        } catch (\Throwable) {
            flash('error', '备注表尚未创建，请先执行数据库迁移。');
            $this->go('/admin/companies');
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to create CSV stream');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['日期', '公司名称', '号码', '备注'], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($stream, [
                csv_safe($row['note_date'] ?? $date),
                csv_safe($row['company_name'] ?: ($row['domain'] ?? '')),
                csv_safe($row['phone'] ?? ''),
                csv_safe($row['note'] ?? ''),
            ], ',', '"', '\\');
        }
        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);
        Response::download('company-notes-' . $date . '.csv', $csv);
    }
}
