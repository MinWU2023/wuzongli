'use strict';

const APP_URL = chrome.runtime.getURL('popup.html');

/**
 * Open a single 1280×800 application window when the toolbar icon is clicked.
 */
chrome.action.onClicked.addListener(async () => {
    try {
        const windows = await chrome.windows.getAll({ populate: true, windowTypes: ['popup'] });
        for (const win of windows) {
            if ((win.tabs || []).some((tab) => tab.url === APP_URL)) {
                await chrome.windows.update(win.id, { focused: true });
                return;
            }
        }
        const created = await chrome.windows.create({
            url: APP_URL,
            type: 'popup',
            width: 1280,
            height: 800,
            focused: true
        });
        if (created && typeof created.id === 'number') {
            // Some platforms ignore size on create; enforce it once more.
            try {
                await chrome.windows.update(created.id, { width: 1280, height: 800, focused: true });
            } catch (error) {
                // The window may have been closed immediately by the user.
            }
        }
    } catch (error) {
        console.error('Unable to open app window', error);
    }
});

chrome.runtime.onInstalled.addListener(async () => {
    const stored = await chrome.storage.local.get('gle_config');
    if (!stored.gle_config) {
        await chrome.storage.local.set({
            gle_config: {
                apiBase: '',
                apiKey: '',
                autoRun: true,
                requestDelay: 2500,
                searchProvider: 'serper',
                serperApiKey: 'a4be645a029169206f66e8024a73361f005fc5ef',
                serperGl: 'us',
                serperHl: 'en'
            }
        });
    }
});

/**
 * Message bridge for the popup. All HTTP and tab operations live in the
 * background worker so the UI stays responsive and credentials stay here.
 */
chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    handleMessage(message)
        .then((data) => sendResponse({ ok: true, data }))
        .catch((error) => sendResponse({ ok: false, error: error instanceof Error ? error.message : String(error) }));
    return true;
});

async function handleMessage(message) {
    if (!message || typeof message.type !== 'string') {
        throw new Error('无效的插件消息');
    }
    switch (message.type) {
        case 'FETCH_SERP':
            return fetchSerp(String(message.url || ''));
        case 'FETCH_SERP_TAB':
            return fetchSerpTab(String(message.url || ''), {
                keepTab: message.keepTab !== false,
                page: Number(message.page || 1),
                pagesTarget: Number(message.pagesTarget || 1)
            });
        case 'CLOSE_SERP_TAB':
            if (typeof serpWorkerTabId === 'number') {
                await closeTabQuietly(serpWorkerTabId);
                serpWorkerTabId = null;
            }
            return { closed: true };
        case 'FETCH_SERPER':
            return fetchSerper(message);
        case 'FETCH_PAGE':
            return fetchPage(String(message.url || ''));
        case 'FETCH_PAGE_TAB':
            return fetchPageTab(String(message.url || ''));
        case 'OPEN_TAB':
            return openTab(String(message.url || ''), message.active !== false);
        case 'WARM_HOST':
            return warmHost(String(message.url || ''));
        case 'HOST_API_FETCH':
            return hostApiFetch(message);
        default:
            throw new Error('未知的插件消息：' + message.type);
    }
}

/** Persistent hidden tab on the API origin — required for InfinityFree aes.js cookies. */
let hostApiTabId = null;

function isSecurityChallengeHtml(text) {
    if (!text || typeof text !== 'string') return false;
    const sample = text.slice(0, 3000).toLowerCase();
    return sample.includes('/aes.js')
        || sample.includes('function tonumber')
        || sample.includes('this site requires javascript')
        || (sample.includes('__test') && sample.includes('document.cookie'));
}

function normalizeApiBase(url) {
    return String(url || '').trim().replace(/\/+$/, '');
}

/**
 * Open / keep a background tab on the API host so the JS cookie check can run.
 * Prefer an HTML page (admin login) over /api/health so the document stays normal.
 */
async function ensureHostApiTab(baseUrl) {
    const base = normalizeApiBase(baseUrl);
    assertHttpUrl(base);
    const origin = new URL(base).origin;
    const warmUrl = base + '/admin/login';

    if (typeof hostApiTabId === 'number') {
        try {
            const existing = await chrome.tabs.get(hostApiTabId);
            if (existing && typeof existing.id === 'number') {
                const tabUrl = String(existing.url || '');
                if (tabUrl.startsWith(origin) || tabUrl === '' || tabUrl === 'about:blank') {
                    if (!tabUrl.startsWith(origin)) {
                        await chrome.tabs.update(hostApiTabId, { url: warmUrl });
                        await waitForTab(hostApiTabId, 20000);
                        await sleep(1600);
                    }
                    return hostApiTabId;
                }
            }
        } catch (error) {
            hostApiTabId = null;
        }
    }

    const created = await backgroundTab(warmUrl);
    if (typeof created.id !== 'number') {
        throw new Error('无法打开站点标签页以通过主机安全校验');
    }
    hostApiTabId = created.id;
    await waitForTab(hostApiTabId, 20000);
    // Challenge pages often redirect once after setting __test.
    await sleep(1600);
    try {
        await waitForTab(hostApiTabId, 8000);
    } catch (error) {
        // Already complete.
    }
    await sleep(500);
    return hostApiTabId;
}

/**
 * Open the API host in a real browser tab so InfinityFree-style JS cookie
 * checks (aes.js / __test) can complete before extension fetch retries.
 */
async function warmHost(url) {
    const base = normalizeApiBase(url).replace(/\/api\/health$/i, '');
    await ensureHostApiTab(base || url);
    return { warmed: true };
}

/**
 * Run fetch inside the API-host tab (first-party cookies). Extension fetch alone
 * cannot satisfy InfinityFree's browser security check.
 */
async function hostApiFetch(message) {
    const base = normalizeApiBase(message.base);
    const path = String(message.path || '');
    if (!base || !path) {
        throw new Error('HOST_API_FETCH 缺少 base/path');
    }
    assertHttpUrl(base);
    const method = String(message.method || 'GET').toUpperCase();
    const headers = message.headers && typeof message.headers === 'object' ? message.headers : {};
    const body = message.body === undefined || message.body === null ? null : String(message.body);
    const timeout = Math.max(8000, Math.min(240000, Number(message.timeout || 45000)));
    const url = base + path;

    const runOnce = async (tabId) => {
        const injection = await chrome.scripting.executeScript({
            target: { tabId },
            world: 'MAIN',
            func: async (fetchUrl, fetchMethod, fetchHeaders, fetchBody, fetchTimeout) => {
                const controller = new AbortController();
                const timer = setTimeout(() => controller.abort(), fetchTimeout);
                try {
                    const init = {
                        method: fetchMethod,
                        headers: fetchHeaders || {},
                        credentials: 'include',
                        cache: 'no-store',
                        signal: controller.signal
                    };
                    if (fetchBody !== null && fetchMethod !== 'GET' && fetchMethod !== 'HEAD') {
                        init.body = fetchBody;
                    }
                    const response = await fetch(fetchUrl, init);
                    const text = await response.text();
                    return { status: response.status, ok: response.ok, text };
                } catch (error) {
                    return {
                        status: 0,
                        ok: false,
                        text: '',
                        error: error && error.name === 'AbortError' ? '请求超时' : String(error && error.message || error)
                    };
                } finally {
                    clearTimeout(timer);
                }
            },
            args: [url, method, headers, body, timeout]
        });
        return injection && injection[0] ? injection[0].result : null;
    };

    let tabId = await ensureHostApiTab(base);
    let result = await runOnce(tabId);
    if (result && result.error && !result.text) {
        throw new Error(result.error);
    }
    if (result && isSecurityChallengeHtml(result.text)) {
        await chrome.tabs.update(tabId, { url: base + '/admin/login' });
        await waitForTab(tabId, 20000);
        await sleep(1800);
        try {
            await waitForTab(tabId, 8000);
        } catch (error) {
            // ignore
        }
        await sleep(500);
        result = await runOnce(tabId);
    }
    if (!result) {
        throw new Error('站点标签页未能执行 API 请求');
    }
    if (result.error && !result.text) {
        throw new Error(result.error);
    }
    return {
        status: Number(result.status || 0),
        ok: !!result.ok,
        text: String(result.text || '')
    };
}

async function fetchSerper(message) {
    const query = String(message.query || '').trim();
    if (!query) {
        throw new Error('Serper 搜索词不能为空');
    }
    const apiKey = String(message.apiKey || '').trim();
    if (!apiKey) {
        throw new Error('尚未填写 Serper.dev API Key');
    }
    const endpoint = String(message.endpoint || 'https://google.serper.dev/search').trim() || 'https://google.serper.dev/search';
    assertHttpUrl(endpoint);
    const page = Math.max(1, Math.min(30, Number(message.page || 1)));
    const gl = String(message.gl || 'us').trim().toLowerCase() || 'us';
    const hl = String(message.hl || 'en').trim().toLowerCase() || 'en';

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 30000);
    try {
        const response = await fetch(endpoint, {
            method: 'POST',
            credentials: 'omit',
            cache: 'no-store',
            signal: controller.signal,
            headers: {
                'X-API-KEY': apiKey,
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                q: query,
                gl,
                hl,
                num: 10,
                page,
                autocorrect: false
            })
        });
        const text = await response.text();
        let data = null;
        try {
            data = text ? JSON.parse(text) : null;
        } catch (error) {
            throw new Error('Serper 返回了无法解析的响应');
        }
        if (response.status === 401 || response.status === 403) {
            throw new Error('Serper API Key 无效或已过期');
        }
        if (response.status === 429) {
            throw new Error('Serper 请求过于频繁或额度不足');
        }
        if (!response.ok || !data) {
            const detail = data && (data.message || data.error) ? String(data.message || data.error) : ('HTTP ' + response.status);
            throw new Error('Serper 请求失败：' + detail);
        }
        const organic = Array.isArray(data.organic) ? data.organic : [];
        const items = [];
        for (const item of organic) {
            if (!item || typeof item.link !== 'string') continue;
            const link = item.link.trim();
            if (!/^https?:\/\//i.test(link)) continue;
            items.push({
                url: link,
                title: String(item.title || '').trim(),
                snippet: String(item.snippet || '').trim(),
                position: items.length + 1
            });
            if (items.length >= 100) break;
        }
        return {
            items,
            credits: typeof data.credits === 'number' ? data.credits : null,
            status: response.status
        };
    } catch (error) {
        throw new Error(error && error.name === 'AbortError' ? 'Serper 请求超时' : (error instanceof Error ? error.message : String(error)));
    } finally {
        clearTimeout(timer);
    }
}

async function fetchSerp(url) {
    assertHttpUrl(url);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20000);
    try {
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'include',
            redirect: 'follow',
            cache: 'no-store',
            signal: controller.signal,
            headers: {
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language': 'en-US,en;q=0.9,zh-CN;q=0.8'
            }
        });
        const html = (await response.text()).slice(0, 2200000);
        return {
            html,
            status: response.status,
            finalUrl: response.url,
            mode: 'fetch',
            empty: html.length < 500
        };
    } catch (error) {
        throw new Error('Google 页面请求失败：' + (error && error.name === 'AbortError' ? '请求超时' : error.message));
    } finally {
        clearTimeout(timer);
    }
}

function pageUrlCandidates(url) {
    const out = [];
    const seen = new Set();
    const push = (value) => {
        try {
            const parsed = new URL(String(value || '').trim());
            if (!/^https?:$/i.test(parsed.protocol)) return;
            const href = parsed.href;
            if (seen.has(href)) return;
            seen.add(href);
            out.push(href);
        } catch (error) {
            // skip invalid
        }
    };
    push(url);
    try {
        const parsed = new URL(url);
        const host = parsed.hostname;
        const hosts = host.startsWith('www.')
            ? [host, host.slice(4)]
            : [host, 'www.' + host];
        for (const nextHost of hosts) {
            for (const protocol of ['https:', 'http:']) {
                const copy = new URL(parsed.href);
                copy.protocol = protocol;
                copy.hostname = nextHost;
                push(copy.href);
            }
        }
    } catch (error) {
        // keep original only
    }
    return out;
}

function isChromeErrorTab(tab) {
    const url = String((tab && (tab.url || tab.pendingUrl)) || '');
    return url.startsWith('chrome-error://')
        || url.startsWith('chrome://')
        || url.startsWith('about:neterror')
        || url.startsWith('edge://');
}

async function fetchPageOnce(url) {
    assertHttpUrl(url);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 18000);
    try {
        const response = await fetch(url, {
            method: 'GET',
            credentials: 'omit',
            redirect: 'follow',
            cache: 'no-store',
            signal: controller.signal,
            headers: {
                'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language': 'en-US,en;q=0.9,zh-CN;q=0.8'
            }
        });
        const html = (await response.text()).slice(0, 2200000);
        return {
            html,
            status: response.status,
            finalUrl: response.url,
            mode: 'fetch',
            empty: html.trim().length < 200,
            error: null
        };
    } catch (error) {
        return {
            html: '',
            status: 0,
            finalUrl: url,
            mode: 'fetch',
            empty: true,
            error: error && error.name === 'AbortError' ? '请求超时' : error.message
        };
    } finally {
        clearTimeout(timer);
    }
}

async function fetchPage(url) {
    assertHttpUrl(url);
    const candidates = pageUrlCandidates(url);
    let best = null;
    let lastError = '';
    for (const candidate of candidates) {
        const result = await fetchPageOnce(candidate);
        if (result.error) lastError = result.error;
        const html = result.html ? result.html.trim() : '';
        if (html.length < 200) continue;
        // Prefer successful HTTP statuses, but keep a long body even on 5xx (some hosts mislabel).
        if (result.status >= 200 && result.status < 400) {
            return result;
        }
        if (!best || html.length > String(best.html || '').length) {
            best = result;
        }
    }
    if (best) return best;
    return {
        html: '',
        status: 0,
        finalUrl: url,
        mode: 'fetch',
        empty: true,
        error: lastError || '未能取得有效 HTML'
    };
}

async function closeTabQuietly(tabId) {
    try {
        await chrome.tabs.remove(tabId);
    } catch (error) {
        // The user may have closed the tab first.
    }
}

/**
 * Open a background tab in a normal browser window. Creating it in the
 * focused popup window would disturb the 1280×800 app window.
 */
async function backgroundTab(url) {
    const options = { url, active: false };
    try {
        const normal = await chrome.windows.getLastFocused({ windowTypes: ['normal'] });
        if (normal && typeof normal.id === 'number') {
            options.windowId = normal.id;
        }
    } catch (error) {
        // No normal window available; Chrome will use the current window.
    }
    return chrome.tabs.create(options);
}

let serpWorkerTabId = null;

async function getOrCreateSerpTab(url, page = 1) {
    if (typeof serpWorkerTabId === 'number') {
        try {
            const existing = await chrome.tabs.get(serpWorkerTabId);
            if (existing && typeof existing.id === 'number') {
                // When advancing to page > 1 on the same keyword, try clicking Google's real "Next" pagination link first.
                let clickedNext = false;
                if (page > 1 && existing.url && /google\./i.test(existing.url)) {
                    try {
                        const targetStart = (page - 1) * 10;
                        const clickRes = await chrome.scripting.executeScript({
                            target: { tabId: serpWorkerTabId },
                            args: [page, targetStart],
                            func: (targetPage, startOffset) => {
                                const candidates = [
                                    document.querySelector('a#pnnext'),
                                    document.querySelector('a[aria-label*="Next"]'),
                                    document.querySelector('a[aria-label*="下一页"]'),
                                    document.querySelector('a[aria-label="Page ' + targetPage + '"]'),
                                    document.querySelector('a[aria-label*="第 ' + targetPage + ' 页"]'),
                                    ...Array.from(document.querySelectorAll('a[href*="start=' + startOffset + '"]'))
                                ].filter(Boolean);
                                if (candidates.length > 0 && candidates[0].href) {
                                    candidates[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    candidates[0].click();
                                    return true;
                                }
                                return false;
                            }
                        });
                        clickedNext = !!(clickRes && clickRes[0] && clickRes[0].result);
                    } catch (error) {
                        clickedNext = false;
                    }
                }
                if (!clickedNext) {
                    await chrome.tabs.update(serpWorkerTabId, { url });
                }
                await sleep(650);
                return existing;
            }
        } catch (error) {
            serpWorkerTabId = null;
        }
    }
    const created = await backgroundTab(url);
    if (created && typeof created.id === 'number') {
        serpWorkerTabId = created.id;
    }
    return created;
}

async function fetchSerpTab(url, options = {}) {
    const cleanUrl = String(url || '').replace(/([?&])gbv=1(&|$)/, (m, p1, p2) => (p2 === '&' ? p1 : '')).replace(/[?&]$/, '');
    assertHttpUrl(cleanUrl);
    const keepTab = options.keepTab !== false;
    const page = Math.max(1, Number(options.page || 1));
    const tab = keepTab ? await getOrCreateSerpTab(cleanUrl, page) : await backgroundTab(cleanUrl);
    const tabId = tab.id;
    if (typeof tabId !== 'number') {
        throw new Error('无法创建 Google 标签页');
    }
    try {
        await waitForTab(tabId, 28000);
        let bestResult = null;
        for (let poll = 0; poll < 8; poll++) {
            await sleep(poll === 0 ? 1200 : 900);
            const injection = await chrome.scripting.executeScript({
                target: { tabId },
                func: extractSerpDom
            });
            const result = injection && injection[0] ? injection[0].result : null;
            if (!result) continue;
            bestResult = result;
            if ((Array.isArray(result.items) && result.items.length > 0) || result.blocked) {
                break;
            }
        }
        if (!bestResult) {
            throw new Error('Google 标签页没有返回任何内容');
        }
        // Smooth scroll to bottom so pagination looks natural in the browser tab.
        try {
            await chrome.scripting.executeScript({
                target: { tabId },
                func: () => {
                    window.scrollTo({ top: Math.min(document.body.scrollHeight || 1600, 1800), behavior: 'smooth' });
                }
            });
        } catch (error) {
            // Ignore scroll errors on restricted pages.
        }
        await sleep(500);
        return bestResult;
    } finally {
        if (!keepTab) {
            await closeTabQuietly(tabId);
        }
    }
}

async function fetchPageTab(url) {
    assertHttpUrl(url);
    const candidates = pageUrlCandidates(url);
    let lastError = '无法抓取网页';

    for (const candidate of candidates) {
        const tab = await backgroundTab(candidate);
        const tabId = tab.id;
        if (typeof tabId !== 'number') {
            lastError = '无法创建网页标签页';
            continue;
        }
        try {
            const loaded = await waitForTab(tabId, 28000);
            if (isChromeErrorTab(loaded)) {
                lastError = '页面无法打开（网络/证书/DNS 错误）：' + candidate;
                continue;
            }
            await sleep(1200);
            // Re-check after settle — some navigations land on chrome-error late.
            const again = await chrome.tabs.get(tabId);
            if (isChromeErrorTab(again)) {
                lastError = '页面无法打开（网络/证书/DNS 错误）：' + candidate;
                continue;
            }
            let injection;
            try {
                injection = await chrome.scripting.executeScript({
                    target: { tabId },
                    func: extractPageDom
                });
            } catch (error) {
                const message = error instanceof Error ? error.message : String(error);
                if (/error page|cannot be scripted|Frame with ID/i.test(message)) {
                    lastError = '标签页处于错误页，无法注入：' + candidate;
                    continue;
                }
                throw error;
            }
            const result = injection && injection[0] ? injection[0].result : null;
            if (result && result.html && String(result.html).trim().length > 200) {
                return result;
            }
            lastError = '标签页内容过短或为空：' + candidate;
        } finally {
            await closeTabQuietly(tabId);
        }
    }

    throw new Error(lastError);
}

async function openTab(url, active) {
    assertHttpUrl(url);
    const tab = await chrome.tabs.create({ url, active });
    return { tabId: tab.id };
}

function assertHttpUrl(url) {
    if (!/^https?:\/\//i.test(url)) {
        throw new Error('只允许访问 HTTP/HTTPS 地址');
    }
}

async function waitForTab(tabId, timeout) {
    const started = Date.now();
    while (Date.now() - started < timeout) {
        try {
            const tab = await chrome.tabs.get(tabId);
            if (tab.status === 'complete') {
                return tab;
            }
        } catch (error) {
            throw new Error('标签页已被关闭');
        }
        await sleep(400);
    }
    throw new Error('页面加载超时');
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Injected into a Google result tab. Must be fully self-contained.
 */
function extractSerpDom() {
    const clean = (value) => (value || '').replace(/\s+/g, ' ').trim();
    const isGoogle = (url) => {
        try {
            const host = new URL(url).hostname.replace(/^www\./, '').toLowerCase();
            return host === 'google.com' || host.startsWith('google.') || host.endsWith('.google.com') || host === 'gstatic.com';
        } catch (error) {
            return true;
        }
    };
    const unwrap = (href) => {
        try {
            const parsed = new URL(href);
            const host = parsed.hostname.replace(/^www\./, '').toLowerCase();
            if (host === 'google.com' || host.startsWith('google.') || host.endsWith('.google.com')) {
                const target = parsed.searchParams.get('q') || parsed.searchParams.get('url') || parsed.searchParams.get('u');
                if (target && /^https?:\/\//i.test(target)) return target;
            }
        } catch (error) { /* keep original */ }
        return href;
    };
    const items = [];
    const seen = new Set();
    const pushItem = (anchor, titleText, containerNode) => {
        if (!anchor || !anchor.href) return;
        const finalUrl = unwrap(anchor.href);
        if (!/^https?:\/\//i.test(finalUrl) || isGoogle(finalUrl) || seen.has(finalUrl)) return;
        const title = clean(titleText || anchor.textContent);
        if (!title || title.length < 2) return;
        let snippet = '';
        const container = containerNode || anchor.closest('div.g, div.MjjYud, div.tF2Cxc, div.Gx5Zad, div[data-snc], div[data-hveid], div');
        if (container) {
            const snippetNode = container.querySelector('.VwiC3b, .aCOpRe, .IsZvec, [data-sncf], .BNeawe.s3v9rd, .st, span');
            snippet = snippetNode ? clean(snippetNode.textContent) : '';
            if (snippet === title) snippet = '';
        }
        seen.add(finalUrl);
        items.push({
            title: title.slice(0, 490),
            url: finalUrl,
            snippet: snippet.slice(0, 1600),
            position: items.length + 1
        });
    };

    const headings = document.querySelectorAll('#search a h3, #rso a h3, a h3, a [role="heading"]');
    headings.forEach((heading) => {
        const anchor = heading.closest('a');
        pushItem(anchor, heading.textContent, heading.closest('div.g, div.MjjYud, div.tF2Cxc, div.Gx5Zad, div[data-snc], div[data-hveid], div'));
    });

    if (items.length === 0) {
        const fallbackAnchors = document.querySelectorAll('#search a[href], #rso a[href], #main a[href], a[jsname="UWckNb"], a[data-ved][href]');
        fallbackAnchors.forEach((anchor) => {
            pushItem(anchor, anchor.querySelector('h3, div, span')?.textContent || anchor.textContent, null);
        });
    }

    const bodyText = clean(document.body ? document.body.innerText : '');
    const blocked = /unusual traffic|detected unusual traffic|captcha|before you continue|enable javascript|our systems have detected|系统检测到您的计算机网络中存在异常流量/i.test(bodyText) ||
        !!document.querySelector('#captcha-form, form[action*=" sorry"], iframe[src*="recaptcha"]');
    return {
        items,
        html: document.documentElement ? document.documentElement.outerHTML.slice(0, 2200000) : '',
        finalUrl: location.href,
        title: document.title,
        blocked,
        mode: 'tab',
        bodyPreview: bodyText.slice(0, 400)
    };
}

/**
 * Injected into a company website tab.
 */
function extractPageDom() {
    const clean = (value) => (value || '').replace(/\s+/g, ' ').trim();
    const bodyText = clean(document.body ? document.body.innerText : '');
    return {
        html: document.documentElement ? document.documentElement.outerHTML.slice(0, 2200000) : '',
        finalUrl: location.href,
        title: document.title,
        textLength: bodyText.length,
        bodyPreview: bodyText.slice(0, 400),
        mode: 'tab'
    };
}
