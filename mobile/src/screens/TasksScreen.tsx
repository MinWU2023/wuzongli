import React, { useCallback, useEffect, useState } from 'react';
import {
  Alert,
  FlatList,
  Modal,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  Switch,
  Text,
  View,
} from 'react-native';
import {
  createTask,
  fetchRunner,
  getTask,
  listTasks,
  resumeTask,
  rewindTask,
  setRunner,
  stopTask,
  updateTask,
} from '../api';
import { Badge, Card, Field, LoadingBlock, Muted, PrimaryButton, Screen, Title } from '../components/ui';
import { colors, radius, spacing } from '../theme';
import type { AppConfig, PipelineTask } from '../types';

type Props = { config: AppConfig };

const STAGE_LABEL: Record<string, string> = {
  keywords: '关键词',
  serp: 'Google',
  classify: 'AI筛选',
  discover: '页面识别',
  extract: '联系提取',
  phone: '电话整理',
  done: '完成',
};

const STATUS_LABEL: Record<string, string> = {
  active: '运行中',
  paused: '已暂停',
  failed: '异常',
  completed: '已完成',
  stopped: '已终止',
};

// 关键词数 6–60；页数 0 表示自动翻页（服务端上限 30 页）
function parseKeywords(value: string): number {
  const n = Math.floor(Number(value));
  return Number.isFinite(n) && n > 0 ? Math.min(120, Math.max(6, n)) : 48;
}

function parsePages(value: string): number {
  if (value.trim() === '') return 3;
  const n = Math.floor(Number(value));
  return Number.isFinite(n) && n >= 0 ? Math.min(30, n) : 3;
}

const REWIND_STAGES = [
  { value: 'keywords', label: '01·关键词' },
  { value: 'serp', label: '02·Google' },
  { value: 'classify', label: '03·AI筛选' },
  { value: 'discover', label: '04·页面识别' },
  { value: 'extract', label: '05·联系方式' },
  { value: 'phone', label: '06·电话标准化' },
];

export function TasksScreen({ config }: Props) {
  const [tasks, setTasks] = useState<PipelineTask[]>([]);
  const [runnerOn, setRunnerOn] = useState(true);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [createOpen, setCreateOpen] = useState(false);
  const [detail, setDetail] = useState<PipelineTask | null>(null);
  const [editOpen, setEditOpen] = useState(false);
  const [rewindOpen, setRewindOpen] = useState(false);

  const [city, setCity] = useState('');
  const [market, setMarket] = useState('全球');
  const [keywordCount, setKeywordCount] = useState('24');
  const [pagesTarget, setPagesTarget] = useState('3');
  const [rewindStage, setRewindStage] = useState('keywords');

  const load = useCallback(async () => {
    if (!config.apiKey) {
      setError('请先到设置填写 API Key');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const [taskData, runner] = await Promise.all([
        listTasks(config.apiBase, config.apiKey),
        fetchRunner(config.apiBase, config.apiKey),
      ]);
      setTasks(taskData.tasks || []);
      setRunnerOn(runner.enabled !== false);
    } catch (err) {
      setError(err instanceof Error ? err.message : '加载失败');
    } finally {
      setLoading(false);
    }
  }, [config.apiBase, config.apiKey]);

  useEffect(() => {
    load();
  }, [load]);

  const toggleRunner = async (enabled: boolean) => {
    try {
      await setRunner(config.apiBase, config.apiKey, enabled);
      setRunnerOn(enabled);
      Alert.alert(enabled ? '已开启' : '已关闭', enabled ? '插件将排队执行任务' : '插件会即时停止自动执行');
    } catch (err) {
      Alert.alert('切换失败', err instanceof Error ? err.message : '请重试');
    }
  };

  const openCreate = () => {
    setCity('');
    setMarket('全球');
    setKeywordCount('24');
    setPagesTarget('3');
    setCreateOpen(true);
  };

  const submitCreate = async () => {
    if (!city.trim()) {
      Alert.alert('请填写城市');
      return;
    }
    try {
      await createTask(config.apiBase, config.apiKey, {
        city: city.trim(),
        target_market: market.trim() || '全球',
        keyword_count: parseKeywords(keywordCount),
        pages_target: parsePages(pagesTarget),
      });
      setCreateOpen(false);
      await load();
      Alert.alert('已创建', '任务已进入队列；开启「插件自动跑」后扩展会排队执行。');
    } catch (err) {
      Alert.alert('创建失败', err instanceof Error ? err.message : '请重试');
    }
  };

  const openDetail = async (task: PipelineTask) => {
    try {
      const data = await getTask(config.apiBase, config.apiKey, task.id);
      setDetail(data.task);
    } catch {
      setDetail(task);
    }
  };

  const openEdit = (task: PipelineTask) => {
    setDetail(task);
    setCity(task.city || '');
    setMarket(task.target_market || '全球');
    setKeywordCount(String(task.keyword_count || 24));
    setPagesTarget(String(task.pages_target ?? 3));
    setEditOpen(true);
  };

  const submitEdit = async () => {
    if (!detail) return;
    try {
      await updateTask(config.apiBase, config.apiKey, detail.id, {
        city: city.trim(),
        target_market: market.trim() || '全球',
        keyword_count: parseKeywords(keywordCount),
        pages_target: parsePages(pagesTarget),
      });
      setEditOpen(false);
      await load();
      Alert.alert('已保存', '任务资料已更新');
    } catch (err) {
      Alert.alert('保存失败', err instanceof Error ? err.message : '请重试');
    }
  };

  const confirmStop = (task: PipelineTask) => {
    Alert.alert('终止任务', `确定终止「${task.city}」？插件会立即停止该任务。`, [
      { text: '取消', style: 'cancel' },
      {
        text: '终止',
        style: 'destructive',
        onPress: async () => {
          try {
            await stopTask(config.apiBase, config.apiKey, task.id);
            await load();
          } catch (err) {
            Alert.alert('失败', err instanceof Error ? err.message : '请重试');
          }
        },
      },
    ]);
  };

  const confirmResume = async (task: PipelineTask) => {
    try {
      await resumeTask(config.apiBase, config.apiKey, task.id);
      await load();
      Alert.alert('已恢复', '任务重新进入排队');
    } catch (err) {
      Alert.alert('失败', err instanceof Error ? err.message : '请重试');
    }
  };

  const openRewind = (task: PipelineTask) => {
    setDetail(task);
    setCity(task.city || '');
    setMarket(task.target_market || '全球');
    setKeywordCount(String(task.keyword_count || 24));
    setPagesTarget(String(task.pages_target ?? 3));
    setRewindStage('keywords');
    setRewindOpen(true);
  };

  const submitRewind = async () => {
    if (!detail) return;
    try {
      await rewindTask(config.apiBase, config.apiKey, detail.id, {
        stage: rewindStage,
        city: city.trim(),
        target_market: market.trim() || '全球',
        keyword_count: parseKeywords(keywordCount),
        pages_target: parsePages(pagesTarget),
      });
      setRewindOpen(false);
      await load();
      Alert.alert('已回退', `任务已回退到「${STAGE_LABEL[rewindStage] || rewindStage}」并重新排队`);
    } catch (err) {
      Alert.alert('回退失败', err instanceof Error ? err.message : '请重试');
    }
  };

  const statusTone = (status?: string) => {
    if (status === 'active') return 'accent' as const;
    if (status === 'paused') return 'warn' as const;
    return 'muted' as const;
  };

  return (
    <Screen style={{ paddingBottom: 0 }}>
      <View style={styles.header}>
        <View style={{ flex: 1 }}>
          <Title>任务管理</Title>
          <Muted>创建 / 编辑 / 终止 / 回退；开关控制插件是否排队执行</Muted>
        </View>
        <PrimaryButton label="新建" onPress={openCreate} />
      </View>

      <Card style={{ marginBottom: spacing.md }}>
        <View style={styles.runnerRow}>
          <View style={{ flex: 1 }}>
            <Text style={styles.runnerTitle}>{runnerOn ? '插件自动跑：开' : '插件自动跑：关'}</Text>
            <Muted>{runnerOn ? '扩展会排队执行 active 任务' : '扩展即时停止，不再自动推进'}</Muted>
          </View>
          <Switch
            value={runnerOn}
            onValueChange={toggleRunner}
            trackColor={{ false: '#C9D7E8', true: colors.accentDeep }}
            thumbColor={runnerOn ? colors.accent : '#FFFFFF'}
          />
        </View>
      </Card>

      {!!error && (
        <Card style={{ marginBottom: spacing.md, borderColor: 'rgba(224,91,91,0.35)' }}>
          <Text style={{ color: colors.danger }}>{error}</Text>
        </Card>
      )}

      {loading && tasks.length === 0 ? (
        <LoadingBlock label="加载任务列表…" />
      ) : (
        <FlatList
          data={tasks}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ paddingBottom: 48, gap: 12 }}
          refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={colors.accent} />}
          ListEmptyComponent={
            <Card>
              <Text style={styles.emptyTitle}>还没有任务</Text>
              <Muted>点右上角「新建」创建城市任务，插件会按队列自动跑。</Muted>
            </Card>
          }
          renderItem={({ item }) => (
            <Card>
              <Pressable onPress={() => openDetail(item)}>
                <View style={styles.rowTop}>
                  <View style={{ flex: 1 }}>
                    <Text style={styles.name}>{item.city}</Text>
                    <Muted>
                      #{item.id} · {STAGE_LABEL[item.stage || ''] || item.stage || '—'} · 进度 {item.progress || 0}%
                    </Muted>
                  </View>
                  <Badge label={STATUS_LABEL[item.status || ''] || item.status || '—'} tone={statusTone(item.status)} />
                </View>
              </Pressable>
              <View style={styles.actions}>
                <Pressable style={styles.chip} onPress={() => openEdit(item)}><Text style={styles.chipText}>编辑</Text></Pressable>
                {item.status === 'paused' || item.status === 'failed' ? (
                  <Pressable style={styles.chip} onPress={() => confirmResume(item)}><Text style={styles.chipText}>恢复</Text></Pressable>
                ) : (
                  <Pressable style={[styles.chip, styles.chipDanger]} onPress={() => confirmStop(item)}>
                    <Text style={[styles.chipText, { color: colors.danger }]}>终止</Text>
                  </Pressable>
                )}
                <Pressable style={styles.chip} onPress={() => openRewind(item)}><Text style={styles.chipText}>回退</Text></Pressable>
                <Pressable style={styles.chip} onPress={() => openDetail(item)}><Text style={styles.chipText}>详情</Text></Pressable>
              </View>
            </Card>
          )}
        />
      )}

      <FormModal
        visible={createOpen}
        title="新建任务"
        city={city}
        market={market}
        keywordCount={keywordCount}
        pagesTarget={pagesTarget}
        onCity={setCity}
        onMarket={setMarket}
        onKeywords={setKeywordCount}
        onPages={setPagesTarget}
        onClose={() => setCreateOpen(false)}
        onSubmit={submitCreate}
        submitLabel="创建并排队"
      />

      <FormModal
        visible={editOpen}
        title="编辑任务"
        city={city}
        market={market}
        keywordCount={keywordCount}
        pagesTarget={pagesTarget}
        onCity={setCity}
        onMarket={setMarket}
        onKeywords={setKeywordCount}
        onPages={setPagesTarget}
        onClose={() => setEditOpen(false)}
        onSubmit={submitEdit}
        submitLabel="保存"
      />

      <Modal visible={rewindOpen} animationType="slide" transparent onRequestClose={() => setRewindOpen(false)}>
        <View style={styles.modalMask}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>回退任务</Text>
            <Muted>清理后续阶段数据，从所选步骤重新跑</Muted>
            <View style={{ height: spacing.md }} />
            {REWIND_STAGES.map((stage) => (
              <Pressable
                key={stage.value}
                style={[styles.stageRow, rewindStage === stage.value && styles.stageRowActive]}
                onPress={() => setRewindStage(stage.value)}
              >
                <Text style={styles.stageText}>{stage.label}</Text>
              </Pressable>
            ))}
            <View style={{ height: spacing.md }} />
            <Field label="城市" value={city} onChangeText={setCity} />
            <Field label="目标市场" value={market} onChangeText={setMarket} />
            <PrimaryButton label="确认回退并重新排队" onPress={submitRewind} />
            <View style={{ height: 10 }} />
            <PrimaryButton label="取消" tone="ghost" onPress={() => setRewindOpen(false)} />
          </View>
        </View>
      </Modal>

      <Modal visible={!!detail && !editOpen && !rewindOpen} animationType="fade" transparent onRequestClose={() => setDetail(null)}>
        <View style={styles.modalMask}>
          <View style={styles.modalCard}>
            <Text style={styles.modalTitle}>{detail?.city}</Text>
            <Muted>#{detail?.id} · {detail?.status} · {STAGE_LABEL[detail?.stage || ''] || detail?.stage}</Muted>
            <View style={{ height: 12 }} />
            <Muted>市场：{detail?.target_market || '—'}</Muted>
            <Muted>关键词数：{detail?.keyword_count ?? '—'}</Muted>
            <Muted>Google 页数：{detail?.pages_target ?? '—'}</Muted>
            <Muted>进度：{detail?.progress ?? 0}%</Muted>
            {!!detail?.error_message && <Text style={{ color: colors.danger, marginTop: 8 }}>{detail.error_message}</Text>}
            <View style={{ height: spacing.md }} />
            <PrimaryButton label="关闭" tone="ghost" onPress={() => setDetail(null)} />
          </View>
        </View>
      </Modal>
    </Screen>
  );
}

function FormModal(props: {
  visible: boolean;
  title: string;
  city: string;
  market: string;
  keywordCount: string;
  pagesTarget: string;
  onCity: (v: string) => void;
  onMarket: (v: string) => void;
  onKeywords: (v: string) => void;
  onPages: (v: string) => void;
  onClose: () => void;
  onSubmit: () => void;
  submitLabel: string;
}) {
  return (
    <Modal visible={props.visible} animationType="slide" transparent onRequestClose={props.onClose}>
      <View style={styles.modalMask}>
        <ScrollView contentContainerStyle={styles.modalCard}>
          <Text style={styles.modalTitle}>{props.title}</Text>
          <Field label="城市" value={props.city} onChangeText={props.onCity} placeholder="例如：武汉" />
          <Field label="目标市场" value={props.market} onChangeText={props.onMarket} placeholder="全球" />
          <Field label="关键词数量" value={props.keywordCount} onChangeText={props.onKeywords} keyboardType="number-pad" />
          <Field label="每个关键词 Google 页数（0 = 自动翻页）" value={props.pagesTarget} onChangeText={props.onPages} keyboardType="number-pad" />
          <PrimaryButton label={props.submitLabel} onPress={props.onSubmit} />
          <View style={{ height: 10 }} />
          <PrimaryButton label="取消" tone="ghost" onPress={props.onClose} />
        </ScrollView>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  header: {
    marginTop: spacing.sm,
    marginBottom: spacing.md,
    flexDirection: 'row',
    alignItems: 'flex-start',
    gap: 12,
  },
  runnerRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  runnerTitle: {
    fontFamily: 'DMSans_700Bold',
    fontSize: 16,
    color: colors.text,
  },
  rowTop: {
    flexDirection: 'row',
    gap: 10,
    alignItems: 'flex-start',
  },
  name: {
    fontFamily: 'DMSans_700Bold',
    fontSize: 17,
    color: colors.text,
  },
  actions: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: 8,
    marginTop: 12,
  },
  chip: {
    backgroundColor: colors.bgSoft,
    borderRadius: radius.pill,
    paddingHorizontal: 12,
    paddingVertical: 8,
  },
  chipDanger: {
    backgroundColor: 'rgba(224,91,91,0.12)',
  },
  chipText: {
    fontFamily: 'DMSans_500Medium',
    fontSize: 12,
    color: colors.accentDeep,
  },
  emptyTitle: {
    fontFamily: 'Fraunces_600SemiBold',
    fontSize: 20,
    color: colors.text,
    marginBottom: 6,
  },
  modalMask: {
    flex: 1,
    backgroundColor: 'rgba(10, 24, 48, 0.45)',
    justifyContent: 'flex-end',
  },
  modalCard: {
    backgroundColor: colors.bgElevated,
    borderTopLeftRadius: 24,
    borderTopRightRadius: 24,
    padding: 20,
    paddingBottom: 36,
    maxHeight: '90%',
  },
  modalTitle: {
    fontFamily: 'Fraunces_600SemiBold',
    fontSize: 24,
    color: colors.text,
    marginBottom: 4,
  },
  stageRow: {
    paddingVertical: 12,
    paddingHorizontal: 12,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.line,
    marginBottom: 8,
  },
  stageRowActive: {
    borderColor: colors.accent,
    backgroundColor: colors.accentSoft,
  },
  stageText: {
    fontFamily: 'DMSans_500Medium',
    color: colors.text,
  },
});
