<?php
/** @var array<string, mixed> $relay */
/** @var bool $isEdit */
$action = $isEdit ? url('/admin/relays/' . (int) $relay['id']) : url('/admin/relays');
?>
<div class="breadcrumb">
    <a href="<?= e(url('/admin/relays')) ?>">AI 中转站</a><span>/</span><b><?= $isEdit ? '编辑' : '添加' ?></b>
</div>
<div class="page-heading compact">
    <div>
        <span class="eyebrow dark"><?= $isEdit ? 'EDIT RELAY' : 'NEW RELAY' ?></span>
        <h2><?= $isEdit ? '编辑 AI 中转站' : '添加 AI 中转站' ?></h2>
        <p>支持 OpenAI、DeepSeek、Moonshot、OneAPI/NewAPI 等兼容 <code>/v1/chat/completions</code> 的服务。</p>
    </div>
</div>

<form method="post" action="<?= e($action) ?>" class="panel form-panel">
    <?= csrf_field() ?>
    <div class="form-section-title"><span>01</span><div><h3>连接信息</h3><p>中转站名称仅用于后台识别</p></div></div>
    <div class="form-grid">
        <label class="field span-2"><span>名称</span><input name="name" value="<?= e($relay['name']) ?>" placeholder="主 AI 中转站" required></label>
        <label class="field span-2"><span>模型</span><input name="model" value="<?= e($relay['model']) ?>" placeholder="gpt-4o-mini" required></label>
        <label class="field span-4"><span>接口地址 Base URL</span><input name="base_url" value="<?= e($relay['base_url']) ?>" placeholder="https://api.openai.com/v1" required></label>
        <label class="field span-4">
            <span>API Key <?= $isEdit ? '<small>留空表示不修改</small>' : '' ?></span>
            <input type="password" name="api_key" value="" placeholder="<?= $isEdit ? '•••••••• 留空保持原 Key' : 'sk-...' ?>" <?= $isEdit ? '' : 'required' ?>>
        </label>
    </div>

    <div class="form-section-title"><span>02</span><div><h3>调用参数</h3><p>一般保持默认即可</p></div></div>
    <div class="form-grid">
        <label class="field"><span>温度</span><input name="temperature" value="<?= e($relay['temperature']) ?>" inputmode="decimal"></label>
        <label class="field"><span>最大输出 Tokens</span><input type="number" name="max_tokens" value="<?= (int) $relay['max_tokens'] ?>" min="256" max="16000"></label>
        <label class="field"><span>超时（秒）</span><input type="number" name="timeout" value="<?= (int) $relay['timeout'] ?>" min="30" max="300"></label>
        <label class="field"><span>优先级</span><input type="number" name="priority" value="<?= (int) $relay['priority'] ?>" min="0" max="999"></label>
    </div>
    <label class="switch-field">
        <input type="checkbox" name="is_active" value="1" <?= (int) $relay['is_active'] ? 'checked' : '' ?>>
        <span class="switch"></span>
        <span><b>启用该中转站</b><small>关闭后不再参与调用</small></span>
    </label>
    <div class="form-actions">
        <a class="btn ghost" href="<?= e(url('/admin/relays')) ?>">取消</a>
        <button class="btn primary" type="submit"><?= $isEdit ? '保存修改' : '添加中转站' ?></button>
    </div>
</form>
