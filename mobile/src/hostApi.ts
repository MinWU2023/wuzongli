/**
 * InfinityFree / similar free hosts inject aes.js and block native fetch.
 * We warm cookies via a hidden WebView on /bridge, then run API calls inside
 * that WebView (first-party cookies), mirroring the Chrome extension flow.
 */

type HostFetchRequest = {
  id: string;
  url: string;
  method: string;
  headers: Record<string, string>;
  body: string | null;
  timeout: number;
};

type HostFetchResult = {
  id: string;
  status?: number;
  text?: string;
  error?: string;
  type?: string;
};

type Pending = {
  resolve: (value: HostFetchResult) => void;
  reject: (reason?: unknown) => void;
  timer: ReturnType<typeof setTimeout>;
};

export type HostBridgeHandlers = {
  warm: (baseUrl: string) => Promise<void>;
  fetch: (req: HostFetchRequest) => Promise<HostFetchResult>;
  reload?: (baseUrl: string) => void;
};

let bridge: HostBridgeHandlers | null = null;
const bridgeWaiters: Array<() => void> = [];
const pending = new Map<string, Pending>();
let warmPromise: Promise<void> | null = null;
let lastWarmBase = '';
/** Sticky: once aes.js is seen, skip native fetch and always use WebView bridge. */
let forceBridge = false;

export function registerHostBridge(handlers: HostBridgeHandlers | null) {
  bridge = handlers;
  if (handlers) {
    const waiters = bridgeWaiters.splice(0);
    waiters.forEach((wake) => {
      try {
        wake();
      } catch {
        // ignore
      }
    });
  }
}

function whenBridgeMounted(timeoutMs = 20000): Promise<HostBridgeHandlers> {
  if (bridge) return Promise.resolve(bridge);
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      const idx = bridgeWaiters.indexOf(wake);
      if (idx >= 0) bridgeWaiters.splice(idx, 1);
      reject(new Error('主机安全桥接未就绪（WebView 未挂载），请重启 App'));
    }, timeoutMs);
    const wake = () => {
      clearTimeout(timer);
      if (bridge) resolve(bridge);
      else reject(new Error('主机安全桥接未就绪（WebView 未挂载），请重启 App'));
    };
    bridgeWaiters.push(wake);
  });
}

export function setForceHostBridge(value: boolean) {
  forceBridge = !!value;
}

export function shouldForceHostBridge(): boolean {
  return forceBridge;
}

export function isSecurityChallengeHtml(text: string | null | undefined): boolean {
  if (!text || typeof text !== 'string') return false;
  const sample = text.slice(0, 4000).toLowerCase();
  return (
    sample.includes('/aes.js') ||
    sample.includes('function tonumber') ||
    sample.includes('this site requires javascript') ||
    sample.includes('checking your browser') ||
    (sample.includes('__test') && sample.includes('document.cookie'))
  );
}

export function looksLikeJson(text: string): boolean {
  const t = String(text || '')
    .replace(/^\uFEFF/, '')
    .trim();
  return t.startsWith('{') || t.startsWith('[');
}

export function browserHeaders(extra: Record<string, string> = {}): Record<string, string> {
  return {
    Accept: 'application/json, text/plain, */*',
    'Accept-Language': 'zh-CN,zh;q=0.9,en;q=0.8',
    'X-Requested-With': 'XMLHttpRequest',
    'User-Agent':
      'Mozilla/5.0 (Linux; Android 13; Mobile) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
    ...extra,
  };
}

function newId(): string {
  return 'h' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
}

export function resolveHostResult(payload: HostFetchResult) {
  const item = pending.get(payload.id);
  if (!item) return;
  clearTimeout(item.timer);
  pending.delete(payload.id);
  item.resolve(payload);
}

export async function warmHost(baseUrl: string): Promise<void> {
  const base = String(baseUrl || '')
    .trim()
    .replace(/\/+$/, '');
  if (!base) return;
  const handlers = await whenBridgeMounted(20000);
  if (warmPromise && lastWarmBase === base) return warmPromise;
  lastWarmBase = base;
  warmPromise = handlers.warm(base).catch((err) => {
    warmPromise = null;
    lastWarmBase = '';
    throw err;
  });
  return warmPromise;
}

export function reloadHostBridge(baseUrl: string) {
  const base = String(baseUrl || '')
    .trim()
    .replace(/\/+$/, '');
  warmPromise = null;
  lastWarmBase = '';
  bridge?.reload?.(base);
}

export async function hostApiFetch(options: {
  base: string;
  path: string;
  method?: string;
  headers?: Record<string, string>;
  body?: string | null;
  timeout?: number;
}): Promise<{ status: number; text: string }> {
  const handlers = await whenBridgeMounted(20000);
  const base = String(options.base || '')
    .trim()
    .replace(/\/+$/, '');
  const path = String(options.path || '');
  await warmHost(base);

  const run = async (): Promise<HostFetchResult> => {
    const id = newId();
    const timeout = Math.max(8000, Math.min(90000, options.timeout || 35000));
    return new Promise<HostFetchResult>((resolve, reject) => {
      const timer = setTimeout(() => {
        pending.delete(id);
        reject(new Error('主机桥接请求超时'));
      }, timeout + 12000);
      pending.set(id, { resolve, reject, timer });
      handlers
        .fetch({
          id,
          url: base + path,
          method: (options.method || 'GET').toUpperCase(),
          headers: options.headers || {},
          body: options.body ?? null,
          timeout,
        })
        .catch((err) => {
          clearTimeout(timer);
          pending.delete(id);
          reject(err);
        });
    });
  };

  let result = await run();

  const challenge =
    !!result.error &&
    (result.error === 'host_security_challenge' ||
      result.error === 'host_bridge_not_ready' ||
      result.error === 'bridge_missing' ||
      isSecurityChallengeHtml(result.text));

  if (challenge || isSecurityChallengeHtml(result.text)) {
    forceBridge = true;
    reloadHostBridge(base);
    // Give aes.js / cookie redirect a full cycle before retrying.
    await new Promise((r) => setTimeout(r, 1200));
    await warmHost(base);
    result = await run();
  }

  if (result.error && !result.text) {
    throw new Error(
      result.error === 'host_security_challenge' || result.error === 'host_bridge_not_ready'
        ? '主机开启了浏览器安全校验(常见于InfinityFree)。请确认App已更新，或换可直连的主机。'
        : result.error
    );
  }
  if (isSecurityChallengeHtml(result.text)) {
    forceBridge = true;
    throw new Error(
      '主机开启了浏览器安全校验(常见于InfinityFree)。请确认App已更新，或换可直连的主机。'
    );
  }

  forceBridge = true;
  return { status: result.status || 0, text: result.text || '' };
}
