<?php
/** @var int $attempts */
/** @var string $version */
$messages = flashes();
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 · 外贸客</title>
    <link rel="stylesheet" href="<?= e(asset('assets/admin.css')) ?>">
</head>
<body class="login-body">
<div class="login-shell">
    <section class="login-brand-panel">
        <div class="login-brand-top">
            <span class="admin-brand-mark large">G</span>
            <span class="login-brand-name">外贸客 <small>GOOGLE LEAD ENGINE</small></span>
        </div>
        <div class="login-hero">
            <span class="eyebrow light">CITY → GOOGLE → AI → LEADS</span>
            <h1>一座城市，<br>一张外贸客户地图。</h1>
            <p>从关键词到 Google 翻页、域名尽调、官网页面识别，再到电话与社媒线索，全流程自动推进。</p>
        </div>
        <div class="login-steps">
            <div><span>01</span><b>城市关键词</b></div>
            <div><span>02</span><b>Google 拓域</b></div>
            <div><span>03</span><b>AI 筛选</b></div>
            <div><span>04</span><b>联系线索</b></div>
        </div>
        <div class="login-brand-foot">
            <span>PHP <?= e(PHP_VERSION) ?></span>
            <span>MySQL 8</span>
            <span>v<?= e($version) ?></span>
        </div>
        <div class="login-orbit orbit-a"></div>
        <div class="login-orbit orbit-b"></div>
    </section>

    <section class="login-form-panel">
        <div class="login-form-wrap">
            <span class="eyebrow dark">ADMIN CONSOLE</span>
            <h2>欢迎回来</h2>
            <p class="muted">登录后配置 AI 中转站、提示词模板，并查看城市任务与线索。</p>

            <?php foreach ($messages as $message): ?>
                <div class="flash flash-<?= e($message['type']) ?>">
                    <span class="flash-icon"><?= $message['type'] === 'success' ? '✓' : ($message['type'] === 'error' ? '!' : 'i') ?></span>
                    <span><?= e($message['message']) ?></span>
                </div>
            <?php endforeach; ?>

            <?php if ($attempts >= 6): ?>
                <div class="flash flash-error"><span class="flash-icon">!</span><span>登录异常次数较多，请稍后再试。</span></div>
            <?php endif; ?>

            <form method="post" class="login-form">
                <?= csrf_field() ?>
                <label class="field">
                    <span>管理员账号</span>
                    <input type="text" name="username" autocomplete="username" placeholder="admin" required autofocus>
                </label>
                <label class="field">
                    <span>密码</span>
                    <div class="password-wrap">
                        <input type="password" name="password" autocomplete="current-password" placeholder="••••••••" required>
                        <button type="button" class="reveal-btn" data-reveal>显示</button>
                    </div>
                </label>
                <button type="submit" class="btn primary large full">进入控制台 <span>→</span></button>
            </form>

            <div class="login-help">
                <span>安全提示</span>
                <p>连续 6 次失败会锁定 5 分钟。请勿在公共电脑保存密码。</p>
            </div>
        </div>
        <div class="login-form-foot">© <?= date('Y') ?> 外贸客 · 仅供合法合规的 B2B 线索研究</div>
    </section>
</div>
<script src="<?= e(asset('assets/admin.js')) ?>"></script>
</body>
</html>
