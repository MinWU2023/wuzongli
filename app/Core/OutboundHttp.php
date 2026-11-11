<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Outbound cURL helpers.
 *
 * Local V2ray / system proxy often hijacks PHP curl and breaks AI relays that
 * are reachable without a VPN. Google browsing still uses the browser proxy.
 */
final class OutboundHttp
{
    /**
     * @param \CurlHandle|resource $ch
     * @param array{mode?:string, proxy?:string} $options mode=direct|system|proxy
     */
    public static function apply($ch, array $options = []): void
    {
        $mode = (string) ($options['mode'] ?? 'direct');
        $proxy = trim((string) ($options['proxy'] ?? ''));

        if ($mode === 'system' && $proxy === '') {
            // Keep libcurl / env / Windows system proxy as-is.
            return;
        }

        // Clear env-inherited proxy. Empty CURLOPT_PROXY alone is not enough on
        // some Windows builds when HTTP(S)_PROXY is set.
        curl_setopt($ch, CURLOPT_PROXY, '');
        curl_setopt($ch, CURLOPT_NOPROXY, '*');
        if (defined('CURLOPT_PROXYTYPE')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        }

        if ($proxy === '') {
            return;
        }

        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_NOPROXY, '');
        if (preg_match('#^socks5h?://#i', $proxy) && defined('CURLPROXY_SOCKS5')) {
            $type = (str_contains(strtolower($proxy), 'socks5h') && defined('CURLPROXY_SOCKS5_HOSTNAME'))
                ? CURLPROXY_SOCKS5_HOSTNAME
                : CURLPROXY_SOCKS5;
            curl_setopt($ch, CURLOPT_PROXYTYPE, $type);
        }
    }

    /**
     * AI 默认直连；填写 ai_http_proxy 时走专用代理；关闭「AI 出站直连」则沿用系统代理。
     *
     * @return array{mode:string, proxy:string}
     */
    public static function aiOptions(): array
    {
        $proxy = self::setting('ai_http_proxy', '');
        if ($proxy !== '') {
            return ['mode' => 'proxy', 'proxy' => $proxy];
        }
        if (self::settingBool('ai_force_direct', true)) {
            return ['mode' => 'direct', 'proxy' => ''];
        }
        return ['mode' => 'system', 'proxy' => ''];
    }

    /**
     * Serper 默认直连；填写 serper_http_proxy 时走专用代理。
     *
     * @return array{mode:string, proxy:string}
     */
    public static function serperOptions(): array
    {
        $proxy = self::setting('serper_http_proxy', '');
        if ($proxy !== '') {
            return ['mode' => 'proxy', 'proxy' => $proxy];
        }
        return ['mode' => 'direct', 'proxy' => ''];
    }

    private static function setting(string $key, string $default = ''): string
    {
        if (!class_exists(\App\Services\Settings::class)) {
            return $default;
        }
        return trim((string) \App\Services\Settings::get($key, $default));
    }

    private static function settingBool(string $key, bool $default = false): bool
    {
        if (!class_exists(\App\Services\Settings::class)) {
            return $default;
        }
        return \App\Services\Settings::bool($key, $default);
    }
}
