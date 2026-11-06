<?php
/** @var array<int, array<string, mixed>> $groups */
/** @var bool $ready */
/** @var int $max */
$totalItems = 0;
$activeItems = 0;
foreach ($groups as $g) {
    foreach ($g['items'] as $it) {
        $totalItems++;
        if ($it['active']) {
            $activeItems++;
        }
    }
}
$palette = [
    ['#4f7cff', '#7a5cff'], ['#0e9f76', '#35c9a0'], ['#f59e0b', '#f97316'], ['#ec4899', '#f472b6'],
    ['#06b6d4', '#3b82f6'], ['#8b5cf6', '#d946ef'], ['#ef4444', '#f97316'], ['#14b8a6', '#22c55e'],
];
$base = url('/admin/categories');
?>
<style>
.cat-stats { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
.cat-stat { display: inline-flex; align-items: baseline; gap: 6px; padding: 7px 14px; border-radius: 999px; background: #fff; border: 1px solid var(--line); box-shadow: var(--shadow-sm); font-size: 11.5px; color: var(--muted); }
.cat-stat b { font-size: 17px; color: var(--ink); letter-spacing: -.03em; }
.cat-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin: 18px 0 16px; }
.cat-toolbar .toolbar-search input { min-width: 260px; }
.cat-actions { display: flex; gap: 8px; flex-wrap: wrap; }
.cat-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; align-items: start; }
@media (max-width: 1100px) { .cat-grid { grid-template-columns: 1fr; } }
.cat-card { background: #fff; border: 1px solid var(--line); border-radius: 18px; box-shadow: var(--shadow-sm); overflow: hidden; transition: box-shadow .2s, transform .2s; }
.cat-card:hover { box-shadow: 0 14px 34px rgba(30,45,90,.09); }
.cat-card.off { opacity: .62; }
.cat-card-head { display: flex; align-items: center; gap: 13px; padding: 16px 18px; border-bottom: 1px solid #eef1f7; background: linear-gradient(180deg, #fbfcff, #fff); }
.cat-ico { width: 42px; height: 42px; flex: none; border-radius: 13px; display: grid; place-items: center; color: #fff; font-size: 17px; font-weight: 800; box-shadow: 0 8px 18px rgba(60,80,160,.22); }
.cat-title { min-width: 0; flex: 1; }
.cat-title h3 { font-size: 14.5px; letter-spacing: -.02em; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.cat-title p { color: var(--muted); font-size: 11px; margin-top: 2px; }
.cat-title code { font-size: 9.5px; color: var(--muted-2); background: #f1f4fa; padding: 1px 6px; border-radius: 5px; }
.cat-count { padding: 2px 8px; border-radius: 999px; background: var(--blue-soft); color: var(--blue); font-size: 10px; font-weight: 700; }
.cat-card-actions { display: flex; gap: 6px; flex: none; }
.cat-icon-btn { width: 32px; height: 32px; border-radius: 9px; border: 1px solid var(--line-strong); background: #fff; color: #5b6784; cursor: pointer; display: grid; place-items: center; font-size: 14px; line-height: 1; transition: .15s; }
.cat-icon-btn:hover { color: var(--blue); border-color: #b9c9f5; background: #f4f7ff; }
.cat-chips { display: flex; flex-wrap: wrap; gap: 8px; padding: 14px 18px 18px; }
.cat-chip { display: inline-flex; flex-direction: column; align-items: flex-start; gap: 1px; padding: 7px 12px; border-radius: 11px; border: 1px solid var(--line-strong); background: #fafbfe; cursor: pointer; text-align: left; transition: .15s; font: inherit; }
.cat-chip b { font-size: 11.5px; color: var(--ink); font-weight: 650; }
.cat-chip small { font-size: 9.5px; color: var(--muted-2); }
.cat-chip:hover { border-color: #aebff2; background: #f4f7ff; transform: translateY(-1px); box-shadow: 0 6px 14px rgba(70,100,200,.12); }
.cat-chip.off { opacity: .5; border-style: dashed; }
.cat-chip.hide { display: none; }
.cat-add-chip { align-self: center; padding: 7px 12px; border-radius: 11px; border: 1px dashed #b9c4d8; background: transparent; color: var(--blue); font-size: 11px; font-weight: 650; cursor: pointer; }
.cat-add-chip:hover { background: var(--blue-soft); }
.cat-empty-group { color: var(--muted-2); font-size: 11.5px; padding: 6px 2px; }
.cat-card.hide { display: none; }
.cat-modal { position: fixed; inset: 0; z-index: 200; display: grid; place-items: center; padding: 22px; background: rgba(15,23,42,.5); backdrop-filter: blur(5px); }
.cat-modal[hidden] { display: none; }
.cat-dialog { width: min(520px, 100%); max-height: calc(100vh - 44px); overflow: auto; background: #fff; border-radius: 20px; box-shadow: 0 30px 90px rgba(15,23,42,.35); animation: catUp .2s ease both; }
@keyframes catUp { from { opacity: 0; transform: translateY(10px) scale(.985); } to { opacity: 1; transform: none; } }
.cat-dialog-head { padding: 20px 24px 6px; }
.cat-dialog-head h3 { font-size: 16px; letter-spacing: -.02em; }
.cat-dialog-head p { color: var(--muted); font-size: 11.5px; margin-top: 4px; }
.cat-dialog-body { padding: 14px 24px 6px; display: flex; flex-direction: column; gap: 14px; }
.cat-dialog-body .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.cat-dialog-body textarea { min-height: 170px; font-family: inherit; line-height: 1.7; }
.cat-dialog-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 16px 24px 22px; }
.cat-dialog-foot .right { display: flex; gap: 8px; margin-left: auto; }
.cat-no-result { display: none; padding: 50px 0; text-align: center; color: var(--muted); font-size: 12.5px; }
</style>

<div class="page-heading">
    <div>
        <span class="eyebrow dark">PRODUCT CATEGORIES</span>
        <h2>产品分类</h2>
        <p>中国出海外贸产品分类库（中英文）。插件新建任务时可从这里选择分类，AI 会按「城市 + 产品分类」生成企业独立站搜索词。这里的修改会立即对插件生效。</p>
        <?php if ($ready): ?>
            <div class="cat-stats">
                <span class="cat-stat"><b><?= count($groups) ?></b>大类</span>
                <span class="cat-stat"><b><?= $totalItems ?></b>细分类</span>
                <span class="cat-stat"><b><?= $activeItems ?></b>启用中</span>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($ready): ?>
        <div class="cat-actions">
            <button class="btn ghost" type="button" id="catBulkBtn">批量添加</button>
            <button class="btn primary" type="button" id="catGroupNew">+ 新增大类</button>
        </div>
    <?php endif; ?>
</div>

<?php if (!$ready): ?>
    <div class="notice warn">分类数据表还没创建。请先访问 <a href="<?= e(url('/migrate.php')) ?>"><b>migrate.php</b></a> 执行数据库迁移（会自动导入现有分类），之后这里就可以管理了。迁移前插件仍使用内置分类文件，不受影响。</div>
<?php elseif ($groups === []): ?>
    <section class="panel">
        <div class="empty-state">
            <h3>还没有分类</h3>
            <p>点击右上角「新增大类」开始添加，或用「批量添加」一次导入多条细分类。</p>
        </div>
    </section>
<?php else: ?>
    <div class="cat-toolbar">
        <label class="toolbar-search">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="catSearch" placeholder="搜索中文或英文，如：沙发 / LED / sofa">
        </label>
        <span class="muted" style="font-size:11px">点击细分类标签可编辑或删除；虚线灰色表示已停用（插件里不显示）。</span>
    </div>

    <div class="cat-grid" id="catGrid">
        <?php foreach ($groups as $gi => $g): $c = $palette[$gi % count($palette)]; ?>
            <section class="cat-card<?= $g['active'] ? '' : ' off' ?>" id="group-<?= (int) $g['id'] ?>">
                <header class="cat-card-head">
                    <span class="cat-ico" style="background:linear-gradient(135deg,<?= $c[0] ?>,<?= $c[1] ?>)"><?= e(mb_substr((string) $g['zh'], 0, 1)) ?></span>
                    <div class="cat-title">
                        <h3><?= e($g['zh']) ?><span class="cat-count"><?= count($g['items']) ?></span><?php if (!$g['active']): ?><span class="badge neutral">已停用</span><?php endif; ?></h3>
                        <p><?= e($g['en']) ?> · <code><?= e($g['code']) ?></code></p>
                    </div>
                    <div class="cat-card-actions">
                        <button type="button" class="cat-icon-btn" title="编辑大类" data-group="<?= e(json_encode(['id' => $g['id'], 'zh' => $g['zh'], 'en' => $g['en'], 'sort' => $g['sort'], 'active' => $g['active'], 'count' => count($g['items'])], JSON_UNESCAPED_UNICODE)) ?>">✎</button>
                        <button type="button" class="cat-icon-btn" title="添加细分类" data-add-item="<?= (int) $g['id'] ?>">＋</button>
                    </div>
                </header>
                <div class="cat-chips">
                    <?php foreach ($g['items'] as $it): ?>
                        <button type="button" class="cat-chip<?= $it['active'] ? '' : ' off' ?>"
                            data-search="<?= e(mb_strtolower($it['zh'] . ' ' . $it['en'] . ' ' . $g['zh'] . ' ' . $g['en'])) ?>"
                            data-item="<?= e(json_encode($it, JSON_UNESCAPED_UNICODE)) ?>">
                            <b><?= e($it['zh']) ?></b><small><?= e($it['en']) ?></small>
                        </button>
                    <?php endforeach; ?>
                    <button type="button" class="cat-add-chip" data-add-item="<?= (int) $g['id'] ?>">＋ 添加</button>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
    <div class="cat-no-result" id="catNoResult">没有匹配的分类</div>
    <div class="notice info">
        插件里单个任务最多可选 <b><?= (int) $max ?></b> 个细分类。每个细分类有固定的 <code>code</code>，创建后改名不会影响已有任务保存的分类；删除后，已有任务里对应的分类会被忽略。
    </div>
<?php endif; ?>

<?php if ($ready): ?>
<!-- 大类弹框 -->
<div class="cat-modal" id="catGroupModal" hidden>
    <form class="cat-dialog" method="post" action="<?= e($base . '/groups') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="gId" value="0">
        <div class="cat-dialog-head"><h3 id="gTitle">新增大类</h3><p>大类用于在插件分类选择框里分组显示。</p></div>
        <div class="cat-dialog-body">
            <div class="row">
                <label class="field"><span>中文名</span><input type="text" name="name_zh" id="gZh" maxlength="100" required placeholder="例如：家具"></label>
                <label class="field"><span>英文名</span><input type="text" name="name_en" id="gEn" maxlength="150" required placeholder="例如：Furniture"></label>
            </div>
            <label class="field"><span>排序 <small>数字越小越靠前，留空自动排最后</small></span><input type="number" name="sort_order" id="gSort"></label>
            <label class="switch-field"><input type="checkbox" name="is_active" value="1" id="gActive" checked><span class="switch"></span><span><b>启用</b><small>关闭后整个大类不在插件里显示</small></span></label>
        </div>
        <div class="cat-dialog-foot">
            <button type="button" class="btn danger-ghost" id="gDelete" hidden>删除大类</button>
            <div class="right"><button type="button" class="btn ghost" data-close>取消</button><button type="submit" class="btn primary">保存</button></div>
        </div>
    </form>
</div>

<!-- 细分类弹框 -->
<div class="cat-modal" id="catItemModal" hidden>
    <form class="cat-dialog" method="post" action="<?= e($base . '/items') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="iId" value="0">
        <div class="cat-dialog-head"><h3 id="iTitle">添加细分类</h3><p>英文名会作为给 AI 的产品词，请尽量写成海外买家常用的搜索说法。</p></div>
        <div class="cat-dialog-body">
            <label class="field"><span>所属大类</span>
                <select name="group_id" id="iGroup" required>
                    <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['zh']) ?> · <?= e($g['en']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <div class="row">
                <label class="field"><span>中文名</span><input type="text" name="name_zh" id="iZh" maxlength="100" required placeholder="例如：蓝牙耳机/TWS耳机"></label>
                <label class="field"><span>英文名</span><input type="text" name="name_en" id="iEn" maxlength="150" required placeholder="例如：Bluetooth Earbuds TWS"></label>
            </div>
            <label class="field"><span>排序 <small>数字越小越靠前，留空自动排最后</small></span><input type="number" name="sort_order" id="iSort"></label>
            <label class="switch-field"><input type="checkbox" name="is_active" value="1" id="iActive" checked><span class="switch"></span><span><b>启用</b><small>关闭后该细分类不在插件里显示</small></span></label>
        </div>
        <div class="cat-dialog-foot">
            <button type="button" class="btn danger-ghost" id="iDelete" hidden>删除</button>
            <div class="right"><button type="button" class="btn ghost" data-close>取消</button><button type="submit" class="btn primary">保存</button></div>
        </div>
    </form>
</div>

<!-- 批量添加弹框 -->
<div class="cat-modal" id="catBulkModal" hidden>
    <form class="cat-dialog" method="post" action="<?= e($base . '/items/bulk') ?>">
        <?= csrf_field() ?>
        <div class="cat-dialog-head"><h3>批量添加细分类</h3><p>每行一条，格式：<code>中文名 | English name</code>。已存在的会自动跳过。</p></div>
        <div class="cat-dialog-body">
            <label class="field"><span>所属大类</span>
                <select name="group_id" id="bGroup" required>
                    <?php foreach ($groups as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['zh']) ?> · <?= e($g['en']) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span>内容</span><textarea name="lines" required placeholder="沙发 | Sofas&#10;办公椅 | Office Chairs&#10;折叠桌 | Folding Tables"></textarea></label>
        </div>
        <div class="cat-dialog-foot">
            <span></span>
            <div class="right"><button type="button" class="btn ghost" data-close>取消</button><button type="submit" class="btn primary">批量添加</button></div>
        </div>
    </form>
</div>

<form id="catDelForm" method="post" hidden><?= csrf_field() ?></form>

<script>
(function () {
    var base = <?= json_encode($base) ?>;
    var $ = function (s) { return document.querySelector(s); };
    var $$ = function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); };
    function open(id) { $(id).hidden = false; document.body.style.overflow = 'hidden'; }
    function closeAll() { $$('.cat-modal').forEach(function (m) { m.hidden = true; }); document.body.style.overflow = ''; }
    $$('.cat-modal').forEach(function (m) {
        m.addEventListener('mousedown', function (e) { if (e.target === m) closeAll(); });
        $$('[data-close]').forEach(function (b) { b.addEventListener('click', closeAll); });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(); });

    function del(path, text) {
        if (!confirm(text)) return;
        var f = $('#catDelForm');
        f.action = base + path;
        f.submit();
    }

    function groupForm(g) {
        $('#gId').value = g ? g.id : 0;
        $('#gZh').value = g ? g.zh : '';
        $('#gEn').value = g ? g.en : '';
        $('#gSort').value = g ? g.sort : '';
        $('#gActive').checked = g ? !!g.active : true;
        $('#gTitle').textContent = g ? '编辑大类' : '新增大类';
        var d = $('#gDelete');
        d.hidden = !g;
        d.onclick = g ? function () { del('/groups/' + g.id + '/delete', '删除大类「' + g.zh + '」及其下 ' + g.count + ' 个细分类？此操作不可恢复。'); } : null;
        open('#catGroupModal');
        setTimeout(function () { $('#gZh').focus(); }, 60);
    }

    function itemForm(it, groupId) {
        $('#iId').value = it ? it.id : 0;
        $('#iGroup').value = it ? it.group_id : groupId;
        $('#iZh').value = it ? it.zh : '';
        $('#iEn').value = it ? it.en : '';
        $('#iSort').value = it ? it.sort : '';
        $('#iActive').checked = it ? !!it.active : true;
        $('#iTitle').textContent = it ? '编辑细分类' : '添加细分类';
        var d = $('#iDelete');
        d.hidden = !it;
        d.onclick = it ? function () { del('/items/' + it.id + '/delete', '删除细分类「' + it.zh + '」？'); } : null;
        open('#catItemModal');
        setTimeout(function () { $('#iZh').focus(); }, 60);
    }

    var gNew = $('#catGroupNew');
    if (gNew) gNew.addEventListener('click', function () { groupForm(null); });
    var bulk = $('#catBulkBtn');
    if (bulk) bulk.addEventListener('click', function () { open('#catBulkModal'); });

    document.addEventListener('click', function (e) {
        var g = e.target.closest('[data-group]');
        if (g) { groupForm(JSON.parse(g.getAttribute('data-group'))); return; }
        var add = e.target.closest('[data-add-item]');
        if (add) { itemForm(null, add.getAttribute('data-add-item')); return; }
        var chip = e.target.closest('[data-item]');
        if (chip) { itemForm(JSON.parse(chip.getAttribute('data-item')), 0); }
    });

    var search = $('#catSearch');
    if (search) {
        search.addEventListener('input', function () {
            var q = search.value.trim().toLowerCase();
            var any = false;
            $$('.cat-card').forEach(function (card) {
                var shown = 0;
                Array.prototype.forEach.call(card.querySelectorAll('.cat-chip'), function (chip) {
                    var ok = !q || chip.getAttribute('data-search').indexOf(q) !== -1;
                    chip.classList.toggle('hide', !ok);
                    if (ok) shown++;
                });
                var hide = q && shown === 0;
                card.classList.toggle('hide', !!hide);
                if (!hide) any = true;
            });
            $('#catNoResult').style.display = (q && !any) ? 'block' : 'none';
        });
    }
})();
</script>
<?php endif; ?>
