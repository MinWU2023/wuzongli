<?php
/** @var array<string, mixed> $prompt */
?>
<div class="breadcrumb">
    <a href="<?= e(url('/admin/prompts')) ?>">提示词模板</a><span>/</span><b><?= e($prompt['name']) ?></b>
</div>
<div class="page-heading compact">
    <div>
        <span class="eyebrow dark">EDIT PROMPT · <?= e($prompt['code']) ?></span>
        <h2><?= e($prompt['name']) ?></h2>
        <p><?= e($prompt['description']) ?></p>
    </div>
    <div class="heading-side">
        <?php foreach ($prompt['variables'] as $variable): ?>
            <code class="variable-chip">{{<?= e($variable) ?>}}</code>
        <?php endforeach; ?>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/prompts/' . (int) $prompt['id'])) ?>" class="form-panel">
    <?= csrf_field() ?>
    <section class="panel">
        <div class="panel-head">
            <div><span class="eyebrow dark">SYSTEM</span><h3>系统提示词</h3></div>
            <span class="char-count" data-count-for="system_prompt"><?= mb_strlen((string) $prompt['system_prompt']) ?> 字符</span>
        </div>
        <textarea class="code-textarea" id="system_prompt" name="system_prompt" rows="7" required><?= e($prompt['system_prompt']) ?></textarea>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div><span class="eyebrow dark">USER TEMPLATE</span><h3>用户提示词</h3></div>
            <span class="char-count" data-count-for="user_prompt"><?= mb_strlen((string) $prompt['user_prompt']) ?> 字符</span>
        </div>
        <textarea class="code-textarea tall" id="user_prompt" name="user_prompt" rows="24" required><?= e($prompt['user_prompt']) ?></textarea>
    </section>

    <div class="sticky-form-actions">
        <label class="switch-field inline">
            <input type="checkbox" name="is_active" value="1" <?= (int) $prompt['is_active'] ? 'checked' : '' ?>>
            <span class="switch"></span>
            <span><b>启用该模板</b></span>
        </label>
        <div class="button-row">
            <a class="btn ghost" href="<?= e(url('/admin/prompts')) ?>">取消</a>
            <button class="btn primary" type="submit">保存提示词</button>
        </div>
    </div>
</form>
