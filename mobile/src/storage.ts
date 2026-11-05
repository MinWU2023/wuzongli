import AsyncStorage from '@react-native-async-storage/async-storage';
import type { AppConfig } from './types';

const KEY = 'gle_dialer_config_v1';

const DEFAULTS: AppConfig = {
  apiBase: 'https://google.yzz.me',
  apiKey: '',
  deviceId: '',
  acceptOrders: false,
  aiBaseUrl: '',
  aiModel: 'gpt-4o-mini',
};

function randomDeviceId(): string {
  return 'm-' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36).slice(-4);
}

export async function loadConfig(): Promise<AppConfig> {
  try {
    const raw = await AsyncStorage.getItem(KEY);
    if (!raw) {
      const fresh = { ...DEFAULTS, deviceId: randomDeviceId() };
      await AsyncStorage.setItem(KEY, JSON.stringify(fresh));
      return fresh;
    }
    const parsed = JSON.parse(raw) as Partial<AppConfig>;
    return {
      ...DEFAULTS,
      ...parsed,
      apiBase: String(parsed.apiBase || DEFAULTS.apiBase).replace(/\/+$/, ''),
      deviceId: parsed.deviceId || randomDeviceId(),
      acceptOrders: !!parsed.acceptOrders,
      aiBaseUrl: String(parsed.aiBaseUrl || '').replace(/\/+$/, ''),
      aiModel: String(parsed.aiModel || DEFAULTS.aiModel || ''),
    };
  } catch {
    return { ...DEFAULTS, deviceId: randomDeviceId() };
  }
}

export async function saveConfig(next: AppConfig): Promise<void> {
  const clean: AppConfig = {
    ...next,
    apiBase: String(next.apiBase || DEFAULTS.apiBase).replace(/\/+$/, ''),
  };
  await AsyncStorage.setItem(KEY, JSON.stringify(clean));
}
