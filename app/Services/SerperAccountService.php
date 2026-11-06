<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Str;

final class SerperAccountService
{
    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $rows = Db::instance()->table('serper_accounts')->orderBy('priority')->orderBy('id')->get();
        return array_map(fn (array $row): array => $this->decorate($row), $rows);
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $row = Db::instance()->table('serper_accounts')->where('id', $id)->first();
        if ($row === null) {
            throw HttpException::notFound('Serper 账号不存在');
        }
        return $this->decorate($row);
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        $db = Db::instance();
        $row = $db->fetch(
            'SELECT * FROM `' . $db->tableName('serper_accounts') . '`
             WHERE `is_current` = 1 AND `is_enabled` = 1 AND `status` <> \'disabled\'
             ORDER BY `priority` ASC, `id` ASC LIMIT 1'
        );
        if ($row !== null) {
            return $this->decorate($row);
        }
        // 没有标记 current 时，自动挑第一个可用账号
        $row = $db->fetch(
            'SELECT * FROM `' . $db->tableName('serper_accounts') . '`
             WHERE `is_enabled` = 1 AND `status` IN (\'ready\', \'error\')
             ORDER BY `priority` ASC, `id` ASC LIMIT 1'
        );
        if ($row === null) {
            return null;
        }
        $this->activate((int) $row['id'], false);
        return $this->decorate($db->table('serper_accounts')->where('id', (int) $row['id'])->first() ?? $row);
    }

    public function currentApiKey(): string
    {
        $current = $this->current();
        if ($current === null) {
            return '';
        }
        return (string) ($current['api_key'] ?? '');
    }

    public function activate(int $id, bool $requireEnabled = true): void
    {
        $db = Db::instance();
        $row = $db->table('serper_accounts')->where('id', $id)->first();
        if ($row === null) {
            throw HttpException::notFound('Serper 账号不存在');
        }
        if ($requireEnabled && !(int) $row['is_enabled']) {
            throw HttpException::validation('该账号已停用，无法设为当前启用');
        }
        $db->query('UPDATE `' . $db->tableName('serper_accounts') . '` SET `is_current` = 0');
        $db->update('serper_accounts', [
            'is_current' => 1,
            'is_enabled' => 1,
            'status' => 'ready',
            'last_error' => null,
            'updated_at' => now(),
        ], ['id' => $id]);
    }

    /**
     * Mark account exhausted/error and switch to the next ready account.
     * @return array<string, mixed>|null next current account
     */
    public function rotateAfterFailure(int $accountId, string $reason, string $status = 'exhausted'): ?array
    {
        $db = Db::instance();
        $db->update('serper_accounts', [
            'is_current' => 0,
            'status' => $status,
            'last_error' => Str::limit($reason, 950, ''),
            'updated_at' => now(),
        ], ['id' => $accountId]);

        $next = $db->fetch(
            'SELECT * FROM `' . $db->tableName('serper_accounts') . '`
             WHERE `is_enabled` = 1
               AND `status` = \'ready\'
               AND `id` <> ?
             ORDER BY `priority` ASC, `id` ASC
             LIMIT 1',
            [$accountId]
        );
        if ($next === null) {
            return null;
        }
        $this->activate((int) $next['id'], false);
        return $this->decorate($db->table('serper_accounts')->where('id', (int) $next['id'])->first() ?? $next);
    }

    public function markSuccess(int $accountId, ?int $credits): void
    {
        $data = [
            'last_used_at' => now(),
            'last_error' => null,
            'status' => 'ready',
            'updated_at' => now(),
        ];
        if ($credits !== null) {
            $data['credits'] = $credits;
            if ($credits <= 0) {
                // 额度用尽：标记并切下一个
                $this->rotateAfterFailure($accountId, 'Serper 余额已用尽（credits=0）', 'exhausted');
                return;
            }
        }
        Db::instance()->update('serper_accounts', $data, ['id' => $accountId]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(array $input): array
    {
        $data = $this->validated($input, true);
        $makeCurrent = !empty($input['is_current']) || $this->current() === null;
        $data['is_current'] = 0;
        $id = Db::instance()->insert('serper_accounts', $data);
        if ($makeCurrent) {
            $this->activate($id, false);
        }
        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $id, array $input): array
    {
        $existing = $this->find($id);
        $data = $this->validated($input, false, $existing);
        Db::instance()->update('serper_accounts', $data, ['id' => $id]);
        if (!empty($input['is_current'])) {
            $this->activate($id, false);
        }
        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $row = $this->find($id);
        Db::instance()->delete('serper_accounts', ['id' => $id]);
        if ((int) ($row['is_current'] ?? 0) === 1) {
            $next = Db::instance()->fetch(
                'SELECT `id` FROM `' . Db::instance()->tableName('serper_accounts') . '`
                 WHERE `is_enabled` = 1 AND `status` = \'ready\'
                 ORDER BY `priority` ASC, `id` ASC LIMIT 1'
            );
            if ($next !== null) {
                $this->activate((int) $next['id'], false);
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function validated(array $input, bool $creating, ?array $existing = null): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw HttpException::validation('名称不能为空且不超过 120 字');
        }
        $account = Str::limit(trim((string) ($input['account'] ?? '')), 190, '');
        $apiKey = trim((string) ($input['api_key'] ?? ''));
        if ($creating && $apiKey === '') {
            throw HttpException::validation('请填写 Serper API Key');
        }
        $password = (string) ($input['password'] ?? '');
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint !== '' && (!filter_var($endpoint, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $endpoint))) {
            throw HttpException::validation('接口地址必须以 http:// 或 https:// 开头');
        }
        $priority = (int) ($input['priority'] ?? ($existing['priority'] ?? 10));
        $priority = max(0, min(999, $priority));
        $isEnabled = !empty($input['is_enabled']) ? 1 : 0;

        $data = [
            'name' => $name,
            'account' => $account !== '' ? $account : null,
            'endpoint' => $endpoint !== '' ? Str::limit($endpoint, 500, '') : null,
            'developer_info' => ($info = trim((string) ($input['developer_info'] ?? ''))) !== '' ? Str::limit($info, 5000, '') : null,
            'notes' => ($notes = trim((string) ($input['notes'] ?? ''))) !== '' ? Str::limit($notes, 5000, '') : null,
            'priority' => $priority,
            'is_enabled' => $isEnabled,
            'updated_at' => now(),
        ];
        if ($creating) {
            $data['created_at'] = now();
            $data['api_key_enc'] = Crypto::encrypt($apiKey);
            $data['password_enc'] = $password !== '' ? Crypto::encrypt($password) : null;
            $data['status'] = $isEnabled ? 'ready' : 'disabled';
            $data['is_enabled'] = $isEnabled;
        } else {
            $data['is_enabled'] = $isEnabled;
            if ($apiKey !== '') {
                $data['api_key_enc'] = Crypto::encrypt($apiKey);
            }
            if (array_key_exists('password', $input) && $password !== '') {
                $data['password_enc'] = Crypto::encrypt($password);
            }
            if (!$isEnabled) {
                $data['is_current'] = 0;
                $data['status'] = 'disabled';
            } else {
                // 重新参与轮换时，从 disabled/exhausted/error 拉回 ready
                $prev = (string) ($existing['status'] ?? 'ready');
                if (in_array($prev, ['disabled', 'exhausted', 'error'], true)) {
                    $data['status'] = 'ready';
                    $data['last_error'] = null;
                }
            }
        }
        return $data;
    }

    /** @param array<string, mixed> $row */
    public function decorate(array $row): array
    {
        $apiKey = '';
        $password = '';
        try {
            $apiKey = Crypto::decrypt((string) ($row['api_key_enc'] ?? ''));
        } catch (\Throwable) {
            $apiKey = '';
        }
        try {
            $password = Crypto::decrypt((string) ($row['password_enc'] ?? ''));
        } catch (\Throwable) {
            $password = '';
        }
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['priority'] = (int) ($row['priority'] ?? 10);
        $row['is_enabled'] = (int) ($row['is_enabled'] ?? 0);
        $row['is_current'] = (int) ($row['is_current'] ?? 0);
        $row['credits'] = isset($row['credits']) && $row['credits'] !== null ? (int) $row['credits'] : null;
        $row['api_key'] = $apiKey;
        $row['password'] = $password;
        $row['masked_key'] = Crypto::mask($apiKey);
        $row['masked_password'] = $password !== '' ? Crypto::mask($password) : '';
        unset($row['api_key_enc'], $row['password_enc']);
        return $row;
    }
}
