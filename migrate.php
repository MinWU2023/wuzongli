<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\Migrator;
use App\Core\Session;

/** @var App\Core\App $app */
$app = require __DIR__ . '/app/bootstrap.php';

$migrationPath = BASE_PATH . '/migrations';
$cli = PHP_SAPI === 'cli';

if (!$app->isInstalled()) {
    if ($cli) {
        fwrite(STDERR, "System is not installed. Open install.php first.\n");
        exit(1);
    }
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>尚未安装</title><link rel="stylesheet" href="assets/install.css"><div style="padding:48px;font-family:sans-serif"><h1>尚未安装</h1><p>请先访问 <a href="install.php">install.php</a> 完成安装。</p></div>';
    exit;
}

$config = require CONFIG_PATH . '/config.php';
Db::init((array) ($config['db'] ?? []));
$migrator = new Migrator(Db::instance(), $migrationPath);

if ($cli) {
    $command = strtolower((string) ($argv[1] ?? 'status'));
    try {
        if ($command === 'up') {
            $done = $migrator->up();
            echo $done === [] ? "Nothing to migrate.\n" : "Migrated:\n - " . implode("\n - ", $done) . "\n";
        } elseif ($command === 'down') {
            $steps = max(1, (int) ($argv[2] ?? 1));
            $done = $migrator->down($steps);
            echo $done === [] ? "Nothing to roll back.\n" : "Rolled back:\n - " . implode("\n - ", $done) . "\n";
        } elseif ($command === 'status') {
            foreach ($migrator->status() as $row) {
                printf("%-48s %s%s\n", $row['migration'], $row['applied'] ? 'applied' : 'pending', $row['applied'] ? ' (batch ' . $row['batch'] . ')' : '');
            }
        } else {
            fwrite(STDERR, "Usage: php migrate.php [status|up|down [steps]]\n");
            exit(1);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Migration error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    exit(0);
}

Session::start();
$providedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
$keyAuthorized = $providedKey !== '' && hash_equals((string) config('app.api_key', ''), $providedKey);
$adminAuthorized = Auth::check();
$authorized = $keyAuthorized || $adminAuthorized;
$message = '';
$error = '';
$status = $migrator->status();

if ($authorized && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        if ($adminAuthorized) {
            Csrf::assert(isset($_POST['_token']) ? (string) $_POST['_token'] : null);
        }
        $action = (string) ($_POST['action'] ?? 'status');
        if ($action === 'up') {
            $done = $migrator->up();
            $message = $done === [] ? '没有待执行的迁移。' : '已执行：' . implode('、', $done);
        } elseif ($action === 'down') {
            $steps = max(1, min(20, (int) ($_POST['steps'] ?? 1)));
            $done = $migrator->down($steps);
            $message = $done === [] ? '没有可回滚的迁移。' : '已回滚：' . implode('、', $done);
        }
        $status = $migrator->status();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

function mh(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>数据库迁移 · 外贸客</title>
    <link rel="stylesheet" href="assets/install.css?v=<?= mh(APP_VERSION) ?>">
</head>
<body class="install-body migrate-body">
<div class="migrate-wrap">
    <header class="migrate-head">
        <div>
            <span class="eyebrow dark">MIGRATIONS</span>
            <h1>数据库迁移</h1>
            <p>共 <?= count($status) ?> 个迁移文件，已执行 <?= count(array_filter($status, static fn (array $row): bool => (bool) $row['applied'])) ?> 个。</p>
        </div>
        <a class="btn ghost" href="<?= mh(url('/admin')) ?>">返回后台</a>
    </header>

    <?php if (!$authorized): ?>
        <section class="panel">
            <h2>需要授权</h2>
            <p class="muted">请先登录后台，或使用安装时生成的 API Key 访问：<code>migrate.php?key=YOUR_API_KEY</code></p>
            <a class="btn primary" href="<?= mh(url('/admin/login')) ?>">去登录</a>
        </section>
    <?php else: ?>
        <?php if ($message !== ''): ?><div class="notice info"><?= mh($message) ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="notice error"><?= mh($error) ?></div><?php endif; ?>

        <section class="panel">
            <div class="migrate-actions">
                <form method="post">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="up">
                    <button class="btn primary" type="submit">执行全部待迁移</button>
                </form>
                <form method="post" onsubmit="return confirm('确定回滚最近的迁移？这会删除对应数据表。');">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="down">
                    <label class="inline-field">回滚步数 <input type="number" name="steps" value="1" min="1" max="20"></label>
                    <button class="btn danger" type="submit">回滚</button>
                </form>
            </div>

            <table class="migrate-table">
                <thead><tr><th>迁移文件</th><th>状态</th><th>批次</th><th>执行时间</th></tr></thead>
                <tbody>
                <?php foreach ($status as $row): ?>
                    <tr>
                        <td><code><?= mh($row['migration']) ?></code></td>
                        <td><?= $row['applied'] ? '<span class="status-pill ok small">已执行</span>' : '<span class="status-pill warn small">待执行</span>' ?></td>
                        <td><?= mh($row['batch'] ?? '—') ?></td>
                        <td><?= mh($row['applied_at'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p class="hint">命令行：<code>php migrate.php up</code> · <code>php migrate.php status</code> · <code>php migrate.php down 1</code></p>
        </section>
    <?php endif; ?>
</div>
</body>
</html>
