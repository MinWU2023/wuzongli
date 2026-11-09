<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Migrator;
use App\Core\Session;

/** @var App\Core\App $app */
$app = require __DIR__ . '/app/bootstrap.php';
Session::start();

$configFile = CONFIG_PATH . '/config.php';
$lockFile = STORAGE_PATH . '/installed.lock';
$alreadyInstalled = is_file($lockFile) && is_file($configFile);

$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
$extensions = [
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'curl' => extension_loaded('curl'),
    'dom' => extension_loaded('dom'),
    'mbstring' => extension_loaded('mbstring'),
    'openssl' => extension_loaded('openssl'),
    'json' => extension_loaded('json'),
];
$envOk = $phpOk && !in_array(false, $extensions, true);
$writable = is_writable(CONFIG_PATH) && is_writable(STORAGE_PATH);

$defaultUrl = '';
if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $defaultUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . APP_BASE_PATH;
}

$form = [
    'db_host' => 'localhost',
    'db_port' => '3306',
    'db_name' => '',
    'db_user' => '',
    'db_prefix' => 'gl_',
    'site_url' => $defaultUrl,
    'admin_user' => 'admin',
    'admin_name' => '站点管理员',
    'ai_name' => '主 AI 中转站',
    'ai_base_url' => 'https://api.openai.com/v1',
    'ai_model' => 'gpt-4o-mini',
    'ai_key' => '',
    'serper_key' => '',
];
$error = null;
$success = null;

if (!$alreadyInstalled && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach ($form as $key => $value) {
        if (isset($_POST[$key]) && is_string($_POST[$key])) {
            $form[$key] = trim($_POST[$key]);
        }
    }

    try {
        Csrf::assert(isset($_POST['_token']) ? (string) $_POST['_token'] : null);

        if (!$envOk) {
            throw new RuntimeException('服务器环境未满足要求，请先安装缺失的 PHP 扩展。');
        }
        if (!$writable) {
            throw new RuntimeException('config/ 或 storage/ 目录不可写，请将目录权限设置为可写（例如 755/775）。');
        }

        $dbHost = trim($form['db_host']);
        $dbPort = (int) $form['db_port'];
        $dbName = trim($form['db_name']);
        $dbUser = trim($form['db_user']);
        $dbPass = (string) ($_POST['db_pass'] ?? '');
        $prefix = trim($form['db_prefix']);

        if ($dbHost === '' || $dbName === '' || $dbUser === '') {
            throw new RuntimeException('请填写数据库地址、数据库名和用户名。');
        }
        if ($dbPort < 1 || $dbPort > 65535) {
            throw new RuntimeException('数据库端口不正确。');
        }
        if (!preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $dbName)) {
            throw new RuntimeException('数据库名只能包含字母、数字、下划线和短横线。');
        }
        if (!preg_match('/^[a-zA-Z0-9_]{0,20}$/', $prefix)) {
            throw new RuntimeException('数据表前缀只能包含字母、数字和下划线，最长 20 位。');
        }

        $adminUser = trim($form['admin_user']);
        $adminPass = (string) ($_POST['admin_pass'] ?? '');
        $adminPassConfirm = (string) ($_POST['admin_pass_confirm'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9_\-\.]{3,40}$/', $adminUser)) {
            throw new RuntimeException('管理员账号需为 3–40 位字母、数字、下划线、点或短横线。');
        }
        if (strlen($adminPass) < 8) {
            throw new RuntimeException('管理员密码至少需要 8 位。');
        }
        if (!hash_equals($adminPass, $adminPassConfirm)) {
            throw new RuntimeException('两次输入的管理员密码不一致。');
        }

        $siteUrl = rtrim(trim($form['site_url']), '/');
        if ($siteUrl === '') {
            $siteUrl = rtrim($defaultUrl, '/');
        }
        if (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $siteUrl)) {
            throw new RuntimeException('站点地址格式不正确，例如 https://example.com。');
        }

        $aiBase = rtrim(trim($form['ai_base_url']), '/');
        $aiKey = trim($form['ai_key']);
        $aiModel = trim($form['ai_model']);
        $aiName = trim($form['ai_name']) ?: '主 AI 中转站';
        if (($aiBase !== '' || $aiKey !== '') && ($aiBase === '' || $aiKey === '' || $aiModel === '')) {
            throw new RuntimeException('如需配置 AI 中转站，请同时填写接口地址、API Key 和模型名；也可以留空稍后在后台配置。');
        }
        if ($aiBase !== '' && (!filter_var($aiBase, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $aiBase))) {
            throw new RuntimeException('AI 接口地址格式不正确，例如 https://api.openai.com/v1。');
        }
        $serperKey = trim($form['serper_key']);
        if ($serperKey !== '' && (strlen($serperKey) < 10 || strlen($serperKey) > 200 || !preg_match('/^[A-Za-z0-9_\-]+$/', $serperKey))) {
            throw new RuntimeException('Serper API Key 格式不正确，请检查是否复制完整。');
        }

        $appKey = bin2hex(random_bytes(32));
        $apiKey = 'gle_' . bin2hex(random_bytes(24));
        $dbConfig = [
            'host' => $dbHost,
            'port' => $dbPort,
            'database' => $dbName,
            'username' => $dbUser,
            'password' => $dbPass,
            'charset' => 'utf8mb4',
            'prefix' => $prefix,
            'timezone' => 'Asia/Shanghai',
        ];

        // 1. Prefer connecting to the existing database. Many shared hosts
        // pre-create the database and forbid CREATE DATABASE for the web user.
        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
        ];
        $dbDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
        try {
            $checkPdo = new PDO($dbDsn, $dbUser, $dbPass, $pdoOptions);
            $mysqlVersion = (string) $checkPdo->query('SELECT VERSION()')->fetchColumn();
            $checkPdo = null;
        } catch (PDOException $dbError) {
            // The database may not exist yet; try to create it if permitted.
            try {
                $serverDsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort);
                $serverPdo = new PDO($serverDsn, $dbUser, $dbPass, $pdoOptions);
                $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $mysqlVersion = (string) $serverPdo->query('SELECT VERSION()')->fetchColumn();
                $serverPdo = null;
            } catch (PDOException $createError) {
                throw new RuntimeException(
                    '无法连接数据库。请先在主机控制面板创建数据库，并确认数据库名、用户名、密码和地址正确。'
                    . '（' . $createError->getMessage() . '）'
                );
            }
        }

        // 2. Connect through the framework and run migrations.
        Db::init($dbConfig);
        $migrator = new Migrator(Db::instance(), BASE_PATH . '/migrations');
        $applied = $migrator->up();

        // 3. Create / update the administrator.
        $existingAdmin = Db::instance()->table('admins')->where('username', $adminUser)->first();
        $adminData = [
            'username' => $adminUser,
            'password_hash' => password_hash($adminPass, PASSWORD_DEFAULT),
            'display_name' => trim($form['admin_name']) ?: '站点管理员',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($existingAdmin !== null) {
            Db::instance()->update('admins', $adminData, ['id' => (int) $existingAdmin['id']]);
        } else {
            $adminData['created_at'] = date('Y-m-d H:i:s');
            Db::instance()->insert('admins', $adminData);
        }

        // 4. Seed the optional AI relay. The key is encrypted with APP_KEY.
        Config::set('app.key', $appKey);
        if ($serperKey !== '') {
            Db::instance()->upsert('settings', [
                'setting_key' => 'search_provider',
                'setting_value' => 'auto',
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['setting_value', 'updated_at']);
            Db::instance()->upsert('settings', [
                'setting_key' => 'serper_api_key',
                'setting_value' => Crypto::encrypt($serperKey),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['setting_value', 'updated_at']);
        }
        if ($aiBase !== '' && $aiKey !== '') {
            Db::instance()->insert('ai_relays', [
                'name' => $aiName,
                'base_url' => $aiBase,
                'api_key' => Crypto::encrypt($aiKey),
                'model' => $aiModel,
                'temperature' => 0.20,
                'max_tokens' => 4096,
                'timeout' => 120,
                'priority' => 10,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        // 5. Write config.php only after the database is ready.
        $config = [
            'app' => [
                'name' => '外贸客 · Google 线索引擎',
                'url' => $siteUrl,
                'timezone' => 'Asia/Shanghai',
                'debug' => false,
                'key' => $appKey,
                'api_key' => $apiKey,
            ],
            'db' => $dbConfig,
        ];
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
            throw new RuntimeException('无法写入 config/config.php，请检查目录权限。');
        }
        @chmod($configFile, 0640);

        $lock = [
            'installed_at' => date('c'),
            'version' => APP_VERSION,
            'php' => PHP_VERSION,
            'mysql' => $mysqlVersion,
        ];
        file_put_contents($lockFile, json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        @chmod($lockFile, 0640);

        Config::load($config);
        $sitePath = (string) (parse_url($siteUrl, PHP_URL_PATH) ?: '');
        $baseSuffix = APP_BASE_PATH;
        if ($baseSuffix !== '' && str_ends_with(rtrim($sitePath, '/'), $baseSuffix)) {
            $baseSuffix = '';
        }
        $appRoot = rtrim($siteUrl, '/') . $baseSuffix;
        $success = [
            'admin_url' => $appRoot . '/admin',
            'install_url' => $appRoot . '/install.php',
            'api_base' => $appRoot,
            'api_key' => $apiKey,
            'admin_user' => $adminUser,
            'mysql' => $mysqlVersion,
            'migrations' => count($applied),
            'relay' => $aiBase !== '',
            'serper' => $serperKey !== '',
        ];
    } catch (Throwable $e) {
        Db::reset();
        $error = $e->getMessage();
    }
}

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function env_badge(bool $ok): string
{
    return $ok
        ? '<span class="check ok">✓ 已启用</span>'
        : '<span class="check bad">× 缺失</span>';
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>安装 · 外贸客 Google 线索引擎</title>
    <link rel="stylesheet" href="assets/install.css?v=<?= h(APP_VERSION) ?>">
</head>
<body class="install-body">
<div class="install-shell">
    <aside class="install-brand">
        <div class="brand-mark">
            <span class="brand-orbit"></span>
            <span class="brand-g">G</span>
        </div>
        <div class="brand-copy">
            <span class="eyebrow">GOOGLE LEAD ENGINE</span>
            <h1>外贸客</h1>
            <p>从一座城市出发，用 Google 与 AI 找到真正做外贸的中国企业独立站。</p>
        </div>
        <div class="brand-flow">
            <div class="flow-item"><span>01</span><div><b>城市关键词</b><small>AI 生成采购意图搜索词</small></div></div>
            <div class="flow-item"><span>02</span><div><b>Google 拓域</b><small>自动翻页、域名去重</small></div></div>
            <div class="flow-item"><span>03</span><div><b>AI 尽调</b><small>筛出真实外贸企业官网</small></div></div>
            <div class="flow-item"><span>04</span><div><b>联系线索</b><small>电话、邮箱、社媒主页</small></div></div>
        </div>
        <div class="brand-footer">
            <span>PHP <?= h(PHP_VERSION) ?></span>
            <span>MySQL 8</span>
            <span>v<?= h(APP_VERSION) ?></span>
        </div>
    </aside>

    <main class="install-main">
        <?php if ($alreadyInstalled): ?>
            <section class="install-card narrow">
                <span class="status-pill ok">系统已安装</span>
                <h2>这套系统已经准备就绪</h2>
                <p class="muted">为避免覆盖数据库和密钥，安装器已锁定。如需重新安装，请先备份并手动删除 <code>config/config.php</code> 与 <code>storage/installed.lock</code>。</p>
                <div class="button-row">
                    <a class="btn primary" href="<?= h(url('/admin/login')) ?>">进入后台</a>
                    <a class="btn ghost" href="<?= h(url('/admin/settings')) ?>">系统设置</a>
                </div>
                <div class="notice info">
                    插件接口地址：<code><?= h(absolute_url('')) ?></code><br>
                    插件 API Key 可在后台「系统设置」中查看。
                </div>
            </section>
        <?php elseif ($success !== null): ?>
            <section class="install-card">
                <span class="status-pill ok">安装完成</span>
                <h2>开始抓取你的第一批外贸线索</h2>
                <p class="muted">数据库迁移 <?= (int) $success['migrations'] ?> 项已完成，MySQL <?= h($success['mysql']) ?>。请把下面的接口地址和 API Key 填入谷歌插件设置。</p>

                <div class="secret-block">
                    <div class="secret-row">
                        <span>后台地址</span>
                        <code><?= h($success['admin_url']) ?></code>
                        <button type="button" class="copy-btn" data-copy="<?= h($success['admin_url']) ?>">复制</button>
                    </div>
                    <div class="secret-row">
                        <span>API 地址</span>
                        <code><?= h($success['api_base']) ?></code>
                        <button type="button" class="copy-btn" data-copy="<?= h($success['api_base']) ?>">复制</button>
                    </div>
                    <div class="secret-row">
                        <span>API Key</span>
                        <code class="key"><?= h($success['api_key']) ?></code>
                        <button type="button" class="copy-btn" data-copy="<?= h($success['api_key']) ?>">复制</button>
                    </div>
                </div>

                <div class="notice warn">
                    <b>请立刻保存 API Key。</b> 离开此页后不会再次完整显示。若遗失，可登录后台在「系统设置」中重新生成。
                </div>
                <div class="notice <?= $success['relay'] ? 'info' : 'warn' ?>">
                    <?= $success['relay'] ? 'AI 中转站已写入，可在后台测试连通性。' : '尚未配置 AI 中转站，请登录后台添加，否则关键词生成和企业筛选无法运行。' ?>
                </div>
                <div class="notice <?= $success['serper'] ? 'info' : 'warn' ?>">
                    <?= $success['serper'] ? 'Serper.dev 搜索已启用，插件将直接消耗 Serper 额度获取 Google 结果，不再触发 Google 验证。' : '尚未配置 Serper，将使用浏览器抓取 Google；可在「系统设置」中启用 Serper。' ?>
                </div>

                <div class="button-row">
                    <a class="btn primary" href="<?= h($success['admin_url']) ?>">进入管理后台</a>
                    <a class="btn ghost" href="<?= h($success['admin_url']) ?>/settings">系统设置</a>
                </div>
            </section>
        <?php else: ?>
            <section class="install-card">
                <div class="card-heading">
                    <div>
                        <span class="eyebrow dark">INSTALLATION</span>
                        <h2>连接你的服务器</h2>
                        <p class="muted">填写 Byte 虚拟空间提供的 MySQL 信息。安装器会自动建表、写入密钥并创建管理员。</p>
                    </div>
                    <span class="step-chip">约 1 分钟</span>
                </div>

                <?php if ($error !== null): ?>
                    <div class="notice error"><b>安装失败：</b><?= h($error) ?></div>
                <?php endif; ?>

                <div class="env-grid">
                    <div class="env-item"><span>PHP ≥ 8.1</span><?= env_badge($phpOk) ?></div>
                    <?php foreach ($extensions as $name => $ok): ?>
                        <div class="env-item"><span><?= h($name) ?></span><?= env_badge($ok) ?></div>
                    <?php endforeach; ?>
                    <div class="env-item"><span>目录可写</span><?= env_badge($writable) ?></div>
                </div>

                <form method="post" autocomplete="off" class="install-form">
                    <?= Csrf::field() ?>

                    <div class="form-section">
                        <div class="section-title"><span>01</span><div><h3>数据库</h3><p>MySQL 8.0 / utf8mb4</p></div></div>
                        <div class="form-grid">
                            <label class="field span-3"><span>数据库地址</span><input name="db_host" value="<?= h($form['db_host']) ?>" placeholder="localhost" required></label>
                            <label class="field"><span>端口</span><input name="db_port" value="<?= h($form['db_port']) ?>" inputmode="numeric" required></label>
                            <label class="field span-2"><span>数据库名</span><input name="db_name" value="<?= h($form['db_name']) ?>" placeholder="byte_xxxxx" required></label>
                            <label class="field span-2"><span>数据库用户</span><input name="db_user" value="<?= h($form['db_user']) ?>" required></label>
                            <label class="field span-2"><span>数据库密码</span><input type="password" name="db_pass" placeholder="留空表示无密码"></label>
                            <label class="field"><span>表前缀</span><input name="db_prefix" value="<?= h($form['db_prefix']) ?>" maxlength="20"></label>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title"><span>02</span><div><h3>站点与管理员</h3><p>用于登录和插件接口</p></div></div>
                        <div class="form-grid">
                            <label class="field span-4"><span>站点地址</span><input name="site_url" value="<?= h($form['site_url']) ?>" placeholder="https://your-domain.com" required></label>
                            <label class="field span-2"><span>管理员账号</span><input name="admin_user" value="<?= h($form['admin_user']) ?>" required></label>
                            <label class="field span-2"><span>显示名称</span><input name="admin_name" value="<?= h($form['admin_name']) ?>"></label>
                            <label class="field span-2"><span>管理员密码</span><input type="password" name="admin_pass" minlength="8" required></label>
                            <label class="field span-2"><span>确认密码</span><input type="password" name="admin_pass_confirm" minlength="8" required></label>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-title"><span>03</span><div><h3>AI 中转站</h3><p>可跳过，稍后在后台配置</p></div></div>
                        <div class="form-grid">
                            <label class="field span-2"><span>名称</span><input name="ai_name" value="<?= h($form['ai_name']) ?>"></label>
                            <label class="field span-2"><span>模型</span><input name="ai_model" value="<?= h($form['ai_model']) ?>" placeholder="gpt-4o-mini"></label>
                            <label class="field span-4"><span>接口地址</span><input name="ai_base_url" value="<?= h($form['ai_base_url']) ?>" placeholder="https://api.openai.com/v1"></label>
                            <label class="field span-4"><span>API Key</span><input type="password" name="ai_key" value="" placeholder="sk-..."></label>
                        </div>
                        <p class="hint">支持 OpenAI 兼容协议（<code>/chat/completions</code>），可填写中转站地址。密钥使用 AES-256-GCM 加密后入库。</p>
                    </div>

                    <div class="form-section">
                        <div class="section-title"><span>04</span><div><h3>Serper.dev 搜索</h3><p>可选，推荐填写；用搜索 API 替代浏览器抓取 Google</p></div></div>
                        <div class="form-grid">
                            <label class="field span-4"><span>Serper API Key</span><input type="password" name="serper_key" value="<?= h($form['serper_key']) ?>" placeholder="留空可稍后在后台「Serper 账号」添加"><small>密钥加密入库。也可安装后在后台添加多个账号自动轮换。</small></label>
                        </div>
                        <p class="hint">Serper 提供免费额度，可避免 Google 人机验证。服务器需要能访问 <code>google.serper.dev</code>；也可稍后在后台「系统设置」中配置。</p>
                    </div>

                    <div class="form-actions">
                        <div class="fineprint">安装即表示你了解 Google 抓取与 AI 调用需遵守目标站点条款和当地法律。</div>
                        <button type="submit" class="btn primary large" <?= $envOk && $writable ? '' : 'disabled' ?>>安装并初始化数据库</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </main>
</div>
<script>
document.querySelectorAll('.copy-btn').forEach(function (button) {
    button.addEventListener('click', function () {
        var text = button.dataset.copy || '';
        var done = function () {
            button.textContent = '已复制';
            setTimeout(function () { button.textContent = '复制'; }, 1600);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done).catch(done);
            return;
        }
        var input = document.createElement('textarea');
        input.value = text;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        try { document.execCommand('copy'); } catch (error) { /* ignore */ }
        input.remove();
        done();
    });
});
</script>
</body>
</html>
