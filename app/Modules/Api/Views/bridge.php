<?php
/** @var string $basePath */
/** @var string $version */
$base = $basePath === '' ? '' : $basePath;
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="gle-base" content="<?= htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <meta name="gle-bridge" content="1">
    <title>GLE Host Bridge</title>
    <style>
        html, body { margin: 0; background: #0b1220; color: #9fb0c8; font: 13px/1.4 system-ui, sans-serif; }
        #s { padding: 10px 12px; }
    </style>
</head>
<body>
<div id="s">host bridge warming…</div>
<script>
(function () {
    'use strict';
    var metaBase = (document.querySelector('meta[name="gle-base"]') || {}).content || '';
    var originBase = window.location.origin + metaBase;
    var statusEl = document.getElementById('s');
    var ready = false;
    var proving = null;
    var reloadScheduled = false;

    function setStatus(text) {
        if (statusEl) statusEl.textContent = text;
    }

    function postNative(payload) {
        try {
            if (window.ReactNativeWebView && typeof window.ReactNativeWebView.postMessage === 'function') {
                window.ReactNativeWebView.postMessage(JSON.stringify(payload));
            }
        } catch (e) {}
    }

    function isChallenge(text) {
        if (!text || typeof text !== 'string') return false;
        var sample = text.slice(0, 4000).toLowerCase();
        if (sample.indexOf('/aes.js') !== -1) return true;
        if (sample.indexOf('function tonumber') !== -1) return true;
        if (sample.indexOf('this site requires javascript') !== -1) return true;
        if (sample.indexOf('checking your browser') !== -1) return true;
        if (sample.indexOf('__test') !== -1 && sample.indexOf('document.cookie') !== -1) return true;
        return false;
    }

    function looksJson(text) {
        var t = String(text || '').replace(/^\uFEFF/, '').trim();
        return t.charAt(0) === '{' || t.charAt(0) === '[';
    }

    function sleep(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function notifyReady() {
        ready = true;
        setStatus('bridge ready · v<?= htmlspecialchars($version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>');
        postNative({ type: 'BRIDGE_READY', base: originBase, at: Date.now() });
        try {
            window.dispatchEvent(new CustomEvent('gle-bridge-ready'));
        } catch (e) {}
    }

    function scheduleReload(reason) {
        if (reloadScheduled) return;
        reloadScheduled = true;
        ready = false;
        setStatus('cookie rotated, reloading… (' + reason + ')');
        postNative({ type: 'BRIDGE_RELOADING', reason: String(reason || '') });
        setTimeout(function () {
            try {
                var url = originBase + '/bridge?_=' + Date.now();
                window.location.replace(url);
            } catch (e) {
                window.location.reload();
            }
        }, 350);
    }

    async function prove(maxTries) {
        if (proving) return proving;
        proving = (async function () {
            var tries = Math.max(3, Math.min(12, maxTries || 8));
            for (var i = 0; i < tries; i++) {
                try {
                    var response = await fetch(originBase + '/api/health', {
                        method: 'GET',
                        credentials: 'include',
                        cache: 'no-store',
                        headers: {
                            'Accept': 'application/json, text/plain, */*',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    var text = await response.text();
                    if (isChallenge(text)) {
                        setStatus('waiting host security… ' + (i + 1) + '/' + tries);
                        await sleep(700 + i * 200);
                        continue;
                    }
                    if (looksJson(text)) {
                        notifyReady();
                        return true;
                    }
                    setStatus('unexpected health payload… ' + (i + 1) + '/' + tries);
                } catch (e) {
                    setStatus('health error… ' + (i + 1) + '/' + tries);
                }
                await sleep(600 + i * 150);
            }
            scheduleReload('prove_failed');
            return false;
        })();
        try {
            return await proving;
        } finally {
            proving = null;
        }
    }

    async function bridgeFetch(opts) {
        opts = opts || {};
        var id = opts.id || ('b' + Date.now());
        var url = String(opts.url || '');
        var method = String(opts.method || 'GET').toUpperCase();
        var headers = opts.headers && typeof opts.headers === 'object' ? opts.headers : {};
        var body = opts.body === undefined ? null : opts.body;
        var timeout = Math.max(5000, Math.min(180000, Number(opts.timeout) || 35000));

        if (!ready) {
            var ok = await prove(8);
            if (!ok) {
                return { id: id, status: 0, text: '', error: 'host_bridge_not_ready' };
            }
        }

        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, timeout);
        try {
            var init = {
                method: method,
                headers: headers,
                credentials: 'include',
                cache: 'no-store',
                signal: controller.signal
            };
            if (body !== null && method !== 'GET' && method !== 'HEAD') {
                init.body = body;
            }
            var response = await fetch(url, init);
            var text = await response.text();
            if (isChallenge(text)) {
                ready = false;
                scheduleReload('fetch_challenge');
                return { id: id, status: response.status, text: text, error: 'host_security_challenge' };
            }
            return { id: id, status: response.status, text: text, ok: response.ok };
        } catch (e) {
            var message = (e && e.name === 'AbortError') ? '请求超时' : String((e && e.message) || e);
            return { id: id, status: 0, text: '', error: message };
        } finally {
            clearTimeout(timer);
        }
    }

    window.__GLE_BRIDGE__ = {
        version: <?= json_encode((string) $version, JSON_UNESCAPED_UNICODE) ?>,
        base: originBase,
        isReady: function () { return !!ready; },
        prove: function () { return prove(8); },
        fetch: bridgeFetch,
        requestFromNative: function (opts) {
            bridgeFetch(opts).then(function (result) {
                postNative(Object.assign({ type: 'BRIDGE_RESULT' }, result));
            });
            return true;
        }
    };

    // Boot: wait a beat so InfinityFree aes.js can set __test, then prove.
    setTimeout(function () { prove(10); }, 500);
    // Cookie parameters rotate; re-prove periodically while the tab stays open.
    setInterval(function () {
        if (!reloadScheduled) prove(4);
    }, 40000);

    // RN / extension can ask for readiness ping.
    window.addEventListener('message', function (event) {
        try {
            var data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
            if (!data || typeof data !== 'object') return;
            if (data.type === 'BRIDGE_PING') {
                postNative({ type: 'BRIDGE_PONG', ready: ready, base: originBase });
            }
            if (data.type === 'BRIDGE_FETCH') {
                window.__GLE_BRIDGE__.requestFromNative(data);
            }
        } catch (e) {}
    });
})();
</script>
</body>
</html>
