import type { Company, DialJob, PipelineTask, ServerSettings } from './types';

export class ApiError extends Error {
  code: string;
  status: number;

  constructor(message: string, code = 'request_failed', status = 0) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

type RequestOptions = {
  method?: string;
  body?: Record<string, unknown> | null;
  timeout?: number;
  query?: Record<string, string | number | undefined | null>;
};

function normalizeBase(base: string): string {
  return String(base || '').trim().replace(/\/+$/, '');
}

// React Native 的 URL / URLSearchParams 实现不完整，这里手工拼接查询串
function buildUrl(base: string, path: string, query?: RequestOptions['query']): string {
  const pairs: string[] = [];
  if (query) {
    Object.entries(query).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') return;
      pairs.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(value)));
    });
  }
  return normalizeBase(base) + path + (pairs.length ? '?' + pairs.join('&') : '');
}

export async function apiRequest<T = unknown>(
  base: string,
  apiKey: string,
  path: string,
  options: RequestOptions = {}
): Promise<T> {
  const cleanBase = normalizeBase(base);
  if (!cleanBase) throw new ApiError('请先配置 API 地址', 'missing_api_base');
  if (!apiKey) throw new ApiError('请先填写 API Key', 'missing_api_key');

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), options.timeout || 25000);
  try {
    const response = await fetch(buildUrl(cleanBase, path, options.query), {
      method: options.method || 'GET',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-API-Key': apiKey,
      },
      body: options.body ? JSON.stringify(options.body) : undefined,
      signal: controller.signal,
    });
    const text = await response.text();
    let payload: any = null;
    try {
      payload = text ? JSON.parse(text) : null;
    } catch {
      throw new ApiError('服务器返回了非 JSON 内容，请确认域名与伪静态。', 'invalid_json', response.status);
    }
    if (!response.ok || !payload || payload.ok !== true) {
      const err = payload?.error || {};
      throw new ApiError(err.message || `请求失败（HTTP ${response.status}）`, err.code || 'request_failed', response.status);
    }
    return payload.data as T;
  } catch (error) {
    if (error instanceof ApiError) throw error;
    if (error instanceof Error && error.name === 'AbortError') {
      throw new ApiError('请求超时，请检查网络或主机响应。', 'timeout');
    }
    throw new ApiError(error instanceof Error ? error.message : '网络异常', 'network_error');
  } finally {
    clearTimeout(timer);
  }
}

export function claimNextDial(base: string, apiKey: string, deviceId: string) {
  return apiRequest<{ job: DialJob | null; pending_count: number }>(base, apiKey, '/api/dial/next', {
    query: { device: deviceId },
    timeout: 20000,
  });
}

export function completeDial(
  base: string,
  apiKey: string,
  id: number,
  status: 'done' | 'failed' | 'cancelled',
  note?: string
) {
  return apiRequest<{ job: DialJob }>(base, apiKey, `/api/dial/${id}/complete`, {
    method: 'POST',
    body: { status, note: note || '' },
  });
}

export function fetchDialStats(base: string, apiKey: string) {
  return apiRequest<{ counts: Record<string, number> }>(base, apiKey, '/api/dial/stats');
}

export function searchCompanies(
  base: string,
  apiKey: string,
  params: { q?: string; city?: string; company?: string; phone?: string; page?: number; limit?: number }
) {
  return apiRequest<{ companies: Company[]; total: number; page: number; limit: number }>(
    base,
    apiKey,
    '/api/companies',
    {
      query: {
        q: params.q,
        city: params.city,
        company: params.company,
        phone: params.phone,
        page: params.page || 1,
        limit: params.limit || 30,
      },
      timeout: 30000,
    }
  );
}

export async function healthCheck(base: string) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), 12000);
  try {
    const response = await fetch(normalizeBase(base) + '/api/health', {
      cache: 'no-store',
      signal: controller.signal,
    });
    const payload = await response.json();
    if (!payload?.ok) throw new ApiError('健康检查失败', 'health_failed', response.status);
    return payload.data as { app?: string; version?: string; php?: string };
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new ApiError('无法连接服务器，请检查 API 地址。', 'network_error');
  } finally {
    clearTimeout(timer);
  }
}

export function fetchServerSettings(base: string, apiKey: string) {
  return apiRequest<ServerSettings>(base, apiKey, '/api/settings', { timeout: 15000 });
}

export function updateServerSettings(
  base: string,
  apiKey: string,
  body: {
    ai_base_url?: string;
    ai_api_key?: string;
    ai_model?: string;
    serper_api_key?: string;
    search_provider?: string;
  }
) {
  return apiRequest<ServerSettings>(base, apiKey, '/api/settings', {
    method: 'POST',
    body,
    timeout: 30000,
  });
}

export function fetchRunner(base: string, apiKey: string) {
  return apiRequest<{ enabled: boolean; active_tasks: number; paused_tasks: number }>(
    base,
    apiKey,
    '/api/runner'
  );
}

export function setRunner(base: string, apiKey: string, enabled: boolean) {
  return apiRequest<{ enabled: boolean; message?: string }>(base, apiKey, '/api/runner', {
    method: 'POST',
    body: { enabled },
  });
}

export function listTasks(base: string, apiKey: string, status?: string) {
  return apiRequest<{ tasks: PipelineTask[] }>(base, apiKey, '/api/tasks', {
    query: { status },
    timeout: 25000,
  });
}

export function createTask(
  base: string,
  apiKey: string,
  body: { city: string; target_market?: string; keyword_count?: number; pages_target?: number }
) {
  return apiRequest<{ task: PipelineTask; counts?: Record<string, number> }>(base, apiKey, '/api/tasks', {
    method: 'POST',
    body,
    timeout: 40000,
  });
}

export function getTask(base: string, apiKey: string, id: number) {
  return apiRequest<{ task: PipelineTask; counts?: Record<string, number> }>(
    base,
    apiKey,
    `/api/tasks/${id}`
  );
}

export function updateTask(
  base: string,
  apiKey: string,
  id: number,
  body: { city?: string; target_market?: string; keyword_count?: number; pages_target?: number }
) {
  return apiRequest<{ task: PipelineTask }>(base, apiKey, `/api/tasks/${id}/update`, {
    method: 'POST',
    body,
  });
}

export function stopTask(base: string, apiKey: string, id: number) {
  return apiRequest<{ task: PipelineTask }>(base, apiKey, `/api/tasks/${id}/stop`, { method: 'POST' });
}

export function resumeTask(base: string, apiKey: string, id: number) {
  return apiRequest<{ task: PipelineTask }>(base, apiKey, `/api/tasks/${id}/resume`, { method: 'POST' });
}

export function rewindTask(
  base: string,
  apiKey: string,
  id: number,
  body: {
    stage: string;
    city?: string;
    target_market?: string;
    keyword_count?: number;
    pages_target?: number;
  }
) {
  return apiRequest<{ task: PipelineTask }>(base, apiKey, `/api/tasks/${id}/rewind`, {
    method: 'POST',
    body,
    timeout: 60000,
  });
}
