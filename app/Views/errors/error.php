<?php
/** @var int $status */
/** @var string $message */
/** @var string $code */
/** @var mixed $details */
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($status) ?> · 外贸客</title>
    <link rel="stylesheet" href="<?= e(asset('assets/admin.css')) ?>">
</head>
<body class="error-body">
<div class="error-shell">
    <div class="error-code"><?= e($status) ?></div>
    <span class="eyebrow dark"><?= e(strtoupper($code)) ?></span>
    <h1><?= e($message) ?></h1>
    <p>请返回上一页重试。若问题持续出现，请检查服务器日志或后台配置。</p>
    <?php if (config('app.debug') && $details !== null): ?>
        <pre class="error-details"><?= e(is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
    <?php endif; ?>
    <div class="button-row">
        <a class="btn primary" href="<?= e(url('/admin')) ?>">返回后台</a>
        <button type="button" class="btn ghost" onclick="history.back()">返回上一页</button>
    </div>
</div>
</body>
</html>
