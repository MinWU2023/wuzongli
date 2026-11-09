<?php
/** @var array<int, array<string, mixed>> $rows */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var string $companyQ */
/** @var string $phoneQ */
/** @var bool $tableReady */
/** @var string $exportDate */
/** @var string $today */
$pages = max(1, (int) ceil($total / max(1, $perPage)));
$listQuery = static fn (int $p): string => url('/admin/companies') . '?' . http_build_query(array_filter([
    'company' => $companyQ !== '' ? $companyQ : null,
    'phone' => $phoneQ !== '' ? $phoneQ : null,
    'page' => $p > 1 ? $p : null,
]));
?>
<div class="page-heading">
    <div>
        <span class="eyebrow dark">COMPANY LEADS</span>
        <h2>企业列表</h2>
        <p>仅展示 AI 筛选通过且含联系电话的企业。可为每个号码填写备注（按日期记录）；双击号码可推送到手机拨号队列；导出指定日期内有备注的公司名称、号码与备注。</p>
    </div>
</div>

<?php if (!$tableReady): ?>
    <div class="notice error">备注表尚未创建，请先访问 <a href="<?= e(url('/migrate.php')) ?>">migrate.php</a> 执行数据库迁移（000014）。</div>
<?php else: ?>
    <section class="panel">
        <form method="get" action="<?= e(url('/admin/companies')) ?>" class="form-grid">
            <label class="field span-2">
                <span>筛选企业</span>
                <input type="text" name="company" value="<?= e($companyQ) ?>" placeholder="公司名 / 域名 / 城市">
            </label>
            <label class="field">
                <span>筛选手机号</span>
                <input type="text" name="phone" value="<?= e($phoneQ) ?>" placeholder="号码关键字">
            </label>
            <div class="field">
                <span>&nbsp;</span>
                <button class="btn primary" type="submit">筛选</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <form method="get" action="<?= e(url('/admin/companies/export.csv')) ?>" class="form-grid" style="align-items:end">
            <label class="field">
                <span>导出备注日期</span>
                <input type="date" name="date" value="<?= e($exportDate) ?>" required>
            </label>
            <div class="field">
                <span>&nbsp;</span>
                <button class="btn primary" type="submit">导出 CSV</button>
            </div>
            <div class="field span-2">
                <span class="muted" style="display:block;padding-top:8px">默认导出当日（<?= e($today) ?>）有备注的：公司名称、号码、备注。</span>
            </div>
        </form>
    </section>

    <section class="panel">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:10px">
            <p class="muted" style="margin:0">共 <?= (int) $total ?> 家企业（当前页按号码展开 <?= count($rows) ?> 行）</p>
            <span class="muted dial-hint">双击电话号码可推送到手机拨号队列</span>
        </div>
        <div class="table-scroll">
            <table class="data-table">
                <thead>
                <tr>
                    <th>公司名称</th>
                    <th>城市</th>
                    <th>域名</th>
                    <th>联系号码</th>
                    <th>备注</th>
                    <th>备注日期</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="muted">暂无符合条件的企业。</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <b><?= e($row['company_name']) ?></b>
                            <?php if (!empty($row['source_url'])): ?>
                                <div><a href="<?= e($row['source_url']) ?>" target="_blank" rel="noopener">来源页</a></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($row['city'] !== '' ? $row['city'] : '—') ?></td>
                        <td><code><?= e($row['domain']) ?></code></td>
                        <td>
                            <button type="button"
                                class="contact-dial"
                                title="双击推送到手机拨号队列"
                                data-dial-phone="<?= e($row['phone']) ?>"
                                data-company-id="<?= (int) $row['company_id'] ?>"
                                data-task-id="<?= (int) $row['task_id'] ?>"
                                data-company-name="<?= e($row['company_name']) ?>"
                                data-city="<?= e($row['city']) ?>"
                                data-domain="<?= e($row['domain']) ?>"
                                data-email="<?= e($row['email'] ?? '') ?>"
                                data-address="<?= e($row['address'] ?? '') ?>"
                                data-source-url="<?= e($row['source_url'] ?? '') ?>"
                            ><?= e($row['phone']) ?></button>
                        </td>
                        <td colspan="3">
                            <form method="post"
                                  action="<?= e(url('/admin/companies/notes')) ?>"
                                  class="js-company-note-form form-grid"
                                  style="grid-template-columns: 1.6fr 140px auto; gap: 8px; margin:0"
                                  data-today="<?= e($today) ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="company_id" value="<?= (int) $row['company_id'] ?>">
                                <input type="hidden" name="phone" value="<?= e($row['phone']) ?>">
                                <label class="field" style="margin:0">
                                    <span class="sr-only">备注</span>
                                    <input type="text" name="note" maxlength="1000" required
                                           value="<?= e($row['note_date'] === $today ? $row['note'] : '') ?>"
                                           placeholder="<?= $row['note'] !== '' ? e('最近：' . $row['note'] . ($row['note_date'] !== '' ? '（' . $row['note_date'] . '）' : '')) : '填写今日备注' ?>">
                                </label>
                                <label class="field" style="margin:0">
                                    <span class="sr-only">日期</span>
                                    <input type="date" name="note_date" value="<?= e($today) ?>" required>
                                </label>
                                <div class="field" style="margin:0">
                                    <button class="btn small primary" type="submit">保存</button>
                                </div>
                            </form>
                            <div class="js-note-meta muted" style="margin-top:6px;font-size:12px">
                                <?php if ($row['note'] !== '' && $row['note_date'] !== $today): ?>
                                    最近备注 <?= e($row['note_date']) ?>：<?= e($row['note']) ?>
                                <?php elseif ($row['note'] !== '' && $row['note_date'] === $today && $row['note_updated_at'] !== ''): ?>
                                    今日已保存 · <?= e(format_dt($row['note_updated_at'])) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <p style="margin-top:12px">
                <?php if ($page > 1): ?><a class="btn small ghost" href="<?= e($listQuery($page - 1)) ?>">上一页</a><?php endif; ?>
                第 <?= (int) $page ?> / <?= (int) $pages ?> 页
                <?php if ($page < $pages): ?><a class="btn small ghost" href="<?= e($listQuery($page + 1)) ?>">下一页</a><?php endif; ?>
            </p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<style>
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0}
</style>
