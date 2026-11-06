<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\Logger;
use App\Core\Str;
use App\Core\Url;

/**
 * 禁止采集名单：平台、B2B/供应商平台、目录、社媒、非企业站等域名。
 * SERP 入库前对比名单，命中的域名不再入库；AI 判定不合格的域名自动加入。
 * 数据表缺失（尚未执行迁移）时所有方法静默降级，不影响主流程。
 */
final class BlocklistService
{
    /** @var array<string, string> */
    public const CATEGORIES = [
        'b2b_platform' => 'B2B/供应商平台',
        'marketplace' => 'B2C 电商平台/第三方店铺',
        'directory' => '黄页/名录/比价聚合',
        'social' => '社交媒体',
        'media' => '新闻/百科/论坛/博客',
        'gov_org' => '政府/协会/展会',
        'recruitment' => '招聘',
        'foreign_local' => '海外本地公司',
        'non_enterprise' => '非企业站/停放/空站',
        'platform' => '规则识别的平台',
        'other' => '其他不符合',
    ];

    /** 这些类别不进名单：企业本身合格，只是与当前任务城市不符，换城市任务时仍可能是目标。 */
    private const NO_BLOCK = ['city_mismatch', 'enterprise'];

    public static function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        if ($category === 'city_mismatch' || $category === 'enterprise') {
            return $category;
        }
        return isset(self::CATEGORIES[$category]) ? $category : 'other';
    }

    public static function shouldBlock(string $category): bool
    {
        return !in_array(self::normalizeCategory($category), self::NO_BLOCK, true);
    }

    /** @return array<int, string> 域名自身及其父域名（至少保留两段）。 */
    public static function candidates(string $domain): array
    {
        $domain = Url::normalizeDomain($domain) ?? '';
        if ($domain === '') {
            return [];
        }
        $out = [$domain];
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            return $out;
        }
        $parts = explode('.', $domain);
        while (count($parts) > 2) {
            array_shift($parts);
            $out[] = implode('.', $parts);
        }
        return $out;
    }

    public static function isBlocked(string $domain): bool
    {
        $candidates = self::candidates($domain);
        if ($candidates === []) {
            return false;
        }
        try {
            $db = Db::instance();
            $row = $db->fetch(
                'SELECT `domain` FROM `' . $db->tableName('domain_blocklist') . '` WHERE `domain` IN (' . self::placeholders(count($candidates)) . ') LIMIT 1',
                $candidates
            );
            if ($row === null) {
                return false;
            }
            self::hit([(string) $row['domain']]);
            return true;
        } catch (\Throwable $e) {
            Logger::error('Blocklist lookup failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 过滤掉名单内域名的搜索结果。
     *
     * @param array<int, array<string, mixed>> $rows 每行含 domain 字段
     * @return array<int, array<string, mixed>>
     */
    public static function filter(array $rows, int &$blocked = 0): array
    {
        $blocked = 0;
        if ($rows === []) {
            return $rows;
        }
        $all = [];
        $perRow = [];
        foreach ($rows as $i => $row) {
            $perRow[$i] = self::candidates((string) ($row['domain'] ?? ''));
            foreach ($perRow[$i] as $candidate) {
                $all[$candidate] = true;
            }
        }
        $all = array_slice(array_keys($all), 0, 1000);
        if ($all === []) {
            return $rows;
        }
        try {
            $db = Db::instance();
            $found = $db->fetchAll(
                'SELECT `domain` FROM `' . $db->tableName('domain_blocklist') . '` WHERE `domain` IN (' . self::placeholders(count($all)) . ')',
                $all
            );
        } catch (\Throwable $e) {
            Logger::error('Blocklist filter failed', ['error' => $e->getMessage()]);
            return $rows;
        }
        if ($found === []) {
            return $rows;
        }
        $set = [];
        foreach ($found as $item) {
            $set[(string) $item['domain']] = true;
        }
        $kept = [];
        $hits = [];
        foreach ($rows as $i => $row) {
            $matched = null;
            foreach ($perRow[$i] as $candidate) {
                if (isset($set[$candidate])) {
                    $matched = $candidate;
                    break;
                }
            }
            if ($matched !== null) {
                $blocked++;
                $hits[$matched] = true;
                continue;
            }
            $kept[] = $row;
        }
        if ($hits !== []) {
            self::hit(array_keys($hits));
        }
        return $kept;
    }

    /** @return bool 是否新增了一条名单记录。 */
    public static function add(string $domain, string $category, string $reason, string $source = 'ai', ?int $taskId = null, ?float $confidence = null): bool
    {
        $domain = Url::normalizeDomain($domain);
        if ($domain === null) {
            return false;
        }
        $category = self::normalizeCategory($category);
        if (!self::shouldBlock($category)) {
            return false;
        }
        try {
            return Db::instance()->insertIgnore('domain_blocklist', [
                'domain' => Str::limit($domain, 190, ''),
                'category' => $category,
                'reason' => Str::limit($reason, 490, ''),
                'source' => in_array($source, ['ai', 'rule', 'manual'], true) ? $source : 'ai',
                'confidence' => $confidence === null ? null : max(0.0, min(1.0, $confidence)),
                'task_id' => $taskId,
                'hit_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Logger::error('Blocklist add failed', ['domain' => $domain, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** @param array<int, string> $domains */
    private static function hit(array $domains): void
    {
        if ($domains === []) {
            return;
        }
        try {
            $db = Db::instance();
            $db->query(
                'UPDATE `' . $db->tableName('domain_blocklist') . '` SET `hit_count` = `hit_count` + 1, `last_hit_at` = ? WHERE `domain` IN (' . self::placeholders(count($domains)) . ')',
                array_merge([now()], array_values($domains))
            );
        } catch (\Throwable) {
            // 命中统计失败不影响采集。
        }
    }

    private static function placeholders(int $count): string
    {
        return implode(',', array_fill(0, max(1, $count), '?'));
    }
}
