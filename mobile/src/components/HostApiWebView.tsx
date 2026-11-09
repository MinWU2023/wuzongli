import React, { useEffect, useRef, useState } from 'react';
import { Platform, StyleSheet, View } from 'react-native';
import { WebView, type WebViewMessageEvent } from 'react-native-webview';
import { registerHostBridge, resolveHostResult } from '../hostApi';

type Props = {
  enabled?: boolean;
  /** Optional default API base to pre-warm as soon as WebView mounts. */
  apiBase?: string;
};

type WarmWaiter = {
  resolve: () => void;
  reject: (err: Error) => void;
  timer: ReturnType<typeof setTimeout>;
};

const CHROME_MOBILE_UA =
  'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36';

/**
 * Off-screen WebView loads /bridge, passes InfinityFree aes.js, then proxies API
 * calls with first-party cookies via window.__GLE_BRIDGE__.
 */
export function HostApiWebView({ enabled = true, apiBase = '' }: Props) {
  const ref = useRef<WebView>(null);
  const [uri, setUri] = useState('about:blank');
  const readyRef = useRef(false);
  const currentBase = useRef('');
  const warmWaiters = useRef<WarmWaiter[]>([]);
  const challengeHits = useRef(0);
  const lastChallengeAt = useRef(0);

  const clearWarmWaiters = (error?: Error) => {
    const list = warmWaiters.current.splice(0);
    list.forEach((item) => {
      clearTimeout(item.timer);
      try {
        if (error) item.reject(error);
        else item.resolve();
      } catch {
        // ignore
      }
    });
  };

  const markReady = () => {
    readyRef.current = true;
    challengeHits.current = 0;
    clearWarmWaiters();
  };

  const reloadBridge = (base: string) => {
    readyRef.current = false;
    currentBase.current = base;
    challengeHits.current = 0;
    setUri(base.replace(/\/+$/, '') + '/bridge?_=' + Date.now());
  };

  useEffect(() => {
    if (!enabled) {
      registerHostBridge(null);
      return;
    }

    registerHostBridge({
      warm: (baseUrl: string) =>
        new Promise<void>((resolve, reject) => {
          const base = baseUrl.replace(/\/+$/, '');
          const timer = setTimeout(() => {
            const idx = warmWaiters.current.findIndex((w) => w.resolve === resolve);
            if (idx >= 0) warmWaiters.current.splice(idx, 1);
            if (readyRef.current && currentBase.current === base) resolve();
            else
              reject(
                new Error(
                  '主机开启了浏览器安全校验(常见于InfinityFree)。请确认App已更新，或换可直连的主机。'
                )
              );
          }, 45000);

          warmWaiters.current.push({ resolve, reject, timer });

          if (currentBase.current === base && readyRef.current) {
            clearTimeout(timer);
            const idx = warmWaiters.current.findIndex((w) => w.resolve === resolve);
            if (idx >= 0) warmWaiters.current.splice(idx, 1);
            resolve();
            return;
          }

          reloadBridge(base);
        }),

      fetch: (req) =>
        new Promise((resolve, reject) => {
          const web = ref.current;
          if (!web) {
            reject(new Error('WebView 未就绪，请稍后重试'));
            return;
          }

          const script = `
            (function() {
              try {
                var bridge = window.__GLE_BRIDGE__;
                if (!bridge || typeof bridge.requestFromNative !== 'function') {
                  window.ReactNativeWebView.postMessage(JSON.stringify({
                    id: ${JSON.stringify(req.id)},
                    status: 0,
                    text: '',
                    error: 'bridge_missing'
                  }));
                  return true;
                }
                bridge.requestFromNative(${JSON.stringify({
                  id: req.id,
                  url: req.url,
                  method: req.method,
                  headers: req.headers || {},
                  body: req.body,
                  timeout: req.timeout,
                })});
              } catch (e) {
                window.ReactNativeWebView.postMessage(JSON.stringify({
                  id: ${JSON.stringify(req.id)},
                  status: 0,
                  text: '',
                  error: String((e && e.message) || e)
                }));
              }
              return true;
            })();
            true;
          `;

          const kick = () => {
            try {
              web.injectJavaScript(script);
              // Result arrives asynchronously via onMessage → resolveHostResult.
              resolve({ id: req.id });
            } catch (err) {
              reject(err);
            }
          };

          const deadline = Date.now() + 22000;
          const waitReady = () => {
            if (readyRef.current || Date.now() >= deadline) {
              setTimeout(kick, readyRef.current ? 40 : 200);
              return;
            }
            setTimeout(waitReady, 250);
          };
          waitReady();
        }),

      reload: (baseUrl: string) => {
        reloadBridge(baseUrl.replace(/\/+$/, ''));
      },
    });

    const base = String(apiBase || '')
      .trim()
      .replace(/\/+$/, '');
    if (base) {
      // Auto pre-warm after handlers are registered (avoids App effect race).
      setTimeout(() => {
        try {
          if (!readyRef.current || currentBase.current !== base) {
            reloadBridge(base);
          }
        } catch {
          // ignore
        }
      }, 80);
    }

    return () => {
      clearWarmWaiters(new Error('主机桥接已卸载'));
      registerHostBridge(null);
    };
  }, [enabled, apiBase]);

  const pokeBridge = () => {
    try {
      ref.current?.injectJavaScript(`
        (function(){
          try {
            var html = document.documentElement ? document.documentElement.innerHTML.slice(0, 4000) : '';
            var challenge = html.indexOf('/aes.js') !== -1
              || html.indexOf('function toNumbers') !== -1
              || (html.indexOf('__test') !== -1 && html.indexOf('document.cookie') !== -1);
            var hasBridge = !!(window.__GLE_BRIDGE__);
            if (challenge && !hasBridge) {
              window.ReactNativeWebView.postMessage(JSON.stringify({
                type: 'BRIDGE_CHALLENGE',
                at: Date.now(),
                href: String(location.href || '')
              }));
              return true;
            }
            if (hasBridge && window.__GLE_BRIDGE__.prove) {
              window.__GLE_BRIDGE__.prove();
            } else if (!hasBridge) {
              window.ReactNativeWebView.postMessage(JSON.stringify({
                type: 'BRIDGE_NO_SCRIPT',
                at: Date.now(),
                href: String(location.href || '')
              }));
            }
          } catch (e) {}
          true;
        })();
      `);
    } catch {
      // ignore
    }
  };

  const onMessage = (event: WebViewMessageEvent) => {
    try {
      const data = JSON.parse(event.nativeEvent.data);
      if (!data || typeof data !== 'object') return;

      if (data.type === 'BRIDGE_READY') {
        markReady();
        return;
      }
      if (data.type === 'BRIDGE_RELOADING') {
        readyRef.current = false;
        return;
      }
      if (data.type === 'BRIDGE_CHALLENGE' || data.type === 'BRIDGE_NO_SCRIPT') {
        const now = Date.now();
        if (now - lastChallengeAt.current > 2500) {
          challengeHits.current += 1;
          lastChallengeAt.current = now;
        }
        // aes.js should redirect itself; if stuck, force a fresh /bridge load.
        if (challengeHits.current >= 4 && currentBase.current) {
          challengeHits.current = 0;
          reloadBridge(currentBase.current);
        }
        return;
      }
      if (data.type === 'BRIDGE_RESULT' || data.id) {
        resolveHostResult(data);
      }
    } catch {
      // ignore
    }
  };

  if (!enabled) return null;

  return (
    <View style={styles.offscreen} pointerEvents="none" collapsable={false}>
      <WebView
        ref={ref}
        source={{ uri }}
        userAgent={CHROME_MOBILE_UA}
        applicationNameForUserAgent="Chrome/120.0.0.0"
        onLoadEnd={() => {
          // First paint is often InfinityFree aes.js; give it time to set __test + redirect.
          setTimeout(pokeBridge, 900);
          setTimeout(pokeBridge, 2200);
          setTimeout(pokeBridge, 4500);
        }}
        onNavigationStateChange={() => {
          // After aes.js location.href, re-check for bridge script.
          setTimeout(pokeBridge, 600);
        }}
        onMessage={onMessage}
        onContentProcessDidTerminate={() => {
          readyRef.current = false;
          if (currentBase.current) reloadBridge(currentBase.current);
        }}
        onRenderProcessGone={() => {
          readyRef.current = false;
          if (currentBase.current) reloadBridge(currentBase.current);
        }}
        javaScriptEnabled
        domStorageEnabled
        thirdPartyCookiesEnabled
        sharedCookiesEnabled
        setSupportMultipleWindows={false}
        originWhitelist={['*']}
        cacheEnabled={false}
        incognito={false}
        mixedContentMode="always"
        androidLayerType={Platform.OS === 'android' ? 'hardware' : undefined}
        allowsFullscreenVideo={false}
        mediaPlaybackRequiresUserAction
      />
    </View>
  );
}

const styles = StyleSheet.create({
  // Real viewport size off-screen: 1×1 / opacity:0 can skip JS on some OEM WebViews.
  offscreen: {
    position: 'absolute',
    width: 360,
    height: 640,
    left: -10000,
    top: 0,
    opacity: 0.02,
    overflow: 'hidden',
  },
});
