# APK 打包说明（GitHub Actions，无 Expo EAS）

## APK 在哪里看

| 入口 | 有没有 APK |
| --- | --- |
| **Releases**（推荐） | 打 `v*` 标签且构建成功后，Assets 里会有 `.apk` |
| **Actions** → 成功 run → Artifacts | 有 `haoxiansuo-apk-…`，点下载 |
| **Tags** 页默认 Assets | **只有** Source code zip/tar，**不会**自动出现 APK |

你在 Tags 里看到只有源码，是正常的；要到 **Releases** 或 **Actions Artifacts** 找 APK。

## 推送并发布

```powershell
cd d:\WorkSpace\Learn\google
git add .
git commit -m "ci: attach APK to GitHub Release on tag"
git push origin main
```

打新标签（会触发打包并挂到 Release）：

```powershell
git tag -a v1.0.5 -m "好线索 1.0.5"
git push origin v1.0.5
```

等 Actions 绿勾后打开：

`https://github.com/MinWU2023/wuzongli/releases`

下载 `haoxiansuo-v1.0.5-release.apk`。

也可：Actions → **Mobile Android APK** → **Run workflow**。
