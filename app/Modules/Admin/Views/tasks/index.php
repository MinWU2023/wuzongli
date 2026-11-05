<?php
/** @var array<int, array<string, mixed>> $tasks */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var int $perPage */
/** @var string $status */
/** @var string $search */
$queryBase = array_filter(['status' => $status, 'q' => $search], static fn ($value): bool => $value !== '');
?>
<div class="page-heading">
    <div>
        <span class="eyebrow dark">CITY TASKS</span>
        <h2>城市任务</h2>
        <p>每个任务从关键词生成开始，严格按阶段推进。插件轮询服务端获取下一步动作。</p>
    </div>
    <div class="heading-side">
        <span class="count-pill"><?= format_number($total) ?> 个任务</span>
        <a class="btn ghost" href="<?= e(url('/admin/settings')) ?>">插件接入设置</a>
    </div>
</div>

<section class="panel flush">
    <form method="get" class="table-toolbar">
        <div class="segmented">
            <a href="<?= e(url('/admin/tasks')) ?>" class="<?= $status === '' ? 'active' : '' ?>">全部</a>
            <a href="<?= e(url('/admin/tasks?status=active')) ?>" class="<?= $status === 'active' ? 'active' : '' ?>">运行中</a>
            <a href="<?= e(url('/admin/tasks?status=paused')) ?>" class="<?= $status === 'paused' ? 'active' : '' ?>">已暂停</a>
            <a href="<?= e(url('/admin/tasks?status=completed')) ?>" class="<?= $status === 'completed' ? 'active' : '' ?>">已完成</a>
        </div>
        <div class="toolbar-search">
            <input type="hidden" name="status" value="<?= e($status) ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="<?= e($search) ?>" placeholder="搜索城市，例如武汉">
            <button type="submit" class="btn small">筛选</button>
        </div>
    </form>

    <?php if ($tasks === []): ?>
        <div class="empty-state">
            <div class="empty-visual">
                <span class="empty-ring"></span>
                <span class="empty-pin">G</span>
            </div>
            <h3>还没有符合条件的任务</h3>
            <p>打开 Chrome 插件，点击「新建城市任务」，输入武汉等城市后会自动出现在这里。</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table task-table">
                <thead>
                <tr>
                    <th>城市 / 任务</th>
                    <th>当前阶段</th>
                    <th>进度</th>
                    <th>关键词</th>
                    <th>域名</th>
                    <th>企业</th>
                    <th>创建时间</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $task): ?>
                    <tr>
                        <td>
                            <div class="table-city">
                                <span class="city-avatar"><?= e(mb_substr((string) $task['city'], 0, 1)) ?></span>
                                <div>
                                    <a class="table-title" href="<?= e(url('/admin/tasks/' . (int) $task['id'])) ?>"><?= e($task['city']) ?></a>
                                    <small>面向 <?= e($task['target_market']) ?> · #<?= (int) $task['id'] ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="stage-cell">
                                <span class="stage-dot stage-<?= e($task['stage']) ?>"></span>
                                <span><?= e(stage_label((string) $task['stage'])) ?></span>
                            </div>
                            <span class="badge status-<?= e($task['status']) ?>"><?= e(task_status_label((string) $task['status'])) ?></span>
                        </td>
                        <td>
                            <div class="progress-cell">
                                <div class="progress-line"><i style="width: <?= (int) $task['progress'] ?>%"></i></div>
                                <span><?= (int) $task['progress'] ?>%</span>
                            </div>
                        </td>
                        <td><b class="table-number"><?= format_number($task['keywords_count']) ?></b></td>
                        <td>
                            <b class="table-number"><?= format_number($task['websites_count']) ?></b>
                            <small class="table-sub"><?= format_number($task['approved_count']) ?> 通过</small>
                        </td>
                        <td><b class="table-number accent"><?= format_number($task['companies_count']) ?></b></td>
                        <td><span class="table-date"><?= e(format_dt((string) $task['created_at'], 'm-d H:i')) ?></span></td>
                        <td class="table-actions"><a class="row-action" href="<?= e(url('/admin/tasks/' . (int) $task['id'])) ?>">详情 →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <div class="pagination">
                <span>第 <?= $page ?> / <?= $pages ?> 页</span>
                <div>
                    <?php if ($page > 1): ?>
                        <a class="page-btn" href="<?= e(url('/admin/tasks?' . http_build_query($queryBase + ['page' => $page - 1]))) ?>">← 上一页</a>
                    <?php endif; ?>
                    <?php if ($page < $pages): ?>
                        <a class="page-btn" href="<?= e(url('/admin/tasks?' . http_build_query($queryBase + ['page' => $page + 1]))) ?>">下一页 →</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
