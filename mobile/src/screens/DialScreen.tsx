import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Alert, Linking, StyleSheet, Switch, Text, View } from 'react-native';
import { claimNextDial, completeDial, fetchDialStats } from '../api';
import { Badge, Card, Muted, PrimaryButton, Screen, Subtitle, Title } from '../components/ui';
import { colors, radius, spacing } from '../theme';
import type { AppConfig, DialJob } from '../types';

type Props = {
  config: AppConfig;
  onAcceptChange: (value: boolean) => void;
};

export function DialScreen({ config, onAcceptChange }: Props) {
  const [pending, setPending] = useState(0);
  const [current, setCurrent] = useState<DialJob | null>(null);
  const [busy, setBusy] = useState(false);
  const [statusText, setStatusText] = useState('等待接单');
  const [lastError, setLastError] = useState('');
  const polling = useRef(false);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  // 轮询回调里读最新的当前号码，避免把 current 放进 effect 依赖导致反复重启轮询
  const currentRef = useRef<DialJob | null>(null);
  const setCurrentJob = useCallback((job: DialJob | null) => {
    currentRef.current = job;
    setCurrent(job);
  }, []);

  const refreshStats = useCallback(async () => {
    if (!config.apiKey) return;
    try {
      const data = await fetchDialStats(config.apiBase, config.apiKey);
      setPending(Number(data.counts?.pending || 0));
    } catch {
      // ignore transient stats errors while polling
    }
  }, [config.apiBase, config.apiKey]);

  const openDialer = async (phone: string) => {
    const cleaned = phone.replace(/[^\d+]/g, '');
    // Android 11+ 上 canOpenURL 对 tel: 可能误判为 false，直接打开并捕获异常
    try {
      await Linking.openURL(`tel:${cleaned}`);
    } catch {
      throw new Error('当前设备无法打开拨号盘');
    }
  };

  const claimAndDial = useCallback(async () => {
    if (polling.current || currentRef.current) return;
    if (!config.apiKey) {
      setStatusText('请先到设置填写 API Key');
      return;
    }
    polling.current = true;
    setBusy(true);
    setLastError('');
    try {
      const data = await claimNextDial(config.apiBase, config.apiKey, config.deviceId);
      setPending(Number(data.pending_count || 0));
      if (!data.job) {
        setStatusText('暂无待拨号码，继续监听…');
        setCurrentJob(null);
        return;
      }
      setCurrentJob(data.job);
      setStatusText('已领取，正在唤起拨号…');
      await openDialer(data.job.phone);
      setStatusText('拨号盘已打开，请完成通话后标记结果');
    } catch (error) {
      const message = error instanceof Error ? error.message : '领取失败';
      setLastError(message);
      setStatusText('领取失败，将自动重试');
    } finally {
      polling.current = false;
      setBusy(false);
    }
  }, [config.apiBase, config.apiKey, config.deviceId, setCurrentJob]);

  useEffect(() => {
    refreshStats();
  }, [refreshStats]);

  useEffect(() => {
    if (timerRef.current) {
      clearInterval(timerRef.current);
      timerRef.current = null;
    }
    if (!config.acceptOrders) {
      setStatusText('接单已关闭');
      return;
    }
    setStatusText('接单中 · 每 3 秒轮询');
    claimAndDial();
    timerRef.current = setInterval(() => {
      if (!currentRef.current) claimAndDial();
      else refreshStats();
    }, 3000);
    return () => {
      if (timerRef.current) {
        clearInterval(timerRef.current);
        timerRef.current = null;
      }
    };
  }, [config.acceptOrders, claimAndDial, refreshStats]);

  const mark = async (status: 'done' | 'failed' | 'cancelled') => {
    if (!current) return;
    setBusy(true);
    try {
      await completeDial(config.apiBase, config.apiKey, current.id, status);
      setCurrentJob(null);
      setStatusText(status === 'done' ? '已完成，继续等待下一单' : '已标记，继续等待下一单');
      await refreshStats();
    } catch (error) {
      Alert.alert('操作失败', error instanceof Error ? error.message : '请稍后重试');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <View style={styles.header}>
        <View style={{ flex: 1 }}>
          <Title>好线索</Title>
          <Subtitle>插件点击「拨号」后号码入库；开启接单后本机每 3 秒领取并唤起电话。</Subtitle>
        </View>
      </View>

      <Card style={styles.acceptCard}>
        <View style={styles.acceptRow}>
          <View style={{ flex: 1 }}>
            <Text style={styles.acceptTitle}>{config.acceptOrders ? '接单中' : '未接单'}</Text>
            <Muted>{config.acceptOrders ? '正在监听拨号队列' : '打开开关开始自动领取'}</Muted>
          </View>
          <Switch
            value={config.acceptOrders}
            onValueChange={onAcceptChange}
            trackColor={{ false: '#2A4038', true: colors.accentDeep }}
            thumbColor={config.acceptOrders ? colors.accent : '#C7D7CF'}
          />
        </View>
        <View style={styles.statsRow}>
          <View style={styles.statPill}>
            <Text style={styles.statNum}>{pending}</Text>
            <Text style={styles.statLabel}>待拨</Text>
          </View>
          <View style={styles.statPill}>
            <Text style={styles.statNum}>{current ? 1 : 0}</Text>
            <Text style={styles.statLabel}>当前</Text>
          </View>
          <Badge label={statusText} tone={config.acceptOrders ? 'accent' : 'muted'} />
        </View>
      </Card>

      {current ? (
        <Card style={styles.jobCard}>
          <Badge label="当前号码" />
          <Text style={styles.phone}>{current.phone}</Text>
          <Text style={styles.company}>{current.company_name || current.domain || '未命名企业'}</Text>
          <Muted>
            {[current.city, current.domain, current.email].filter(Boolean).join(' · ') || '暂无更多资料'}
          </Muted>
          {!!current.address && <Muted>{current.address}</Muted>}
          <View style={styles.actions}>
            <PrimaryButton label="再次拨号" onPress={() => openDialer(current.phone)} disabled={busy} />
            <PrimaryButton label="已接通 / 完成" onPress={() => mark('done')} disabled={busy} />
            <PrimaryButton label="未接通 / 失败" onPress={() => mark('failed')} disabled={busy} tone="ghost" />
          </View>
        </Card>
      ) : (
        <Card style={styles.emptyCard}>
          <Text style={styles.emptyTitle}>队列空闲</Text>
          <Subtitle>
            在 Chrome 插件企业列表点击「拨号」，号码会进入队列。手机开启接单后自动弹出拨号盘。
          </Subtitle>
          <View style={{ height: spacing.md }} />
          <PrimaryButton label="立即检查队列" onPress={claimAndDial} disabled={busy || !config.apiKey} />
        </Card>
      )}

      {!!lastError && (
        <Card style={{ marginTop: spacing.md, borderColor: 'rgba(229,116,116,0.35)' }}>
          <Text style={{ color: colors.danger, fontFamily: 'DMSans_500Medium' }}>{lastError}</Text>
        </Card>
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  header: {
    marginBottom: spacing.lg,
    marginTop: spacing.sm,
  },
  acceptCard: {
    marginBottom: spacing.md,
    gap: spacing.md,
  },
  acceptRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  acceptTitle: {
    fontFamily: 'DMSans_700Bold',
    fontSize: 18,
    color: colors.text,
  },
  statsRow: {
    flexDirection: 'row',
    alignItems: 'center',
    flexWrap: 'wrap',
    gap: 10,
  },
  statPill: {
    minWidth: 72,
    backgroundColor: colors.bgSoft,
    borderRadius: radius.md,
    paddingVertical: 10,
    paddingHorizontal: 12,
  },
  statNum: {
    fontFamily: 'Fraunces_600SemiBold',
    fontSize: 22,
    color: colors.accent,
  },
  statLabel: {
    fontFamily: 'DMSans_400Regular',
    fontSize: 11,
    color: colors.textMuted,
    marginTop: 2,
  },
  jobCard: {
    gap: 8,
  },
  phone: {
    fontFamily: 'Fraunces_600SemiBold',
    fontSize: 34,
    color: colors.text,
    letterSpacing: -0.6,
    marginTop: 8,
  },
  company: {
    fontFamily: 'DMSans_700Bold',
    fontSize: 17,
    color: colors.text,
  },
  actions: {
    marginTop: spacing.md,
    gap: 10,
  },
  emptyCard: {
    gap: 4,
  },
  emptyTitle: {
    fontFamily: 'Fraunces_600SemiBold',
    fontSize: 22,
    color: colors.text,
  },
});
