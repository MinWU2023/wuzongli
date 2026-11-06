<?php
/** @var array<string, mixed> $account */
/** @var bool $isEdit */
$action = $isEdit ? url('/admin/serper/' . (int) $account['id']) : url('/admin/serper');
?>
<div class="breadcrumb">
    <a href="<?= e(url('/admin/serper')) ?>">Serper 账号</a><span>/</span><b><?= $isEdit ? '编辑' : '添加' ?></b>
</div>
<div class="page-heading compact">
    <div>
        <span class="eyebrow dark"><?= $isEdit ? 'EDIT SERPER' : 'NEW SERPER' ?></span>
        <h2><?= $isEdit ? '编辑 Serper 账号' : '添加 Serper 账号' ?></h2>
        <p>保存登录账号/密码与开发者信息便于运维；实际搜索只使用加密存储的 API Key。</p>
    </div>
</div>

<form method="post" action="<?= e($action) ?>" class="panel form-panel">
    <?= csrf_field() ?>
    <div class="form-section-title"><span>01</span><div><h3>账号与密钥</h3><p>API Key 使用 AES-256-GCM 加密入库</p></div></div>
    <div class="form-grid">
        <label class="field span-2"><span>名称</span><input name="name" value="<?= e($account['name'] ?? '') ?>" placeholder="主账号 / 备用1" required></label>
        <label class="field span-2"><span>优先级</span><input type="number" name="priority" value="<?= (int) ($account['priority'] ?? 10) ?>" min="0" max="999"><small>数字越小越优先</small></label>
        <label class="field span-2"><span>登录账号 / 邮箱</span><input name="account" value="<?= e($account['account'] ?? '') ?>" placeholder="serper 注册邮箱" autocomplete="off"></label>
        <label class="field span-2">
            <span>登录密码 <?= $isEdit ? '<small>留空不修改</small>' : '' ?></span>
            <input type="password" name="password" value="" placeholder="<?= $isEdit ? '•••• 留空保持原密码' : '账号密码' ?>" autocomplete="new-password">
        </label>
        <label class="field span-4">
            <span>API Key <?= $isEdit ? '<small>留空不修改</small>' : '' ?></span>
            <input type="password" name="api_key" value="" placeholder="<?= $isEdit ? e(($account['masked_key'] ?? '') . ' · 留空保持') : '粘贴 Serper API Key' ?>" <?= $isEdit ? '' : 'required' ?> autocomplete="off">
        </label>
        <label class="field span-4"><span>自定义接口（可选）</span>
            <input name="endpoint" value="<?= e($account['endpoint'] ?? '') ?>" placeholder="默认 https://google.serper.dev/search">
            <small>一般留空；走代理时再填写</small>
        </label>
    </div>

    <div class="form-section-title"><span>02</span><div><h3>开发信息</h3><p>后台备注，不参与接口调用</p></div></div>
    <div class="form-grid">
        <label class="field span-4"><span>开发者 / 控制台信息</span>
            <textarea name="developer_info" rows="4" placeholder="例如：控制台地址、团队 ID、账单邮箱、注册手机号等"><?= e($account['developer_info'] ?? '') ?></textarea>
        </label>
        <label class="field span-4"><span>其他备注</span>
            <textarea name="notes" rows="3" placeholder="额度套餐、到期时间、用途说明…"><?= e($account['notes'] ?? '') ?></textarea>
        </label>
    </div>

    <label class="switch-field">
        <input type="checkbox" name="is_enabled" value="1" <?= (int) ($account['is_enabled'] ?? 1) ? 'checked' : '' ?>>
        <span class="switch"></span>
        <span><b>参与轮换</b><small>关闭后不会被自动选用</small></span>
    </label>
    <label class="switch-field">
        <input type="checkbox" name="is_current" value="1" <?= (int) ($account['is_current'] ?? 0) ? 'checked' : '' ?>>
        <span class="switch"></span>
        <span><b>设为当前启用</b><small>保存后立即切换到该账号（同时只会有一个当前）</small></span>
    </label>

    <div class="form-actions">
        <a class="btn ghost" href="<?= e(url('/admin/serper')) ?>">取消</a>
        <button class="btn primary" type="submit"><?= $isEdit ? '保存修改' : '添加账号' ?></button>
    </div>
</form>
