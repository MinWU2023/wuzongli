<?php
/** @var array<string, mixed> $task */
/** @var array<string, int> $counts */
/** @var int $progress */
/** @var array<int, array<string, mixed>> $keywords */
/** @var array<int, array<string, mixed>> $websites */
/** @var array<int, array<string, mixed>> $companies */
/** @var array<int, array<string, mixed>> $logs */
$stages = stage_meta();
$currentIndex = $stages[$task['stage']]['index'] ?? 0;
$isDone = $task['stage'] === 'done';
$countCards = [
    ['label' => '关键词', 'value' => $counts['keywords_total'], 'sub' => $counts['keywords_done'] . ' 个已完成'],
    ['label' => '搜索页', 'value' => $counts['serp_pages_done'] . ' / ' . $counts['serp_pages_target'], 'sub' => $counts['serp_pages_target'] - $counts['serp_pages_done'] . ' 页待处理', 'raw' => true],
    ['label' => '去重域名', 'value' => $counts['websites_total'], 'sub' => $counts['websites_approved'] . ' 个通过 AI 筛选'],
    ['label' => '已识别官网', 'value' => $counts['pages_done'], 'sub' => $counts['page_errors'] . ' 个异常'],
    ['label' => '企业线索', 'value' => $counts['companies'], 'sub' => $counts['extract_errors'] . ' 个提取异常'],
];
?>
<div class="breadcrumb">
    <a href="<?= e(url('/admin/tasks')) ?>">城市任务</a>
    <span>/</span>
    <b><?= e($task['city']) ?></b>
</div>

<section class="task-detail-hero">
    <div class="task-detail-main">
        <div class="task-detail-title">
            <span class="city-avatar large"><?= e(mb_substr((string) $task['city'], 0, 1)) ?></span>
            <div>
                <div class="task-detail-kicker">
                    <span class="badge status-<?= e($task['status']) ?>"><?= e(task_status_label((string) $task['status'])) ?></span>
                    <span class="badge neutral">#<?= (int) $task['id'] ?></span>
                    <span class="task-date">创建于 <?= e(format_dt((string) $task['created_at'])) ?></span>
                </div>
                <h2><?= e($task['city']) ?> <small>外贸独立站线索任务</small></h2>
                <p>目标市场：<?= e($task['target_market']) ?> · 关键词 <?= (int) $task['keyword_count'] ?> 个 · 每个关键词 <?= (int) $task['pages_target'] ?> 页 Google 结果</p>
            </div>
        </div>
        <div class="task-detail-actions">
            <?php if ($task['status'] === 'paused'): ?>
                <form method="post" action="<?= e(url('/admin/tasks/' . (int) $task['id'] . '/action')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="resume">
                    <button class="btn primary" type="submit">继续任务</button>
                </form>
            <?php elseif ($task['status'] !== 'completed'): ?>
                <form method="post" action="<?= e(url('/admin/tasks/' . (int) $task['id'] . '/action')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="pause">
                    <button class="btn ghost" type="submit">暂停任务</button>
                </form>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/admin/tasks/' . (int) $task['id'] . '/action')) ?>" onsubmit="return confirm('将重置异常项并从当前可执行阶段继续，确定吗？');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="retry">
                <button class="btn ghost" type="submit">重试异常</button>
            </form>
            <a class="btn dark" href="<?= e(url('/admin/tasks/' . (int) $task['id'] . '/export.csv')) ?>">导出 CSV</a>
        </div>
    </div>

    <div class="task-progress-block">
        <div class="progress-head"><span>整体进度</span><b><?= (int) $progress ?>%</b></div>
        <div class="progress-track"><i style="width: <?= (int) $progress ?>%"></i></div>
        <div class="stage-stepper">
            <?php foreach ($stages as $key => $stage): ?>
                <?php
                $index = $stage['index'];
                $state = $isDone ? 'complete' : ($index < $currentIndex ? 'complete' : ($index === $currentIndex ? 'current' : 'upcoming'));
                if ($task['status'] === 'paused' && $index === $currentIndex) {
                    $state = 'paused';
                }
                ?>
                <div class="stepper-item state-<?= $state ?>">
                    <span class="stepper-dot"><?= $state === 'complete' ? '✓' : $index + 1 ?></span>
                    <div><b><?= e($stage['short']) ?></b><small><?= e($stage['label']) ?></small></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?php if (!empty($task['error_message'])): ?>
    <div class="alert-banner">
        <span class="alert-icon">!</span>
        <div><b>最近一次异常</b><p><?= e($task['error_message']) ?></p></div>
    </div>
<?php endif; ?>

<section class="detail-stat-grid">
    <?php foreach ($countCards as $card): ?>
        <div class="detail-stat">
            <span><?= e($card['label']) ?></span>
            <b><?= !empty($card['raw']) ? e($card['value']) : format_number($card['value']) ?></b>
            <small><?= e($card['sub']) ?></small>
        </div>
    <?php endforeach; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <span class="eyebrow dark">PIPELINE LOG</span>
            <h3>执行日志</h3>
        </div>
        <span class="count-pill">最近 <?= count($logs) ?> 条</span>
    </div>
    <?php if ($logs === []): ?>
        <div class="empty-inline compact"><div><b>暂无日志</b><p>插件第一次轮询后会开始写入执行记录。</p></div></div>
    <?php else: ?>
        <div class="log-feed">
            <?php foreach ($logs as $log): ?>
                <div class="log-line level-<?= e($log['level']) ?>">
                    <time><?= e(format_dt((string) $log['created_at'], 'm-d H:i:s')) ?></time>
                    <span class="log-badge"><?= e(stage_meta()[$log['stage']]['short'] ?? $log['stage']) ?></span>
                    <p><?= e($log['message']) ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <span class="eyebrow dark">KEYWORDS</span>
            <h3>关键词与搜索进度</h3>
        </div>
        <span class="count-pill"><?= format_number($counts['keywords_total']) ?> 个</span>
    </div>
    <?php if ($keywords === []): ?>
        <div class="empty-inline"><div><b>关键词尚未生成</b><p>在插件中启动任务后会调用 AI 生成关键词。</p></div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>搜索词</th><th>意图</th><th>状态</th><th>页码</th><th>结果</th><th>最近错误</th></tr></thead>
                <tbody>
                <?php foreach ($keywords as $keyword): ?>
                    <tr>
                        <td><span class="keyword-text"><?= e($keyword['keyword']) ?></span></td>
                        <td><span class="mini-tag"><?= e($keyword['intent']) ?></span></td>
                        <td><span class="badge soft-<?= e($keyword['status']) ?>"><?= e($keyword['status']) ?></span></td>
                        <td><?= (int) $keyword['pages_done'] ?> / <?= (int) $keyword['pages_target'] ?></td>
                        <td><?= format_number($keyword['results_count']) ?></td>
                        <td class="muted-cell"><?= e(truncate_text((string) ($keyword['last_error'] ?? ''), 60)) ?: '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <span class="eyebrow dark">DOMAINS</span>
            <h3>域名与企业筛选</h3>
        </div>
        <span class="count-pill"><?= format_number($counts['websites_total']) ?> 个 · <?= format_number($counts['websites_approved']) ?> 通过</span>
    </div>
    <?php if ($websites === []): ?>
        <div class="empty-inline"><div><b>还没有域名</b><p>Google / Serper 搜索结果中的域名会自动去重并写入这里。</p></div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table domain-table">
                <thead><tr><th>域名</th><th>AI 判断</th><th>官网页面</th><th>联系人提取</th><th>依据 / 错误</th></tr></thead>
                <tbody>
                <?php foreach ($websites as $website): ?>
                    <?php
                    $classify = $website['classify_status'];
                    $classLabel = ['approved' => '通过', 'rejected' => '排除', 'pending' => '待判断', 'error' => '异常'][$classify] ?? $classify;
                    ?>
                    <tr>
                        <td>
                            <a class="domain-link" href="<?= e($website['root_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e($website['domain']) ?></a>
                            <?php if (!empty($website['title'])): ?><small class="domain-title"><?= e(truncate_text((string) $website['title'], 70)) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <span class="badge soft-<?= e($classify) ?>"><?= e($classLabel) ?></span>
                            <?php if ($website['ai_confidence'] !== null): ?><small class="confidence"><?= e(number_format((float) $website['ai_confidence'] * 100)) ?>%</small><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($website['page_status'] === 'done'): ?>
                                <div class="page-links">
                                    <?php if (!empty($website['home_url'])): ?><a href="<?= e($website['home_url']) ?>" target="_blank" rel="noopener noreferrer">首页</a><?php endif; ?>
                                    <?php if (!empty($website['about_url'])): ?><a href="<?= e($website['about_url']) ?>" target="_blank" rel="noopener noreferrer">关于</a><?php endif; ?>
                                    <?php if (!empty($website['contact_url'])): ?><a href="<?= e($website['contact_url']) ?>" target="_blank" rel="noopener noreferrer">联系</a><?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="badge soft-<?= e($website['page_status']) ?>"><?= e($website['page_status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge soft-<?= e($website['extract_status']) ?>"><?= e($website['extract_status']) ?></span></td>
                        <td class="muted-cell"><?= e(truncate_text((string) ($website['ai_reason'] ?: $website['last_error'] ?: '—'), 100)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-head">
        <div>
            <span class="eyebrow dark">COMPANIES</span>
            <h3>企业联系线索</h3>
        </div>
        <div class="panel-head-actions">
            <span class="count-pill"><?= format_number($counts['companies']) ?> 家</span>
            <span class="muted dial-hint">双击电话号码可推送到手机拨号队列</span>
            <a class="text-link" href="<?= e(url('/admin/tasks/' . (int) $task['id'] . '/export.csv')) ?>">导出 CSV →</a>
        </div>
    </div>
    <?php if ($companies === []): ?>
        <div class="empty-inline"><div><b>还没有企业线索</b><p>完成官网页面识别后，系统会从首页、关于和联系页面提取电话、邮箱与社媒。</p></div></div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table company-table">
                <thead><tr><th>企业</th><th>电话</th><th>邮箱</th><th>社交主页</th><th>来源</th></tr></thead>
                <tbody>
                <?php foreach ($companies as $company): ?>
                    <tr>
                        <td>
                            <b class="company-name"><?= e($company['company_name'] ?: $company['domain']) ?></b>
                            <a class="domain-link small" href="https://<?= e($company['domain']) ?>" target="_blank" rel="noopener noreferrer"><?= e($company['domain']) ?></a>
                        </td>
                        <td>
                            <?php if (!empty($company['phone'])): ?>
                                <button type="button"
                                    class="contact-dial"
                                    title="双击推送到手机拨号队列"
                                    data-dial-phone="<?= e($company['phone']) ?>"
                                    data-company-id="<?= (int) $company['id'] ?>"
                                    data-task-id="<?= (int) $task['id'] ?>"
                                    data-company-name="<?= e($company['company_name'] ?? '') ?>"
                                    data-city="<?= e($company['city'] ?: ($task['city'] ?? '')) ?>"
                                    data-domain="<?= e($company['domain'] ?? '') ?>"
                                    data-email="<?= e($company['email'] ?? '') ?>"
                                    data-address="<?= e($company['address'] ?? '') ?>"
                                    data-source-url="<?= e($company['source_url'] ?? '') ?>"
                                ><?= e($company['phone']) ?></button>
                            <?php endif; ?>
                            <?php if (!empty($company['phone_alt'])): ?>
                                <button type="button"
                                    class="contact-dial alt"
                                    title="双击推送到手机拨号队列"
                                    data-dial-phone="<?= e($company['phone_alt']) ?>"
                                    data-company-id="<?= (int) $company['id'] ?>"
                                    data-task-id="<?= (int) $task['id'] ?>"
                                    data-company-name="<?= e($company['company_name'] ?? '') ?>"
                                    data-city="<?= e($company['city'] ?: ($task['city'] ?? '')) ?>"
                                    data-domain="<?= e($company['domain'] ?? '') ?>"
                                    data-email="<?= e($company['email'] ?? '') ?>"
                                    data-address="<?= e($company['address'] ?? '') ?>"
                                    data-source-url="<?= e($company['source_url'] ?? '') ?>"
                                ><?= e($company['phone_alt']) ?></button>
                            <?php endif; ?>
                            <?php if (empty($company['phone']) && empty($company['phone_alt'])): ?><span class="muted">—</span><?php endif; ?>
                        </td>
                        <td><?php if (!empty($company['email'])): ?><a class="table-link" href="mailto:<?= e($company['email']) ?>"><?= e($company['email']) ?></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                        <td>
                            <div class="social-links">
                                <?php foreach (['linkedin' => 'in', 'tiktok' => 'tk', 'youtube' => 'yt', 'facebook' => 'fb', 'instagram' => 'ig', 'twitter' => 'x'] as $key => $label): ?>
                                    <?php if (!empty($company[$key])): ?>
                                        <a href="<?= e($company[$key]) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($key) ?>"><?= e($label) ?></a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (!empty($company['whatsapp'])): ?><span class="wa-pill">WA</span><?php endif; ?>
                                <?php if (empty($company['linkedin']) && empty($company['tiktok']) && empty($company['youtube']) && empty($company['facebook']) && empty($company['instagram'])): ?>
                                    <span class="muted">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if (!empty($company['source_url'])): ?><a class="table-link small" href="<?= e($company['source_url']) ?>" target="_blank" rel="noopener noreferrer">查看页面</a><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
