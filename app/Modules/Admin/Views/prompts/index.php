<?php
/** @var array<int, array<string, mixed>> $prompts */
?>
<div class="page-heading">
    <div>
        <span class="eyebrow dark">PROMPT OPS</span>
        <h2>提示词模板</h2>
        <p>四个 AI 环节的提示词都在这里维护。修改变量占位符时请保持 <code>{{variable}}</code> 原样，否则渲染会失败。</p>
    </div>
</div>

<div class="prompt-grid">
    <?php foreach ($prompts as $prompt): ?>
        <section class="prompt-card <?= (int) $prompt['is_active'] ? '' : 'inactive' ?>">
            <div class="prompt-card-top">
                <span class="prompt-code"><?= e($prompt['code']) ?></span>
                <span class="badge <?= (int) $prompt['is_active'] ? 'soft-approved' : 'neutral' ?>"><?= (int) $prompt['is_active'] ? '启用' : '停用' ?></span>
            </div>
            <h3><?= e($prompt['name']) ?></h3>
            <p><?= e(truncate_text((string) $prompt['description'], 130)) ?></p>
            <div class="variable-list">
                <?php foreach ($prompt['variables'] as $variable): ?>
                    <code>{{<?= e($variable) ?>}}</code>
                <?php endforeach; ?>
            </div>
            <div class="prompt-card-foot">
                <span>更新于 <?= e(format_dt((string) $prompt['updated_at'], 'Y-m-d H:i')) ?></span>
                <a class="btn small ghost" href="<?= e(url('/admin/prompts/' . (int) $prompt['id'] . '/edit')) ?>">编辑模板 →</a>
            </div>
        </section>
    <?php endforeach; ?>
</div>

<div class="notice warn">
    修改提示词后立即生效，下一个任务轮询会使用新版本。建议先复制原始内容备份，再进行试验性调整。
</div>
