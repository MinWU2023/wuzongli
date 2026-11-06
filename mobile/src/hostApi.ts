/**
 * InfinityFree / similar free hosts inject aes.js and block native fetch.
 * We warm cookies in a hidden WebView, then run API calls inside that WebView
 * (first-party cookies), mirroring the Chrome extension HOST_API_FETCH flow.
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
};

type Pending = {
  resolve: (value: HostFetchResult) => void;
  reject: (reason?: unknown) => void;
  timer: ReturnType<typeof setTimeout>;
};

export type HostBridgeHandlers = {
  warm: (baseUrl: string) => Promise<void>;
  fetch: (req: HostFetchRequest) => Promise<HostFetchResult>;
};

let bridge: HostBridgeHandlers | null = null;
const pending = new Map<string, Pending>();
let warmPromise: Promise<void> | null = null;
let lastWarmBase = '';

export function registerHostBridge(handlers: HostBridgeHandlers | null) {
  bridge = handlers;
}

export function isSecurityChallengeHtml(text: string | null | undefined): boolean {
  if (!text || typeof text !== 'string') return false;
  const sample = text.slice(0, 4000).toLowerCase();
  return (
    sample.includes('/aes.js') ||
    sample.includes('function tonumber') ||
    sample.includes('this site requires javascript') ||
    sample.includes('checking your browser') ||
    (sample.includes('__test') && sample.includes('document.cookie')) ||
    (sample.includes('<html') && !sample.trimStart().startsWith('{'))
  );
}

export function looksLikeJson(text: string): boolean {
  const t = text.trim();
  return t.startsWith('{') || t.startsWith('[');
}

export function browserHeaders(extra: Record<string, string> = {}): Record<string, string> {
  return {
    Accept: 'application/json, text/plain, */*',
    'Accept-Language': 'zh-CN,zh;q=0.9,en;q=0.8',
    'X-Requested-With': 'XMLHttpRequest',
    // InfinityFree often blocks empty / non-browser UAs from native clients
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
  if (!base || !bridge) return;
  if (warmPromise && lastWarmBase === base) return warmPromise;
  lastWarmBase = base;
  warmPromise = bridge.warm(base).catch((err) => {
    warmPromise = null;
    throw err;
  });
  return warmPromise;
}

export async function hostApiFetch(options: {
  base: string;
  path: string;
  method?: string;
  headers?: Record<string, string>;
  body?: string | null;
  timeout?: number;
}): Promise<{ status: number; text: string }> {
  if (!bridge) {
    throw new Error('主机安全桥接未就绪');
  }
  const base = String(options.base || '')
    .trim()
    .replace(/\/+$/, '');
  const path = String(options.path || '');
  await warmHost(base);

  const id = newId();
  const timeout = Math.max(8000, Math.min(90000, options.timeout || 35000));
  const result = await new Promise<HostFetchResult>((resolve, reject) => {
    const timer = setTimeout(() => {
      pending.delete(id);
      reject(new Error('主机桥接请求超时'));
    }, timeout + 5000);
    pending.set(id, { resolve, reject, timer });
    bridge!
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

  if (result.error) {
    throw new Error(result.error);
  }
  return { status: result.status || 0, text: result.text || '' };
}
