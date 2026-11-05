# Byte 免费虚拟空间部署指南

本文按「Byte 免费虚拟空间 / 共享主机」的常见限制编写。不同套餐的控制面板名称可能略有差异，但检查项一致。

## 1. 先确认免费空间满足这 6 项

| 检查项 | 要求 | 不满足时 |
| --- | --- | --- |
| PHP | 8.1+，推荐 8.2 | 安装器会直接报版本不足 |
| PHP 扩展 | `pdo_mysql`、`curl`、`dom`、`mbstring`、`openssl`、`json` | 安装页「环境检查」标红 |
| MySQL | 8.0+，`utf8mb4` | 8.0 以下部分 SQL 不兼容 |
| 伪静态 | Apache `mod_rewrite` 或 Nginx `try_files` | `/admin`、`/api` 全部 404 |
| 可写目录 | `config/`、`storage/`（含 `storage/sessions/`）可写 | 无法生成配置、写锁/日志；登录会一直 419 CSRF |
| 出站网络 | 服务器可 HTTPS 访问 AI 中转站 | 关键词、筛选、提取全部失败 |

免费空间常见限制：

- 没有 SSH，只能通过面板/文件管理器上传。
- 不能执行 `composer`、`php` 命令行；本项目不依赖 Composer。
- MySQL 用户可能没有 `CREATE DATABASE` 权限，数据库需先在面板创建。
- 可能有出站防火墙，只允许白名单域名。需要把 AI 中转站域名加入白名单，或换支持出站的主机。
- 单请求超时较短。AI 请求可能 30–120 秒，建议在后台把中转站超时设为 60–90 秒，或升级套餐。
- 免费空间可能休眠、限速或插入广告；商务使用建议至少使用基础付费套餐。

> 本项目**不需要**服务器访问 Google，也不需要服务器抓取目标网站。Google 搜索与网页抓取全部由 Chrome 插件完成。服务器只做：读写 MySQL、调用 AI 中转站、返回 JSON。

## 2. 创建数据库

在 Byte 面板创建 MySQL 8 数据库，记录：

- 主机：通常是 `localhost` 或面板给出的内网地址
- 端口：通常是 `3306`
- 数据库名：例如 `u123456_gleads`
- 用户名：例如 `u123456`
- 密码

如果面板已自动创建数据库，**不要再执行 CREATE DATABASE**。本安装器会优先直连已有数据库；只有直连失败时才尝试创建。

## 3. 上传文件

1. 本地解压发布包。
2. 删除（如果存在）：
   - `config/config.php`
   - `storage/installed.lock`
   - `storage/logs/*`
3. 把其余文件上传到站点根目录（`public_html`、`wwwroot` 或面板指定目录）。
4. 保持目录结构不变，特别是：
   - `app/`
   - `migrations/`
   - `extension/`（可不上传到服务器，插件在本地加载）
5. 设置权限：
   - 目录：`755`
   - 文件：`644`
   - `config/`、`storage/`：`775` 或面板要求的可写权限

`config/`、`storage/`、`migrations/` 自带 `.htaccess`，禁止直接访问。不要删除。

## 4. 运行安装器

浏览器打开：

```text
https://你的域名/install.php
```

填写：

- 数据库主机/端口/库名/用户/密码
- 表前缀：默认 `gl_`，冲突时可改
- 站点地址：`https://你的域名`（必须带协议，不要带末尾 `/`）
- 管理员账号、显示名、密码
- AI 中转站（可跳过，安装后后台再配）

点击安装。成功后：

1. **立即复制 API Key**。
2. 打开 `https://你的域名/admin` 登录。
3. 到「系统设置」确认 API 地址为 `https://你的域名`。

安装器会生成：

- `config/config.php`：数据库与 API Key，权限应不可被下载。
- `storage/installed.lock`：安装锁；重新安装需手动删除。

## 5. 验证伪静态

依次访问：

```text
https://你的域名/api/health
https://你的域名/admin/login
https://你的域名/install.php?status=1
```

`/api/health` 必须返回 JSON，而不是 404 或首页 HTML。若 404：

- Apache：确认根目录 `.htaccess` 已上传，`mod_rewrite` 已开启，`AllowOverride All`。
- Nginx：在站点配置中加入：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

- 部分免费空间会忽略 `.htaccess`，需要在面板开启「伪静态 / Rewrite」并选择 `index.php` 作为入口。

## 6. 配置 AI 中转站

后台 →「AI 中转站」→ 新建：

| 字段 | 示例 |
| --- | --- |
| 名称 | 主中转站 |
| 接口地址 | `https://api.openai.com/v1` 或你的 NewAPI 地址 |
| API Key | `sk-...` |
| 模型 | `gpt-4o-mini` / `deepseek-chat` / 你的模型名 |
| 超时 | 60–120 秒 |
| 优先级 | 10（数字越小越优先） |

保存后点「测试」确认可用。可添加多个中转站，主站失败会自动切换。

AI 输出必须是合法 JSON。若中转站不支持 `response_format: json_object`，后台「系统设置」关闭 JSON Mode，系统会用提示词约束并在服务端解析。

## 7. 安装 Chrome 插件

1. 本地打开 Chrome `chrome://extensions/`。
2. 开启「开发者模式」。
3. 「加载已解压的扩展程序」→ 选择 `extension/`。
4. 点击插件图标，填写：
   - API 地址：`https://你的域名`
   - API Key：安装完成页的 Key
5. 保存后点「测试连接」，显示绿色「已连接」。
6. 新建城市任务，保持插件窗口打开，任务会自动推进。

插件不需要上传到服务器，服务器上保留 `extension/` 也不会被访问（只有静态文件）。

## 8. 免费空间性能建议

- 关键词数量：先设 `12`，页数 `1`，确认整条链路跑通再放大。
- 域名筛选批量：免费空间 CPU 较弱，保持 `20–30`。
- 请求间隔：`2500–4000ms`，降低 Google 风控与主机压力。
- 单任务企业数：免费空间建议先控制在 100 家以内，再导出 CSV 清理。
- 日志：`storage/logs/` 会增长，定期在面板删除旧日志。
- 不要用免费空间跑多个城市并发；同一时间只跑一个任务。

## 9. 常见报错

**「无法连接数据库。请先在主机控制面板创建数据库…」**  
数据库名/用户/密码/主机不对，或用户无权限。先在面板创建库，确认主机地址不是 `localhost` 而是面板给的内网地址。

**「写入配置失败」**  
`config/` 不可写。把权限改为 775，或让主机商放开写权限。

**「安装已锁定」**  
`storage/installed.lock` 存在。确认要重装后，备份数据库，删除 `config/config.php` 和 `storage/installed.lock` 再访问安装器。

**API 返回 401**  
Key 前后有空格、复制不完整、或在后台轮换过。重新复制。

**API 返回 404**  
伪静态未生效，或 API 地址填成了 `https://域名/api`。应填站点根地址。

**插件提示非 JSON，响应含 `aes.js`**  
InfinityFree（含 `ftpupload.net`）免费空间会对所有请求做 JS Cookie 校验，Chrome 插件直连 API 常被拦成 HTML。插件会自动开一次标签页尝试过检；校验轮换后仍可能失败。商务使用请换付费/无此校验的主机。

**AI 超时 / 插件显示「服务器开小差了」**  
InfinityFree 等免费空间 PHP 执行上限约 60 秒且无法调高。关键词生成若多次调用 AI 会直接被掐断。后台把中转站超时设为 **35–45 秒**，关键词数量先用 **12**，换更快模型；仍失败请换付费主机。

**Google 验证码**  
插件会先直连，失败后用隐藏标签页；仍失败时降低频率、换网络，或换用合规 SERP 数据源。不要用服务器 IP 直连 Google。

## 10. 备份与更新

备份：

- 数据库：面板导出 SQL。
- 文件：`config/config.php`、`storage/`、`extension/`。
- 企业数据：后台任务详情导出 CSV。

更新代码：

1. 备份数据库与 `config/config.php`。
2. 覆盖 `app/`、`assets/`、`migrations/`、`index.php`、`install.php`、`migrate.php`、`.htaccess`。
3. 保留原 `config/config.php` 与 `storage/installed.lock`。
4. 访问 `/migrate.php` 执行新迁移（需登录后台或带 API Key）。
5. 检查 `/api/health` 与后台首页。

## 11. 安全清单

- 全站 HTTPS，后台不要用裸 HTTP。
- 管理员密码 12 位以上，不与其它站点复用。
- API Key 只填在插件里，不写进公开页面。
- 不把 `config/config.php`、`storage/logs/` 提交到 Git 或网盘。
- 定期轮换 API Key；离职/换设备时立即轮换。
- 联系企业时遵守 GDPR、CAN-SPAM、PECR 与目标网站条款。
