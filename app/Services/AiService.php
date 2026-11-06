<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Logger;
use App\Core\Str;

/**
 * OpenAI-compatible relay client + the four prompt-driven AI jobs.
 *
 * Relays are tried in priority order. API keys are decrypted only in memory.
 */
final class AiService
{
    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed> $options
     */
    public function chat(array $messages, array $options = []): string
    {
        @set_time_limit(300);
        $relays = Db::instance()->table('ai_relays')->where('is_active', 1)->orderBy('priority')->orderBy('id')->get();
        if ($relays === []) {
            throw new HttpException(503, '尚未配置可用的 AI 中转站，请先在后台添加。', 'ai_not_configured');
        }

        $jsonMode = array_key_exists('json_mode', $options)
            ? (bool) $options['json_mode']
            : Settings::bool('ai_json_mode', true);
        $maxTokens = (int) ($options['max_tokens'] ?? 0);
        $lastError = '未知错误';
        $requestStarted = $this->requestStart();

        foreach ($relays as $relay) {
            $temperature = array_key_exists('temperature', $options)
                ? (float) $options['temperature']
                : (float) ($relay['temperature'] ?? 0.2);
            $apiKey = Crypto::decrypt((string) $relay['api_key']);
            if ($apiKey === '') {
                $lastError = '中转站「' . $relay['name'] . '」的 API Key 无法解密';
                continue;
            }
            $started = microtime(true);
            try {
                $body = [
                    'model' => (string) ($options['model'] ?? $relay['model']),
                    'messages' => $messages,
                    'temperature' => $temperature,
                    'max_tokens' => $maxTokens > 0 ? $maxTokens : (int) $relay['max_tokens'],
                    'stream' => false,
                ];
                if ($jsonMode) {
                    $body['response_format'] = ['type' => 'json_object'];
                }
                $attemptTimeout = $this->attemptTimeout((int) $relay['timeout'], $requestStarted);
                [$status, $decoded, $raw] = $this->request($relay, $apiKey, $body, $attemptTimeout);

                // Some relays do not implement response_format; retry once without it if time remains.
                if ($jsonMode && $status >= 400 && $status < 500 && $this->remainingSeconds($requestStarted) >= 18) {
                    unset($body['response_format']);
                    $attemptTimeout = $this->attemptTimeout((int) $relay['timeout'], $requestStarted);
                    [$status, $decoded, $raw] = $this->request($relay, $apiKey, $body, $attemptTimeout);
                }

                if ($status < 200 || $status >= 300) {
                    $message = $decoded['error']['message'] ?? $decoded['message'] ?? ('HTTP ' . $status . ' ' . Str::limit($raw, 300));
                    throw new \RuntimeException((string) $message);
                }

                $content = $decoded['choices'][0]['message']['content'] ?? null;
                if (is_array($content)) {
                    $parts = [];
                    foreach ($content as $part) {
                        if (is_array($part) && isset($part['text'])) {
                            $parts[] = (string) $part['text'];
                        }
                    }
                    $content = implode("\n", $parts);
                }
                if (!is_string($content) || trim($content) === '') {
                    throw new \RuntimeException('AI 返回了空内容');
                }

                Db::instance()->ensureConnected();
                Db::instance()->update('ai_relays', [
                    'last_used_at' => now(),
                    'last_error' => null,
                ], ['id' => (int) $relay['id']]);

                Logger::info('AI call succeeded', [
                    'relay' => $relay['name'],
                    'model' => $body['model'],
                    'prompt' => $options['prompt_code'] ?? null,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'tokens' => $decoded['usage'] ?? null,
                ]);
                return $content;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                try {
                    Db::instance()->ensureConnected();
                    Db::instance()->update('ai_relays', [
                        'last_error' => Str::limit($lastError, 900),
                    ], ['id' => (int) $relay['id']]);
                } catch (\Throwable) {
                    // Never mask the actual AI error with a secondary DB error.
                }
                Logger::error('AI relay failed', [
                    'relay' => $relay['name'],
                    'model' => $relay['model'],
                    'prompt' => $options['prompt_code'] ?? null,
                    'error' => $lastError,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
            }
        }

        throw new HttpException(502, 'AI 调用失败：' . Str::limit($lastError, 400), 'ai_failed');
    }

    /**
     * Ask for JSON and tolerate markdown fences / leading commentary.
     * @param array<int, array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    public function json(array $messages, array $options = []): array
    {
        $options['json_mode'] = true;
        $raw = $this->chat($messages, $options);
        $decoded = $this->decodeJson($raw);
        if ($decoded !== null) {
            return $decoded;
        }

        // Skip a second full chat when the PHP process is nearly out of time (common on free hosts).
        if ($this->remainingSeconds() < 20) {
            throw new HttpException(502, 'AI 返回内容无法解析为 JSON，且主机剩余执行时间不足，无法重试。', 'ai_invalid_json', ['raw' => Str::limit($raw, 1000)]);
        }

        $retryMessages = $messages;
        $retryMessages[] = ['role' => 'assistant', 'content' => Str::limit($raw, 6000, '')];
        $retryMessages[] = ['role' => 'user', 'content' => '上一次输出不是合法 JSON。请只输出符合要求的 JSON 对象，不要 Markdown 代码块、前言或注释。'];
        $raw = $this->chat($retryMessages, $options);
        $decoded = $this->decodeJson($raw);
        if ($decoded === null) {
            throw new HttpException(502, 'AI 返回内容无法解析为 JSON', 'ai_invalid_json', ['raw' => Str::limit($raw, 1000)]);
        }
        return $decoded;
    }

    private function requestStart(): float
    {
        return isset($_SERVER['REQUEST_TIME_FLOAT'])
            ? (float) $_SERVER['REQUEST_TIME_FLOAT']
            : microtime(true);
    }

    private function phpTimeBudget(): int
    {
        $phpLimit = (int) ini_get('max_execution_time');
        return $phpLimit > 0 ? max(20, $phpLimit - 8) : 120;
    }

    private function remainingSeconds(?float $startedAt = null): float
    {
        $startedAt ??= $this->requestStart();
        return $this->phpTimeBudget() - (microtime(true) - $startedAt);
    }

    private function attemptTimeout(int $relayTimeout, float $startedAt): int
    {
        $remaining = (int) floor($this->remainingSeconds($startedAt));
        if ($remaining < 12) {
            throw new HttpException(504, '服务器剩余执行时间不足，无法继续调用 AI。请减少关键词数量或换更快模型。', 'execution_timeout');
        }
        return max(12, min(300, $relayTimeout, $remaining, $this->phpTimeBudget()));
    }

    /** @return array<string, mixed>|null */
    private function decodeJson(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return null;
    }

    /** @return array{0:int,1:array<string,mixed>,2:string} */
    private function request(array $relay, string $apiKey, array $body, int $timeout): array
    {
        $base = rtrim((string) $relay['base_url'], '/');
        $url = str_ends_with($base, '/chat/completions') ? $base : $base . '/chat/completions';
        // Free hosts (e.g. InfinityFree) often hard-cap PHP at ~60s and disable set_time_limit.
        $phpLimit = (int) ini_get('max_execution_time');
        $budget = $phpLimit > 0 ? max(20, $phpLimit - 8) : 120;
        $timeout = max(20, min(300, $timeout, $budget));

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('无法初始化 cURL');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => (bool) config('app.ai_ssl_verify', true),
            CURLOPT_SSL_VERIFYHOST => config('app.ai_ssl_verify', true) ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'GoogleLeadEngine/' . APP_VERSION,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException('网络错误：' . ($error !== '' ? $error : 'cURL ' . $errno));
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        return [$status, $decoded, (string) $raw];
    }

    // ---------------------------------------------------------------------
    // Prompt-driven jobs
    // ---------------------------------------------------------------------

    /** @return array<int, array{keyword:string,intent:string,language:string}> */
    public function generateKeywords(array $task, int $count): array
    {
        $prompt = $this->prompt('keyword_generation');
        $categories = CategoryService::sanitize($task['categories'] ?? ($task['options']['categories'] ?? []));
        $variables = [
            'city' => (string) $task['city'],
            'country' => (string) $task['country'],
            'target_market' => (string) $task['target_market'],
            'count' => (string) $count,
            'current_year' => date('Y'),
            'categories' => CategoryService::promptList($categories),
        ];
        $result = $this->json([
            ['role' => 'system', 'content' => $this->render($prompt['system_prompt'], $variables)],
            ['role' => 'user', 'content' => $this->render($prompt['user_prompt'], $variables) . $this->storeFirstRules((string) $prompt['user_prompt'], $variables) . $this->keywordCategoryInstruction($categories, $count, (string) $task['city'])],
        ], ['prompt_code' => 'keyword_generation', 'temperature' => 0.5, 'max_tokens' => 3000]);

        $items = $result['keywords'] ?? $result;
        if (!is_array($items)) {
            throw new HttpException(502, 'AI 未返回关键词列表', 'ai_invalid_output');
        }
        $keywords = [];
        $seen = [];
        foreach ($items as $item) {
            $keyword = is_array($item) ? (string) ($item['keyword'] ?? $item['query'] ?? '') : (string) $item;
            $keyword = trim(preg_replace('/\s+/', ' ', $keyword) ?? '');
            if ($keyword === '' || mb_strlen($keyword) > 250) {
                continue;
            }
            $key = mb_strtolower($keyword);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $intent = is_array($item) ? (string) ($item['intent'] ?? 'official') : 'official';
            $intent = preg_match('/^[a-z_]{1,30}$/', $intent) ? $intent : 'official';
            $language = is_array($item) ? (string) ($item['language'] ?? 'en') : 'en';
            $keywords[] = [
                'keyword' => $keyword,
                'intent' => $intent,
                'language' => preg_match('/^[a-z\-]{2,10}$/', $language) ? $language : 'en',
            ];
            if (count($keywords) >= $count) {
                break;
            }
        }
        if ($keywords === []) {
            throw new HttpException(502, 'AI 没有生成有效关键词，请检查提示词或更换模型。', 'ai_empty_keywords');
        }
        return $keywords;
    }

    /**
     * @param array<int, array<string, mixed>> $websites
     * @return array<int, array{n:int,is_target:bool,confidence:float,reason:string}>
     */
    public function classifyDomains(array $task, array $websites): array
    {
        $prompt = $this->prompt('domain_classification');
        $businesses = [];
        foreach (array_values($websites) as $index => $website) {
            $businesses[] = [
                'n' => $index + 1,
                'domain' => (string) $website['domain'],
                'title' => Str::limit((string) ($website['title'] ?? ''), 160),
                'snippet' => Str::limit((string) ($website['snippet'] ?? ''), 260),
                'url' => Str::limit((string) ($website['sample_url'] ?? ''), 240),
            ];
        }
        $variables = [
            'city' => (string) $task['city'],
            'country' => (string) $task['country'],
            'businesses' => json_encode($businesses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $result = $this->json([
            ['role' => 'system', 'content' => $this->render($prompt['system_prompt'], $variables)],
            ['role' => 'user', 'content' => $this->render($prompt['user_prompt'], $variables) . $this->classificationCategoryInstruction((string) $prompt['user_prompt'])],
        ], ['prompt_code' => 'domain_classification', 'temperature' => 0.1, 'max_tokens' => 4000]);

        $rows = $result['results'] ?? $result;
        if (!is_array($rows)) {
            throw new HttpException(502, 'AI 未返回企业判断结果', 'ai_invalid_output');
        }
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $n = (int) ($row['n'] ?? $row['index'] ?? 0);
            if ($n < 1) {
                continue;
            }
            $isTarget = $row['is_target'] ?? $row['is_chinese_enterprise'] ?? $row['target'] ?? false;
            if (is_string($isTarget)) {
                $isTarget = in_array(strtolower($isTarget), ['1', 'true', 'yes', '是'], true);
            }
            $normalized[$n] = [
                'n' => $n,
                'is_target' => (bool) $isTarget,
                'category' => BlocklistService::normalizeCategory((string) ($row['category'] ?? ($isTarget ? 'enterprise' : 'other'))),
                'confidence' => max(0.0, min(1.0, (float) ($row['confidence'] ?? 0.5))),
                'reason' => Str::limit((string) ($row['reason'] ?? ''), 450),
            ];
        }
        if ($normalized === []) {
            throw new HttpException(502, 'AI 返回的企业判断结果为空', 'ai_empty_classification');
        }
        return array_values($normalized);
    }

    /**
     * 让 AI 对不合格域名返回 category，用于写入禁止采集名单。
     * 提示词里已自带 category 要求（后台自行改过）时不再追加。
     */
    private function classificationCategoryInstruction(string $userPrompt): string
    {
        if (str_contains($userPrompt, 'category')) {
            return '';
        }
        return "\n\n补充要求：每个结果必须额外返回 category 字段。is_target 为 true 时填 \"enterprise\"；为 false 时取下列之一："
            . "b2b_platform（B2B 平台/供应商平台/批发平台）、marketplace（B2C 电商平台/第三方店铺）、directory（黄页/名录/比价聚合）、"
            . "social（社交媒体）、media（新闻/百科/论坛/博客）、gov_org（政府/协会/展会）、recruitment（招聘）、"
            . "foreign_local（海外本地公司）、non_enterprise（停放域名/空站/个人站/非企业独立站）、"
            . "city_mismatch（是合格的中国企业独立站，但与目标城市无关）、other（其他不符合）。\n"
            . '返回示例：{"results":[{"n":1,"is_target":false,"category":"b2b_platform","confidence":0.9,"reason":"B2B 供应商平台"}]}';
    }

    /**
     * @param array<int, array{url:string,text:string}> $links
     * @return array{home:?string,about:?string,contact:?string}
     */
    public function discoverPages(array $task, array $website, array $links): array
    {
        $prompt = $this->prompt('page_discovery');
        $variables = [
            'domain' => (string) $website['domain'],
            'home_url' => (string) $website['root_url'],
            'links' => json_encode(array_values($links), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $result = $this->json([
            ['role' => 'system', 'content' => $this->render($prompt['system_prompt'], $variables)],
            ['role' => 'user', 'content' => $this->render($prompt['user_prompt'], $variables)],
        ], ['prompt_code' => 'page_discovery', 'temperature' => 0.1, 'max_tokens' => 1800]);

        $normalize = static function (mixed $value): ?string {
            if (!is_string($value)) {
                return null;
            }
            $value = trim($value);
            return preg_match('#^https?://#i', $value) ? $value : null;
        };
        return [
            'home' => $normalize($result['home'] ?? $result['home_url'] ?? null),
            'about' => $normalize($result['about'] ?? $result['about_url'] ?? null),
            'contact' => $normalize($result['contact'] ?? $result['contact_url'] ?? null),
        ];
    }

    /**
     * 电话标准化：把每家企业抓取到的原始电话整理成 E.164 并判断手机/座机。
     * 优先使用后台「提示词模板」里的 phone_normalization，没有时用内置默认模板。
     *
     * @param array<int, array<string, mixed>> $items 每项含 n、company、domain、phones_raw 等
     * @return array<int, array{numbers:array<int, array<string, mixed>>}> 以 n 为键
     */
    public function normalizePhones(array $task, array $items): array
    {
        try {
            $prompt = $this->prompt('phone_normalization');
        } catch (\Throwable) {
            $prompt = require BASE_PATH . '/app/Data/phone_prompt.php';
        }
        $variables = [
            'city' => (string) ($task['city'] ?? ''),
            'default_country' => '86（中国大陆）',
            'companies' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $result = $this->json([
            ['role' => 'system', 'content' => $this->render((string) $prompt['system_prompt'], $variables)],
            ['role' => 'user', 'content' => $this->render((string) $prompt['user_prompt'], $variables)],
        ], ['prompt_code' => 'phone_normalization', 'temperature' => 0.0, 'max_tokens' => 4000]);

        $rows = $result['results'] ?? $result;
        if (!is_array($rows)) {
            throw new HttpException(502, 'AI 未返回电话标准化结果', 'ai_invalid_output');
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $n = (int) ($row['n'] ?? $row['index'] ?? 0);
            if ($n < 1) {
                continue;
            }
            $numbers = $row['numbers'] ?? $row['phones'] ?? [];
            $out[$n] = ['numbers' => is_array($numbers) ? array_values(array_filter($numbers, 'is_array')) : []];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function extractContacts(array $task, array $website, string $pagesText): array
    {
        $prompt = $this->prompt('contact_extraction');
        $variables = [
            'domain' => (string) $website['domain'],
            'pages_text' => $pagesText,
        ];
        $result = $this->json([
            ['role' => 'system', 'content' => $this->render($prompt['system_prompt'], $variables)],
            ['role' => 'user', 'content' => $this->render($prompt['user_prompt'], $variables)],
        ], ['prompt_code' => 'contact_extraction', 'temperature' => 0.0, 'max_tokens' => 2200]);
        return $result;
    }

    /**
     * 「独立商城站优先」规则：数据库里的模板还没有该规则时由代码强制追加，保证关键词一定偏向搜出独立站。
     *
     * @param array<string, string> $variables
     */
    private function storeFirstRules(string $userPrompt, array $variables): string
    {
        if (str_contains($userPrompt, '独立商城站优先策略')) {
            return '';
        }
        $file = BASE_PATH . '/app/Data/keyword_store_rules.php';
        $rules = is_file($file) ? require $file : '';
        if (!is_string($rules) || $rules === '') {
            return '';
        }
        return "\n\n" . $this->render($rules, $variables);
    }

    /**
     * 关键词生成的分类补充要求。
     * 选了分类：按「城市 + 产品分类」生成企业独立站与门户站搜索词，并覆盖各分类。
     * 没选分类：不追加任何内容，沿用模板里仅按城市生成的规则。
     *
     * @param array<int, string> $categories
     */
    private function keywordCategoryInstruction(array $categories, int $count, string $city): string
    {
        if ($categories === []) {
            return '';
        }
        $list = CategoryService::promptList($categories);
        $perCategory = max(1, (int) floor($count / count($categories)));
        return "\n\n【产品分类定向要求（优先级高于上文的行业发散规则）】\n"
            . "本次任务只针对下列产品分类（格式：英文名 | 中文名 | 所属大类），所有关键词必须是「{$city} + 产品分类」的组合：\n{$list}\n\n"
            . "1. 每个关键词必须同时包含城市英文名和某一个分类的英文产品词（可使用该分类英文名的核心词或常见同义词），不要出现未选分类的产品。\n"
            . "2. 关键词总数约 {$count} 条，尽量让每个分类至少有 {$perCategory} 条，所选分类较多时优先覆盖全部分类。\n"
            . "3. 以「独立商城站优先策略」为主：每个分类的关键词绝大多数（约 80%）面向独立站，少量（约 20%）面向行业门户站。两类网站如下：\n"
            . "   - 企业独立站/品牌官网/DTC 独立商城：manufacturer、factory、official website、brand、online store、OEM/ODM、custom。\n"
            . "   - 行业门户站/垂直行业网站：industry portal、trade portal、industry directory、association、expo、supplier guide，用于发现该产品领域的门户站和行业垂直站。\n"
            . "4. intent 字段：企业独立站类使用 manufacturer/official/dtc/buyer，门户站类使用 portal；language 固定为 \"en\"。\n"
            . "5. 仍然不得使用 site:、引号、减号、OR 等运算符，不要编造具体企业名称。\n"
            . '仅返回 JSON：{"keywords":[{"keyword":"Wuhan LED lamps manufacturer","intent":"manufacturer","language":"en"},{"keyword":"Wuhan LED lighting industry portal","intent":"portal","language":"en"}]}';
    }

    /** @return array<string, mixed> */
    private function prompt(string $code): array
    {
        $prompt = Db::instance()->table('prompts')->where('code', $code)->where('is_active', 1)->first();
        if ($prompt === null) {
            throw new HttpException(500, '提示词模板缺失：' . $code . '，请在后台检查。', 'prompt_missing');
        }
        return $prompt;
    }

    /** @param array<string, string> $variables */
    private function render(string $template, array $variables): string
    {
        $replace = [];
        foreach ($variables as $key => $value) {
            $replace['{{' . $key . '}}'] = $value;
        }
        return strtr($template, $replace);
    }
}
