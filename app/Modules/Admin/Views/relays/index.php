<?php
/** @var array<int, array<string, mixed>> $relays */
?>
<div class="page-heading">
    <div>
        <span class="eyebrow dark">AI RELAYS</span>
        <h2>AI 中转站</h2>
        <p>所有提示词调用都经由 OpenAI 兼容接口完成，按优先级自动故障转移。API Key 使用 AES-256-GCM 加密存储。</p>
    </div>
    <a class="btn primary" href="<?= e(url('/admin/relays/create')) ?>">+ 添加中转站</a>
</div>

<?php if ($relays === []): ?>
    <section class="panel">
        <div class="empty-state">
            <div class="empty-visual"><span class="empty-ring"></span><span class="empty-pin">AI</span></div>
            <h3>还没有配置 AI 中转站</h3>
            <p>关键词生成、域名筛选、页面识别和联系方式提取都需要 AI。添加一个 OpenAI 兼容接口即可开始。</p>
            <a class="btn primary" href="<?= e(url('/admin/relays/create')) ?>">添加第一个中转站</a>
        </div>
    </section>
<?php else: ?>
    <?php
    $primaryId = null;
    foreach ($relays as $r) {
        if ((int) ($r['is_active'] ?? 0) === 1) {
            $primaryId = (int) $r['id'];
            break;
        }
    }
    ?>
    <div class="relay-grid">
        <?php foreach ($relays as $relay): ?>
            <section class="relay-card <?= (int) $relay['is_active'] ? '' : 'inactive' ?>">
                <div class="relay-card-head">
                    <div class="relay-icon">AI</div>
                    <div class="relay-title">
                        <div>
                            <h3><?= e($relay['name']) ?></h3>
                            <span class="badge <?= (int) $relay['is_active'] ? 'soft-approved' : 'neutral' ?>"><?= (int) $relay['is_active'] ? '启用中' : '已停用' ?></span>
                            <?php if ($primaryId !== null && (int) $relay['id'] === $primaryId): ?>
                                <span class="badge soft-approved">当前优先</span>
                            <?php endif; ?>
                        </div>
                        <p><?= e($relay['base_url']) ?></p>
                    </div>
                    <span class="priority-pill">P<?= (int) $relay['priority'] ?></span>
                </div>
                <div class="relay-meta-grid">
                    <div><span>模型</span><b><?= e($relay['model']) ?></b></div>
                    <div><span>温度</span><b><?= e(number_format((float) $relay['temperature'], 2)) ?></b></div>
                    <div><span>超时</span><b><?= (int) $relay['timeout'] ?>s</b></div>
                    <div><span>最大输出</span><b><?= format_number($relay['max_tokens']) ?></b></div>
                </div>
                <div class="relay-key"><span>API KEY</span><code><?= e($relay['masked_key'] ?: '—') ?></code></div>
                <?php if (!empty($relay['last_error'])): ?>
                    <div class="relay-error"><b>最近错误</b><p><?= e(truncate_text((string) $relay['last_error'], 160)) ?></p></div>
                <?php else: ?>
                    <div class="relay-ok"><i></i> 最近调用正常<?= !empty($relay['last_used_at']) ? ' · ' . e(format_dt((string) $relay['last_used_at'], 'm-d H:i')) : '' ?></div>
                <?php endif; ?>
                <div class="relay-actions">
                    <a class="btn small ghost" href="<?= e(url('/admin/relays/' . (int) $relay['id'] . '/edit')) ?>">编辑</a>
                    <form method="post" action="<?= e(url('/admin/relays/' . (int) $relay['id'] . '/delete')) ?>" onsubmit="return confirm('删除这个中转站？');">
                        <?= csrf_field() ?>
                        <button class="btn small danger-ghost" type="submit">删除</button>
                    </form>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <div class="notice info">
        调用会按 <code>priority</code> 从小到大尝试，失败时自动切换下一个。若仍在请求旧的测试 Claude，请把它<strong>停用或删除</strong>，并确保自购中转站显示「当前优先」。
    </div>
<?php endif; ?>
