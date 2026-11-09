import {
  DMSans_400Regular,
  DMSans_500Medium,
  DMSans_700Bold,
} from '@expo-google-fonts/dm-sans';
import { Fraunces_600SemiBold } from '@expo-google-fonts/fraunces';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { NavigationContainer, DarkTheme } from '@react-navigation/native';
import { useFonts } from 'expo-font';
import { StatusBar } from 'expo-status-bar';
import React, { useEffect, useState } from 'react';
import { ActivityIndicator, Text, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { HostApiWebView } from './src/components/HostApiWebView';
import { warmHost } from './src/hostApi';
import { CompaniesScreen } from './src/screens/CompaniesScreen';
import { DialScreen } from './src/screens/DialScreen';
import { SettingsScreen } from './src/screens/SettingsScreen';
import { TasksScreen } from './src/screens/TasksScreen';
import { loadConfig, saveConfig } from './src/storage';
import { colors } from './src/theme';
import type { AppConfig } from './src/types';

const Tab = createBottomTabNavigator();

const navTheme = {
  ...DarkTheme,
  dark: false,
  colors: {
    ...DarkTheme.colors,
    background: colors.bg,
    card: colors.bgElevated,
    text: colors.text,
    border: colors.line,
    primary: colors.accent,
    notification: colors.accent,
  },
};

export default function App() {
  const [fontsLoaded, fontError] = useFonts({
    DMSans_400Regular,
    DMSans_500Medium,
    DMSans_700Bold,
    Fraunces_600SemiBold,
  });
  const [config, setConfig] = useState<AppConfig | null>(null);
  const [fontWaitTimedOut, setFontWaitTimedOut] = useState(false);

  useEffect(() => {
    let alive = true;
    // 本地存储在部分 release 包里可能永不返回，3 秒后用默认配置继续启动，避免一直卡在启动页
    const timeout = new Promise<AppConfig>((resolve) =>
      setTimeout(
        () =>
          resolve({
            apiBase: 'https://google.yzz.me',
            apiKey: '',
            deviceId: 'm-' + Math.random().toString(36).slice(2, 10),
            acceptOrders: false,
            aiBaseUrl: '',
            aiModel: 'gpt-4o-mini',
          }),
        3000
      )
    );
    Promise.race([loadConfig(), timeout])
      .then((cfg) => {
        if (alive) setConfig(cfg);
      })
      .catch(() => {
        if (alive) {
          setConfig({
            apiBase: 'https://google.yzz.me',
            apiKey: '',
            deviceId: 'm-fallback',
            acceptOrders: false,
            aiBaseUrl: '',
            aiModel: 'gpt-4o-mini',
          });
        }
      });
    return () => {
      alive = false;
    };
  }, []);

  // release 包字体偶发永不回调，超时后继续进主界面
  useEffect(() => {
    const timer = setTimeout(() => setFontWaitTimedOut(true), 2500);
    return () => clearTimeout(timer);
  }, []);

  const updateConfig = async (next: AppConfig) => {
    await saveConfig(next);
    setConfig(next);
  };

  // Pre-warm InfinityFree /bridge as soon as API base is known.
  useEffect(() => {
    const base = String(config?.apiBase || '').trim();
    if (!base) return;
    warmHost(base).catch(() => {
      // First API call will retry warm.
    });
  }, [config?.apiBase]);

  const fontsReady = fontsLoaded || !!fontError || fontWaitTimedOut;
  const booting = !fontsReady || !config;

  return (
    <SafeAreaProvider>
      {/* InfinityFree aes.js：隐藏 WebView 过主机校验后再调 API */}
      <HostApiWebView enabled />
      {booting ? (
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg }}>
          <ActivityIndicator color={colors.accent} />
          <Text style={{ color: colors.textMuted, marginTop: 12 }}>好线索启动中…</Text>
        </View>
      ) : (
        <NavigationContainer theme={navTheme}>
          <StatusBar style="dark" />
          <Tab.Navigator
            screenOptions={{
              headerShown: false,
              tabBarStyle: {
                backgroundColor: colors.bgElevated,
                borderTopColor: colors.line,
                height: 64,
                paddingBottom: 8,
                paddingTop: 8,
              },
              tabBarActiveTintColor: colors.accent,
              tabBarInactiveTintColor: colors.textDim,
              tabBarLabelStyle: {
                fontFamily: fontsLoaded ? 'DMSans_500Medium' : undefined,
                fontSize: 11,
              },
            }}
          >
            <Tab.Screen name="Dial" options={{ title: '接单', tabBarIcon: ({ color }) => <TabGlyph label="☎" color={color} fontsLoaded={fontsLoaded} /> }}>
              {() => (
                <DialScreen
                  config={config!}
                  onAcceptChange={(acceptOrders) => updateConfig({ ...config!, acceptOrders })}
                />
              )}
            </Tab.Screen>
            <Tab.Screen name="Tasks" options={{ title: '任务', tabBarIcon: ({ color }) => <TabGlyph label="任" color={color} fontsLoaded={fontsLoaded} /> }}>
              {() => <TasksScreen config={config!} />}
            </Tab.Screen>
            <Tab.Screen name="Companies" options={{ title: '企业', tabBarIcon: ({ color }) => <TabGlyph label="企" color={color} fontsLoaded={fontsLoaded} /> }}>
              {() => <CompaniesScreen config={config!} />}
            </Tab.Screen>
            <Tab.Screen name="Settings" options={{ title: '设置', tabBarIcon: ({ color }) => <TabGlyph label="设" color={color} fontsLoaded={fontsLoaded} /> }}>
              {() => <SettingsScreen config={config!} onSave={updateConfig} />}
            </Tab.Screen>
          </Tab.Navigator>
        </NavigationContainer>
      )}
    </SafeAreaProvider>
  );
}

function TabGlyph({
  label,
  color,
  fontsLoaded,
}: {
  label: string;
  color: string;
  fontsLoaded: boolean;
}) {
  return (
    <Text
      style={{
        color,
        fontSize: 16,
        fontFamily: fontsLoaded ? 'DMSans_700Bold' : undefined,
        fontWeight: fontsLoaded ? undefined : '700',
        marginTop: 2,
      }}
    >
      {label}
    </Text>
  );
}
