<?php
/** @var array<int, array<string, mixed>> $accounts */
/** @var array<string, mixed>|null $current */
$statusLabel = [
    'ready' => '可用',
    'exhausted' => '已用尽',
    'error' => '异常',
    'disabled' => '停用',
];
?>
<div class="page-heading">
    <div>
        <span class="eyebrow dark">SERPER ACCOUNTS</span>
        <h2>Serper 账号</h2>
        <p>以列表管理多个 Serper.dev API Key。同一时间仅一个「当前启用」；额度用尽或 Key 失效时自动切换到下一个可用账号。</p>
    </div>
    <a class="btn primary" href="<?= e(url('/admin/serper/create')) ?>">+ 添加账号</a>
</div>

<?php if ($current): ?>
    <div class="notice info">
        当前启用：<b><?= e($current['name']) ?></b>
        <?php if (!empty($current['masked_key'])): ?> · Key <?= e($current['masked_key']) ?><?php endif; ?>
        <?php if ($current['credits'] !== null): ?> · 余额 <?= format_number($current['credits']) ?><?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($accounts === []): ?>
    <section class="panel">
        <div class="empty-state">
            <div class="empty-visual"><span class="empty-ring"></span><span class="empty-pin">S</span></div>
            <h3>还没有 Serper 账号</h3>
            <p>添加账号后，插件服务端搜索会自动使用当前启用的 Key，并在用尽时轮换。</p>
            <a class="btn primary" href="<?= e(url('/admin/serper/create')) ?>">添加第一个账号</a>
        </div>
    </section>
<?php else: ?>
    <div class="table-wrap panel" style="padding:0;overflow:hidden">
        <table class="data-table">
            <thead>
            <tr>
                <th>名称</th>
                <th>账号</th>
                <th>API Key</th>
                <th>余额</th>
                <th>优先级</th>
                <th>状态</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($accounts as $row): ?>
                <tr class="<?= (int) $row['is_current'] ? '' : '' ?>">
                    <td>
                        <b><?= e($row['name']) ?></b>
                        <?php if ((int) $row['is_current']): ?><span class="badge soft-approved">当前</span><?php endif; ?>
                        <?php if (!empty($row['developer_info'])): ?>
                            <small class="muted" style="display:block;max-width:220px"><?= e(truncate_text((string) $row['developer_info'], 80)) ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['account'] ?: '—') ?></td>
                    <td><code><?= e($row['masked_key'] ?: '—') ?></code></td>
                    <td><?= $row['credits'] === null ? '—' : format_number($row['credits']) ?></td>
                    <td>P<?= (int) $row['priority'] ?></td>
                    <td>
                        <span class="badge <?= (int) $row['is_enabled'] && $row['status'] === 'ready' ? 'soft-approved' : ($row['status'] === 'exhausted' ? 'soft-error' : 'neutral') ?>">
                            <?= e($statusLabel[$row['status']] ?? $row['status']) ?>
                        </span>
                        <?php if (!empty($row['last_error'])): ?>
                            <small class="muted" style="display:block;max-width:180px" title="<?= e($row['last_error']) ?>"><?= e(truncate_text((string) $row['last_error'], 60)) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="relay-actions" style="justify-content:flex-start;flex-wrap:wrap;gap:6px">
                            <?php if (!(int) $row['is_current'] && (int) $row['is_enabled']): ?>
                                <form method="post" action="<?= e(url('/admin/serper/' . (int) $row['id'] . '/activate')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn small ghost" type="submit">设为启用</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= e(url('/admin/serper/' . (int) $row['id'] . '/test')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn small ghost" type="submit">测试</button>
                            </form>
                            <a class="btn small ghost" href="<?= e(url('/admin/serper/' . (int) $row['id'] . '/edit')) ?>">编辑</a>
                            <form method="post" action="<?= e(url('/admin/serper/' . (int) $row['id'] . '/delete')) ?>" onsubmit="return confirm('删除该 Serper 账号？');">
                                <?= csrf_field() ?>
                                <button class="btn small danger-ghost" type="submit">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="notice info">
        轮换顺序按 <code>priority</code> 从小到大。额度用尽（credits≤0）或返回 401/429 时自动切下一个「可用」账号。
    </div>
<?php endif; ?>
