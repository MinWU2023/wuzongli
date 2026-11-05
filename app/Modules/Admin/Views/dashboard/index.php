<?php
/** @var array<string, int> $stats */
/** @var array<int, array<string, mixed>> $recent */
/** @var array<int, array<string, mixed>> $logs */
/** @var string $apiBase */
$statCards = [
    ['label' => '城市任务', 'value' => $stats['tasks'], 'sub' => $stats['active'] . ' 个运行中', 'tone' => 'blue', 'icon' => 'M4 6h16M4 12h10M4 18h7'],
    ['label' => '去重域名', 'value' => $stats['websites'], 'sub' => $stats['approved'] . ' 个已通过 AI 筛选', 'tone' => 'violet', 'icon' => 'M4 12a8 8 0 1 0 16 0 8 8 0 1 0-16 0M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20'],
    ['label' => '企业线索', 'value' => $stats['companies'], 'sub' => '今日新增 ' . $stats['today'] . ' 家', 'tone' => 'green', 'icon' => 'M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6'],
    ['label' => 'AI 中转站', 'value' => $stats['relays'], 'sub' => $stats['relays'] > 0 ? '已就绪' : '尚未配置', 'tone' => $stats['relays'] > 0 ? 'amber' : 'red', 'icon' => 'M12 3v4m0 10v4M5 12H3m18 0h-2M7 7l-1.5-1.5M18.5 18.5 17 17M7 17l-1.5 1.5M18.5 5.5 17 7'],
];
?>
<section class="dashboard-hero">
    <div class="hero-copy">
        <span class="eyebrow light">CITY INTELLIGENCE PIPELINE</span>
        <h2>把一座城市，变成一张<br>可执行的出海客户地图。</h2>
        <p>插件负责用真实浏览器搜索与抓取，服务器负责 AI 判断与线索沉淀。每一步完成，才会进入下一步。</p>
        <div class="button-row">
            <a class="btn light" href="<?= e(url('/admin/tasks')) ?>">查看任务流水</a>
            <a class="btn ghost-light" href="<?= e(url('/admin/relays')) ?>">配置 AI 中转站</a>
        </div>
    </div>
    <div class="hero-pipeline">
        <div class="pipeline-node active"><span>01</span><b>城市</b><small>WUHAN</small></div>
        <div class="pipeline-line"></div>
        <div class="pipeline-node"><span>02</span><b>Google</b><small>SERP</small></div>
        <div class="pipeline-line"></div>
        <div class="pipeline-node"><span>03</span><b>AI 尽调</b><small>DOMAIN</small></div>
        <div class="pipeline-line"></div>
        <div class="pipeline-node"><span>04</span><b>线索</b><small>CONTACT</small></div>
    </div>
    <div class="hero-glow"></div>
</section>

<section class="stat-grid">
    <?php foreach ($statCards as $card): ?>
        <div class="stat-card tone-<?= e($card['tone']) ?>">
            <div class="stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="<?= e($card['icon']) ?>"/></svg></div>
            <div class="stat-body">
                <span><?= e($card['label']) ?></span>
                <b><?= format_number($card['value']) ?></b>
                <small><?= e($card['sub']) ?></small>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<div class="dashboard-grid">
    <section class="panel">
        <div class="panel-head">
            <div>
                <span class="eyebrow dark">RECENT TASKS</span>
                <h3>最近的城市任务</h3>
            </div>
            <a class="text-link" href="<?= e(url('/admin/tasks')) ?>">全部任务 →</a>
        </div>
        <?php if ($recent === []): ?>
            <div class="empty-inline">
                <div class="empty-icon">⌘</div>
                <div><b>还没有任务</b><p>打开浏览器插件，输入「武汉」创建第一个城市任务。</p></div>
            </div>
        <?php else: ?>
            <div class="task-rows">
                <?php foreach ($recent as $task): ?>
                    <a class="task-row" href="<?= e(url('/admin/tasks/' . (int) $task['id'])) ?>">
                        <div class="task-city-badge"><?= e(mb_substr((string) $task['city'], 0, 1)) ?></div>
                        <div class="task-row-main">
                            <div class="task-row-title">
                                <b><?= e($task['city']) ?></b>
                                <span class="badge status-<?= e($task['status']) ?>"><?= e(task_status_label((string) $task['status'])) ?></span>
                            </div>
                            <div class="task-row-meta">
                                <span><?= e(stage_label((string) $task['stage'])) ?></span>
                                <span><?= format_number($task['counts']['websites_total']) ?> 域名</span>
                                <span><?= format_number($task['counts']['companies']) ?> 企业</span>
                            </div>
                            <div class="progress-line"><i style="width: <?= (int) $task['progress'] ?>%"></i></div>
                        </div>
                        <div class="task-row-progress"><?= (int) $task['progress'] ?>%</div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div>
                <span class="eyebrow dark">LIVE FEED</span>
                <h3>流程动态</h3>
            </div>
            <span class="live-dot"><i></i> LIVE</span>
        </div>
        <?php if ($logs === []): ?>
            <div class="empty-inline compact">
                <div><b>暂无动态</b><p>任务开始后，这里会显示每一步的处理记录。</p></div>
            </div>
        <?php else: ?>
            <div class="activity-list">
                <?php foreach ($logs as $log): ?>
                    <div class="activity-item level-<?= e($log['level']) ?>">
                        <span class="activity-dot"></span>
                        <div class="activity-body">
                            <div class="activity-meta">
                                <b><?= e($log['city'] ?? '系统') ?></b>
                                <span><?= e(stage_label((string) $log['stage'])) ?></span>
                                <time><?= e(format_dt((string) $log['created_at'], 'H:i')) ?></time>
                            </div>
                            <p><?= e(truncate_text((string) $log['message'], 100)) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>

<div class="dashboard-grid lower">
    <section class="panel onboarding-card">
        <div class="panel-head">
            <div>
                <span class="eyebrow dark">QUICK START</span>
                <h3>三步开始采集</h3>
            </div>
        </div>
        <div class="onboarding-steps">
            <div class="onboarding-step">
                <span class="step-num">1</span>
                <div><b>配置 AI 中转站</b><p>填写 OpenAI 兼容接口和 Key，运行一次任务前请确认状态正常。</p><a href="<?= e(url('/admin/relays')) ?>">去配置 →</a></div>
            </div>
            <div class="onboarding-step">
                <span class="step-num">2</span>
                <div><b>安装 Chrome 插件</b><p>加载 <code>extension/</code> 文件夹，在插件设置中填入下方 API 地址与 Key。</p><a href="<?= e(url('/admin/settings')) ?>">获取 API Key →</a></div>
            </div>
            <div class="onboarding-step">
                <span class="step-num">3</span>
                <div><b>添加城市并启动</b><p>插件会轮询服务端，自动完成关键词、搜索、筛选、抓取和提取。</p></div>
            </div>
        </div>
    </section>

    <section class="panel endpoint-card">
        <span class="eyebrow dark">CONNECTION</span>
        <h3>插件接口</h3>
        <p>将以下地址填入插件设置。扩展与服务器通过 API Key 鉴权。</p>
        <div class="copy-field">
            <code><?= e($apiBase) ?></code>
            <button type="button" class="copy-btn" data-copy="<?= e($apiBase) ?>">复制</button>
        </div>
        <div class="endpoint-status">
            <span class="status-line"><i class="ok"></i> REST API v1</span>
            <span class="status-line"><i class="ok"></i> 轮询式任务编排</span>
        </div>
        <div class="notice info small">
            插件只负责浏览器侧的 Google / Serper 搜索与网页抓取，AI Key 始终保存在服务器。
        </div>
    </section>
</div>
