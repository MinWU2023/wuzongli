<?php
/** @var array<string, string> $settings */
/** @var string $apiKey */
/** @var string $apiBase */
/** @var string $siteUrl */
/** @var string $mysql */
/** @var string $php */
/** @var int $relayCount */
/** @var string $serperMasked */
/** @var bool $serperConfigured */
/** @var string $serperProvider */
?>
<div class="page-heading">
    <div>
        <span class="eyebrow dark">SYSTEM SETTINGS</span>
        <h2>系统设置</h2>
        <p>管理插件接口、采集节奏和运行环境。AI 中转站在单独页面维护。</p>
    </div>
</div>

<div class="settings-grid">
    <section class="panel settings-connection">
        <div class="panel-head">
            <div><span class="eyebrow dark">EXTENSION API</span><h3>插件接口</h3></div>
            <span class="badge <?= $relayCount > 0 ? 'soft-approved' : 'soft-error' ?>"><?= $relayCount > 0 ? $relayCount . ' 个 AI 可用' : 'AI 未配置' ?></span>
        </div>
        <div class="connection-block">
            <label class="field"><span>API 地址</span>
                <div class="copy-field"><code><?= e($apiBase) ?></code><button type="button" class="copy-btn" data-copy="<?= e($apiBase) ?>">复制</button></div>
            </label>
            <label class="field"><span>API Key</span>
                <div class="copy-field secret">
                    <input id="apiKeyField" type="password" value="<?= e($apiKey) ?>" readonly>
                    <button type="button" class="reveal-btn" data-reveal-target="apiKeyField">显示</button>
                    <button type="button" class="copy-btn" data-copy="<?= e($apiKey) ?>">复制</button>
                </div>
            </label>
            <p class="hint">把这两项填入 Chrome 插件的「连接设置」。API Key 等同密码，请勿公开分享。</p>
        </div>
        <div class="regenerate-box">
            <div><b>轮换 API Key</b><p>重新生成后旧 Key 立即失效，所有插件需要重新配置。</p></div>
            <form method="post" action="<?= e(url('/admin/settings/regenerate-key')) ?>" onsubmit="return confirm('确定重新生成 API Key？现有插件连接会立即失效。');">
                <?= csrf_field() ?>
                <button class="btn small danger-ghost" type="submit">重新生成</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div><span class="eyebrow dark">PIPELINE</span><h3>采集节奏与搜索数据源</h3></div>
        </div>
        <form method="post" action="<?= e(url('/admin/settings')) ?>" class="settings-form">
            <?= csrf_field() ?>
            <div class="form-grid">
                <label class="field span-2"><span>每个任务关键词数</span><input type="number" name="keyword_count" value="<?= (int) $settings['keyword_count'] ?>" min="6" max="60"><small>6–60，AI 实际产出会受模型影响</small></label>
                <label class="field span-2"><span>每个关键词搜索页数</span><input type="number" name="serp_pages" value="<?= (int) $settings['serp_pages'] ?>" min="1" max="10"><small>1–10 页，每页 10 条 Google 结果</small></label>
                <label class="field span-2"><span>域名筛选批量</span><input type="number" name="classify_batch" value="<?= (int) $settings['classify_batch'] ?>" min="5" max="50"><small>每批提交给 AI 的域名数量</small></label>
                <label class="field span-2"><span>失败重试次数</span><input type="number" name="max_attempts" value="<?= (int) $settings['max_attempts'] ?>" min="1" max="10"><small>超过后跳过并记录异常</small></label>
                <label class="field span-2"><span>Google 域名</span><input name="google_domain" value="<?= e($settings['google_domain']) ?>"><small>浏览器抓取模式使用</small></label>
                <label class="field span-2"><span>请求间隔（毫秒）</span><input type="number" name="request_delay_ms" value="<?= (int) $settings['request_delay_ms'] ?>" min="500" max="15000"><small>浏览器抓取建议 2000–5000</small></label>
            </div>

            <div class="form-section">
                <div class="section-title"><span>02</span><div><h3>Serper.dev 搜索</h3><p>用官方搜索 API 替代浏览器抓取，避开 Google 人机验证</p></div></div>
                <div class="form-grid">
                    <label class="field span-2"><span>搜索数据源</span>
                        <select name="search_provider">
                            <option value="auto" <?= $serperProvider === 'auto' ? 'selected' : '' ?>>自动（配置 Key 后优先 Serper）</option>
                            <option value="serper" <?= $serperProvider === 'serper' ? 'selected' : '' ?>>强制使用 Serper</option>
                            <option value="browser" <?= $serperProvider === 'browser' ? 'selected' : '' ?>>浏览器抓取 Google</option>
                        </select>
                        <small>Serper 失败时不会自动降级，避免悄悄绕过你的选择</small>
                    </label>
                    <label class="field span-2"><span>Serper API Key</span>
                        <input type="password" name="serper_api_key" value="" placeholder="<?= $serperConfigured ? e('已配置 ' . $serperMasked . '，留空不修改') : '粘贴 serper.dev API Key' ?>" autocomplete="off">
                        <small>使用 AES-256-GCM 加密入库，不会下发给插件</small>
                    </label>
                    <label class="field span-4"><span>Serper 接口地址</span>
                        <input name="serper_base_url" value="<?= e($settings['serper_base_url'] ?? 'https://google.serper.dev/search') ?>">
                        <small>默认 https://google.serper.dev/search，使用代理时才需要修改</small>
                    </label>
                    <label class="field"><span>国家代码</span><input name="serper_gl" value="<?= e($settings['serper_gl'] ?? 'us') ?>" maxlength="2"><small>us / uk / de</small></label>
                    <label class="field"><span>语言代码</span><input name="serper_hl" value="<?= e($settings['serper_hl'] ?? 'en') ?>" maxlength="5"><small>en / zh-cn</small></label>
                    <label class="field span-2"><span>超时（秒）</span><input type="number" name="serper_timeout" value="<?= (int) ($settings['serper_timeout'] ?? 30) ?>" min="5" max="60"><small>5–60 秒</small></label>
                </div>
                <div class="notice info small">
                    Serper 模式需要服务器能访问 <code>google.serper.dev</code>。启用后不会触发 Google 页面验证，也不再需要 Google 域名和请求间隔。
                </div>
            </div>

            <label class="switch-field">
                <input type="checkbox" name="ai_json_mode" value="1" <?= (int) $settings['ai_json_mode'] ? 'checked' : '' ?>>
                <span class="switch"></span>
                <span><b>启用 JSON Mode</b><small>中转站不支持 response_format 时会自动回退</small></span>
            </label>
            <div class="form-actions">
                <button class="btn primary" type="submit">保存设置</button>
            </div>
        </form>
        <?php if ($serperConfigured): ?>
            <form method="post" action="<?= e(url('/admin/settings/test-serper')) ?>" class="serper-test-form">
                <?= csrf_field() ?>
                <button class="btn ghost small" type="submit">测试 Serper 连接</button>
                <span class="hint">会消耗 1 次 Serper 查询额度</span>
            </form>
        <?php endif; ?>
    </section>
</div>

<section class="panel">
    <div class="panel-head">
        <div><span class="eyebrow dark">RUNTIME</span><h3>运行环境</h3></div>
    </div>
    <div class="runtime-grid">
        <div class="runtime-item"><span>应用版本</span><b>v<?= e(APP_VERSION) ?></b></div>
        <div class="runtime-item"><span>PHP</span><b><?= e($php) ?></b></div>
        <div class="runtime-item"><span>MySQL</span><b><?= e($mysql ?: '未知') ?></b></div>
        <div class="runtime-item"><span>站点地址</span><b title="<?= e($siteUrl) ?>"><?= e(truncate_text($siteUrl, 40)) ?></b></div>
        <div class="runtime-item"><span>接口版本</span><b>REST v1</b></div>
        <div class="runtime-item"><span>数据表前缀</span><b><?= e((string) config('db.prefix', '')) ?></b></div>
    </div>
    <div class="notice info small">
        若插件提示 CORS 或 404，请检查伪静态规则是否将所有请求转发到根目录 <code>index.php</code>，并确认 API 地址是站点根地址。
    </div>
</section>
