import React, { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { WebView, type WebViewMessageEvent } from 'react-native-webview';
import { registerHostBridge, resolveHostResult } from '../hostApi';

type Props = {
  enabled?: boolean;
};

type WarmWaiter = {
  resolve: () => void;
  reject: (err: Error) => void;
  timer: ReturnType<typeof setTimeout>;
};

/**
 * Hidden WebView loads /bridge, passes InfinityFree aes.js, then proxies API
 * calls with first-party cookies via window.__GLE_BRIDGE__.
 */
export function HostApiWebView({ enabled = true }: Props) {
  const ref = useRef<WebView>(null);
  const [uri, setUri] = useState('about:blank');
  const readyRef = useRef(false);
  const currentBase = useRef('');
  const warmWaiters = useRef<WarmWaiter[]>([]);
  const loadGeneration = useRef(0);

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
    clearWarmWaiters();
  };

  const reloadBridge = (base: string) => {
    readyRef.current = false;
    currentBase.current = base;
    loadGeneration.current += 1;
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
            else reject(new Error('主机桥接预热超时，请确认已部署 /bridge 页面'));
          }, 28000);

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

          if (readyRef.current) {
            setTimeout(kick, 40);
          } else {
            setTimeout(kick, 1200);
          }
        }),

      reload: (baseUrl: string) => {
        reloadBridge(baseUrl.replace(/\/+$/, ''));
      },
    });

    return () => {
      clearWarmWaiters(new Error('主机桥接已卸载'));
      registerHostBridge(null);
    };
  }, [enabled]);

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
      if (data.type === 'BRIDGE_RESULT' || data.id) {
        resolveHostResult(data);
      }
    } catch {
      // ignore
    }
  };

  if (!enabled) return null;

  return (
    <View style={styles.hidden} pointerEvents="none">
      <WebView
        ref={ref}
        source={{ uri }}
        onLoadEnd={() => {
          // Bridge script posts BRIDGE_READY after /api/health proves JSON.
          // Fallback: if page loaded but message missed, poke prove().
          setTimeout(() => {
            if (readyRef.current) return;
            try {
              ref.current?.injectJavaScript(`
                (function(){
                  try {
                    if (window.__GLE_BRIDGE__ && window.__GLE_BRIDGE__.prove) {
                      window.__GLE_BRIDGE__.prove();
                    }
                  } catch (e) {}
                  true;
                })();
              `);
            } catch {
              // ignore
            }
          }, 1600);
        }}
        onMessage={onMessage}
        javaScriptEnabled
        domStorageEnabled
        thirdPartyCookiesEnabled
        sharedCookiesEnabled
        setSupportMultipleWindows={false}
        originWhitelist={['*']}
        cacheEnabled={false}
        incognito={false}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  hidden: {
    position: 'absolute',
    width: 1,
    height: 1,
    opacity: 0,
    overflow: 'hidden',
  },
});
