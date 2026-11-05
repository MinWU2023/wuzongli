# API 接口文档

所有插件接口以 `/api` 开头，返回 JSON。除 `/api/health` 外，全部需要认证。

## 认证

任选一种方式，推荐第一种：

```http
X-API-Key: gle_xxxxxxxxxxxxxxxx
```

```http
Authorization: Bearer gle_xxxxxxxxxxxxxxxx
```

```text
GET /api/tasks?api_key=gle_xxxxxxxxxxxxxxxx
```

API Key 在安装完成页显示，也可在后台「系统设置」查看或重新生成。轮换后旧 Key 立即失效。

## 响应信封

成功：

```json
{
  "ok": true,
  "data": {}
}
```

失败：

```json
{
  "ok": false,
  "error": {
    "code": "validation_error",
    "message": "请输入城市名称",
    "details": null
  }
}
```

常见错误码：

| HTTP | code | 含义 |
| --- | --- | --- |
| 400 | `validation_error` | 参数缺失或格式错误 |
| 401 | `unauthorized` | API Key 缺失/错误 |
| 404 | `not_found` | 任务、网站、页面不存在 |
| 409 | `task_busy` | 同一任务/阶段正在执行，稍后重试 |
| 422 | `task_paused` | 任务已暂停 |
| 429 | `rate_limited` | 请求过于频繁 |
| 500 | `server_error` | 服务器异常，详情见日志 |
| 502 | `ai_request_failed` / `ai_invalid_output` | AI 中转站或输出异常 |
| 503 | `ai_not_configured` | 未配置可用 AI 中转站 |

## 健康检查

### `GET /api/health`

无需认证，用于插件「测试连接」。

```json
{
  "ok": true,
  "data": {
    "app": "Google Lead Engine",
    "version": "1.0.0",
    "php": "8.2.0",
    "mysql": "8.0.31",
    "database": true,
    "time": "2026-11-04T15:00:00+00:00",
    "stage": "ready"
  }
}
```

## 任务

### `GET /api/tasks`

可选 `status=active|paused|completed|failed`，最多返回 100 条。

```json
{
  "ok": true,
  "data": {
    "tasks": [
      {
        "id": 1,
        "city": "武汉",
        "country": "中国",
        "target_market": "全球",
        "language": "en",
        "stage": "serp",
        "status": "active",
        "progress": 42,
        "keyword_count": 24,
        "pages_target": 3
      }
    ]
  }
}
```

### `POST /api/tasks`

创建城市任务。

```json
{
  "city": "武汉",
  "target_market": "北美",
  "keyword_count": 24,
  "pages_target": 3,
  "language": "en"
}
```

- `city`：必填，1–60 字。
- `target_market`：可选，默认「全球」。
- `keyword_count`：可选，6–60；不传用系统设置。
- `pages_target`：可选，1–10；不传用系统设置。
- `language`：可选，默认 `en`，用于 Google `hl` 参数。

返回 `201`：

```json
{
  "ok": true,
  "data": {
    "task": { "id": 1, "city": "武汉", "stage": "keywords", "status": "active" },
    "counts": { "keywords_total": 0, "serp_pages_target": 0 },
    "action": "generate_keywords"
  }
}
```

### `GET /api/tasks/{id}`

任务详情 + 实时统计。

### `GET /api/tasks/{id}/next`

**插件轮询主接口。** 服务端根据数据库状态决定下一步；同一阶段未完成时不会跳到下一阶段。

```json
{
  "ok": true,
  "data": {
    "task": { "id": 1, "stage": "serp", "status": "active", "progress": 18 },
    "counts": {
      "keywords_total": 24,
      "keywords_done": 4,
      "serp_pages_target": 72,
      "serp_pages_done": 13,
      "websites_total": 41,
      "websites_approved": 0,
      "companies": 0
    },
    "action": "fetch_serp",
    "job": {
      "keyword_id": 7,
      "keyword": "wuhan textile manufacturer",
      "page": 2,
      "pages_target": 3,
      "google_url": "https://www.google.com/search?q=...",
      "delay_ms": 2500
    },
    "message": "请用浏览器抓取 Google 搜索结果页。"
  }
}
```

`action` 取值：

| action | 插件动作 |
| --- | --- |
| `generate_keywords` | 调 `POST /keywords/generate` |
| `fetch_serp` | 浏览器抓 Google → 调 `POST /serp` |
| `search_serper` | 调 `POST /serp/serper`，由服务端请求 Serper.dev |
| `classify_domains` | 调 `POST /classify` |
| `crawl_website` | 抓首页 → 调 `POST /websites/{wid}/pages` |
| `extract_contacts` | 抓关键页 → 调 `POST /websites/{wid}/extract` |
| `done` | 任务完成 |
| `paused` | 等待用户继续 |
| `failed` | 等待用户重试 |

启用 Serper 后，`next` 返回的 `job` 会带 `provider: "serper"`，不再需要 `google_url`：

```json
{
  "action": "search_serper",
  "job": {
    "keyword_id": 7,
    "keyword": "wuhan textile manufacturer",
    "page": 2,
    "pages_target": 3,
    "provider": "serper",
    "delay_ms": 300
  },
  "message": "通过 Serper.dev 获取 Google 搜索结果。"
}
```

### `POST /api/tasks/{id}/pause`

暂停。返回 `data.task`。

### `POST /api/tasks/{id}/resume`

继续。返回 `data.task`。

### `POST /api/tasks/{id}/retry`

把当前阶段失败/卡住的项重置为待处理，并清除任务错误。返回 `data.task` 与 `data.counts`。

### `POST /api/tasks/{id}/keywords/generate`

调用 AI 生成关键词。已有关键词时不会重复生成，返回 `generated: 0, existing: N`。

```json
{
  "ok": true,
  "data": {
    "generated": 24,
    "keywords": [
      {
        "id": 1,
        "task_id": 1,
        "keyword": "wuhan textile manufacturer",
        "intent": "manufacturer",
        "language": "en",
        "status": "pending",
        "pages_target": 3,
        "pages_done": 0
      }
    ],
    "counts": { "keywords_total": 24, "serp_pages_target": 72 }
  }
}
```

未配置 AI 时返回 `503 ai_not_configured`，不会创建空任务数据。

### `GET /api/tasks/{id}/keywords`

可选 `limit`（1–500，默认 200）。

### `GET /api/tasks/{id}/websites`

可选：

- `limit`：1–2000，默认 1000。
- `status`：`approved`、`rejected`、`pending`、`error`。

返回 `websites[]` 与 `total`。`pages_json` 为已识别页面的 JSON 字符串。

### `GET /api/tasks/{id}/companies`

可选：

- `limit`：1–2000，默认 1000。
- `has_contact=1`：只返回有电话/邮箱/LinkedIn/WhatsApp 的企业。

## 插件 Runner（App 控制自动跑任务）

### `GET /api/runner`

返回 `{ enabled, active_tasks, paused_tasks }`。`enabled=false` 时 Chrome 插件立即停止自动推进。

### `POST /api/runner`

```json
{ "enabled": true }
```

### 任务管理补充

- `POST /api/tasks/{id}/update`：编辑城市/市场/关键词数/页数（不清空流水线）
- `POST /api/tasks/{id}/stop`：终止（等同 pause，插件会跳过并切下一任务）

插件行为：任务 `completed`/`done` 后自动切到下一个 `active` 任务；每轮轮询都会读 `/api/runner`。

## 拨号队列（Chrome 插件 → 手机 App）

均需 `X-API-Key`。

### `POST /api/dial/enqueue`

插件点击「拨号」入库。Body 示例：

```json
{
  "company_id": 12,
  "task_id": 3,
  "phone": "+862787376766",
  "company_name": "Wuhan Han Star Machine",
  "city": "武汉",
  "domain": "hanstarmachine.com"
}
```

10 分钟内同一 `pending` 号码会去重返回 `duplicated: true`。

### `GET /api/dial/next?device=m-xxxx`

App 领取最早一通 `pending`，状态改为 `claimed`。无任务时 `job` 为 `null`，并返回 `pending_count`。

### `POST /api/dial/{id}/complete`

```json
{ "status": "done", "note": "" }
```

`status`：`done` | `failed` | `cancelled`。

### `GET /api/dial/stats`

返回各状态计数。

### `GET /api/companies`

跨任务企业搜索。可选：`q`、`city`、`company`、`phone`、`page`、`limit`（默认 30，最大 100）。

### `GET /api/tasks/{id}/logs`

可选 `limit`（1–200，默认 80）。

```json
{
  "ok": true,
  "data": {
    "logs": [
      {
        "id": 18,
        "level": "info",
        "stage": "serp",
        "message": "关键词「wuhan textile manufacturer」第 2 页写入 3 个域名",
        "created_at": "2026-11-04 15:20:00"
      }
    ]
  }
}
```

### `GET /api/tasks/{id}/export.csv`

下载当前任务全部企业 CSV（UTF-8 BOM，Excel 可直接打开）。需要站点伪静态支持 `.csv` 路径。

## 搜索阶段

### `POST /api/tasks/{id}/serp/serper`

服务端通过 Serper.dev 获取 Google 结果。插件**不发送 API Key**：Key 以 AES-256-GCM 加密保存在 `settings` 表，只由 PHP 服务端解密使用。

请求：

```json
{ "keyword_id": 7, "page": 2 }
```

成功时返回与 `POST /serp` 相同的入库结果，并附带 Serper 余额：

```json
{
  "ok": true,
  "data": {
    "parsed": 10,
    "stored": 8,
    "new_domains": 6,
    "provider": "serper",
    "credits": 2489,
    "counts": { "serp_pages_done": 14, "websites_total": 47 }
  }
}
```

- 服务端使用 `search_provider`（`auto` / `serper` / `browser`）选择搜索源；`auto` 在配置 Key 后优先 Serper。
- Serper 请求参数：`q`、`gl`、`hl`、`num=10`、`page`、`autocorrect=false`。
- 401/403（Key 无效）、429（限流/欠费）会暂停任务并返回 `serper_unauthorized` / `serper_rate_limited`；修正 Key 或充值后继续任务。
- 网络错误、超时或空结果会走现有 `attempts` / `error_pages` 重试与跳过逻辑，不会无限重试。

### `POST /api/tasks/{id}/serp`

插件把 Google 页面结果交回服务端。二选一：

**A. 直接传 HTML（服务端解析）**

```json
{
  "keyword_id": 7,
  "page": 2,
  "html": "<!doctype html>...",
  "blocked": false
}
```

**B. 传插件提取后的结构化结果**

```json
{
  "keyword_id": 7,
  "page": 2,
  "results": [
    {
      "url": "https://alpha-textile.com/",
      "title": "Wuhan Alpha Textile Co., Ltd.",
      "snippet": "Wuhan textile manufacturer..."
    }
  ],
  "blocked": false,
  "error": ""
}
```

- `blocked=true` 或页面命中验证码特征时，该页记为失败并重试。
- 同一关键词同一页重复提交不会重复写域名。
- 域名会去除 `www`、统一小写，只保留根域。
- 平台/目录/社媒域名在入库前直接拒绝，减少 AI 调用。

成功：

```json
{
  "ok": true,
  "data": {
    "parsed": 10,
    "stored": 8,
    "new_domains": 6,
    "counts": { "serp_pages_done": 14, "websites_total": 47 }
  }
}
```

## AI 域名筛选

### `POST /api/tasks/{id}/classify`

每次处理一批（默认 30，后台可调 5–50），直到没有 `pending` 域名。

```json
{
  "ok": true,
  "data": {
    "processed": 30,
    "approved": 9,
    "rejected": 21,
    "errors": 0,
    "pending": 0,
    "counts": {
      "websites_total": 47,
      "websites_classified": 47,
      "websites_approved": 12,
      "pending_classify": 0
    }
  }
}
```

只把 `approved` 的站点送进页面识别。

## 官网页面识别

### `POST /api/tasks/{id}/websites/{websiteId}/pages`

提交首页 HTML；服务端提取链接，调用 AI 识别首页/About/Contact，并保存三个 URL。

```json
{
  "url": "https://alpha-textile.com/",
  "html": "<!doctype html>..."
}
```

```json
{
  "ok": true,
  "data": {
    "pages": [
      { "type": "home", "url": "https://alpha-textile.com/" },
      { "type": "about", "url": "https://alpha-textile.com/about-us" },
      { "type": "contact", "url": "https://alpha-textile.com/contact" }
    ],
    "about": "https://alpha-textile.com/about-us",
    "contact": "https://alpha-textile.com/contact",
    "counts": { "pages_done": 5, "pending_crawl": 2 }
  }
}
```

AI 返回的外链、跨域 URL 会被丢弃；找不到 About/Contact 时允许为空。

## 联系方式提取

### `POST /api/tasks/{id}/websites/{websiteId}/extract`

提交首页、About、Contact 的 HTML。服务端清洗正文、调用 AI 抽取，并用正则兜底电话/邮箱/社媒。

```json
{
  "pages": [
    { "type": "home", "url": "https://alpha-textile.com/", "html": "<!doctype html>..." },
    { "type": "about", "url": "https://alpha-textile.com/about-us", "html": "..." },
    { "type": "contact", "url": "https://alpha-textile.com/contact", "html": "..." }
  ]
}
```

```json
{
  "ok": true,
  "data": {
    "company": {
      "id": 3,
      "website_id": 5,
      "company_name": "Wuhan Alpha Textile Co., Ltd.",
      "domain": "alpha-textile.com",
      "phone": "+86 138 0000 1234",
      "email": "sales@alpha-textile.com",
      "linkedin": "https://www.linkedin.com/company/alpha-textile",
      "tiktok": "https://www.tiktok.com/@alphatextile",
      "youtube": "https://www.youtube.com/@alphatextile"
    },
    "counts": { "companies": 3, "extract_ready": 2 }
  }
}
```

同一网站重复提交会更新原企业记录，不会重复插入。

## 插件与服务的边界

- 插件只负责“用浏览器拿 HTML”：Google SERP、官网首页、About、Contact。
- 插件**不把 Google/网站 HTML 发给 AI**；HTML 发给你的 PHP 服务端，由服务端调用 AI。
- AI Key 只存在服务器，永不下发到插件。
- 插件只保存 API 地址与 API Key（`chrome.storage.local`）。

## 速率与并发

- 同一任务的关键词生成、批量筛选、抓站提取使用 MySQL 命名锁，并发请求会得到 `409 task_busy`。
- 插件按 `delay_ms` 串行执行，默认 2.5 秒；不要并发开多个插件窗口跑同一任务。
- 失败重试次数默认 3，超过后标记 `error`，任务可继续处理其他项。
