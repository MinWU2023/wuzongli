import React, { useCallback, useEffect, useState } from 'react';
import { Alert, ScrollView, StyleSheet, Text, View } from 'react-native';
import { ApiError, fetchServerSettings, healthCheck, updateServerSettings } from '../api';
import { Card, Field, Muted, PrimaryButton, Screen, Subtitle, Title } from '../components/ui';
import { colors, spacing } from '../theme';
import type { AppConfig } from '../types';

type Props = {
  config: AppConfig;
  onSave: (next: AppConfig) => Promise<void>;
};

export function SettingsScreen({ config, onSave }: Props) {
  const [apiBase, setApiBase] = useState(config.apiBase);
  const [apiKey, setApiKey] = useState(config.apiKey);
  const [aiBaseUrl, setAiBaseUrl] = useState(config.aiBaseUrl || '');
  const [aiApiKey, setAiApiKey] = useState('');
  const [aiModel, setAiModel] = useState(config.aiModel || 'gpt-4o-mini');
  const [aiKeyHint, setAiKeyHint] = useState('留空则保持服务器现有密钥不变');
  const [aiStatus, setAiStatus] = useState('');
  const [saving, setSaving] = useState(false);
  const [loadingAi, setLoadingAi] = useState(false);
  const [probe, setProbe] = useState('');

  const applyRelay = useCallback((relay: { base_url?: string; model?: string; masked_key?: string; has_key?: boolean } | null | undefined) => {
    if (!relay) {
      setAiStatus('尚未配置 AI 中转站');
      return;
    }
    if (relay.base_url) setAiBaseUrl(relay.base_url);
    if (relay.model) setAiModel(relay.model);
    if (relay.masked_key) {
      setAiKeyHint(`当前已配置：${relay.masked_key}（留空保持不变）`);
    } else {
      setAiKeyHint('留空则保持服务器现有密钥不变');
    }
    setAiStatus(relay.has_key ? `已配置 · ${relay.model || 'AI'}` : '已有地址，但密钥未配置');
  }, []);

  const loadAiSettings = useCallback(async (base: string, key: string) => {
    if (!base.trim() || !key.trim()) {
      setAiStatus('填写 API 地址与 Key 后可同步 AI 配置');
      return;
    }
    setLoadingAi(true);
    try {
      const data = await fetchServerSettings(base.trim().replace(/\/+$/, ''), key.trim());
      applyRelay(data.ai_relay);
    } catch (error) {
      setAiStatus(error instanceof Error ? error.message : '同步 AI 配置失败');
    } finally {
      setLoadingAi(false);
    }
  }, [applyRelay]);

  useEffect(() => {
    setApiBase(config.apiBase);
    setApiKey(config.apiKey);
    setAiBaseUrl(config.aiBaseUrl || '');
    setAiModel(config.aiModel || 'gpt-4o-mini');
    void loadAiSettings(config.apiBase, config.apiKey);
  }, [config.apiBase, config.apiKey, config.aiBaseUrl, config.aiModel, loadAiSettings]);

  const save = async () => {
    const cleanBase = apiBase.trim().replace(/\/+$/, '');
    const cleanKey = apiKey.trim();
    const cleanAiUrl = aiBaseUrl.trim().replace(/\/+$/, '');
    const cleanAiKey = aiApiKey.trim();
    const cleanModel = aiModel.trim();

    if (cleanAiUrl && !/^https?:\/\//i.test(cleanAiUrl)) {
      Alert.alert('格式错误', 'AI 中转站 URL 必须以 http:// 或 https:// 开头');
      return;
    }

    setSaving(true);
    try {
      const nextConfig: AppConfig = {
        ...config,
        apiBase: cleanBase,
        apiKey: cleanKey,
        aiBaseUrl: cleanAiUrl,
        aiModel: cleanModel || 'gpt-4o-mini',
      };
      await onSave(nextConfig);

      if (cleanKey && (cleanAiUrl || cleanAiKey || cleanModel)) {
        const payload: {
          ai_base_url?: string;
          ai_api_key?: string;
          ai_model?: string;
        } = {};
        if (cleanAiUrl) payload.ai_base_url = cleanAiUrl;
        if (cleanAiKey) payload.ai_api_key = cleanAiKey;
        if (cleanModel) payload.ai_model = cleanModel;
        const res = await updateServerSettings(cleanBase, cleanKey, payload);
        applyRelay(res.ai_relay);
        setAiApiKey('');
        Alert.alert('已保存', '连接配置与 AI 中转站已同步到服务器');
      } else {
        Alert.alert('已保存', '连接配置已更新');
      }
    } catch (error) {
      Alert.alert('保存失败', error instanceof Error ? error.message : '请重试');
    } finally {
      setSaving(false);
    }
  };

  const test = async () => {
    setProbe('检测中…');
    try {
      const data = await healthCheck(apiBase.trim());
      setProbe(`连接正常 · ${data.app || 'GLE'} · PHP ${data.php || '?'}`);
      if (apiKey.trim()) {
        await loadAiSettings(apiBase.trim(), apiKey.trim());
      }
    } catch (error) {
      setProbe(error instanceof ApiError ? error.message : '连接失败');
    }
  };

  return (
    <Screen>
      <ScrollView contentContainerStyle={{ paddingBottom: 40 }}>
        <View style={styles.header}>
          <Title>好线索 · 设置</Title>
          <Subtitle>对接后台 API，并配置插件流水线使用的 AI 中转站。</Subtitle>
        </View>

        <Card>
          <Field
            label="API 地址"
            value={apiBase}
            onChangeText={setApiBase}
            autoCapitalize="none"
            autoCorrect={false}
            placeholder="https://google.yzz.me"
          />
          <Field
            label="API Key"
            value={apiKey}
            onChangeText={setApiKey}
            autoCapitalize="none"
            autoCorrect={false}
            secureTextEntry
            placeholder="gle_…"
          />
          <Muted>设备 ID：{config.deviceId}</Muted>
        </Card>

        <Card style={{ marginTop: spacing.md }}>
          <Text style={styles.sectionTitle}>AI 中转站</Text>
          <Muted>{loadingAi ? '正在同步服务器配置…' : aiStatus || '用于关键词 / 筛选 / 提取等 AI 步骤'}</Muted>
          <View style={{ height: spacing.sm }} />
          <Field
            label="AI URL"
            value={aiBaseUrl}
            onChangeText={setAiBaseUrl}
            autoCapitalize="none"
            autoCorrect={false}
            placeholder="https://api.openai.com/v1"
          />
          <Field
            label="AI 密钥"
            value={aiApiKey}
            onChangeText={setAiApiKey}
            autoCapitalize="none"
            autoCorrect={false}
            secureTextEntry
            placeholder={aiKeyHint}
          />
          <Field
            label="模型"
            value={aiModel}
            onChangeText={setAiModel}
            autoCapitalize="none"
            autoCorrect={false}
            placeholder="gpt-4o-mini"
          />
          <Muted>密钥仅在填写时才会覆盖服务器已有配置；留空保持不变。</Muted>
        </Card>

        <View style={{ marginTop: spacing.md }}>
          <PrimaryButton label={saving ? '保存中…' : '保存配置'} onPress={save} disabled={saving} />
          <View style={{ height: 10 }} />
          <PrimaryButton label="测试连接 / 同步 AI" onPress={test} tone="ghost" />
          {!!probe && (
            <Text style={[styles.probe, probe.includes('正常') ? styles.ok : styles.bad]}>{probe}</Text>
          )}
        </View>

        <Card style={{ marginTop: spacing.md }}>
          <Text style={styles.helpTitle}>使用说明</Text>
          <Muted>1. 先填 API 地址与 Key，再配置 AI URL / 密钥。</Muted>
          <Muted>2. 保存后会写入服务器 AI 中转站，插件与流水线共用。</Muted>
          <Muted>3. 「任务」页开关可即时控制插件是否自动跑。</Muted>
          <Muted>4. 开启接单后，拨号队列号码会唤起系统电话。</Muted>
        </Card>
      </ScrollView>
    </Screen>
  );
}

const styles = StyleSheet.create({
  header: {
    marginTop: spacing.sm,
    marginBottom: spacing.lg,
  },
  sectionTitle: {
    fontFamily: 'DMSans_700Bold',
    fontSize: 15,
    color: colors.text,
    marginBottom: 4,
  },
  probe: {
    marginTop: spacing.md,
    fontFamily: 'DMSans_500Medium',
    fontSize: 13,
  },
  ok: { color: colors.accent },
  bad: { color: colors.danger },
  helpTitle: {
    fontFamily: 'DMSans_700Bold',
    fontSize: 15,
    color: colors.text,
    marginBottom: 8,
  },
});
