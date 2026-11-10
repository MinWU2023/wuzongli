<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Str;

/**
 * Admin「企业列表」：AI 通过且有手机号的企业 + 按日备注。
 */
final class CompanyLeadService
{
    /**
     * Expand all contact phones for a company row.
     *
     * @param array<string, mixed> $company
     * @return array<int, string>
     */
    public function phonesOf(array $company): array
    {
        $found = [];
        foreach (['phone', 'phone_alt', 'whatsapp'] as $key) {
            $raw = trim((string) ($company[$key] ?? ''));
            if ($raw === '') {
                continue;
            }
            foreach (preg_split('/[,;\/|]+/u', $raw) ?: [] as $piece) {
                $phone = $this->normalizePhone($piece);
                if ($phone === '') {
                    continue;
                }
                $fp = PhoneService::fingerprint($phone);
                if ($fp === '' || isset($found[$fp])) {
                    continue;
                }
                $found[$fp] = $phone;
            }
        }

        $json = trim((string) ($company['phone_list_json'] ?? ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                foreach ($decoded as $item) {
                    if (is_string($item) || is_numeric($item)) {
                        $phone = $this->normalizePhone((string) $item);
                    } elseif (is_array($item)) {
                        $phone = $this->normalizePhone((string) ($item['dial'] ?? $item['phone'] ?? $item['number'] ?? ''));
                    } else {
                        continue;
                    }
                    if ($phone === '') {
                        continue;
                    }
                    $fp = PhoneService::fingerprint($phone);
                    if ($fp === '' || isset($found[$fp])) {
                        continue;
                    }
                    $found[$fp] = $phone;
                }
            }
        }

        return array_values($found);
    }

    public function normalizePhone(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        // Keep leading + and digits / common separators for display, but collapse spaces.
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return Str::limit($value, 80, '');
    }

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int, table_ready: bool}
     */
    public function listLeads(string $companyQ, string $phoneQ, int $page, int $perPage = 40): array
    {
        $db = Db::instance();
        $companies = $db->tableName('companies');
        $websites = $db->tableName('websites');
        $tasks = $db->tableName('tasks');
        $notes = $db->tableName('company_phone_notes');

        try {
            $db->value("SELECT 1 FROM `{$notes}` LIMIT 1");
        } catch (\Throwable) {
            return ['rows' => [], 'total' => 0, 'table_ready' => false];
        }

        $where = [
            "w.`classify_status` = 'approved'",
            "(
                (c.`phone` IS NOT NULL AND TRIM(c.`phone`) <> '')
                OR (c.`phone_alt` IS NOT NULL AND TRIM(c.`phone_alt`) <> '')
                OR (c.`whatsapp` IS NOT NULL AND TRIM(c.`whatsapp`) <> '')
                OR (c.`phone_list_json` IS NOT NULL AND TRIM(c.`phone_list_json`) <> '' AND c.`phone_list_json` NOT IN ('[]', 'null', 'NULL'))
            )",
        ];
        $bindings = [];

        $companyQ = trim($companyQ);
        if ($companyQ !== '') {
            $like = '%' . $this->likeEscape($companyQ) . '%';
            $where[] = '(c.`company_name` LIKE ? OR c.`domain` LIKE ? OR COALESCE(NULLIF(c.`city`, \'\'), t.`city`) LIKE ?)';
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
        }

        $phoneQ = trim($phoneQ);
        if ($phoneQ !== '') {
            $like = '%' . $this->likeEscape($phoneQ) . '%';
            $where[] = '(c.`phone` LIKE ? OR c.`phone_alt` LIKE ? OR c.`whatsapp` LIKE ? OR c.`phone_list_json` LIKE ? OR EXISTS (
                SELECT 1 FROM `' . $notes . '` n
                WHERE n.`company_id` = c.`id` AND n.`phone` LIKE ?
            ))';
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
            $bindings[] = $like;
        }

        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $from = " FROM `{$companies}` c
            INNER JOIN `{$websites}` w ON w.`id` = c.`website_id`
            LEFT JOIN `{$tasks}` t ON t.`id` = c.`task_id`";

        $total = (int) ($db->value('SELECT COUNT(*)' . $from . $whereSql, $bindings) ?? 0);
        $page = max(1, $page);
        $perPage = max(10, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        $companiesRows = $db->fetchAll(
            'SELECT c.*, COALESCE(NULLIF(c.`city`, \'\'), t.`city`) AS task_city, t.`city` AS pipeline_city, w.`ai_reason`, w.`ai_confidence`'
            . $from . $whereSql
            . ' ORDER BY c.`id` DESC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $bindings
        );

        $companyIds = array_map(static fn (array $row): int => (int) $row['id'], $companiesRows);
        $noteMap = $this->latestNotesByCompanyPhones($companyIds);

        $rows = [];
        foreach ($companiesRows as $company) {
            $phones = $this->phonesOf($company);
            if ($phoneQ !== '') {
                $phones = array_values(array_filter(
                    $phones,
                    static fn (string $p): bool => str_contains(mb_strtolower($p), mb_strtolower($phoneQ))
                ));
                if ($phones === []) {
                    // Company matched via note/phone_alt LIKE but this page expansion missed — keep all.
                    $phones = $this->phonesOf($company);
                }
            }
            if ($phones === []) {
                continue;
            }
            foreach ($phones as $phone) {
                $key = ((int) $company['id']) . '|' . $phone;
                $note = $noteMap[$key] ?? null;
                $rows[] = [
                    'company_id' => (int) $company['id'],
                    'task_id' => (int) $company['task_id'],
                    'company_name' => (string) ($company['company_name'] ?: $company['domain']),
                    'domain' => (string) ($company['domain'] ?? ''),
                    'city' => (string) ($company['task_city'] ?? $company['city'] ?? ''),
                    'phone' => $phone,
                    'email' => (string) ($company['email'] ?? ''),
                    'address' => (string) ($company['address'] ?? ''),
                    'source_url' => (string) ($company['source_url'] ?? ''),
                    'note_id' => $note ? (int) $note['id'] : null,
                    'note' => $note ? (string) $note['note'] : '',
                    'note_date' => $note ? (string) $note['note_date'] : '',
                    'note_updated_at' => $note ? (string) ($note['updated_at'] ?? '') : '',
                ];
            }
        }

        return ['rows' => $rows, 'total' => $total, 'table_ready' => true];
    }

    /**
     * @param array<int, int> $companyIds
     * @return array<string, array<string, mixed>>
     */
    public function latestNotesByCompanyPhones(array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }
        $db = Db::instance();
        $table = $db->tableName('company_phone_notes');
        $placeholders = implode(',', array_fill(0, count($companyIds), '?'));
        // Prefer today's note, otherwise the newest note_date per company+phone.
        $rows = $db->fetchAll(
            "SELECT n.*
             FROM `{$table}` n
             INNER JOIN (
                SELECT `company_id`, `phone`, MAX(`note_date`) AS max_date
                FROM `{$table}`
                WHERE `company_id` IN ({$placeholders})
                GROUP BY `company_id`, `phone`
             ) latest
               ON latest.`company_id` = n.`company_id`
              AND latest.`phone` = n.`phone`
              AND latest.`max_date` = n.`note_date`",
            array_values($companyIds)
        );
        $map = [];
        foreach ($rows as $row) {
            $map[((int) $row['company_id']) . '|' . (string) $row['phone']] = $row;
        }
        return $map;
    }

    /**
     * Upsert one note for company + phone + date.
     *
     * @return array<string, mixed>
     */
    public function saveNote(int $companyId, string $phone, string $note, ?string $noteDate = null): array
    {
        if ($companyId <= 0) {
            throw HttpException::validation('企业 ID 无效');
        }
        $phone = $this->normalizePhone($phone);
        if ($phone === '') {
            throw HttpException::validation('联系号码不能为空');
        }
        $note = Str::limit(trim($note), 1000, '');
        if ($note === '') {
            throw HttpException::validation('请填写备注内容');
        }
        $date = $this->normalizeDate($noteDate) ?? date('Y-m-d');

        $db = Db::instance();
        $company = $db->table('companies')->where('id', $companyId)->first();
        if ($company === null) {
            throw HttpException::notFound('企业不存在');
        }
        $phones = $this->phonesOf($company);
        if ($phones !== [] && !in_array($phone, $phones, true)) {
            // Allow exact match after light normalization differences.
            $ok = false;
            foreach ($phones as $existing) {
                if ($this->digits($existing) !== '' && $this->digits($existing) === $this->digits($phone)) {
                    $phone = $existing;
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                throw HttpException::validation('该号码不属于此企业');
            }
        }

        $db->upsert('company_phone_notes', [
            'company_id' => $companyId,
            'phone' => $phone,
            'note' => $note,
            'note_date' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ], ['note', 'updated_at']);

        $row = $db->fetch(
            'SELECT * FROM `' . $db->tableName('company_phone_notes') . '`
             WHERE `company_id` = ? AND `phone` = ? AND `note_date` = ? LIMIT 1',
            [$companyId, $phone, $date]
        );
        return $row ?? [
            'company_id' => $companyId,
            'phone' => $phone,
            'note' => $note,
            'note_date' => $date,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportNotesByDate(string $date): array
    {
        $date = $this->normalizeDate($date) ?? date('Y-m-d');
        $db = Db::instance();
        $notes = $db->tableName('company_phone_notes');
        $companies = $db->tableName('companies');
        return $db->fetchAll(
            "SELECT n.`note_date`, n.`phone`, n.`note`, n.`updated_at`,
                    c.`id` AS company_id, c.`company_name`, c.`domain`, c.`city`
             FROM `{$notes}` n
             INNER JOIN `{$companies}` c ON c.`id` = n.`company_id`
             WHERE n.`note_date` = ?
             ORDER BY n.`id` ASC",
            [$date]
        );
    }

    public function normalizeDate(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $parts = array_map('intval', explode('-', $value));
        if (!checkdate($parts[1], $parts[2], $parts[0])) {
            return null;
        }
        return $value;
    }

    private function likeEscape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
