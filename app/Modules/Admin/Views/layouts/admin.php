<?php
/** @var array<string, mixed> $admin */
/** @var string $pageTitle */
/** @var string $activeNav */
/** @var string $version */
/** @var string $content */
$messages = flashes();
$navItems = [
    ['key' => 'dashboard', 'label' => '总览', 'href' => '/admin', 'icon' => '<path d="M4 13h6V4H4v9Zm0 7h6v-5H4v5Zm10 0h6V11h-6v9Zm0-16v5h6V4h-6Z"/>'],
    ['key' => 'tasks', 'label' => '城市任务', 'href' => '/admin/tasks', 'icon' => '<path d="M4 6h16M4 12h10M4 18h7"/><circle cx="19" cy="12" r="2"/><circle cx="15" cy="18" r="2"/>'],
    ['key' => 'relays', 'label' => 'AI 中转站', 'href' => '/admin/relays', 'icon' => '<path d="M12 3v4m0 10v4M5 12H3m18 0h-2M7.05 7.05 5.64 5.64m12.72 12.72-1.41-1.41M7.05 16.95l-1.41 1.41M18.36 5.64l-1.41 1.41"/><circle cx="12" cy="12" r="4"/>'],
    ['key' => 'blocklist', 'label' => '禁止采集名单', 'href' => '/admin/blocklist', 'icon' => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>'],
    ['key' => 'serper', 'label' => 'Serper 账号', 'href' => '/admin/serper', 'icon' => '<path d="M4 18 9 8l4 6 3-8 4 12"/><path d="M4 21h16"/>'],
    ['key' => 'categories', 'label' => '产品分类', 'href' => '/admin/categories', 'icon' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><path d="M14 17.5h7M17.5 14v7"/>'],
    ['key' => 'prompts', 'label' => '提示词模板', 'href' => '/admin/prompts', 'icon' => '<path d="M5 4h14v16H5z"/><path d="M8 8h8M8 12h8M8 16h5"/>'],
    ['key' => 'settings', 'label' => '系统设置', 'href' => '/admin/settings', 'icon' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06-2.83 2.83-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21h-4v-.09A1.7 1.7 0 0 0 8.9 19.3a1.7 1.7 0 0 0-1.87.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.51-1H3v-4h.09A1.7 1.7 0 0 0 4.6 8.9a1.7 1.7 0 0 0-.34-1.87l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 8.9 4.6 1.7 1.7 0 0 0 10 3.09V3h4v.09a1.7 1.7 0 0 0 1 1.51 1.7 1.7 0 0 0 1.87-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 8.9a1.7 1.7 0 0 0 1.51 1H21v4h-.09a1.7 1.7 0 0 0-1.51 1Z"/>'],
];
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · 外贸客</title>
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="stylesheet" href="<?= e(asset('assets/admin.css')) ?>">
</head>
<body class="admin-body" data-base="<?= e(rtrim(url(''), '/')) ?>">
<div class="admin-shell">
    <aside class="admin-sidebar" id="sidebar">
        <a class="admin-brand" href="<?= e(url('/admin')) ?>">
            <span class="admin-brand-mark">G</span>
            <span class="admin-brand-copy"><b>外贸客</b><small>GOOGLE LEAD ENGINE</small></span>
        </a>
        <div class="sidebar-section-label">工作台</div>
        <nav class="admin-nav">
            <?php foreach ($navItems as $item): ?>
                <a class="admin-nav-link <?= $activeNav === $item['key'] ? 'active' : '' ?>" href="<?= e(url($item['href'])) ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $item['icon'] ?></svg>
                    <span><?= e($item['label']) ?></span>
                    <?php if ($item['key'] === 'tasks'): ?><span class="nav-dot"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-plugin">
            <span class="sidebar-plugin-label">CHROME EXTENSION</span>
            <b>插件接口</b>
            <div class="sidebar-endpoint" title="<?= e(absolute_url('')) ?>"><?= e(absolute_url('')) ?></div>
            <a href="<?= e(url('/admin/settings')) ?>">查看 API Key <span>→</span></a>
        </div>
        <div class="sidebar-foot">
            <span>v<?= e($version) ?></span>
            <span>PHP <?= e(PHP_VERSION) ?></span>
        </div>
    </aside>

    <div class="admin-main">
        <header class="admin-topbar">
            <button class="icon-btn mobile-nav-toggle" id="navToggle" type="button" aria-label="切换菜单">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
            <div class="topbar-context">
                <span class="topbar-eyebrow">PIPELINE CONSOLE · 运营工作台</span>
                <h1><?= e($pageTitle) ?></h1>
            </div>
            <div class="topbar-actions">
                <span class="topbar-pulse"><i></i> 系统正常</span>
                <div class="user-chip">
                    <span class="avatar"><?= e(mb_substr((string) ($admin['display_name'] ?? 'A'), 0, 1)) ?></span>
                    <span class="user-meta"><b><?= e($admin['display_name'] ?? '管理员') ?></b><small>@<?= e($admin['username'] ?? '') ?></small></span>
                </div>
                <form method="post" action="<?= e(url('/admin/logout')) ?>" class="logout-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="icon-btn" title="退出登录">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>
                    </button>
                </form>
            </div>
        </header>

        <main class="admin-content">
            <?php foreach ($messages as $message): ?>
                <div class="flash flash-<?= e($message['type']) ?>">
                    <span class="flash-icon"><?= $message['type'] === 'success' ? '✓' : ($message['type'] === 'error' ? '!' : 'i') ?></span>
                    <span><?= e($message['message']) ?></span>
                </div>
            <?php endforeach; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<div class="sidebar-scrim" id="scrim"></div>
<script src="<?= e(asset('assets/admin.js')) ?>"></script>
</body>
</html>
