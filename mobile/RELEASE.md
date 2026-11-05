# 1.0.0 提交与发布

当前环境是 **Windows PowerShell**，下面命令可直接复制。

## 一次性准备（本机不用装 EAS / Node）

打包全在 **GitHub Actions 云端**完成。你只需要：

1. 浏览器打开 https://expo.dev → Access tokens → 新建 token  
2. GitHub 仓库 → Settings → Secrets and variables → Actions 新增：

| Name | Value |
| --- | --- |
| `EXPO_TOKEN` | 上一步的 token |

`mobile/app.json` 里已有 `extra.eas.projectId` 即可（当前仓库已配置）。

不要点旧失败 run 的 **Re-run**（还是旧脚本）；推送新 commit，或到 Actions → **Mobile Android APK** → **Run workflow**。

---

## A. 初始化 Git 并提交 1.0.0 代码

在项目根目录执行：

```powershell
cd d:\WorkSpace\Learn\google

git init
git add .
git status
git commit -m "release: v1.0.0 拨号 App 与 dial API"

git branch -M main
git remote add origin https://github.com/<你的用户名>/<仓库名>.git
git push -u origin main
```

把 `<你的用户名>/<仓库名>` 换成真实地址。若 remote 已存在：

```powershell
git remote set-url origin https://github.com/<你的用户名>/<仓库名>.git
git push -u origin main
```

---

## B. 打 v1.0.0 标签并触发 GitHub 打包

```powershell
cd d:\WorkSpace\Learn\google

git tag -a v1.0.0 -m "好线索 1.0.0"
git push origin v1.0.0
```

推送 `v1.0.0` 后，Actions 工作流 **Mobile Android APK** 会用 EAS **production** 打 APK。

也可手动：GitHub → Actions → Mobile Android APK → Run workflow → 选 `production` 或 `preview`。

APK 在 https://expo.dev 对应项目的 Builds 里下载。

---

## C. 不经过 GitHub、本机直接打 APK

```powershell
cd d:\WorkSpace\Learn\google\mobile
eas build --platform android --profile preview --non-interactive
# 正式包：
eas build --platform android --profile production --non-interactive
eas build:list --platform android
```

---

## 发布后检查

1. 服务端执行迁移：`php migrate.php up`（创建 `dial_jobs`）
2. Chrome 扩展重新加载；企业列表点「拨号」入库
3. 安装 APK → 设置 API：`https://google.yzz.me` + Key → 开启接单
