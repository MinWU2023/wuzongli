# 好线索 · Mobile

Expo（React Native）App「好线索」：对接 `https://google.yzz.me`，领取 Chrome 插件推送的拨号任务并唤起系统电话；同时浏览/搜索企业线索。浅蓝色科技图标见 `assets/icon.png`。

## 功能

- **接单开关**：开启后每 **3 秒** 调用 `GET /api/dial/next` 领取待拨号码，自动 `tel:` 打开拨号盘
- **企业列表**：`GET /api/companies`，支持城市 / 公司名 / 手机号 / 关键词搜索
- **设置**：API 地址（默认 `https://google.yzz.me`）与后台 API Key

## 配合服务端

1. 部署最新 PHP 代码，执行迁移：

```bash
php migrate.php up
```

确认生成表 `gl_dial_jobs`（前缀以你的配置为准）。

2. Chrome 插件企业列表点击 **拨号** → `POST /api/dial/enqueue`
3. App 开启接单 → 领取并拨号 → 标记完成/失败

## 本地运行

```bash
cd mobile
npm install
npx expo start
```

用 Expo Go 扫码，或按 `a` 开 Android 模拟器。真机拨号需在实体设备上验证。

## 用 GitHub 打包 APK（EAS）

完整 1.0.0 提交 / 打标签 / 发布命令见 [RELEASE.md](./RELEASE.md)。

1. `npm i -g eas-cli && eas login && cd mobile && npm install && eas init`
2. GitHub Secrets 增加 `EXPO_TOKEN`
3. 推送标签 `v1.0.0` → Actions 用 **production** 打 APK  
   或 Actions 手动选 `preview` / `production`
4. 到 [expo.dev](https://expo.dev) 下载 APK

## API 摘要

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| POST | `/api/dial/enqueue` | 插件推号入库 |
| GET | `/api/dial/next?device=` | App 领取下一通 |
| POST | `/api/dial/{id}/complete` | 标记 done/failed/cancelled |
| GET | `/api/dial/stats` | 队列统计 |
| GET | `/api/companies` | 企业搜索 `q/city/company/phone` |

均需请求头 `X-API-Key`（`/api/health` 除外）。
