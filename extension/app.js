(() => {
    'use strict';

    const STORAGE_KEY = 'gle_config';
    const LAST_TASK_KEY = 'gle_last_task';
    const STAGES = [
        { key: 'keywords', no: '01', label: '关键词', full: 'AI 生成城市关键词' },
        { key: 'serp', no: '02', label: 'Google', full: 'Google / Serper 搜索拓域' },
        { key: 'classify', no: '03', label: 'AI 筛选', full: '企业属性筛选' },
        { key: 'discover', no: '04', label: '官网识别', full: '关键页面识别' },
        { key: 'extract', no: '05', label: '联系提取', full: '联系方式提取' },
        { key: 'phone', no: '06', label: '电话整理', full: 'AI 电话标准化' },
        { key: 'done', no: '07', label: '完成', full: '线索入库' }
    ];
    const STAGE_LABEL = {
        keywords: 'AI 生成关键词',
        serp: 'Google / Serper 搜索',
        classify: 'AI 企业筛选',
        discover: '官网页面识别',
        extract: '联系方式提取',
        phone: 'AI 电话标准化',
        done: '已完成'
    };
    const ACTION_LABEL = {
        generate_keywords: 'AI 生成城市关键词',
        fetch_serp: '抓取 Google 搜索结果',
        search_serper: 'Serper 搜索 Google 结果',
        classify_domains: 'AI 筛选企业独立站',
        crawl_website: '抓取官网首页',
        extract_contacts: '提取联系方式',
        normalize_phones: 'AI 标准化电话号码',
        done: '全部流程完成',
        paused: '任务已暂停',
        failed: '任务异常'
    };
    const STATUS_LABEL = {
        active: '运行中',
        paused: '已暂停',
        completed: '已完成',
        failed: '异常'
    };
    const ICONS = {
        generate_keywords: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m12 3 1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9L12 3Z"/><path d="M19 16v4M17 18h4M5 3v3M3.5 4.5h3"/></svg>',
        fetch_serp: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>',
        search_serper: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 18 9 8l4 6 3-8 4 12"/><path d="M4 21h16"/></svg>',
        classify_domains: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 5h16l-6 8v5l-4 2v-7L4 5Z"/></svg>',
        crawl_website: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18M12 3a15 15 0 0 0 0 18"/></svg>',
        extract_contacts: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg>',
        normalize_phones: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/></svg>',
        done: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 13 4 4L19 7"/></svg>',
        default: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'
    };

    const DEFAULT_CONFIG = {
        apiBase: '',
        apiKey: '',
        autoRun: true,
        requestDelay: 2500,
        searchProvider: 'serper',
        serperApiKey: 'a4be645a029169206f66e8024a73361f005fc5ef',
        serperGl: 'us',
        serperHl: 'en',
        aiBaseUrl: '',
        aiApiKey: '',
        aiModel: 'gpt-4o-mini'
    };

    const state = {
        config: { ...DEFAULT_CONFIG },
        tasks: [],
        /** Task currently shown in the workspace (viewing only). */
        currentId: null,
        /** Task currently being executed by the auto-run queue (id order). */
        runnerId: null,
        next: null,
        tab: 'overview',
        domainFilter: 'all',
        data: {},
        localLogs: [],
        busy: false,
        token: 0,
        forceRun: false,
        pausedByUser: false,
        /** Synced from App via /api/runner — when false, stop immediately. */
        serverRunnerEnabled: true,
        cooldownUntil: 0,
        search: '',
        initialized: false,
        /** 产品分类库（/api/categories）与选择状态 */
        categoryTree: null,
        categoryMax: 12,
        newCategories: [],
        editCategories: [],
        categoryTarget: 'new',
        categoryDraft: [],
        /** Once InfinityFree aes.js is detected, always route API via /bridge tab. */
        forceHostBridge: false
    };

    class ApiError extends Error {
        constructor(message, code = 'request_failed', status = 0) {
            super(message);
            this.name = 'ApiError';
            this.code = code;
            this.status = status;
        }
    }

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[char]));
    }

    function safeHref(value) {
        try {
            const url = new URL(String(value || ''));
            return /^https?:$/.test(url.protocol) ? escapeHtml(url.href) : '#';
        } catch (error) {
            return '#';
        }
    }

    function formatNumber(value) {
        const number = Number(value || 0);
        return new Intl.NumberFormat('zh-CN').format(Number.isFinite(number) ? number : 0);
    }

    function formatDateTime(value, short = false) {
        if (!value) return '—';
        const text = String(value).replace('T', ' ');
        return short ? text.slice(5, 16) : text.slice(0, 16);
    }

    function normalizeBase(value) {
        let base = String(value || '').trim().replace(/\/+$/, '');
        if (!base) return '';
        if (!/^https?:\/\//i.test(base)) base = 'https://' + base;
        base = base.replace(/\/index\.php$/i, '');
        return base.replace(/\/+$/, '');
    }

    /** InfinityFree / similar free hosts return HTML aes.js instead of the API. */
    function isHostSecurityChallenge(text) {
        if (!text || typeof text !== 'string') return false;
        const sample = text.slice(0, 4000).toLowerCase();
        return sample.includes('/aes.js')
            || sample.includes('function tonumber')
            || sample.includes('this site requires javascript')
            || sample.includes('checking your browser')
            || (sample.includes('__test') && sample.includes('document.cookie'));
    }

    async function warmApiHost(base) {
        await sendBackground('WARM_HOST', { url: normalizeBase(base) }, 35000);
    }

    async function requestViaHostTab(path, options = {}) {
        const base = normalizeBase(options.base || state.config.apiBase);
        const method = options.method || 'GET';
        const timeout = options.timeout || 45000;
        const apiKey = options.apiKey !== undefined ? options.apiKey : state.config.apiKey;
        const headers = { 'Accept': 'application/json' };
        if (options.body !== undefined && options.body !== null) headers['Content-Type'] = 'application/json';
        if (apiKey) headers['X-API-Key'] = apiKey;
        const body = options.body !== undefined && options.body !== null ? JSON.stringify(options.body) : null;
        return sendBackground('HOST_API_FETCH', {
            base,
            path,
            method,
            headers,
            body,
            timeout
        }, Math.max(45000, timeout + 20000));
    }

    function parseApiPayload(text, status) {
        if (isHostSecurityChallenge(text)) {
            throw new ApiError('主机开启了浏览器安全校验（InfinityFree aes.js）。已尝试用站点标签页请求仍失败，请换主机或稍后重试。', 'host_security', status || 0);
        }
        let payload = null;
        try {
            payload = text ? JSON.parse(text) : null;
        } catch (error) {
            throw new ApiError('服务器返回了非 JSON 内容。请确认 API 地址指向站点根目录，且已安装应用。', 'invalid_json', status || 0);
        }
        if (!payload || payload.ok !== true) {
            const apiError = payload && payload.error ? payload.error : {};
            const message = apiError.message || ('请求失败（HTTP ' + (status || 0) + '）');
            throw new ApiError(message, apiError.code || 'request_failed', status || 0);
        }
        return payload.data;
    }

    function taskById(id) {
        return state.tasks.find((task) => Number(task.id) === Number(id)) || null;
    }

    function actionable(next) {
        return !!next && next.task && next.task.status === 'active' &&
            ['generate_keywords', 'fetch_serp', 'search_serper', 'classify_domains', 'crawl_website', 'extract_contacts', 'normalize_phones'].includes(next.action);
    }

    /* ------------------------------------------------------------------ */
    /* Config & API                                                       */
    /* ------------------------------------------------------------------ */
    async function loadConfig() {
        try {
            const stored = await chrome.storage.local.get(STORAGE_KEY);
            const saved = stored[STORAGE_KEY] || {};
            const provider = ['serper', 'server', 'browser'].includes(saved.searchProvider) ? saved.searchProvider : DEFAULT_CONFIG.searchProvider;
            const serperKey = typeof saved.serperApiKey === 'string' && saved.serperApiKey.trim() !== ''
                ? saved.serperApiKey.trim()
                : DEFAULT_CONFIG.serperApiKey;
            state.config = {
                ...DEFAULT_CONFIG,
                ...saved,
                apiBase: normalizeBase(saved.apiBase || ''),
                requestDelay: Math.max(300, Math.min(15000, Number(saved.requestDelay || 2500))),
                searchProvider: provider,
                serperApiKey: serperKey
            };
        } catch (error) {
            state.config = { ...DEFAULT_CONFIG };
        }
    }

    async function persistConfig() {
        await chrome.storage.local.set({ [STORAGE_KEY]: state.config });
    }

    async function request(path, options = {}) {
        const base = state.config.apiBase;
        if (!base) throw new ApiError('尚未配置 API 地址，请先连接服务器。', 'missing_api_base');
        const method = options.method || 'GET';
        const timeout = options.timeout || 45000;
        const headers = { 'Accept': 'application/json' };
        if (options.body !== undefined && options.body !== null) headers['Content-Type'] = 'application/json';
        if (state.config.apiKey) headers['X-API-Key'] = state.config.apiKey;
        const body = options.body !== undefined && options.body !== null ? JSON.stringify(options.body) : undefined;

        // Sticky bridge: once InfinityFree challenge is seen, always use /bridge tab.
        if (state.forceHostBridge) {
            const viaTab = await requestViaHostTab(path, options);
            const data = parseApiPayload(viaTab.text, viaTab.status);
            setConnection('ok', '已连接·桥接');
            return data;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeout);
        let response;
        let text = '';
        let networkFailed = false;
        try {
            response = await fetch(base + path, {
                method,
                headers,
                body,
                signal: controller.signal,
                cache: 'no-store',
                credentials: 'include'
            });
            text = await response.text();
        } catch (error) {
            networkFailed = true;
            text = '';
            if (!(error && error.name === 'AbortError')) {
                // Fall through to host bridge (common on locked-down free hosts).
            } else {
                setConnection('off', '连接失败');
                throw new ApiError('请求超时，请检查服务器响应。', 'network_error');
            }
        } finally {
            clearTimeout(timer);
        }

        // InfinityFree: extension fetch often gets aes.js HTML — use /bridge tab.
        if (networkFailed || isHostSecurityChallenge(text)) {
            state.forceHostBridge = true;
            try {
                await warmApiHost(base);
                const viaTab = await requestViaHostTab(path, options);
                const data = parseApiPayload(viaTab.text, viaTab.status);
                setConnection('ok', '已连接·桥接');
                return data;
            } catch (error) {
                setConnection('off', '连接失败');
                if (error instanceof ApiError) throw error;
                throw new ApiError('主机安全校验失败：' + (error.message || '无法打开站点桥接页'), 'host_security', response ? response.status : 0);
            }
        }

        const data = parseApiPayload(text, response.status);
        setConnection('ok', '已连接');
        return data;
    }

    async function probeConnection(base, apiKey) {
        const cleanBase = normalizeBase(base);
        if (!cleanBase) throw new ApiError('请输入 API 地址。', 'missing_api_base');

        async function fetchText(url, init = {}) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 12000);
            try {
                const response = await fetch(url, {
                    cache: 'no-store',
                    credentials: 'include',
                    signal: controller.signal,
                    ...init
                });
                const text = await response.text();
                return { status: response.status, ok: response.ok, text };
            } finally {
                clearTimeout(timer);
            }
        }

        let health;
        try {
            health = await fetchText(cleanBase + '/api/health');
        } catch (error) {
            health = { status: 0, ok: false, text: '' };
        }
        if (!health.text || isHostSecurityChallenge(health.text)) {
            state.forceHostBridge = true;
            await warmApiHost(cleanBase);
            health = await requestViaHostTab('/api/health', { base: cleanBase, apiKey: '', timeout: 25000 });
        }
        if (isHostSecurityChallenge(health.text)) {
            throw new ApiError('主机开启了浏览器安全校验（InfinityFree）。已尝试 /bridge 桥接仍失败，请确认已部署最新服务端，或换无此校验的主机。', 'host_security', health.status);
        }
        if (!(health.ok || (health.status >= 200 && health.status < 300))) {
            throw new ApiError('健康检查返回 HTTP ' + health.status + '，请检查伪静态与入口文件。', 'health_failed', health.status);
        }
        let payload;
        try {
            payload = health.text ? JSON.parse(health.text) : null;
        } catch (error) {
            throw new ApiError('健康检查返回非 JSON，API 地址可能不是站点根目录。', 'invalid_json');
        }
        if (!payload || payload.ok !== true) throw new ApiError('服务端健康检查未通过。', 'health_failed');

        if (apiKey) {
            let probe;
            try {
                probe = state.forceHostBridge
                    ? await requestViaHostTab('/api/tasks', { base: cleanBase, apiKey, timeout: 25000 })
                    : await fetchText(cleanBase + '/api/tasks', {
                        headers: { 'X-API-Key': apiKey, 'Accept': 'application/json' }
                    });
            } catch (error) {
                probe = { status: 0, ok: false, text: '' };
            }
            if (!probe.text || isHostSecurityChallenge(probe.text)) {
                state.forceHostBridge = true;
                probe = await requestViaHostTab('/api/tasks', { base: cleanBase, apiKey, timeout: 25000 });
            }
            if (probe.status === 401 || probe.status === 403) {
                throw new ApiError('API Key 无效或已被轮换，请到后台重新复制。', 'unauthorized', probe.status);
            }
            if (!(probe.ok || (probe.status >= 200 && probe.status < 300))) {
                throw new ApiError('API Key 校验失败（HTTP ' + probe.status + '）。', 'auth_failed', probe.status);
            }
        }
        return payload.data || {};
    }

    function setConnection(mode, label) {
        const dot = $('#connectionDot');
        const text = $('#connectionLabel');
        if (dot) dot.className = mode === 'ok' ? 'ok' : (mode === 'checking' ? 'checking' : 'off');
        if (text) text.textContent = label;
    }

    async function checkConnection(notify = false) {
        if (!state.config.apiBase) {
            setConnection('off', '未连接');
            if (notify) toast('请先填写 API 地址。', 'error');
            return false;
        }
        if (!state.config.apiKey) {
            setConnection('off', '缺少 Key');
            if (notify) toast('请先填写后台生成的 API Key。', 'error');
            return false;
        }
        setConnection('checking', '检查中');
        try {
            const data = await probeConnection(state.config.apiBase, state.config.apiKey);
            setConnection('ok', '已连接');
            if (notify) toast('服务器连接正常 · PHP ' + (data.php || '?'), 'success');
            return true;
        } catch (error) {
            setConnection('off', '连接失败');
            if (notify) toast(error.message, 'error', 4500);
            return false;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Background bridge                                                  */
    /* ------------------------------------------------------------------ */
    function sendBackground(type, payload = {}, timeout = 45000) {
        return new Promise((resolve, reject) => {
            let settled = false;
            const timer = setTimeout(() => {
                if (settled) return;
                settled = true;
                reject(new Error('浏览器操作超时，请稍后重试。'));
            }, timeout);
            try {
                chrome.runtime.sendMessage({ type, ...payload }, (response) => {
                    if (settled) return;
                    settled = true;
                    clearTimeout(timer);
                    if (chrome.runtime.lastError) {
                        reject(new Error(chrome.runtime.lastError.message));
                        return;
                    }
                    if (!response) {
                        reject(new Error('扩展后台没有响应。'));
                        return;
                    }
                    if (!response.ok) {
                        reject(new Error(response.error || '浏览器操作失败。'));
                        return;
                    }
                    resolve(response.data);
                });
            } catch (error) {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                reject(new Error('扩展后台不可用，请在扩展管理页重新加载。'));
            }
        });
    }

    async function fetchPageWithFallback(url, label = '') {
        let direct = null;
        try {
            // Background tries https/http and www/apex variants automatically.
            direct = await sendBackground('FETCH_PAGE', { url }, 45000);
        } catch (error) {
            direct = null;
        }
        if (direct && direct.html && direct.html.trim().length > 200 && direct.status >= 200 && direct.status < 500) {
            return direct;
        }
        // Keep a long 5xx body if it still looks like a real page.
        if (direct && direct.html && direct.html.trim().length > 1500) {
            return direct;
        }
        if (label) addLocal(label + ' · 切换标签页抓取', 'warn');
        try {
            const tab = await sendBackground('FETCH_PAGE_TAB', { url }, 90000);
            if (tab && tab.html && tab.html.trim().length > 200) return tab;
        } catch (error) {
            addLocal('标签页抓取失败：' + error.message, 'error');
        }
        return direct;
    }

    /**
     * Report a crawl/extract soft-failure so the backend increments attempts
     * and eventually marks the website as error (stops infinite retry loops).
     */
    async function reportWebsiteFail(taskId, websiteId, stage, message) {
        if (!websiteId) {
            addLocal(message, 'error');
            return { skipped: false };
        }
        try {
            const data = await request('/api/tasks/' + taskId + '/websites/' + websiteId + '/fail', {
                method: 'POST',
                timeout: 30000,
                body: { stage: stage === 'extract' ? 'extract' : 'discover', message: String(message || '抓取失败') }
            });
            const attempts = Number(data.attempts || 0);
            const max = Number(data.max_attempts || 3);
            if (data.skipped) {
                addLocal((message || '抓取失败') + ' · 已跳过该官网', 'warn');
            } else {
                addLocal((message || '抓取失败') + '（第 ' + Math.max(1, attempts) + '/' + max + ' 次，将自动重试）', 'warn');
            }
            return data;
        } catch (error) {
            addLocal(message + '（上报失败计数失败：' + (error.message || error) + '）', 'error');
            throw error instanceof ApiError ? error : new ApiError(String(message || '抓取失败'), 'empty_html');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Task loading & polling                                             */
    /* ------------------------------------------------------------------ */
    async function loadTasks(options = {}) {
        if (!state.config.apiBase || !state.config.apiKey) {
            state.tasks = [];
            renderTaskList();
            showEmptyWorkspace();
            return;
        }
        try {
            const data = await request('/api/tasks', { timeout: 20000 });
            state.tasks = Array.isArray(data.tasks) ? data.tasks : [];
            renderTaskList();
            let id = state.currentId;
            if (!taskById(id)) {
                const remembered = Number(localStorage.getItem(LAST_TASK_KEY) || 0);
                id = taskById(remembered) ? remembered : (state.tasks[0] ? Number(state.tasks[0].id) : null);
            }
            if (id) {
                // Switching / loading tasks is view-only; the queue runner starts separately.
                await selectTask(id);
            } else {
                state.currentId = null;
                state.next = null;
                showEmptyWorkspace();
            }
            if (options.kick !== false) {
                ensureRunner();
            }
        } catch (error) {
            addLocal('加载任务失败：' + error.message, 'error');
            if (options.notify) toast(error.message, 'error');
            showEmptyWorkspace();
        }
    }

    async function loadTasksSilent() {
        if (!state.config.apiBase || !state.config.apiKey) return;
        try {
            const data = await request('/api/tasks', { timeout: 15000 });
            state.tasks = Array.isArray(data.tasks) ? data.tasks : [];
            if (state.runnerId && !taskById(state.runnerId)) {
                state.runnerId = null;
            }
            renderTaskList();
            if (state.currentId && !taskById(state.currentId)) {
                state.currentId = null;
                state.next = null;
                showEmptyWorkspace();
            }
        } catch (error) {
            /* transient errors are handled by the polling cycle */
        }
    }

    /**
     * Select a task for viewing only. Never starts or steals the auto-run queue.
     */
    async function selectTask(id) {
        const task = taskById(id);
        if (!task) return;
        const changed = Number(state.currentId) !== Number(id);
        state.currentId = Number(id);
        localStorage.setItem(LAST_TASK_KEY, String(state.currentId));
        if (changed) {
            state.next = null;
            state.data = {};
            switchTab('overview', false);
        }
        showTaskWorkspace();
        renderTaskList();
        await loadNext(state.currentId);
    }

    /**
     * Fetch /next for a task. UI workspace updates only when that task is the one being viewed.
     */
    async function loadNext(taskId) {
        if (!taskId) return null;
        const expectedId = Number(taskId);
        const data = await request('/api/tasks/' + expectedId + '/next', { timeout: 30000 });
        const task = data.task || {};
        const counts = data.counts || {};
        mergeTask(task, counts);
        if (Number(state.currentId) === expectedId) {
            state.next = data;
            renderWorkspace(task, counts);
            renderNext(data);
            if (state.tab !== 'overview') {
                const cache = state.data[state.tab];
                if (!cache || Date.now() - cache.at > 7000) {
                    loadTabData(state.tab, true);
                }
            }
        }
        renderTaskList();
        return data;
    }

    function mergeTask(task, counts) {
        const existing = taskById(task.id);
        if (!existing) {
            state.tasks.unshift(task);
            return;
        }
        Object.assign(existing, task);
        if (counts) {
            existing.keywords_count = counts.keywords_total;
            existing.websites_count = counts.websites_total;
            existing.approved_count = counts.websites_approved;
            existing.companies_count = counts.companies;
        }
    }

    async function syncRunnerFlag() {
        try {
            const data = await request('/api/runner', { timeout: 12000 });
            const enabled = data.enabled !== false;
            if (state.serverRunnerEnabled && !enabled) {
                addLocal('App 已关闭插件自动执行，立即停止当前队列。', 'warn');
                toast('App 已关闭自动跑任务', 'warn', 4000);
                state.token += 1;
                state.forceRun = false;
            } else if (!state.serverRunnerEnabled && enabled) {
                addLocal('App 已开启插件自动执行，开始排队跑任务。', 'success');
            }
            state.serverRunnerEnabled = enabled;
            return enabled;
        } catch (error) {
            // Keep last known state on transient errors.
            return state.serverRunnerEnabled;
        }
    }

    function queuedActiveTasks() {
        return state.tasks
            .filter((task) => task && task.status === 'active')
            .sort((a, b) => Number(a.id) - Number(b.id));
    }

    /** Always pick the lowest-id active task for the auto-run queue. */
    function pickRunnerTaskId() {
        const active = queuedActiveTasks();
        if (!active.length) return null;
        return Number(active[0].id);
    }

    function ensureRunner() {
        if (state.busy || document.hidden) return;
        if (!state.config.autoRun && !state.forceRun) return;
        if (state.pausedByUser || !state.serverRunnerEnabled) return;
        if (Date.now() < state.cooldownUntil) return;
        const taskId = pickRunnerTaskId();
        if (!taskId) {
            state.runnerId = null;
            renderTaskList();
            return;
        }
        kickLoop();
    }

    async function advanceToNextQueuedTask(fromTaskId) {
        await loadTasksSilent();
        const active = queuedActiveTasks();
        if (!active.length) {
            state.runnerId = null;
            renderTaskList();
            addLocal('没有更多可执行任务，队列空闲。', 'info');
            return false;
        }
        const current = Number(fromTaskId || state.runnerId || 0);
        // Strict id order: next higher id, otherwise the lowest remaining active task.
        const next = active.find((task) => Number(task.id) > current) || active[0];
        if (!next) return false;
        if (Number(next.id) === current) {
            const probe = await loadNext(current);
            return actionable(probe) && probe?.task?.status === 'active';
        }
        state.runnerId = Number(next.id);
        renderTaskList();
        addLocal('按顺序开始下一任务 #' + next.id + ' ' + (next.city || ''), 'success');
        toast('队列执行：#' + next.id + ' ' + (next.city || ''), 'success');
        return true;
    }

    async function poll() {
        if (document.hidden) return;
        if (state.busy || Date.now() < state.cooldownUntil) return;
        if (!state.config.apiBase || !state.config.apiKey) return;
        const runnerOn = await syncRunnerFlag();
        await loadTasksSilent();
        if (!runnerOn) return;

        // Keep the viewed task's workspace fresh without touching the runner.
        if (state.currentId) {
            try {
                await loadNext(state.currentId);
            } catch (error) {
                if (error instanceof ApiError && error.status === 404) {
                    state.currentId = null;
                    state.next = null;
                    showEmptyWorkspace();
                } else {
                    setConnection('off', '连接异常');
                    return;
                }
            }
        } else {
            const fallback = state.tasks[0];
            if (fallback) await selectTask(Number(fallback.id));
        }

        ensureRunner();
    }

    async function kickLoop() {
        if (state.busy) return;
        const token = state.token;
        const taskId = pickRunnerTaskId();
        if (!taskId) {
            state.runnerId = null;
            renderTaskList();
            return;
        }
        state.runnerId = taskId;
        state.busy = true;
        updateBusyUI();
        renderTaskList();
        let lastNext = null;
        try {
            for (let guard = 0; guard < 500; guard += 1) {
                if (state.token !== token) break;
                // Always stay on the lowest-id active task; never follow UI selection.
                const preferred = pickRunnerTaskId();
                if (!preferred) break;
                if (Number(preferred) !== Number(taskId)) {
                    // A lower-id task became active — finish this loop and let ensureRunner pick it up.
                    break;
                }
                if (Number(state.runnerId) !== Number(taskId)) break;
                if (!(await syncRunnerFlag())) {
                    addLocal('检测到 App 关闭自动执行，已停止。', 'warn');
                    break;
                }
                lastNext = await loadNext(taskId);
                if (!actionable(lastNext)) break;
                if (lastNext.task.status !== 'active') break;
                if (!state.config.autoRun && !state.forceRun) break;
                await executeAction(lastNext);
                if (state.token !== token) break;
                if (Number(state.currentId) === Number(taskId)) {
                    invalidateData();
                    if (state.tab !== 'overview') await loadTabData(state.tab, true);
                }
                lastNext = await loadNext(taskId);
                if (!state.config.autoRun && !state.forceRun) break;
                await sleep(280);
            }
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            addLocal(message, 'error');
            toast(message, 'error', 6000);
            if (error instanceof ApiError && (error.code === 'task_busy' || error.code === 'stage_conflict')) {
                state.cooldownUntil = Date.now() + 4500;
            } else {
                state.cooldownUntil = Date.now() + 12000;
            }
        } finally {
            state.busy = false;
            updateBusyUI();
            renderTaskList();
            if (state.config.autoRun && !state.pausedByUser && state.serverRunnerEnabled && !document.hidden) {
                const task = taskById(taskId);
                const finished = !task || task.status === 'completed' || task.stage === 'done' || !actionable(lastNext);
                if (finished) {
                    setTimeout(async () => {
                        if (state.busy) return;
                        const moved = await advanceToNextQueuedTask(taskId);
                        if (moved) ensureRunner();
                        else if (!state.busy) poll();
                    }, 500);
                } else {
                    setTimeout(() => { if (!state.busy) ensureRunner(); }, 700);
                }
            }
        }
    }

    async function runOneStep() {
        if (state.busy) {
            toast('队列正在执行任务 #' + (state.runnerId || '—') + '，请稍候或关闭自动执行后再单步。', 'warn');
            return;
        }
        const taskId = state.currentId;
        if (!taskId) return;
        state.busy = true;
        state.forceRun = true;
        updateBusyUI();
        try {
            const next = await loadNext(taskId);
            if (!actionable(next)) {
                toast('当前没有可执行的动作。', 'info');
                return;
            }
            await executeAction(next);
            invalidateData();
            await loadNext(taskId);
        } catch (error) {
            addLocal(error.message, 'error');
            toast(error.message, 'error', 5000);
        } finally {
            state.forceRun = false;
            state.busy = false;
            updateBusyUI();
        }
    }

    function updateBusyUI() {
        const startBtn = $('#startBtn');
        const startLabel = $('#startBtnLabel');
        const runOne = $('#runOneBtn');
        const viewingRunner = state.busy && Number(state.currentId) === Number(state.runnerId);
        if (startBtn) startBtn.disabled = false;
        if (runOne) runOne.disabled = state.busy;
        if (startLabel) {
            if (viewingRunner) {
                startLabel.textContent = '运行中…';
            } else {
                const task = taskById(state.currentId);
                const current = task ? task.status : '';
                startLabel.textContent = current === 'paused' ? '继续任务' : (current === 'failed' ? '重新开始' : '开始采集');
            }
        }
        const banner = $('#actionBanner');
        if (banner) banner.classList.toggle('is-running', viewingRunner);
        renderTaskList();
    }

    /* ------------------------------------------------------------------ */
    /* Action execution                                                   */
    /* ------------------------------------------------------------------ */
    async function executeAction(next) {
        const taskId = Number(next.task.id);
        const action = next.action;
        if (action === 'generate_keywords') {
            addLocal('AI 正在根据城市生成外贸关键词…', 'info');
            const data = await request('/api/tasks/' + taskId + '/keywords/generate', { method: 'POST', timeout: 240000 });
            const generated = Number(data.generated || 0);
            addLocal('关键词生成完成，新增 ' + generated + ' 个。', 'success');
            toast('已生成 ' + generated + ' 个关键词', 'success');
            return data;
        }
        if (action === 'fetch_serp' || action === 'search_serper') {
            if (state.config.searchProvider === 'browser') {
                return executeSerp(taskId, next.job || {});
            }
            if (state.config.searchProvider === 'server') {
                return executeSerperServer(taskId, next.job || {});
            }
            return executeSerper(taskId, next.job || {}, action);
        }
        if (action === 'classify_domains') {
            sendBackground('CLOSE_SERP_TAB', {}, 5000).catch(() => {});
            addLocal('正在调用 AI 判断域名企业属性…', 'info');
            const data = await request('/api/tasks/' + taskId + '/classify', { method: 'POST', timeout: 240000 });
            const processed = Number(data.processed || data.batch_size || 0);
            addLocal('本批域名筛选完成' + (processed ? '（' + processed + ' 个）' : '') + '。', 'success');
            return data;
        }
        if (action === 'crawl_website') {
            const job = next.job || {};
            addLocal('抓取官网：' + (job.domain || job.url), 'info');
            const page = await fetchPageWithFallback(job.url, job.domain || '');
            if (!page || !page.html) {
                const message = '网页抓取失败，未能取得 HTML：' + (job.domain || job.url);
                const fail = await reportWebsiteFail(taskId, Number(job.website_id), 'discover', message);
                await sleep(fail && fail.skipped ? 400 : 1800);
                return fail || { skipped: false, reason: 'empty_html' };
            }
            const data = await request('/api/tasks/' + taskId + '/websites/' + Number(job.website_id) + '/pages', {
                method: 'POST',
                timeout: 240000,
                body: { html: page.html, url: page.finalUrl || job.url }
            });
            const pages = Array.isArray(data.pages) ? data.pages.length : 0;
            addLocal('官网页面识别完成，找到 ' + pages + ' 个关键页面。', 'success');
            await sleep(Math.max(600, Number(job.delay_ms || 800)));
            return data;
        }
        if (action === 'extract_contacts') {
            const job = next.job || {};
            const pages = Array.isArray(job.pages) ? job.pages : [];
            addLocal('抓取 ' + (job.domain || '') + ' 的关键页面以提取联系方式…', 'info');
            const collected = [];
            for (const page of pages.slice(0, 4)) {
                if (!page || !page.url) continue;
                const fetched = await fetchPageWithFallback(page.url, '');
                if (fetched && fetched.html) {
                    collected.push({ type: page.type || 'page', url: fetched.finalUrl || page.url, html: fetched.html });
                }
                await sleep(280);
            }
            if (!collected.length) {
                const message = '关键页面均无法抓取：' + (job.domain || '');
                const fail = await reportWebsiteFail(taskId, Number(job.website_id), 'extract', message);
                await sleep(fail && fail.skipped ? 400 : 1800);
                return fail || { skipped: false, reason: 'empty_pages' };
            }
            const data = await request('/api/tasks/' + taskId + '/websites/' + Number(job.website_id) + '/extract', {
                method: 'POST',
                timeout: 240000,
                body: { pages: collected }
            });
            const company = data.company || {};
            addLocal('企业线索已入库：' + (company.company_name || job.domain || ''), 'success');
            toast('新增企业线索：' + (company.company_name || job.domain || ''), 'success');
            await sleep(350);
            return data;
        }
        if (action === 'normalize_phones') {
            const job = next.job || {};
            addLocal('AI 正在把电话整理成可直接拨打的格式，并判断手机/座机（待处理 ' + Number(job.pending_count || 0) + ' 家）…', 'info');
            const data = await request('/api/tasks/' + taskId + '/phones/normalize', { method: 'POST', timeout: 240000 });
            const done = Number(data.normalized || 0);
            const retry = Number(data.retry || 0);
            addLocal('本批电话整理完成：已标准化 ' + done + ' 家' +
                (Number(data.skipped || 0) ? '，无电话跳过 ' + Number(data.skipped) + ' 家' : '') +
                (Number(data.fallback || 0) ? '，规则兜底 ' + Number(data.fallback) + ' 家' : '') +
                (retry ? '，待重试 ' + retry + ' 家' : '') + '。', retry && !done ? 'warn' : 'success');
            await sleep(400);
            return data;
        }
        throw new ApiError('未知动作：' + action, 'unknown_action');
    }

    async function executeSerper(taskId, job, action = 'search_serper') {
        const keywordId = Number(job.keyword_id || 0);
        if (!keywordId) throw new ApiError('服务器没有提供关键词 ID。', 'missing_keyword_id');
        const query = String(job.keyword || '').trim();
        const page = Number(job.page || 1);
        const pagesTarget = job.auto_pages ? '∞' : Number(job.pages_target || 1);
        const liveInputKey = $('#serperKeyInput') && !$('#settingsModal')?.hidden ? $('#serperKeyInput').value.trim() : '';
        const apiKey = String(liveInputKey || state.config.serperApiKey || DEFAULT_CONFIG.serperApiKey || '').trim();

        if (apiKey) {
            addLocal('Serper.dev · 「' + query + '」第 ' + page + ' / ' + pagesTarget + ' 页', 'info');
            try {
                const serperRes = await sendBackground('FETCH_SERPER', {
                    query,
                    page,
                    apiKey,
                    gl: job.gl || state.config.serperGl || 'us',
                    hl: job.hl || state.config.serperHl || 'en',
                    endpoint: job.serper_endpoint || 'https://google.serper.dev/search'
                }, 35000);
                const items = serperRes && Array.isArray(serperRes.items) ? serperRes.items : [];
                if (items.length > 0) {
                    const data = await postSerp(taskId, job, {
                        results: items,
                        blocked: false,
                        error: '',
                        provider: 'serper'
                    });
                    if (Number(data.parsed || 0) > 0) {
                        addLocal('Serper 第 ' + page + ' / ' + pagesTarget + ' 页解析 ' + data.parsed + ' 条，新增 ' + (data.new_domains || 0) + ' 个域名' + (data.blocked ? '，禁止名单拦截 ' + data.blocked + ' 条' : '') + (data.known_skipped ? '，已采集过的合格域名跳过 ' + data.known_skipped + ' 条' : '') + '。', 'success');
                    }
                    if (serperRes.credits !== null && serperRes.credits !== undefined) {
                        addLocal('Serper 剩余额度：' + serperRes.credits, 'info');
                    }
                    await sleep(Math.max(250, Number(job.delay_ms || 300)));
                    return data;
                }
                if (action === 'search_serper' && page <= 1) {
                    return executeSerperServer(taskId, job);
                }
                const emptyData = await postSerp(taskId, job, {
                    results: [],
                    blocked: false,
                    error: page > 1 ? '' : 'Serper 未返回搜索结果',
                    provider: 'serper'
                });
                await sleep(Math.max(250, Number(job.delay_ms || 300)));
                return emptyData;
            } catch (error) {
                if (action === 'search_serper') {
                    addLocal('插件直连 Serper 受限，切换服务端 Serper 通道…', 'warn');
                    return executeSerperServer(taskId, job);
                }
                throw error;
            }
        }

        return executeSerperServer(taskId, job);
    }

    async function executeSerperServer(taskId, job) {
        const keywordId = Number(job.keyword_id || 0);
        if (!keywordId) throw new ApiError('服务器没有提供关键词 ID。', 'missing_keyword_id');
        const page = Number(job.page || 1);
        const pagesTarget = job.auto_pages ? '∞' : Number(job.pages_target || 1);
        const liveInputKey = $('#serperKeyInput') && !$('#settingsModal')?.hidden ? $('#serperKeyInput').value.trim() : '';
        const serperApiKey = String(liveInputKey || state.config.serperApiKey || DEFAULT_CONFIG.serperApiKey || '').trim();
        addLocal('Serper · 「' + (job.keyword || '') + '」第 ' + page + ' / ' + pagesTarget + ' 页', 'info');
        const data = await request('/api/tasks/' + taskId + '/serp/serper', {
            method: 'POST',
            timeout: 90000,
            body: {
                keyword_id: keywordId,
                page,
                serper_api_key: serperApiKey
            }
        });
        if (Number(data.parsed || 0) > 0) {
            addLocal('Serper 第 ' + page + ' / ' + pagesTarget + ' 页解析 ' + data.parsed + ' 条，新增 ' + (data.new_domains || 0) + ' 个域名' + (data.blocked ? '，禁止名单拦截 ' + data.blocked + ' 条' : '') + (data.known_skipped ? '，已采集过的合格域名跳过 ' + data.known_skipped + ' 条' : '') + '。', 'success');
        } else if (data.skipped) {
            addLocal('该搜索页连续失败，已跳过：' + (data.message || ''), 'warn');
            toast('Serper 搜索页被跳过：' + (data.message || ''), 'warn', 5000);
        } else if (data.message) {
            addLocal('Serper 搜索失败：' + data.message, 'warn');
        }
        if (data.credits !== null && data.credits !== undefined) {
            addLocal('Serper 剩余额度：' + data.credits, 'info');
        }
        await sleep(Math.max(250, Number(job.delay_ms || 300)));
        return data;
    }

    async function executeSerp(taskId, job) {
        if (!job.google_url) throw new ApiError('服务器没有提供 Google 搜索地址。', 'missing_google_url');
        addLocal('Google · 「' + (job.keyword || '') + '」第 ' + (job.page || 1) + (job.auto_pages ? ' 页（自动翻页）' : ' / ' + (job.pages_target || 1) + ' 页'), 'info');

        let result = null;
        let tab = null;
        try {
            tab = await sendBackground('FETCH_SERP_TAB', {
                url: job.google_url,
                page: Number(job.page || 1),
                pagesTarget: Number(job.pages_target || 1),
                keepTab: true
            }, 55000);
        } catch (error) {
            addLocal('标签页抓取失败：' + error.message, 'error');
        }

        if (tab && Array.isArray(tab.items) && tab.items.length > 0) {
            result = await postSerp(taskId, job, {
                results: tab.items,
                blocked: false,
                error: '',
                html: ''
            });
        } else {
            let fetched = null;
            if (!tab || (!tab.html && !tab.blocked)) {
                try {
                    fetched = await sendBackground('FETCH_SERP', { url: job.google_url }, 30000);
                } catch (error) {
                    addLocal('备用请求失败：' + error.message, 'warn');
                }
            }
            const html = (tab && tab.html) ? tab.html : (fetched && fetched.html ? fetched.html : '');
            const blocked = !!(tab && tab.blocked) || (!!fetched && (fetched.blocked || fetched.status === 429)) ||
                /unusual traffic|detected unusual traffic|captcha|before you continue/i.test(html.slice(0, 6000));
            result = await postSerp(taskId, job, {
                html,
                results: [],
                blocked,
                error: html ? '' : (tab ? (Number(job.page || 1) > 1 && !blocked ? '' : '标签页未解析到结果') : '无法打开 Google 标签页')
            });
        }

        if (result && Number(result.parsed || 0) > 0) {
            addLocal('第 ' + (job.page || 1) + ' 页解析 ' + result.parsed + ' 条结果，新增 ' + (result.new_domains || 0) + ' 个域名' + (result.blocked ? '，禁止名单拦截 ' + result.blocked + ' 条' : '') + (result.known_skipped ? '，已采集过的合格域名跳过 ' + result.known_skipped + ' 条' : '') + '。', 'success');
        } else if (result && result.skipped) {
            addLocal('该搜索页连续失败，已跳过：' + (result.message || ''), 'warn');
            toast('搜索页被跳过：' + (result.message || 'Google 验证'), 'warn', 5000);
        } else if (result && result.message) {
            addLocal('搜索页失败：' + result.message, 'warn');
        }
        await sleep(Math.max(900, Number(job.delay_ms || state.config.requestDelay || 2500)));
        return result;
    }

    function postSerp(taskId, job, payload) {
        return request('/api/tasks/' + taskId + '/serp', {
            method: 'POST',
            timeout: 90000,
            body: {
                keyword_id: Number(job.keyword_id),
                page: Number(job.page || 1),
                html: payload.html || '',
                results: payload.results || [],
                blocked: !!payload.blocked,
                error: payload.error || '',
                provider: payload.provider || 'google'
            }
        });
    }

    /* ------------------------------------------------------------------ */
    /* Rendering                                                          */
    /* ------------------------------------------------------------------ */
    function showEmptyWorkspace() {
        const empty = $('#workspaceEmpty');
        const workspace = $('#taskWorkspace');
        if (empty) empty.hidden = false;
        if (workspace) workspace.hidden = true;
    }

    function showTaskWorkspace() {
        const empty = $('#workspaceEmpty');
        const workspace = $('#taskWorkspace');
        if (empty) empty.hidden = true;
        if (workspace) workspace.hidden = false;
    }

    function renderTaskList() {
        const list = $('#taskList');
        const counter = $('#taskCount');
        if (!list) return;
        if (counter) counter.textContent = String(state.tasks.length);
        const filtered = state.tasks.filter((task) => {
            if (!state.search) return true;
            const haystack = [task.city, task.target_market].join(' ').toLowerCase();
            return haystack.includes(state.search.toLowerCase());
        });
        if (!filtered.length) {
            list.innerHTML = '<div class="rail-empty">' + (state.tasks.length ? '没有匹配的城市任务。' : '还没有任务。<br>点击右上角「新建城市任务」。') + '</div>';
            return;
        }
        list.innerHTML = filtered.map((task) => {
            const viewing = Number(task.id) === Number(state.currentId);
            const running = Number(task.id) === Number(state.runnerId) && state.busy;
            const progress = Math.max(0, Math.min(100, Number(task.progress || 0)));
            const stageText = running
                ? '队列执行中 · ' + progress + '%'
                : escapeHtml(STAGE_LABEL[task.stage] || task.stage || '等待启动') + ' · ' + progress + '%';
            return '<button class="task-item ' + (viewing ? 'active' : '') + (running ? ' is-running' : '') + '" data-task-id="' + Number(task.id) + '" type="button">' +
                '<span class="task-avatar">' + escapeHtml(String(task.city || '?').slice(0, 1)) + '</span>' +
                '<span class="task-item-body">' +
                    '<span class="task-item-top"><b>' + escapeHtml(task.city) + '</b>' +
                        (running ? '<em class="task-running-tag">执行中</em>' : '') +
                        '<i class="status-dot ' + escapeHtml(task.status) + '"></i></span>' +
                    '<small>' + stageText + '</small>' +
                    '<span class="task-mini-track"><i style="width:' + progress + '%"></i></span>' +
                '</span>' +
                '<span class="task-chevron">›</span>' +
            '</button>';
        }).join('');
    }

    function renderWorkspace(task, counts) {
        if (!task) return;
        const status = String(task.status || 'active');
        const progress = Math.max(0, Math.min(100, Number(task.progress || 0)));
        const badge = $('#taskStatusBadge');
        if (badge) {
            badge.className = 'status-badge ' + status;
            badge.textContent = STATUS_LABEL[status] || status;
        }
        setText('#taskId', '#' + task.id);
        setText('#taskCreated', '创建于 ' + formatDateTime(task.created_at, true));
        setText('#taskCity', task.city || '—');
        setText('#taskMeta', '目标市场：' + (task.target_market || '全球') + ' · ' + Number(task.keyword_count || 0) + ' 个关键词 · ' + (Number(task.pages_target || 0) > 0 ? '每个 ' + Number(task.pages_target) + ' 页' : '自动翻页至无结果') + ' Google · ' + (task.category_label ? '分类：' + task.category_label : '未选分类（仅按城市）'));
        setText('#progressPercent', progress + '%');
        setText('#progressHint', status === 'paused' ? '任务已暂停' : (status === 'completed' ? '全部完成' : (STAGE_LABEL[task.stage] || '处理中')));
        const fill = $('#progressFill');
        if (fill) fill.style.width = progress + '%';

        setText('#statKeywords', formatNumber(counts.keywords_total));
        setText('#statKeywordsSub', formatNumber(counts.keywords_done) + ' 个已完成');
        setText('#statDomains', formatNumber(counts.websites_total));
        setText('#statDomainsSub', formatNumber(counts.websites_classified) + ' 个已判断');
        setText('#statApproved', formatNumber(counts.websites_approved));
        setText('#statApprovedSub', formatNumber(counts.pages_done) + ' 个已抓官网');
        setText('#statCompanies', formatNumber(counts.companies));
        setText('#statCompaniesSub', formatNumber(counts.extract_errors) + ' 个提取异常');

        setText('#tabKeywordsCount', formatNumber(counts.keywords_total));
        setText('#tabDomainsCount', formatNumber(counts.websites_total));
        setText('#tabCompaniesCount', formatNumber(counts.companies));
        setText('#missionCity', task.city || '—');
        setText('#missionMarket', task.target_market || '全球');
        setText('#missionKeywords', Number(task.keyword_count || 0) + ' 个');
        setText('#missionPages', Number(task.pages_target || 0) > 0 ? Number(task.pages_target) + ' 页 / 词' : '自动翻页');
        setText('#missionCreated', formatDateTime(task.created_at, true));
        setText('#missionStatus', STATUS_LABEL[status] || status);
        renderStepper(task);

        const startBtn = $('#startBtn');
        const startLabel = $('#startBtnLabel');
        const pauseBtn = $('#pauseBtn');
        const retryBtn = $('#retryBtn');
        if (startBtn) {
            startBtn.hidden = status === 'completed';
            startBtn.disabled = false;
        }
        if (startLabel) {
            const viewingRunner = state.busy && Number(state.currentId) === Number(state.runnerId);
            if (viewingRunner) startLabel.textContent = '运行中…';
            else startLabel.textContent = status === 'paused' ? '继续任务' : (status === 'failed' ? '重新开始' : '开始采集');
        }
        if (pauseBtn) pauseBtn.hidden = status !== 'active';
        if (retryBtn) retryBtn.hidden = status === 'completed';
        renderTaskList();
    }

    function renderStepper(task) {
        const stepper = $('#stepper');
        if (!stepper) return;
        const currentIndex = STAGES.findIndex((stage) => stage.key === task.stage);
        const done = task.stage === 'done' || task.status === 'completed';
        stepper.innerHTML = STAGES.map((stage, index) => {
            let cls = 'upcoming';
            if (done || (currentIndex >= 0 && index < currentIndex)) cls = 'complete';
            else if (index === currentIndex) cls = task.status === 'paused' ? 'paused' : 'current';
            const titleAttr = stage.key !== 'done'
                ? ' title="点击回退到「' + stage.label + '」重新开始" data-stage="' + stage.key + '"'
                : ' data-stage="done"';
            return '<div class="stepper-step ' + cls + '"' + titleAttr + '>' +
                '<span class="stepper-dot">' + (cls === 'complete' ? '✓' : stage.no) + '</span>' +
                '<span class="stepper-copy"><b>' + stage.label + '</b><small>' + stage.full + '</small></span>' +
            '</div>';
        }).join('');
    }

    function renderNext(next) {
        if (!next) return;
        const action = next.action || 'done';
        const task = next.task || {};
        const counts = next.counts || {};
        const job = next.job || {};
        const label = ACTION_LABEL[action] || next.message || '等待下一步';
        const icon = ICONS[action] || ICONS.default;

        const viewingRunner = state.busy && Number(state.currentId) === Number(state.runnerId);
        const banner = $('#actionBanner');
        if (banner) {
            banner.className = 'action-banner' + (viewingRunner ? ' is-running' : '') +
                (action === 'done' || task.status === 'completed' ? ' is-done' : '') +
                (task.status === 'paused' || action === 'paused' ? ' is-paused' : '');
        }
        setText('#actionTitle', label);
        let message = next.message || '等待服务器下发下一步动作。';
        if (state.busy && state.runnerId && Number(state.currentId) !== Number(state.runnerId)) {
            const runner = taskById(state.runnerId);
            message = '当前为查看模式。队列正在执行 #' + state.runnerId +
                (runner && runner.city ? ' ' + runner.city : '') + '（按任务 ID 顺序）。';
        }
        setText('#actionMessage', message);
        const actionIcon = $('#currentActionIcon');
        if (actionIcon) actionIcon.innerHTML = icon;
        setText('#currentActionTitle', label);
        setText('#currentActionText', message);

        const meta = $('#actionMeta');
        if (meta) meta.innerHTML = buildActionMeta(action, job, counts);
        const actionCounts = $('#actionCounts');
        if (actionCounts) actionCounts.innerHTML = buildActionCounts(counts);

        const runBtn = $('#runOneBtn');
        if (runBtn) {
            runBtn.hidden = !actionable(next);
            runBtn.disabled = state.busy;
        }
        updateBusyUI();
    }

    function buildActionMeta(action, job, counts) {
        const chips = [];
        if (action === 'fetch_serp') {
            chips.push('关键词 ' + escapeHtml(job.keyword || ''));
            chips.push('第 ' + Number(job.page || 1) + (job.auto_pages ? ' 页（自动翻页）' : ' / ' + Number(job.pages_target || 0) + ' 页'));
        } else if (action === 'search_serper') {
            chips.push('关键词 ' + escapeHtml(job.keyword || ''));
            chips.push('第 ' + Number(job.page || 1) + (job.auto_pages ? ' 页（自动翻页）' : ' / ' + Number(job.pages_target || 0) + ' 页'));
            chips.push('Serper');
        } else if (action === 'classify_domains') {
            chips.push('待判断 ' + formatNumber(counts.pending_classify) + ' 个域名');
            chips.push('每批 ' + Number(job.batch_size || 30) + ' 个');
        } else if (action === 'crawl_website') {
            chips.push(escapeHtml(job.domain || ''));
            chips.push('第 ' + Number(job.attempt || 1) + ' 次尝试');
        } else if (action === 'extract_contacts') {
            chips.push(escapeHtml(job.domain || ''));
            chips.push((Array.isArray(job.pages) ? job.pages.length : 0) + ' 个页面');
        } else if (action === 'normalize_phones') {
            chips.push('待整理 ' + Number(job.pending_count || 0) + ' 家');
            chips.push('每批 ' + Number(job.batch_size || 0) + ' 家');
        } else if (action === 'generate_keywords') {
            chips.push('目标 ' + Number(job.count || 0) + ' 个词');
        }
        return chips.map((chip) => '<span class="meta-chip">' + chip + '</span>').join('');
    }

    function buildActionCounts(counts) {
        const items = [
            ['关键词', counts.keywords_total],
            ['域名', counts.websites_total],
            ['通过', counts.websites_approved],
            ['企业', counts.companies]
        ];
        return items.map(([label, value]) =>
            '<div class="count-cell"><b>' + formatNumber(value) + '</b><span>' + label + '</span></div>'
        ).join('');
    }

    function setText(selector, text) {
        const node = $(selector);
        if (node) node.textContent = text == null ? '—' : String(text);
    }

    function switchTab(name, load = true) {
        state.tab = name;
        $$('.tab-btn').forEach((button) => {
            button.classList.toggle('active', button.getAttribute('data-tab') === name);
        });
        const panels = {
            overview: $('#overviewPanel'),
            keywords: $('#keywordsPanel'),
            domains: $('#domainsPanel'),
            companies: $('#companiesPanel'),
            logs: $('#logsPanel')
        };
        Object.entries(panels).forEach(([key, panel]) => {
            if (panel) panel.hidden = key !== name;
        });
        if (load && name !== 'overview') loadTabData(name);
    }

    function invalidateData() {
        state.data = {};
    }

    async function loadTabData(name, force = false) {
        if (name === 'overview') return;
        const taskId = state.currentId;
        if (!taskId) return;
        const cache = state.data[name];
        if (!force && cache && Date.now() - cache.at < 5000) return;
        try {
            if (name === 'keywords') {
                const data = await request('/api/tasks/' + taskId + '/keywords?limit=500', { timeout: 20000 });
                if (Number(state.currentId) !== Number(taskId)) return;
                state.data.keywords = { at: Date.now(), rows: data.keywords || [] };
                renderKeywords();
            } else if (name === 'domains') {
                const data = await request('/api/tasks/' + taskId + '/websites?limit=2000', { timeout: 25000 });
                if (Number(state.currentId) !== Number(taskId)) return;
                state.data.domains = { at: Date.now(), rows: data.websites || [] };
                renderDomains();
            } else if (name === 'companies') {
                const data = await request('/api/tasks/' + taskId + '/companies?limit=2000', { timeout: 25000 });
                if (Number(state.currentId) !== Number(taskId)) return;
                state.data.companies = { at: Date.now(), rows: data.companies || [] };
                renderCompanies();
            } else if (name === 'logs') {
                const data = await request('/api/tasks/' + taskId + '/logs?limit=120', { timeout: 20000 });
                if (Number(state.currentId) !== Number(taskId)) return;
                state.data.logs = { at: Date.now(), rows: data.logs || [] };
                renderLogs();
            }
        } catch (error) {
            addLocal('读取' + (name === 'keywords' ? '关键词' : name === 'domains' ? '域名' : name === 'companies' ? '企业' : '日志') + '失败：' + error.message, 'error');
        }
    }

    function renderKeywords() {
        const rows = state.data.keywords ? state.data.keywords.rows : null;
        if (rows === null) return;
        const body = $('#keywordsTableBody');
        const empty = $('#keywordsEmpty');
        if (!body) return;
        body.innerHTML = rows.map((row) => {
            const done = row.status === 'done';
            const statusClass = done ? 'done' : (row.status === 'error' ? 'error' : 'pending');
            const statusLabel = done ? '已完成' : (row.status === 'error' ? '异常' : '待处理');
            return '<tr>' +
                '<td><span class="cell-keyword">' + escapeHtml(row.keyword) + '</span></td>' +
                '<td><span class="intent-tag">' + escapeHtml(row.intent || 'general') + '</span></td>' +
                '<td><span class="mono">' + Number(row.pages_done || 0) + ' / ' + Number(row.pages_target || 0) + '</span></td>' +
                '<td><span class="mono">' + formatNumber(row.results_count) + '</span></td>' +
                '<td><span class="mini-badge ' + statusClass + '">' + statusLabel + '</span></td>' +
            '</tr>';
        }).join('');
        if (empty) empty.hidden = rows.length > 0;
    }

    function renderDomains() {
        const cache = state.data.domains;
        if (!cache) return;
        const rows = cache.rows || [];
        const filter = state.domainFilter;
        const filtered = rows.filter((row) => filter === 'all' || row.classify_status === filter);
        const body = $('#domainsTableBody');
        const empty = $('#domainsEmpty');
        const note = $('#domainFilterNote');
        if (note) note.textContent = '显示 ' + filtered.length + ' / ' + rows.length + ' 个域名';
        if (body) {
            body.innerHTML = filtered.map((row) => {
                const status = row.classify_status || 'pending';
                const statusLabel = { approved: '通过', rejected: '排除', pending: '待筛', error: '异常' }[status] || status;
                const pages = [];
                if (row.page_status === 'done') {
                    if (row.home_url) pages.push('<a href="' + safeHref(row.home_url) + '" target="_blank" rel="noopener noreferrer">首页</a>');
                    if (row.about_url) pages.push('<a href="' + safeHref(row.about_url) + '" target="_blank" rel="noopener noreferrer">关于</a>');
                    if (row.contact_url) pages.push('<a href="' + safeHref(row.contact_url) + '" target="_blank" rel="noopener noreferrer">联系</a>');
                }
                const pageCell = pages.length ? '<div class="page-links-mini">' + pages.join('') + '</div>' :
                    '<span class="mini-badge ' + escapeHtml(row.page_status || 'pending') + '">' + escapeHtml(pageStatusLabel(row.page_status)) + '</span>';
                const extract = row.extract_status === 'done' ? '<span class="mini-badge done">已提取</span>' :
                    '<span class="mini-badge ' + escapeHtml(row.extract_status || 'pending') + '">' + escapeHtml(extractStatusLabel(row.extract_status)) + '</span>';
                return '<tr>' +
                    '<td><div class="domain-cell"><a class="domain-name" href="' + safeHref(row.root_url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(row.domain) + '</a>' +
                        (row.title ? '<small>' + escapeHtml(row.title) + '</small>' : '') + '</div></td>' +
                    '<td><span class="mini-badge ' + escapeHtml(status) + '">' + escapeHtml(statusLabel) + '</span>' +
                        (row.ai_confidence != null ? '<small class="confidence">' + Math.round(Number(row.ai_confidence) * 100) + '%</small>' : '') + '</td>' +
                    '<td>' + pageCell + '</td>' +
                    '<td>' + extract + '</td>' +
                    '<td><span class="reason-cell">' + escapeHtml(row.ai_reason || row.last_error || '—') + '</span></td>' +
                '</tr>';
            }).join('');
        }
        if (empty) empty.hidden = filtered.length > 0;
    }

    function pageStatusLabel(status) {
        return { done: '已识别', pending: '待抓取', error: '异常', running: '处理中' }[status] || status || '待抓取';
    }

    function extractStatusLabel(status) {
        return { done: '已提取', pending: '待提取', error: '异常' }[status] || status || '待提取';
    }

    async function enqueueDial(row, phone, button) {
        if (!phone) {
            toast('没有可拨打的号码。', 'error');
            return;
        }
        if (button) {
            button.disabled = true;
            button.textContent = '推送中…';
        }
        try {
            const task = taskById(state.currentId);
            const data = await request('/api/dial/enqueue', {
                method: 'POST',
                timeout: 20000,
                body: {
                    company_id: Number(row?.id || 0) || undefined,
                    task_id: Number(row?.task_id || state.currentId || 0) || undefined,
                    phone,
                    company_name: row?.company_name || '',
                    city: row?.city || task?.city || '',
                    domain: row?.domain || '',
                    email: row?.email || '',
                    address: row?.address || '',
                    source_url: row?.source_url || ''
                }
            });
            const duplicated = !!(data && data.duplicated);
            toast(duplicated ? '该号码已在拨号队列中' : '已推送到手机拨号队列', duplicated ? 'info' : 'success');
            addLocal((duplicated ? '拨号队列已有：' : '已入拨号队列：') + phone + (row?.company_name ? ' · ' + row.company_name : ''), 'success');
        } catch (error) {
            toast(error.message || '推送拨号失败', 'error', 5000);
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = '拨号';
            }
        }
    }

    function renderCompanies() {
        const cache = state.data.companies;
        if (!cache) return;
        const rows = cache.rows || [];
        const body = $('#companiesTableBody');
        const empty = $('#companiesEmpty');
        const label = $('#companyCountLabel');
        if (label) label.textContent = rows.length + ' 家企业';
        if (body) {
            body.innerHTML = rows.map((row) => {
                const social = (key, text) => row[key] ?
                    '<a class="social-pill" href="' + safeHref(row[key]) + '" target="_blank" rel="noopener noreferrer">' + text + '</a>' :
                    '<span class="muted">—</span>';
                return '<tr data-company-id="' + Number(row.id || 0) + '">' +
                    '<td><div class="company-cell"><b>' + escapeHtml(row.company_name || row.domain) + '</b>' +
                        '<a href="' + safeHref('https://' + String(row.domain || '')) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(row.domain) + '</a>' +
                        (row.city ? '<small>' + escapeHtml(row.city) + '</small>' : '') + '</div></td>' +
                    '<td>' + (row.phone
                        ? '<div class="phone-actions">' +
                            '<span class="phone-cell">' + escapeHtml(row.phone) + '</span>' +
                            '<button type="button" class="btn ghost small dial-btn" data-dial-company="' + Number(row.id || 0) + '" data-dial-phone="' + escapeHtml(row.phone) + '" title="推送到手机拨号队列">拨号</button>' +
                          '</div>'
                        : '<span class="muted">—</span>') +
                        (row.phone_alt ? '<small class="sub-cell">' + escapeHtml(row.phone_alt) + '</small>' : '') + '</td>' +
                    '<td>' + (row.email ? '<a class="email-cell" href="mailto:' + escapeHtml(row.email) + '">' + escapeHtml(row.email) + '</a>' : '<span class="muted">—</span>') + '</td>' +
                    '<td>' + social('linkedin', 'in') + '</td>' +
                    '<td>' + social('tiktok', 'tk') + '</td>' +
                    '<td>' + social('youtube', 'yt') + '</td>' +
                    '<td>' + (row.source_url ? '<a class="source-link" href="' + safeHref(row.source_url) + '" target="_blank" rel="noopener noreferrer">页面 ↗</a>' : '<span class="muted">—</span>') + '</td>' +
                '</tr>';
            }).join('');
        }
        if (empty) empty.hidden = rows.length > 0;
    }

    function renderLogs() {
        const cache = state.data.logs;
        if (!cache) return;
        const rows = cache.rows || [];
        const host = $('#serverLogs');
        const empty = $('#logsEmpty');
        if (host) {
            host.innerHTML = rows.map((row) => {
                const stage = STAGE_LABEL[row.stage] || row.stage || '系统';
                return '<div class="server-log-line level-' + escapeHtml(row.level || 'info') + '">' +
                    '<time>' + formatDateTime(row.created_at, true) + '</time>' +
                    '<span class="log-stage">' + escapeHtml(stage) + '</span>' +
                    '<p>' + escapeHtml(row.message) + '</p>' +
                '</div>';
            }).join('');
        }
        if (empty) empty.hidden = rows.length > 0;
    }

    function addLocal(message, level = 'info') {
        state.localLogs.unshift({ time: new Date(), level, message: String(message || '') });
        if (state.localLogs.length > 60) state.localLogs.length = 60;
        renderLocalConsole();
    }

    function renderLocalConsole() {
        const host = $('#localConsole');
        if (!host) return;
        if (!state.localLogs.length) {
            host.innerHTML = '<div class="console-empty">等待启动任务…</div>';
            return;
        }
        host.innerHTML = state.localLogs.map((entry) => {
            const time = entry.time.toLocaleTimeString('zh-CN', { hour12: false });
            return '<div class="console-line level-' + escapeHtml(entry.level) + '">' +
                '<time>' + time + '</time>' +
                '<span class="console-dot"></span>' +
                '<p>' + escapeHtml(entry.message) + '</p>' +
            '</div>';
        }).join('');
    }

    /* ------------------------------------------------------------------ */
    /* Toasts & modals                                                    */
    /* ------------------------------------------------------------------ */
    function toast(message, type = 'success', duration = 3600) {
        const host = $('#toastHost');
        if (!host) return;
        const node = document.createElement('div');
        node.className = 'toast toast-' + type;
        const icon = type === 'success' ? '✓' : (type === 'error' ? '!' : (type === 'warn' ? '!' : 'i'));
        node.innerHTML = '<span class="toast-icon">' + icon + '</span><span>' + escapeHtml(message) + '</span>';
        host.appendChild(node);
        requestAnimationFrame(() => node.classList.add('show'));
        setTimeout(() => {
            node.classList.remove('show');
            setTimeout(() => node.remove(), 260);
        }, duration);
    }

    function openModal(selector) {
        const modal = $(selector);
        if (modal) modal.hidden = false;
        document.body.classList.add('modal-open');
    }

    function closeModal(selector) {
        const modal = $(selector);
        if (modal) modal.hidden = true;
        if (!$$('.modal-backdrop').some((node) => !node.hidden)) document.body.classList.remove('modal-open');
    }

    function openSettings() {
        $('#apiBaseInput').value = state.config.apiBase;
        $('#apiKeyInput').value = state.config.apiKey;
        $('#autoRunInput').checked = !!state.config.autoRun;
        $('#delayInput').value = String(state.config.requestDelay || 2500);
        if ($('#searchProviderInput')) {
            $('#searchProviderInput').value = state.config.searchProvider || 'serper';
        }
        if ($('#serperKeyInput')) {
            $('#serperKeyInput').value = state.config.serperApiKey || DEFAULT_CONFIG.serperApiKey;
            $('#serperKeyInput').type = 'password';
        }
        if ($('#toggleSerperKey')) {
            $('#toggleSerperKey').textContent = '显示';
        }
        if ($('#aiBaseUrlInput')) {
            $('#aiBaseUrlInput').value = state.config.aiBaseUrl || '';
        }
        if ($('#aiModelInput')) {
            $('#aiModelInput').value = state.config.aiModel || 'gpt-4o-mini';
        }
        if ($('#aiApiKeyInput')) {
            $('#aiApiKeyInput').value = state.config.aiApiKey || '';
            $('#aiApiKeyInput').type = 'password';
        }
        if ($('#toggleAiKey')) {
            $('#toggleAiKey').textContent = '显示';
        }
        $('#apiKeyInput').type = 'password';
        $('#toggleApiKey').textContent = '显示';
        const result = $('#connectionResult');
        if (result) {
            result.hidden = true;
            result.className = 'connection-result';
            result.textContent = '';
        }
        openModal('#settingsModal');
        loadRemoteSettingsIntoModal();
        setTimeout(() => $('#apiBaseInput').focus(), 80);
    }

    async function loadRemoteSettingsIntoModal() {
        if (!state.config.apiBase || !state.config.apiKey) return;
        try {
            const data = await request('/api/settings', { timeout: 10000 });
            if (data && data.ai_relay) {
                const relay = data.ai_relay;
                const urlInput = $('#aiBaseUrlInput');
                const modelInput = $('#aiModelInput');
                const keyInput = $('#aiApiKeyInput');
                const statusEl = $('#aiRelayStatus');
                if (urlInput && !urlInput.value && relay.base_url) {
                    urlInput.value = relay.base_url;
                    state.config.aiBaseUrl = relay.base_url;
                }
                if (modelInput && (!modelInput.value || modelInput.value === 'gpt-4o-mini') && relay.model) {
                    modelInput.value = relay.model;
                    state.config.aiModel = relay.model;
                }
                if (keyInput && !keyInput.value && relay.masked_key) {
                    keyInput.placeholder = '当前已配置：' + relay.masked_key + '（留空保持不变）';
                }
                if (statusEl && relay.has_key) {
                    statusEl.textContent = '已配置 · ' + (relay.model || 'AI');
                    statusEl.style.color = '#2dd4a3';
                }
                await persistConfig();
            }
        } catch (error) {
            // Ignore if server is not yet connected.
        }
    }

    async function saveSettings() {
        const base = normalizeBase($('#apiBaseInput').value);
        const key = $('#apiKeyInput').value.trim();
        const searchProvider = $('#searchProviderInput') ? $('#searchProviderInput').value : 'serper';
        const serperKey = $('#serperKeyInput') ? $('#serperKeyInput').value.trim() : DEFAULT_CONFIG.serperApiKey;
        const aiBaseUrl = $('#aiBaseUrlInput') ? $('#aiBaseUrlInput').value.trim().replace(/\/+$/, '') : '';
        const aiModel = $('#aiModelInput') ? $('#aiModelInput').value.trim() : 'gpt-4o-mini';
        const aiApiKey = $('#aiApiKeyInput') ? $('#aiApiKeyInput').value.trim() : '';
        if (!base) {
            toast('请填写 API 地址。', 'error');
            return;
        }
        if (!/^https?:\/\//i.test(base)) {
            toast('API 地址必须以 http:// 或 https:// 开头。', 'error');
            return;
        }
        if (!key) {
            toast('请填写 API Key。', 'error');
            return;
        }
        if (searchProvider === 'serper' && !serperKey) {
            toast('启用 Serper.dev 搜索时请填写 Serper API Key。', 'error');
            return;
        }
        if (aiBaseUrl && !/^https?:\/\//i.test(aiBaseUrl)) {
            toast('AI 中转站 URL 必须以 http:// 或 https:// 开头。', 'error');
            return;
        }
        state.config.apiBase = base;
        state.config.apiKey = key;
        state.config.autoRun = $('#autoRunInput').checked;
        state.config.requestDelay = Math.max(300, Math.min(15000, Number($('#delayInput').value || 2500)));
        state.config.searchProvider = ['serper', 'server', 'browser'].includes(searchProvider) ? searchProvider : 'serper';
        state.config.serperApiKey = serperKey || DEFAULT_CONFIG.serperApiKey;
        if (aiBaseUrl) state.config.aiBaseUrl = aiBaseUrl;
        if (aiModel) state.config.aiModel = aiModel;
        if (aiApiKey) state.config.aiApiKey = aiApiKey;
        await persistConfig();
        await syncSerperSettingsToServer(state.config.serperApiKey, state.config.searchProvider, {
            aiBaseUrl: state.config.aiBaseUrl,
            aiModel: state.config.aiModel || 'gpt-4o-mini',
            aiApiKey
        });
        closeModal('#settingsModal');
        setConnection('checking', '检查中');
        const ok = await checkConnection(false);
        if (ok) {
            toast('已连接服务器，正在同步任务。', 'success');
            state.currentId = null;
            await loadTasks();
        } else {
            toast('配置已保存，但连接检查失败，请检查地址与 Key。', 'error', 5000);
            showEmptyWorkspace();
        }
    }

    let serperSyncTimer = null;

    async function syncSerperSettingsToServer(serperApiKey, searchProvider, aiOptions = {}) {
        if (!state.config.apiBase || !state.config.apiKey) return;
        try {
            const payload = {
                serper_api_key: serperApiKey || '',
                search_provider: searchProvider === 'browser' ? 'browser' : 'serper'
            };
            if (aiOptions.aiBaseUrl) payload.ai_base_url = aiOptions.aiBaseUrl;
            if (aiOptions.aiModel) payload.ai_model = aiOptions.aiModel;
            if (aiOptions.aiApiKey) payload.ai_api_key = aiOptions.aiApiKey;
            const res = await request('/api/settings', {
                method: 'POST',
                timeout: 15000,
                body: payload
            });
            const aiStatus = $('#aiRelayStatus');
            if (aiStatus && res && res.ai_relay && res.ai_relay.has_key) {
                aiStatus.textContent = '已同步 · ' + (res.ai_relay.model || 'AI');
                aiStatus.style.color = '#2dd4a3';
            }
        } catch (error) {
            // Ignore background sync errors if server is temporarily unreachable.
        }
    }

    async function applyRealtimeSerperConfig(notifyToast = false) {
        const providerInput = $('#searchProviderInput');
        const keyInput = $('#serperKeyInput');
        const statusEl = $('#serperKeyStatus');
        const aiUrlInput = $('#aiBaseUrlInput');
        const aiModelInput = $('#aiModelInput');
        const aiKeyInput = $('#aiApiKeyInput');
        const nextProvider = providerInput && ['serper', 'server', 'browser'].includes(providerInput.value)
            ? providerInput.value
            : state.config.searchProvider;
        const nextKey = keyInput ? keyInput.value.trim() : state.config.serperApiKey;
        const nextAiUrl = aiUrlInput ? aiUrlInput.value.trim().replace(/\/+$/, '') : state.config.aiBaseUrl;
        const nextAiModel = aiModelInput ? aiModelInput.value.trim() : state.config.aiModel;
        const nextAiKey = aiKeyInput ? aiKeyInput.value.trim() : '';

        state.config.searchProvider = nextProvider;
        if (nextKey) {
            state.config.serperApiKey = nextKey;
        }
        if (nextAiUrl) state.config.aiBaseUrl = nextAiUrl;
        if (nextAiModel) state.config.aiModel = nextAiModel;
        if (nextAiKey) state.config.aiApiKey = nextAiKey;
        await persistConfig();

        if (statusEl) {
            statusEl.textContent = '已实时保存';
            statusEl.style.color = '#2dd4a3';
        }
        if (serperSyncTimer) clearTimeout(serperSyncTimer);
        serperSyncTimer = setTimeout(() => {
            syncSerperSettingsToServer(state.config.serperApiKey, state.config.searchProvider, {
                aiBaseUrl: state.config.aiBaseUrl,
                aiModel: state.config.aiModel || 'gpt-4o-mini',
                aiApiKey: nextAiKey || state.config.aiApiKey
            });
        }, 400);

        if (notifyToast && nextKey) {
            toast('配置已实时更新并同步至服务器', 'success', 2400);
        }
    }

    async function testSerperKey() {
        const button = $('#testSerperBtn');
        const result = $('#connectionResult');
        const keyInput = $('#serperKeyInput');
        const statusEl = $('#serperKeyStatus');
        const apiKey = keyInput ? keyInput.value.trim() : state.config.serperApiKey;
        if (!apiKey) {
            toast('请先填写要验证的 Serper.dev API Key。', 'error');
            keyInput?.focus();
            return;
        }
        await applyRealtimeSerperConfig(false);
        if (button) {
            button.disabled = true;
            button.textContent = '验证中…';
        }
        if (result) {
            result.hidden = false;
            result.className = 'connection-result checking';
            result.textContent = '正在验证 Serper.dev API Key…';
        }
        try {
            const data = await sendBackground('FETCH_SERPER', {
                query: 'google',
                page: 1,
                apiKey,
                gl: state.config.serperGl || 'us',
                hl: state.config.serperHl || 'en',
                endpoint: 'https://google.serper.dev/search'
            }, 25000);
            const count = Array.isArray(data.items) ? data.items.length : 0;
            const creditsText = data.credits !== null && data.credits !== undefined ? ' · 剩余额度 ' + data.credits : '';
            if (statusEl) {
                statusEl.textContent = 'Key 有效' + creditsText;
                statusEl.style.color = '#2dd4a3';
            }
            if (result) {
                result.className = 'connection-result ok';
                result.innerHTML = '<b>Serper.dev Key 验证通过且已实时生效</b><span>测试返回 ' + count + ' 条结果' + escapeHtml(creditsText) + '</span>';
            }
            toast('Serper Key 验证成功' + creditsText, 'success');
        } catch (error) {
            if (statusEl) {
                statusEl.textContent = 'Key 验证失败';
                statusEl.style.color = '#fb7185';
            }
            if (result) {
                result.className = 'connection-result error';
                result.innerHTML = '<b>Serper.dev Key 验证失败</b><span>' + escapeHtml(error.message || '请求失败') + '</span>';
            }
            toast(error.message || 'Serper Key 验证失败', 'error', 4500);
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = '验证 Key';
            }
        }
    }

    async function testConnection() {
        const button = $('#testConnectionBtn');
        const result = $('#connectionResult');
        const base = normalizeBase($('#apiBaseInput').value);
        const key = $('#apiKeyInput').value.trim();
        if (!base) {
            toast('请先填写 API 地址。', 'error');
            return;
        }
        button.disabled = true;
        button.textContent = '测试中…';
        if (result) {
            result.hidden = false;
            result.className = 'connection-result checking';
            result.textContent = '正在连接服务器…';
        }
        try {
            const data = await probeConnection(base, key);
            if (result) {
                result.className = 'connection-result ok';
                result.innerHTML = '<b>连接成功</b><span>PHP ' + escapeHtml(data.php || '?') + ' · MySQL ' + escapeHtml(data.mysql || '?') + '</span>';
            }
        } catch (error) {
            if (result) {
                result.className = 'connection-result error';
                result.innerHTML = '<b>连接失败</b><span>' + escapeHtml(error.message) + '</span>';
            }
        } finally {
            button.disabled = false;
            button.textContent = '测试连接';
        }
    }

    /* ------------------------------------------------------------------ */
    /* Product category picker                                            */
    /* ------------------------------------------------------------------ */
    async function loadCategoryTree() {
        if (state.categoryTree) return state.categoryTree;
        const data = await request('/api/categories', { timeout: 20000 });
        state.categoryTree = Array.isArray(data.groups) ? data.groups : [];
        state.categoryMax = Number(data.max_selected || 12);
        return state.categoryTree;
    }

    function categoryLookup() {
        const map = {};
        (state.categoryTree || []).forEach((group) => {
            group.items.forEach((item) => {
                map[item.code] = Object.assign({ group_zh: group.zh, group_en: group.en }, item);
            });
        });
        return map;
    }

    function categorySummary(codes) {
        if (!codes || !codes.length) return '不选分类，仅按城市生成';
        const lookup = categoryLookup();
        const names = codes.map((code) => (lookup[code] ? lookup[code].zh : code));
        const shown = names.slice(0, 3).join('、');
        return '已选 ' + codes.length + ' 类：' + shown + (names.length > 3 ? ' 等' : '');
    }

    function refreshCategorySummary(target) {
        const codes = target === 'edit' ? state.editCategories : state.newCategories;
        const summary = $(target === 'edit' ? '#editCategorySummary' : '#newCategorySummary');
        const button = $(target === 'edit' ? '#editCategoryBtn' : '#newCategoryBtn');
        if (summary) summary.textContent = categorySummary(codes);
        if (button) button.classList.toggle('has-value', !!(codes && codes.length));
    }

    async function openCategoryPicker(target) {
        state.categoryTarget = target;
        try {
            await loadCategoryTree();
        } catch (error) {
            toast('分类库加载失败：' + error.message, 'error', 5000);
            return;
        }
        state.categoryDraft = (target === 'edit' ? state.editCategories : state.newCategories).slice();
        if ($('#categorySearch')) $('#categorySearch').value = '';
        const stats = $('#categoryStats');
        if (stats) {
            const total = state.categoryTree.reduce((sum, group) => sum + group.items.length, 0);
            stats.textContent = '共 ' + state.categoryTree.length + ' 个大类、' + total + ' 个细分类，最多可选 ' + state.categoryMax + ' 个。';
        }
        renderCategoryPicker();
        openModal('#categoryModal');
    }

    function renderCategoryPicker() {
        const body = $('#categoryBody');
        if (!body) return;
        const keyword = String($('#categorySearch') ? $('#categorySearch').value : '').trim().toLowerCase();
        const draft = new Set(state.categoryDraft);
        let html = '';
        (state.categoryTree || []).forEach((group) => {
            const groupMatch = !keyword || group.zh.toLowerCase().includes(keyword) || group.en.toLowerCase().includes(keyword);
            const items = group.items.filter((item) => groupMatch || item.zh.toLowerCase().includes(keyword) || item.en.toLowerCase().includes(keyword));
            if (!items.length) return;
            const selectedCount = group.items.filter((item) => draft.has(item.code)).length;
            html += '<section class="category-group" data-group="' + escapeHtml(group.code) + '">' +
                '<div class="category-group-head">' +
                    '<div><strong>' + escapeHtml(group.zh) + '</strong><small>' + escapeHtml(group.en) + '</small></div>' +
                    '<div class="group-meta"><span>' + selectedCount + ' / ' + group.items.length + '</span>' +
                    '<button type="button" class="group-all" data-group-all="' + escapeHtml(group.code) + '">' + (selectedCount === group.items.length ? '取消全选' : '全选') + '</button></div>' +
                '</div>' +
                '<div class="category-items">' +
                items.map((item) => '<button type="button" class="category-chip' + (draft.has(item.code) ? ' selected' : '') + '" data-code="' + escapeHtml(item.code) + '">' +
                    escapeHtml(item.zh) + '<small>' + escapeHtml(item.en) + '</small></button>').join('') +
                '</div></section>';
        });
        body.innerHTML = html || '<div class="category-empty">没有匹配的分类</div>';
        const count = $('#categoryCount');
        if (count) count.textContent = '已选 ' + state.categoryDraft.length + ' / ' + state.categoryMax;
    }

    function toggleCategory(code) {
        const index = state.categoryDraft.indexOf(code);
        if (index >= 0) {
            state.categoryDraft.splice(index, 1);
        } else {
            if (state.categoryDraft.length >= state.categoryMax) {
                toast('最多选择 ' + state.categoryMax + ' 个细分类。', 'warn');
                return;
            }
            state.categoryDraft.push(code);
        }
        renderCategoryPicker();
    }

    function toggleCategoryGroup(groupCode) {
        const group = (state.categoryTree || []).find((node) => node.code === groupCode);
        if (!group) return;
        const codes = group.items.map((item) => item.code);
        const allSelected = codes.every((code) => state.categoryDraft.includes(code));
        if (allSelected) {
            state.categoryDraft = state.categoryDraft.filter((code) => !codes.includes(code));
        } else {
            const merged = state.categoryDraft.slice();
            codes.forEach((code) => { if (!merged.includes(code)) merged.push(code); });
            if (merged.length > state.categoryMax) {
                toast('选择数量超过上限 ' + state.categoryMax + '，请改为勾选具体细分类。', 'warn');
                return;
            }
            state.categoryDraft = merged;
        }
        renderCategoryPicker();
    }

    function confirmCategoryPicker() {
        if (state.categoryTarget === 'edit') {
            state.editCategories = state.categoryDraft.slice();
        } else {
            state.newCategories = state.categoryDraft.slice();
        }
        refreshCategorySummary(state.categoryTarget);
        closeModal('#categoryModal');
    }

    function bindCategoryPicker() {
        $('#newCategoryBtn')?.addEventListener('click', () => openCategoryPicker('new'));
        $('#editCategoryBtn')?.addEventListener('click', () => openCategoryPicker('edit'));
        $('#categoryClose')?.addEventListener('click', () => closeModal('#categoryModal'));
        $('#categoryCancel')?.addEventListener('click', () => closeModal('#categoryModal'));
        $('#categoryConfirm')?.addEventListener('click', confirmCategoryPicker);
        $('#categoryClear')?.addEventListener('click', () => {
            state.categoryDraft = [];
            renderCategoryPicker();
        });
        $('#categorySearch')?.addEventListener('input', renderCategoryPicker);
        $('#categoryBody')?.addEventListener('click', (event) => {
            const all = event.target.closest('[data-group-all]');
            if (all) {
                toggleCategoryGroup(all.getAttribute('data-group-all'));
                return;
            }
            const chip = event.target.closest('[data-code]');
            if (chip) toggleCategory(chip.getAttribute('data-code'));
        });
    }

    function openNewTask() {
        if (!state.config.apiBase || !state.config.apiKey) {
            toast('请先连接服务器。', 'warn');
            openSettings();
            return;
        }
        state.newCategories = [];
        refreshCategorySummary('new');
        $('#newTaskForm').reset();
        $('#keywordCountInput').value = '24';
        $('#pagesTargetInput').value = '0';
        $('#marketInput').value = '全球';
        $$('.preset-buttons button').forEach((button) => {
            button.classList.toggle('active', button.getAttribute('data-preset') === 'standard');
        });
        openModal('#newTaskModal');
        setTimeout(() => $('#cityInput').focus(), 80);
    }

    async function createTask() {
        const button = $('#newTaskSubmit');
        const city = $('#cityInput').value.trim();
        if (!city) {
            toast('请输入城市名称。', 'error');
            $('#cityInput').focus();
            return;
        }
        const keywordCount = Math.max(6, Math.min(60, Number($('#keywordCountInput').value || 24)));
        const pagesRaw = String($('#pagesTargetInput').value || '').trim();
        const pagesTarget = pagesRaw === '' ? 0 : Math.max(0, Math.min(30, Number(pagesRaw) || 0));
        button.disabled = true;
        button.textContent = '创建中…';
        try {
            const data = await request('/api/tasks', {
                method: 'POST',
                timeout: 40000,
                body: {
                    city,
                    target_market: $('#marketInput').value || '全球',
                    keyword_count: keywordCount,
                    pages_target: pagesTarget,
                    categories: state.newCategories.slice(),
                    language: 'en'
                }
            });
            closeModal('#newTaskModal');
            toast('任务已创建，将按 ID 顺序加入队列。', 'success');
            const task = data.task;
            state.pausedByUser = false;
            if (task) {
                mergeTask(task, data.counts || {});
            }
            renderTaskList();
            await loadTasks({ kick: false });
            if (task) await selectTask(Number(task.id));
            // Do not jump the runner to the new task — keep ascending id order.
            ensureRunner();
        } catch (error) {
            toast(error.message, 'error', 5000);
        } finally {
            button.disabled = false;
            button.textContent = '创建并开始';
        }
    }

    /* ------------------------------------------------------------------ */
    /* Task actions                                                       */
    /* ------------------------------------------------------------------ */
    async function startTask() {
        const task = taskById(state.currentId);
        if (!task) return;
        if (task.status === 'completed') {
            toast('该任务已完成。', 'info');
            return;
        }
        try {
            if (task.status === 'paused') {
                await request('/api/tasks/' + task.id + '/resume', { method: 'POST' });
                addLocal('任务 #' + task.id + ' 已恢复，将按 ID 顺序排队执行。', 'success');
            } else if (task.status === 'failed') {
                await request('/api/tasks/' + task.id + '/retry', { method: 'POST' });
                addLocal('任务 #' + task.id + ' 已重置异常并重新排队。', 'success');
            }
            state.pausedByUser = false;
            await loadTasksSilent();
            await loadNext(task.id);
            // Resume only puts it back in the id-ordered queue; do not steal the runner.
            ensureRunner();
            toast('已加入队列（按任务 ID 顺序执行）', 'success');
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function pauseTask() {
        const task = taskById(state.currentId);
        if (!task) return;
        try {
            await request('/api/tasks/' + task.id + '/pause', { method: 'POST' });
            const wasRunning = Number(task.id) === Number(state.runnerId);
            if (wasRunning) {
                state.token += 1;
                state.forceRun = false;
                state.runnerId = null;
            }
            addLocal('任务 #' + task.id + ' 已暂停。', 'warn');
            toast('任务已暂停，关闭窗口不会丢失进度。', 'warn');
            await loadTasksSilent();
            await loadNext(task.id);
            if (wasRunning) {
                // Continue the remaining queue by id; pausing one task must not stop others.
                setTimeout(() => ensureRunner(), 400);
            }
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function retryTask() {
        const task = taskById(state.currentId);
        if (!task) return;
        try {
            await request('/api/tasks/' + task.id + '/retry', { method: 'POST' });
            state.pausedByUser = false;
            addLocal('任务 #' + task.id + ' 异常项已重置，按队列顺序继续。', 'success');
            toast('已重置异常项。', 'success');
            await loadTasksSilent();
            await loadNext(task.id);
            ensureRunner();
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    const REWIND_STAGE_HINTS = {
        keywords: '将清空本任务已生成的关键词、Google 搜索结果、域名与企业名录，从第 01 步重新调用 AI 生成关键词。',
        serp: '保留当前已生成的关键词，清空已抓域名与企业数据，从第 02 步重新抓取 Google 搜索结果。',
        classify: '保留当前所有已抓取的去重域名，清空历史 AI 筛选结论与企业数据，从第 03 步重新调用 AI 判断企业官网。',
        discover: '保留已通过 AI 筛选的企业域名，清空官网页面识别结果与企业联系方式，从第 04 步重新识别 About / Contact 页面。',
        extract: '保留已识别完成的官网关键页面，清空已提取的企业名录，从第 05 步重新提取电话、邮箱与社媒。',
        phone: '保留已提取的企业联系方式，只重新让 AI 整理电话：统一成可直接拨打的国际格式，并重新判断手机/座机。'
    };

    function updateRewindStageHint() {
        const select = $('#rewindStageSelect');
        const hint = $('#rewindStageHint');
        if (!select || !hint) return;
        hint.textContent = REWIND_STAGE_HINTS[select.value] || '将清理所选阶段及之后的数据并从该阶段重新运行。';
    }

    function openRewindModal(preselectedStage = null) {
        const task = taskById(state.currentId);
        if (!task) {
            toast('请先选择一个任务。', 'warn');
            return;
        }
        const validStages = ['keywords', 'serp', 'classify', 'discover', 'extract', 'phone'];
        const currentStage = validStages.includes(task.stage) ? task.stage : 'classify';
        const targetStage = preselectedStage && validStages.includes(preselectedStage) ? preselectedStage : currentStage;
        if ($('#rewindStageSelect')) {
            $('#rewindStageSelect').value = targetStage;
        }
        if ($('#editCityInput')) {
            $('#editCityInput').value = task.city || '';
        }
        if ($('#editMarketInput')) {
            $('#editMarketInput').value = task.target_market || '全球';
        }
        if ($('#editKeywordCountInput')) {
            $('#editKeywordCountInput').value = String(task.keyword_count || 24);
        }
        if ($('#editPagesTargetInput')) {
            $('#editPagesTargetInput').value = String(Number(task.pages_target) || 0);
        }
        state.editCategories = Array.isArray(task.categories) ? task.categories.slice() : [];
        refreshCategorySummary('edit');
        loadCategoryTree().then(() => refreshCategorySummary('edit')).catch(() => {});
        updateRewindStageHint();
        openModal('#rewindModal');
    }

    async function submitRewindTask() {
        const task = taskById(state.currentId);
        if (!task) return;
        const button = $('#rewindSubmit');
        const stage = $('#rewindStageSelect') ? $('#rewindStageSelect').value : 'keywords';
        const city = $('#editCityInput') ? $('#editCityInput').value.trim() : task.city;
        const targetMarket = $('#editMarketInput') ? $('#editMarketInput').value : (task.target_market || '全球');
        const keywordCount = Math.max(6, Math.min(60, Number($('#editKeywordCountInput')?.value || task.keyword_count || 24)));
        const editPagesRaw = String($('#editPagesTargetInput')?.value ?? '').trim();
        const pagesTarget = editPagesRaw === '' ? 0 : Math.max(0, Math.min(30, Number(editPagesRaw) || 0));
        if (!city) {
            toast('城市名称不能为空。', 'error');
            $('#editCityInput')?.focus();
            return;
        }
        if (button) {
            button.disabled = true;
            button.textContent = '正在回退…';
        }
        try {
            // Stop any in-flight loop step first
            state.token += 1;
            const data = await request('/api/tasks/' + task.id + '/rewind', {
                method: 'POST',
                timeout: 60000,
                body: {
                    stage,
                    city,
                    target_market: targetMarket,
                    keyword_count: keywordCount,
                    pages_target: pagesTarget,
                    categories: state.editCategories.slice()
                }
            });
            closeModal('#rewindModal');
            if (data.task) {
                mergeTask(data.task, data.counts || {});
            }
            // Clear cached tab datasets so UI reloads fresh rows
            state.data[task.id] = { keywords: null, domains: null, companies: null, logs: null };
            const stageName = STAGE_LABEL[stage] || stage;
            addLocal('已将任务 #' + task.id + ' 回退到「' + stageName + '」，按队列顺序继续。', 'success');
            toast('任务已回退至「' + stageName + '」', 'success');
            state.pausedByUser = false;
            if (Number(task.id) === Number(state.runnerId)) {
                state.token += 1;
                state.runnerId = null;
            }
            await loadTasksSilent();
            await loadNext(task.id);
            await refreshCurrentTab();
            ensureRunner();
        } catch (error) {
            toast(error.message, 'error', 5000);
        } finally {
            if (button) {
                button.disabled = false;
                button.textContent = '确认回退并重新开始';
            }
        }
    }

    async function exportCsv() {
        const taskId = state.currentId;
        if (!taskId) return;
        try {
            const response = await fetch(state.config.apiBase + '/api/tasks/' + taskId + '/export.csv', {
                headers: { 'X-API-Key': state.config.apiKey },
                cache: 'no-store'
            });
            if (!response.ok) throw new Error('导出失败（HTTP ' + response.status + '）');
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = 'companies-' + taskId + '.csv';
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            setTimeout(() => URL.revokeObjectURL(url), 4000);
            toast('CSV 已开始下载。', 'success');
        } catch (error) {
            toast(error.message, 'error');
        }
    }

    async function refreshEverything() {
        const button = $('#refreshBtn');
        if (button) button.classList.add('spinning');
        try {
            await checkConnection(false);
            await loadTasks({ kick: false });
            if (state.currentId) {
                invalidateData();
                await loadNext(state.currentId);
            }
            toast('已刷新任务状态。', 'success', 2200);
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            if (button) button.classList.remove('spinning');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Events                                                             */
    /* ------------------------------------------------------------------ */
    function bindEvents() {
        $('#newTaskBtn')?.addEventListener('click', openNewTask);
        $('#emptyNewBtn')?.addEventListener('click', openNewTask);
        $('#emptySettingsBtn')?.addEventListener('click', openSettings);
        $('#settingsBtn')?.addEventListener('click', openSettings);
        $('#settingsClose')?.addEventListener('click', () => closeModal('#settingsModal'));
        $('#settingsCancel')?.addEventListener('click', () => closeModal('#settingsModal'));
        $('#settingsSaveBtn')?.addEventListener('click', saveSettings);
        $('#testConnectionBtn')?.addEventListener('click', testConnection);
        $('#settingsForm')?.addEventListener('submit', (event) => { event.preventDefault(); saveSettings(); });
        $('#toggleApiKey')?.addEventListener('click', () => {
            const input = $('#apiKeyInput');
            input.type = input.type === 'password' ? 'text' : 'password';
            $('#toggleApiKey').textContent = input.type === 'password' ? '显示' : '隐藏';
        });
        $('#toggleSerperKey')?.addEventListener('click', () => {
            const input = $('#serperKeyInput');
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
            $('#toggleSerperKey').textContent = input.type === 'password' ? '显示' : '隐藏';
        });
        $('#toggleAiKey')?.addEventListener('click', () => {
            const input = $('#aiApiKeyInput');
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
            $('#toggleAiKey').textContent = input.type === 'password' ? '显示' : '隐藏';
        });
        $('#testSerperBtn')?.addEventListener('click', testSerperKey);
        $('#serperKeyInput')?.addEventListener('input', () => {
            applyRealtimeSerperConfig(false);
        });
        $('#serperKeyInput')?.addEventListener('change', () => {
            applyRealtimeSerperConfig(true);
        });
        $('#searchProviderInput')?.addEventListener('change', () => {
            applyRealtimeSerperConfig(false);
        });
        $('#aiBaseUrlInput')?.addEventListener('change', () => {
            applyRealtimeSerperConfig(true);
        });
        $('#aiModelInput')?.addEventListener('change', () => {
            applyRealtimeSerperConfig(false);
        });
        $('#aiApiKeyInput')?.addEventListener('change', () => {
            applyRealtimeSerperConfig(true);
        });
        chrome.storage.onChanged.addListener((changes, areaName) => {
            if (areaName === 'local' && changes[STORAGE_KEY]?.newValue) {
                const updated = changes[STORAGE_KEY].newValue;
                if (typeof updated.serperApiKey === 'string' && updated.serperApiKey.trim() !== '') {
                    state.config.serperApiKey = updated.serperApiKey.trim();
                }
                if (['serper', 'server', 'browser'].includes(updated.searchProvider)) {
                    state.config.searchProvider = updated.searchProvider;
                }
            }
        });
        $('#connectionPill')?.addEventListener('click', () => checkConnection(true));

        bindCategoryPicker();
        $('#newTaskClose')?.addEventListener('click', () => closeModal('#newTaskModal'));
        $('#newTaskCancel')?.addEventListener('click', () => closeModal('#newTaskModal'));
        $('#newTaskSubmit')?.addEventListener('click', createTask);
        $('#newTaskForm')?.addEventListener('submit', (event) => { event.preventDefault(); createTask(); });
        $$('.preset-buttons button').forEach((button) => {
            button.addEventListener('click', () => {
                const presets = {
                    quick: { keywords: 12, pages: 2 },
                    standard: { keywords: 24, pages: 0 },
                    deep: { keywords: 40, pages: 0 }
                };
                const preset = presets[button.getAttribute('data-preset')] || presets.standard;
                $('#keywordCountInput').value = String(preset.keywords);
                $('#pagesTargetInput').value = String(preset.pages);
                $$('.preset-buttons button').forEach((node) => node.classList.toggle('active', node === button));
            });
        });

        $('#startBtn')?.addEventListener('click', startTask);
        $('#pauseBtn')?.addEventListener('click', pauseTask);
        $('#retryBtn')?.addEventListener('click', retryTask);
        $('#rewindBtn')?.addEventListener('click', () => openRewindModal());
        $('#rewindClose')?.addEventListener('click', () => closeModal('#rewindModal'));
        $('#rewindCancel')?.addEventListener('click', () => closeModal('#rewindModal'));
        $('#rewindSubmit')?.addEventListener('click', submitRewindTask);
        $('#rewindStageSelect')?.addEventListener('change', updateRewindStageHint);
        $('#rewindForm')?.addEventListener('submit', (event) => { event.preventDefault(); submitRewindTask(); });
        $('#stepper')?.addEventListener('click', (event) => {
            const stepNode = event.target.closest('[data-stage]');
            if (!stepNode) return;
            const stage = stepNode.getAttribute('data-stage');
            if (stage && stage !== 'done') {
                openRewindModal(stage);
            }
        });
        $('#runOneBtn')?.addEventListener('click', runOneStep);
        $('#refreshBtn')?.addEventListener('click', refreshEverything);
        $('#exportBtn')?.addEventListener('click', exportCsv);
        $('#brandHome')?.addEventListener('click', () => {
            const workspace = $('#workspace');
            if (workspace) workspace.scrollTo({ top: 0, behavior: 'smooth' });
            if (!state.tasks.length) openNewTask();
        });
        $('#clearLocalBtn')?.addEventListener('click', () => {
            state.localLogs = [];
            renderLocalConsole();
        });

        $('#companiesTableBody')?.addEventListener('click', (event) => {
            const dialBtn = event.target.closest('[data-dial-phone]');
            if (!dialBtn) return;
            event.preventDefault();
            const phone = dialBtn.getAttribute('data-dial-phone') || '';
            const companyId = Number(dialBtn.getAttribute('data-dial-company') || 0);
            const cache = state.data.companies;
            const row = (cache?.rows || []).find((item) => Number(item.id) === companyId) || { id: companyId, phone };
            enqueueDial(row, phone, dialBtn);
        });

        $('#taskList')?.addEventListener('click', (event) => {
            const item = event.target.closest('[data-task-id]');
            if (item) selectTask(Number(item.getAttribute('data-task-id')));
        });
        $('#taskSearch')?.addEventListener('input', (event) => {
            state.search = event.target.value.trim();
            renderTaskList();
        });

        $$('.tab-btn').forEach((button) => {
            button.addEventListener('click', () => switchTab(button.getAttribute('data-tab')));
        });
        $('#domainFilters')?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-domain-filter]');
            if (!button) return;
            state.domainFilter = button.getAttribute('data-domain-filter');
            $$('#domainFilters .chip').forEach((chip) => chip.classList.toggle('active', chip === button));
            renderDomains();
        });
        $$('.modal-backdrop').forEach((backdrop) => {
            backdrop.addEventListener('mousedown', (event) => {
                if (event.target === backdrop) backdrop.hidden = true;
                document.body.classList.remove('modal-open');
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                $$('.modal-backdrop').forEach((modal) => { modal.hidden = true; });
                document.body.classList.remove('modal-open');
            }
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                $('#taskSearch')?.focus();
            }
        });

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && state.initialized) poll();
        });
    }

    async function init() {
        bindEvents();
        renderStepper({ stage: 'keywords', status: 'active' });
        addLocal('插件已就绪，等待连接服务器…', 'info');
        renderLocalConsole();
        await loadConfig();
        if (state.config.apiBase) {
            setConnection('checking', '检查中');
            await checkConnection(false);
        } else {
            setConnection('off', '未连接');
        }
        state.initialized = true;
        await loadTasks();
        setInterval(poll, 3000);
        setInterval(() => {
            if (!state.busy && !document.hidden) loadTasksSilent();
        }, 25000);
        if (!state.config.apiBase || !state.config.apiKey) {
            setTimeout(openSettings, 450);
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
