<?php
/** @var array<int, array<string, mixed>> $rows */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var string $search */
/** @var string $category */
/** @var string $source */
/** @var array<string, string> $categories */
/** @var bool $tableReady */
$pages = max(1, (int) ceil($total / $perPage));
$sourceLabels = ['ai' => 'AI 判定', 'rule' => '规则识别', 'manual' => '人工'];
$query = static fn (int $p): string => url('/admin/blocklist') . '?' . http_build_query(array_filter([
    'q' => $search, 'category' => $category, 'source' => $source, 'page' => $p > 1 ? $p : null,
]));
?>
<div class="page-heading">
    <div>
        <span class="eyebrow dark">BLOCKLIST</span>
        <h2>禁止采集名单</h2>
        <p>AI 判定为 B2B/供应商平台、电商平台、目录、非企业独立站等不合格的域名会自动加入这里；之后采集到名单内的域名（含其子域名）不再入库。</p>
    </div>
</div>

<?php if (!$tableReady): ?>
    <div class="notice error">数据表尚未创建，请先访问 <a href="<?= e(url('/migrate.php')) ?>">migrate.php</a> 执行数据库迁移。</div>
<?php else: ?>
    <section class="panel">
        <form method="get" action="<?= e(url('/admin/blocklist')) ?>" class="form-grid">
            <label class="field span-2"><span>搜索域名/原因</span><input type="text" name="q" value="<?= e($search) ?>" placeholder="example.com"></label>
            <label class="field"><span>类别</span>
                <select name="category">
                    <option value="">全部</option>
                    <?php foreach ($categories as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span>来源</span>
                <select name="source">
                    <option value="">全部</option>
                    <?php foreach ($sourceLabels as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $source === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="field"><span>&nbsp;</span><button class="btn primary" type="submit">筛选</button></div>
        </form>
    </section>

    <section class="panel">
        <h3>手动添加</h3>
        <form method="post" action="<?= e(url('/admin/blocklist')) ?>" class="form-grid">
            <?= csrf_field() ?>
            <label class="field span-2"><span>域名（每行一个，或用逗号/空格分隔）</span><textarea name="domains" rows="3" placeholder="example.com&#10;shop.example.net"></textarea></label>
            <label class="field"><span>类别</span>
                <select name="category">
                    <?php foreach ($categories as $key => $label): ?>
                        <option value="<?= e($key) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span>备注</span><input type="text" name="reason" maxlength="200"></label>
            <div class="field"><span>&nbsp;</span><button class="btn primary" type="submit">加入名单</button></div>
        </form>
    </section>

    <section class="panel">
        <p class="muted">共 <?= (int) $total ?> 条记录</p>
        <div class="table-scroll">
            <table class="data-table">
                <thead><tr><th>域名</th><th>类别</th><th>来源</th><th>原因</th><th>置信度</th><th>拦截次数</th><th>加入时间</th><th></th></tr></thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="muted">暂无记录</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= e($row['domain']) ?></code></td>
                        <td><?= e($categories[$row['category']] ?? $row['category']) ?></td>
                        <td><?= e($sourceLabels[$row['source']] ?? $row['source']) ?></td>
                        <td><?= e($row['reason'] ?? '') ?></td>
                        <td><?= $row['confidence'] !== null ? e(number_format((float) $row['confidence'], 2)) : '—' ?></td>
                        <td><?= (int) $row['hit_count'] ?></td>
                        <td><?= e(format_dt((string) $row['created_at'])) ?></td>
                        <td>
                            <form method="post" action="<?= e(url('/admin/blocklist/' . (int) $row['id'] . '/delete')) ?>" onsubmit="return confirm('从名单移除该域名？');">
                                <?= csrf_field() ?>
                                <button class="btn small danger-ghost" type="submit">移除</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <p>
                <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e($query($page - 1)) ?>">上一页</a><?php endif; ?>
                第 <?= (int) $page ?> / <?= (int) $pages ?> 页
                <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e($query($page + 1)) ?>">下一页</a><?php endif; ?>
            </p>
        <?php endif; ?>
    </section>
<?php endif; ?>
