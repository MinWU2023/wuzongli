<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;

/**
 * 电话号码标准化的规则层：
 * - 把 AI 返回的结果再校验一遍（E.164 格式、中国号码手机/座机规则），AI 出错时由规则兜底；
 * - 选出主号码（手机优先）和备用号码，输出可直接拨号的 E.164 格式（如 +8613812345678）。
 */
final class PhoneService
{
    public const TYPES = ['mobile', 'landline', 'toll_free', 'voip', 'fax', 'unknown'];

    public const TYPE_LABELS = [
        'mobile' => '手机',
        'landline' => '座机',
        'toll_free' => '400/800',
        'voip' => '网络电话',
        'fax' => '传真',
        'unknown' => '未知',
    ];

    /** 电话标准化字段（迁移 000012）是否已存在；未迁移时整个阶段自动跳过。 */
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        if (self::$ready !== null) {
            return self::$ready;
        }
        try {
            $db = Db::instance();
            $db->fetch('SELECT `phone_status` FROM `' . $db->tableName('companies') . '` LIMIT 1');
            return self::$ready = true;
        } catch (\Throwable) {
            return self::$ready = false;
        }
    }

    /** 标准化的原始输入：优先使用首次保存的原始电话，保证回退重跑时不被已整理的结果污染。 */
    public static function sourceText(array $company): string
    {
        $raw = trim((string) ($company['phone_raw'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }
        $parts = [];
        foreach (['phone', 'phone_alt'] as $key) {
            $value = trim((string) ($company[$key] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        return implode(', ', $parts);
    }

    /** @return array<int, string> */
    public static function split(string $text): array
    {
        $parts = preg_split('/[;,，；、|\r\n]+/u', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * 规则解析单个原始号码；无法确定国家/区号时返回 null（不猜测）。
     *
     * @return array{raw:string,dial:string,type:string,cc:string,display:string,ext:string,source:string}|null
     */
    public static function fromRaw(string $raw): ?array
    {
        $original = trim($raw);
        if ($original === '') {
            return null;
        }
        $isFax = (bool) preg_match('/fax|传真/iu', $original);
        $ext = '';
        $clean = $original;
        if (preg_match('/(?:ext\.?|extension|x|转|分机)\s*[:：]?\s*(\d{1,6})\s*$/iu', $clean, $m)) {
            $ext = $m[1];
            $clean = (string) preg_replace('/(?:ext\.?|extension|x|转|分机)\s*[:：]?\s*\d{1,6}\s*$/iu', '', $clean);
        }
        $clean = (string) preg_replace('/\(\s*0\s*\)/', '', $clean);
        $hasPlus = (bool) preg_match('/(^|[^\d])\+\s*\d/', $clean);
        $digits = (string) preg_replace('/\D+/', '', $clean);
        if ($digits === '') {
            return null;
        }
        if (!$hasPlus && str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
            $hasPlus = true;
        }

        if ($hasPlus) {
            $dial = '+' . $digits;
        } elseif (preg_match('/^1[3-9]\d{9}$/', $digits)) {
            $dial = '+86' . $digits;
        } elseif (preg_match('/^(400|800)\d{7}$/', $digits)) {
            $dial = '+86' . $digits;
        } elseif (preg_match('/^86(1[3-9]\d{9})$/', $digits, $m)) {
            $dial = '+86' . $m[1];
        } elseif (preg_match('/^0(\d{9,11})$/', $digits, $m)) {
            $dial = '+86' . $m[1];
        } elseif (preg_match('/^86([1-9]\d{8,10})$/', $digits, $m)) {
            $dial = '+86' . $m[1];
        } else {
            return null;
        }
        return self::build($original, $dial, $isFax, $ext);
    }

    /**
     * 校验并修正 AI 返回的单个号码；不合格时回退到规则解析原始号码。
     *
     * @param array<string, mixed> $n
     * @return array{raw:string,dial:string,type:string,cc:string,display:string,ext:string,source:string}|null
     */
    public static function sanitizeAi(array $n): ?array
    {
        $raw = trim((string) ($n['raw'] ?? $n['original'] ?? ''));
        $dial = (string) preg_replace('/[^\d+]/', '', (string) ($n['e164'] ?? $n['dial'] ?? ''));
        $valid = $n['valid'] ?? true;
        if (is_string($valid)) {
            $valid = !in_array(strtolower($valid), ['0', 'false', 'no', '否'], true);
        }
        if (!$valid || !preg_match('/^\+[1-9]\d{7,14}$/', $dial)) {
            return $raw !== '' ? self::fromRaw($raw) : null;
        }
        $type = strtolower(trim((string) ($n['type'] ?? 'unknown')));
        if (!in_array($type, self::TYPES, true)) {
            $type = 'unknown';
        }
        if (str_starts_with($dial, '+86')) {
            $ruleType = self::classifyCn(substr($dial, 3));
            if ($ruleType === null) {
                return $raw !== '' ? self::fromRaw($raw) : null;
            }
            if ($type !== 'fax') {
                $type = $ruleType;
            }
        }
        $ext = (string) preg_replace('/\D+/', '', (string) ($n['ext'] ?? ''));
        $entry = self::build($raw !== '' ? $raw : $dial, $dial, $type === 'fax', $ext);
        if ($type !== 'fax') {
            $entry['type'] = $type;
        }
        $display = trim((string) ($n['display'] ?? ''));
        if ($display !== '' && mb_strlen($display) <= 40) {
            $entry['display'] = $display;
        }
        $entry['source'] = 'ai';
        return $entry;
    }

    /**
     * 合并 AI 结果与规则解析结果（按号码去重），AI 漏掉但规则能确定的号码会补上。
     *
     * @param array<int, mixed> $aiNumbers
     * @return array<int, array{raw:string,dial:string,type:string,cc:string,display:string,ext:string,source:string}>
     */
    public static function finalize(array $aiNumbers, string $source): array
    {
        $out = [];
        foreach ($aiNumbers as $n) {
            if (!is_array($n)) {
                continue;
            }
            $entry = self::sanitizeAi($n);
            if ($entry !== null && !isset($out[$entry['dial']])) {
                $out[$entry['dial']] = $entry;
            }
        }
        foreach (self::split($source) as $part) {
            $entry = self::fromRaw($part);
            if ($entry !== null && !isset($out[$entry['dial']])) {
                $out[$entry['dial']] = $entry;
            }
        }
        return array_values($out);
    }

    /**
     * 选主号码和备用号码：手机 > 座机 > 网络电话 > 400/800 > 未知，传真不参与。
     *
     * @param array<int, array<string, mixed>> $numbers
     * @return array{primary:?array<string, mixed>,alts:array<int, string>}
     */
    public static function pick(array $numbers): array
    {
        $rank = ['mobile' => 0, 'landline' => 1, 'voip' => 2, 'toll_free' => 3, 'unknown' => 4];
        $usable = array_values(array_filter($numbers, static fn (array $n): bool => ($n['type'] ?? '') !== 'fax'));
        usort($usable, static fn (array $a, array $b): int => ($rank[$a['type']] ?? 9) <=> ($rank[$b['type']] ?? 9));
        if ($usable === []) {
            return ['primary' => null, 'alts' => []];
        }
        $primary = $usable[0];
        $alts = [];
        foreach (array_slice($usable, 1) as $n) {
            $alts[] = (string) $n['dial'];
        }
        return ['primary' => $primary, 'alts' => $alts];
    }

    /** 中国大陆号码（去掉 +86 后）的类型：mobile / toll_free / landline，不合规返回 null。 */
    public static function classifyCn(string $national): ?string
    {
        if (preg_match('/^1[3-9]\d{9}$/', $national)) {
            return 'mobile';
        }
        if (preg_match('/^(400|800)\d{7}$/', $national)) {
            return 'toll_free';
        }
        if (preg_match('/^(10\d{8}|[2-9]\d{8,10})$/', $national)) {
            return 'landline';
        }
        return null;
    }

    /** @return array{raw:string,dial:string,type:string,cc:string,display:string,ext:string,source:string}|null */
    private static function build(string $raw, string $dial, bool $isFax, string $ext): ?array
    {
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $dial)) {
            return null;
        }
        $digits = substr($dial, 1);
        $cc = self::countryCode($digits);
        $type = 'unknown';
        if ($cc === '86') {
            $type = self::classifyCn(substr($digits, 2));
            if ($type === null) {
                return null;
            }
        }
        if ($isFax) {
            $type = 'fax';
        }
        return [
            'raw' => $raw,
            'dial' => $dial,
            'type' => $type,
            'cc' => $cc,
            'display' => '+' . $cc . ' ' . substr($digits, strlen($cc)),
            'ext' => $ext,
            'source' => 'rule',
        ];
    }

    private static function countryCode(string $digits): string
    {
        if ($digits === '') {
            return '';
        }
        if (in_array($digits[0], ['1', '7'], true)) {
            return $digits[0];
        }
        $two = ['20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45', '46', '47', '48', '49', '51', '52', '53', '54', '55', '56', '57', '58', '60', '61', '62', '63', '64', '65', '66', '81', '82', '84', '86', '90', '91', '92', '93', '94', '95', '98'];
        if (in_array(substr($digits, 0, 2), $two, true)) {
            return substr($digits, 0, 2);
        }
        return substr($digits, 0, 3);
    }
}
