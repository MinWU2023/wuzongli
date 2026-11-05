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
  const [fontsLoaded] = useFonts({
    DMSans_400Regular,
    DMSans_500Medium,
    DMSans_700Bold,
    Fraunces_600SemiBold,
  });
  const [config, setConfig] = useState<AppConfig | null>(null);

  useEffect(() => {
    loadConfig().then(setConfig);
  }, []);

  const updateConfig = async (next: AppConfig) => {
    await saveConfig(next);
    setConfig(next);
  };

  if (!fontsLoaded || !config) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.bg }}>
        <ActivityIndicator color={colors.accent} />
        <Text style={{ color: colors.textMuted, marginTop: 12, fontFamily: 'System' }}>好线索启动中…</Text>
      </View>
    );
  }

  return (
    <SafeAreaProvider>
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
              fontFamily: 'DMSans_500Medium',
              fontSize: 11,
            },
          }}
        >
          <Tab.Screen name="Dial" options={{ title: '接单', tabBarIcon: ({ color }) => <TabGlyph label="☎" color={color} /> }}>
            {() => (
              <DialScreen
                config={config}
                onAcceptChange={(acceptOrders) => updateConfig({ ...config, acceptOrders })}
              />
            )}
          </Tab.Screen>
          <Tab.Screen name="Tasks" options={{ title: '任务', tabBarIcon: ({ color }) => <TabGlyph label="任" color={color} /> }}>
            {() => <TasksScreen config={config} />}
          </Tab.Screen>
          <Tab.Screen name="Companies" options={{ title: '企业', tabBarIcon: ({ color }) => <TabGlyph label="企" color={color} /> }}>
            {() => <CompaniesScreen config={config} />}
          </Tab.Screen>
          <Tab.Screen name="Settings" options={{ title: '设置', tabBarIcon: ({ color }) => <TabGlyph label="设" color={color} /> }}>
            {() => <SettingsScreen config={config} onSave={updateConfig} />}
          </Tab.Screen>
        </Tab.Navigator>
      </NavigationContainer>
    </SafeAreaProvider>
  );
}

function TabGlyph({ label, color }: { label: string; color: string }) {
  return (
    <Text style={{ color, fontSize: 16, fontFamily: 'DMSans_700Bold', marginTop: 2 }}>{label}</Text>
  );
}
