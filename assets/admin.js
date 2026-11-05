(function () {
    'use strict';

    const copyText = async (text) => {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (error) {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            let ok = false;
            try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
            document.body.removeChild(area);
            return ok;
        }
    };

    document.addEventListener('click', async (event) => {
        const copyButton = event.target.closest('[data-copy]');
        if (copyButton) {
            event.preventDefault();
            const ok = await copyText(copyButton.getAttribute('data-copy') || '');
            const original = copyButton.textContent;
            copyButton.textContent = ok ? '已复制' : '复制失败';
            setTimeout(() => { copyButton.textContent = original; }, 1500);
            return;
        }

        const reveal = event.target.closest('[data-reveal]');
        if (reveal) {
            event.preventDefault();
            const wrap = reveal.closest('.password-wrap');
            const input = wrap ? wrap.querySelector('input') : null;
            if (input) {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                reveal.textContent = show ? '隐藏' : '显示';
            }
            return;
        }

        const revealTarget = event.target.closest('[data-reveal-target]');
        if (revealTarget) {
            event.preventDefault();
            const input = document.getElementById(revealTarget.getAttribute('data-reveal-target'));
            if (input) {
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                revealTarget.textContent = show ? '隐藏' : '显示';
            }
            return;
        }

        const scrim = event.target.closest('#scrim');
        if (scrim) {
            document.getElementById('sidebar')?.classList.remove('open');
            scrim.classList.remove('show');
        }
    });

    const navToggle = document.getElementById('navToggle');
    const sidebar = document.getElementById('sidebar');
    const scrim = document.getElementById('scrim');
    if (navToggle && sidebar && scrim) {
        navToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            scrim.classList.toggle('show');
        });
    }

    document.querySelectorAll('textarea[data-count-for], textarea#system_prompt, textarea#user_prompt').forEach((area) => {
        const targetId = area.getAttribute('data-count-for') || area.id;
        const counter = document.querySelector('[data-count-for="' + targetId + '"]');
        if (!counter) return;
        const update = () => { counter.textContent = area.value.length + ' 字符'; };
        area.addEventListener('input', update);
        update();
    });

    const toast = (message, type = 'ok') => {
        let host = document.getElementById('adminToastHost');
        if (!host) {
            host = document.createElement('div');
            host.id = 'adminToastHost';
            host.className = 'admin-toast-host';
            document.body.appendChild(host);
        }
        const node = document.createElement('div');
        node.className = 'admin-toast ' + type;
        node.textContent = message;
        host.appendChild(node);
        requestAnimationFrame(() => node.classList.add('show'));
        setTimeout(() => {
            node.classList.remove('show');
            setTimeout(() => node.remove(), 220);
        }, 2800);
    };

    const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const appBase = () => document.body.getAttribute('data-base') || '';

    document.addEventListener('dblclick', async (event) => {
        const btn = event.target.closest('[data-dial-phone]');
        if (!btn || btn.classList.contains('pending')) return;
        event.preventDefault();
        const phone = btn.getAttribute('data-dial-phone') || '';
        const taskId = Number(btn.getAttribute('data-task-id') || 0);
        if (!phone || !taskId) {
            toast('号码无效', 'err');
            return;
        }
        const original = btn.textContent;
        btn.classList.add('pending');
        btn.classList.remove('ok', 'err');
        btn.textContent = '推送中…';
        try {
            const res = await fetch(appBase() + '/admin/tasks/' + taskId + '/dial', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    _token: csrfToken(),
                    phone,
                    company_id: Number(btn.getAttribute('data-company-id') || 0) || undefined,
                    company_name: btn.getAttribute('data-company-name') || '',
                    city: btn.getAttribute('data-city') || '',
                    domain: btn.getAttribute('data-domain') || '',
                    email: btn.getAttribute('data-email') || '',
                    address: btn.getAttribute('data-address') || '',
                    source_url: btn.getAttribute('data-source-url') || '',
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                throw new Error(data.message || ('推送失败 (' + res.status + ')'));
            }
            btn.classList.add('ok');
            toast(data.message || (data.duplicated ? '该号码已在拨号队列中' : '已推送到手机拨号队列'), data.duplicated ? 'info' : 'ok');
        } catch (error) {
            btn.classList.add('err');
            toast(error instanceof Error ? error.message : '推送拨号失败', 'err');
        } finally {
            btn.classList.remove('pending');
            btn.textContent = original;
            setTimeout(() => btn.classList.remove('ok', 'err'), 1800);
        }
    });
})();
