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

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('form.js-company-note-form');
        if (!form) return;
        event.preventDefault();
        event.stopPropagation();

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn && submitBtn.disabled) return;

        const noteInput = form.querySelector('input[name="note"]');
        const dateInput = form.querySelector('input[name="note_date"]');
        const note = (noteInput?.value || '').trim();
        const noteDate = (dateInput?.value || '').trim();
        if (!note) {
            toast('请填写备注内容', 'err');
            noteInput?.focus();
            return;
        }
        if (!noteDate) {
            toast('请选择备注日期', 'err');
            dateInput?.focus();
            return;
        }

        const originalLabel = submitBtn ? submitBtn.textContent : '保存';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = '保存中…';
        }

        try {
            const res = await fetch(form.getAttribute('action') || (appBase() + '/admin/companies/notes'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    _token: form.querySelector('input[name="_token"]')?.value || csrfToken(),
                    company_id: Number(form.querySelector('input[name="company_id"]')?.value || 0),
                    phone: form.querySelector('input[name="phone"]')?.value || '',
                    note,
                    note_date: noteDate,
                }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || data.ok === false) {
                throw new Error(data.message || ('保存失败 (' + res.status + ')'));
            }

            const saved = data.note || {};
            const today = form.getAttribute('data-today') || '';
            const meta = form.parentElement?.querySelector('.js-note-meta');
            if (meta) {
                if (saved.note_date && saved.note_date === today) {
                    meta.textContent = '今日已保存 · 刚刚';
                } else if (saved.note_date && saved.note) {
                    meta.textContent = '最近备注 ' + saved.note_date + '：' + saved.note;
                }
            }
            if (noteInput && saved.note_date === today) {
                noteInput.value = saved.note || note;
            }
            toast(data.message || '备注已保存', 'ok');
        } catch (error) {
            toast(error instanceof Error ? error.message : '备注保存失败', 'err');
        } finally {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalLabel;
            }
        }
    });

    document.addEventListener('dblclick', async (event) => {
        const btn = event.target.closest('[data-dial-phone]');
        if (!btn || btn.classList.contains('pending')) return;
        event.preventDefault();
        const phone = btn.getAttribute('data-dial-phone') || '';
        const taskId = Number(btn.getAttribute('data-task-id') || 0);
        const companyId = Number(btn.getAttribute('data-company-id') || 0);
        if (!phone || (!taskId && !companyId)) {
            toast('号码无效', 'err');
            return;
        }
        const original = btn.textContent;
        btn.classList.add('pending');
        btn.classList.remove('ok', 'err');
        btn.textContent = '推送中…';
        const endpoint = taskId > 0
            ? (appBase() + '/admin/tasks/' + taskId + '/dial')
            : (appBase() + '/admin/companies/dial');
        try {
            const res = await fetch(endpoint, {
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
                    company_id: companyId || undefined,
                    task_id: taskId || undefined,
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
