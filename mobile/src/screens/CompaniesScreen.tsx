import React, { useCallback, useEffect, useState } from 'react';
import {
  FlatList,
  Linking,
  Pressable,
  RefreshControl,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import { searchCompanies } from '../api';
import { Badge, Card, Field, LoadingBlock, Muted, PrimaryButton, Screen, Title } from '../components/ui';
import { colors, radius, spacing } from '../theme';
import type { AppConfig, Company } from '../types';

type Props = {
  config: AppConfig;
};

export function CompaniesScreen({ config }: Props) {
  const [q, setQ] = useState('');
  const [city, setCity] = useState('');
  const [company, setCompany] = useState('');
  const [phone, setPhone] = useState('');
  const [rows, setRows] = useState<Company[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [showFilters, setShowFilters] = useState(false);

  const load = useCallback(async () => {
    if (!config.apiKey) {
      setError('请先到设置填写 API Key');
      return;
    }
    setLoading(true);
    setError('');
    try {
      const data = await searchCompanies(config.apiBase, config.apiKey, {
        q,
        city,
        company,
        phone,
        page: 1,
        limit: 50,
      });
      setRows(data.companies || []);
      setTotal(Number(data.total || 0));
    } catch (err) {
      setError(err instanceof Error ? err.message : '加载失败');
    } finally {
      setLoading(false);
    }
  }, [city, company, config.apiBase, config.apiKey, phone, q]);

  useEffect(() => {
    load();
  }, []);

  const dial = async (value?: string | null) => {
    if (!value) return;
    const cleaned = value.replace(/[^\d+]/g, '');
    await Linking.openURL(`tel:${cleaned}`);
  };

  return (
    <Screen style={{ paddingBottom: 0 }}>
      <View style={styles.header}>
        <Title>企业线索</Title>
        <Muted>共 {total} 条 · 可按城市 / 公司 / 手机号筛选</Muted>
      </View>

      <Field
        label="快速搜索"
        value={q}
        onChangeText={setQ}
        placeholder="公司、城市、电话…"
        returnKeyType="search"
        onSubmitEditing={load}
      />

      <View style={styles.filterBar}>
        <Pressable onPress={() => setShowFilters((v) => !v)}>
          <Text style={styles.filterToggle}>{showFilters ? '收起筛选' : '高级筛选'}</Text>
        </Pressable>
        <PrimaryButton label="搜索" onPress={load} />
      </View>

      {showFilters && (
        <Card style={{ marginBottom: spacing.md }}>
          <Field label="城市" value={city} onChangeText={setCity} placeholder="例如：武汉" />
          <Field label="公司名" value={company} onChangeText={setCompany} placeholder="公司或域名" />
          <Field label="手机号" value={phone} onChangeText={setPhone} placeholder="号码片段" keyboardType="phone-pad" />
        </Card>
      )}

      {!!error && (
        <Card style={{ marginBottom: spacing.md, borderColor: 'rgba(229,116,116,0.35)' }}>
          <Text style={{ color: colors.danger }}>{error}</Text>
        </Card>
      )}

      {loading && rows.length === 0 ? (
        <LoadingBlock label="正在拉取企业列表…" />
      ) : (
        <FlatList
          data={rows}
          keyExtractor={(item) => String(item.id)}
          contentContainerStyle={{ paddingBottom: 40, gap: 12 }}
          refreshControl={<RefreshControl refreshing={loading} onRefresh={load} tintColor={colors.accent} />}
          ListEmptyComponent={
            <Card>
              <Text style={styles.emptyTitle}>暂无匹配企业</Text>
              <Muted>换个关键词，或先在后台任务里完成联系方式提取。</Muted>
            </Card>
          }
          renderItem={({ item }) => (
            <Card style={styles.row}>
              <View style={styles.rowTop}>
                <View style={{ flex: 1 }}>
                  <Text style={styles.name}>{item.company_name || item.domain || '未命名企业'}</Text>
                  <Muted>{[item.city, item.domain].filter(Boolean).join(' · ')}</Muted>
                </View>
                {!!item.city && <Badge label={String(item.city)} tone="muted" />}
              </View>
              {!!item.phone && (
                <Pressable onPress={() => dial(item.phone)} style={styles.phoneChip}>
                  <Text style={styles.phoneText}>{item.phone}</Text>
                  <Text style={styles.dialHint}>点击拨打</Text>
                </Pressable>
              )}
              {!!item.email && <Muted>{item.email}</Muted>}
              {!!item.address && <Muted>{item.address}</Muted>}
            </Card>
          )}
        />
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  header: {
    marginTop: spacing.sm,
    marginBottom: spacing.md,
  },
  filterBar: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.md,
    gap: 12,
  },
  filterToggle: {
    fontFamily: 'DMSans_500Medium',
    color: colors.accent,
    fontSize: 14,
  },
  row: {
    gap: 8,
  },
  rowTop: {
    flexDirection: 'row',
    gap: 10,
    alignItems: 'flex-start',
  },
  name: {
    fontFamily: 'DMSans_700Bold',
    fontSize: 16,
    color: colors.text,
    marginBottom: 2,
  },
  phoneChip: {
    marginTop: 4,
    alignSelf: 'flex-start',
    backgroundColor: colors.accentSoft,
    borderRadius: radius.md,
    paddingHorizontal: 12,
    paddingVertical: 10,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
  },
  phoneText: {
    fontFamily: 'Fraunces_600SemiBold',
    fontSize: 18,
    color: colors.accent,
  },
  dialHint: {
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
});
