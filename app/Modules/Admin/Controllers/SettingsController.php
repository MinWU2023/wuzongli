<?php
declare(strict_types=1);

namespace App\Modules\Admin\Controllers;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Db;
use App\Core\HttpException;
use App\Services\SerperService;
use App\Services\Settings;

final class SettingsController extends AdminController
{
    public function index(): void
    {
        $settings = Settings::all() + Settings::defaults();
        $db = Db::instance();
        $this->renderAdmin('settings/index', [
            'settings' => $settings,
            'apiKey' => (string) config('app.api_key', ''),
            'apiBase' => absolute_url(''),
            'siteUrl' => (string) config('app.url', ''),
            'mysql' => (string) ($db->value('SELECT VERSION()') ?? ''),
            'php' => PHP_VERSION,
            'relayCount' => (int) ($db->value('SELECT COUNT(*) FROM `' . $db->tableName('ai_relays') . '` WHERE `is_active` = 1') ?? 0),
            'serperMasked' => Crypto::mask(SerperService::apiKey()),
            'serperConfigured' => SerperService::configured(),
            'serperProvider' => (string) ($settings['search_provider'] ?? 'auto'),
        ], '系统设置', 'settings');
    }

    public function update(): never
    {
        $this->guardCsrf();
        $keywordCount = max(6, min(60, $this->request->int('keyword_count', 24)));
        $serpPages = max(1, min(10, $this->request->int('serp_pages', 3)));
        $batch = max(5, min(50, $this->request->int('classify_batch', 30)));
        $attempts = max(1, min(10, $this->request->int('max_attempts', 3)));
        $delay = max(500, min(15000, $this->request->int('request_delay_ms', 2500)));
        $googleDomain = strtolower(trim($this->request->string('google_domain', 'www.google.com')));
        $googleDomain = preg_replace('#^https?://#', '', $googleDomain) ?? '';
        $googleDomain = rtrim(explode('/', $googleDomain)[0], '/');
        if (!preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}$/i', $googleDomain)) {
            flash('error', 'Google 域名格式不正确。');
            $this->back();
        }

        $provider = strtolower(trim($this->request->string('search_provider', 'auto')));
        if (!in_array($provider, ['auto', 'serper', 'browser'], true)) {
            $provider = 'auto';
        }
        if ($provider === 'serper' && !SerperService::configured()) {
            flash('error', '选择「强制使用 Serper」前请先在「Serper 账号」添加并启用至少一个 API Key。');
            $this->back();
        }
        $serperBase = trim($this->request->string('serper_base_url', SerperService::DEFAULT_ENDPOINT));
        if ($serperBase === '') {
            $serperBase = SerperService::DEFAULT_ENDPOINT;
        }
        if (!filter_var($serperBase, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $serperBase)) {
            flash('error', 'Serper 接口地址格式不正确。');
            $this->back();
        }
        $serperGl = strtolower(trim($this->request->string('serper_gl', 'us')));
        $serperHl = strtolower(trim($this->request->string('serper_hl', 'en')));
        if (!preg_match('/^[a-z]{2}$/', $serperGl)) {
            flash('error', 'Serper 国家代码应为两个字母，例如 us、uk。');
            $this->back();
        }
        if (!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $serperHl)) {
            flash('error', 'Serper 语言代码格式不正确，例如 en、zh-cn。');
            $this->back();
        }
        $serperTimeout = max(5, min(60, $this->request->int('serper_timeout', 30)));

        Settings::setMany([
            'keyword_count' => $keywordCount,
            'serp_pages' => $serpPages,
            'classify_batch' => $batch,
            'max_attempts' => $attempts,
            'request_delay_ms' => $delay,
            'google_domain' => $googleDomain,
            'ai_json_mode' => $this->request->boolean('ai_json_mode') ? 1 : 0,
            'search_provider' => $provider,
            'serper_base_url' => $serperBase,
            'serper_gl' => $serperGl,
            'serper_hl' => $serperHl,
            'serper_timeout' => $serperTimeout,
        ]);
        flash('success', '系统设置已保存。');
        $this->go('/admin/settings');
    }

    public function testSerper(): never
    {
        $this->guardCsrf();
        try {
            $result = (new SerperService())->search('OpenAI', 1, 'en');
            $credits = $result['credits'] === null ? '' : '，剩余额度 ' . $result['credits'];
            flash('success', 'Serper 连接成功，返回 ' . count($result['results']) . ' 条结果' . $credits . '。');
        } catch (HttpException $e) {
            flash('error', 'Serper 测试失败：' . $e->getMessage());
        } catch (\Throwable $e) {
            flash('error', 'Serper 测试失败：' . $e->getMessage());
        }
        $this->go('/admin/settings');
    }

    public function regenerateKey(): never
    {
        $this->guardCsrf();
        $configFile = CONFIG_PATH . '/config.php';
        $config = require $configFile;
        if (!is_array($config)) {
            flash('error', '配置文件格式错误，无法重新生成 API Key。');
            $this->go('/admin/settings');
        }
        $newKey = 'gle_' . bin2hex(random_bytes(24));
        $config['app']['api_key'] = $newKey;
        $php = "<?php\n\ndeclare(strict_types=1);\n\n// Generated by install.php on " . date('Y-m-d H:i:s') . ".\n// Do not commit or share this file.\n\nreturn " . var_export($config, true) . ";\n";
        $tmp = $configFile . '.tmp';
        $written = file_put_contents($tmp, $php, LOCK_EX) !== false;
        if ($written) {
            // rename() cannot reliably replace an existing file on Windows.
            if (!@rename($tmp, $configFile)) {
                $written = @copy($tmp, $configFile);
                @unlink($tmp);
            }
        } else {
            @unlink($tmp);
        }
        if (!$written) {
            flash('error', '无法写入 config/config.php，请检查目录权限。');
        } else {
            @chmod($configFile, 0640);
            Config::set('app.api_key', $newKey);
            flash('success', 'API Key 已重新生成，请立即更新谷歌插件设置；旧 Key 已失效。');
        }
        $this->go('/admin/settings');
    }
}
