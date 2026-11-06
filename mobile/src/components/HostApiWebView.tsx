import React, { useEffect, useRef, useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { WebView, type WebViewMessageEvent } from 'react-native-webview';
import { registerHostBridge, resolveHostResult } from '../hostApi';

type Props = {
  enabled?: boolean;
};

/**
 * Hidden WebView: pass InfinityFree aes.js, then run API fetch with first-party cookies.
 */
export function HostApiWebView({ enabled = true }: Props) {
  const ref = useRef<WebView>(null);
  const [uri, setUri] = useState('about:blank');
  const readyRef = useRef(false);
  const currentBase = useRef('');
  const warmWaiters = useRef<Array<() => void>>([]);

  const flushWarm = () => {
    const list = warmWaiters.current.splice(0);
    list.forEach((fn) => {
      try {
        fn();
      } catch {
        // ignore
      }
    });
  };

  useEffect(() => {
    if (!enabled) {
      registerHostBridge(null);
      return;
    }

    registerHostBridge({
      warm: (baseUrl: string) =>
        new Promise<void>((resolve) => {
          const base = baseUrl.replace(/\/+$/, '');
          const warmUrl = base + '/admin/login';
          warmWaiters.current.push(resolve);

          if (currentBase.current === base && readyRef.current) {
            setTimeout(flushWarm, 350);
            return;
          }

          currentBase.current = base;
          readyRef.current = false;
          setUri(warmUrl);
          // Safety: never block forever if onLoadEnd never fires
          setTimeout(flushWarm, 16000);
        }),

      fetch: (req) =>
        new Promise((resolve, reject) => {
          const web = ref.current;
          if (!web) {
            reject(new Error('WebView 未就绪，请稍后重试'));
            return;
          }

          const script = `
            (async function() {
              var id = ${JSON.stringify(req.id)};
              try {
                var controller = new AbortController();
                var timer = setTimeout(function() { controller.abort(); }, ${Number(req.timeout) || 35000});
                var init = {
                  method: ${JSON.stringify(req.method)},
                  headers: ${JSON.stringify(req.headers || {})},
                  credentials: 'include',
                  cache: 'no-store',
                  signal: controller.signal
                };
                var body = ${JSON.stringify(req.body)};
                if (body !== null && init.method !== 'GET' && init.method !== 'HEAD') {
                  init.body = body;
                }
                var response = await fetch(${JSON.stringify(req.url)}, init);
                var text = await response.text();
                clearTimeout(timer);
                window.ReactNativeWebView.postMessage(JSON.stringify({
                  id: id, status: response.status, text: text
                }));
              } catch (e) {
                window.ReactNativeWebView.postMessage(JSON.stringify({
                  id: id, status: 0, text: '',
                  error: (e && e.name === 'AbortError') ? '请求超时' : String((e && e.message) || e)
                }));
              }
            })();
            true;
          `;

          setTimeout(() => {
            try {
              web.injectJavaScript(script);
              // Inject queued OK — real result arrives via onMessage → resolveHostResult
              resolve({ id: req.id });
            } catch (err) {
              reject(err);
            }
          }, readyRef.current ? 60 : 900);
        }),
    });

    return () => registerHostBridge(null);
  }, [enabled]);

  const onMessage = (event: WebViewMessageEvent) => {
    try {
      const data = JSON.parse(event.nativeEvent.data);
      if (data && data.id) resolveHostResult(data);
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
          readyRef.current = true;
          // Give aes.js time to set __test cookie then finish warm
          setTimeout(flushWarm, 1400);
        }}
        onMessage={onMessage}
        javaScriptEnabled
        domStorageEnabled
        thirdPartyCookiesEnabled
        sharedCookiesEnabled
        setSupportMultipleWindows={false}
        originWhitelist={['*']}
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
