# 1.0.0 提交与发布（不依赖 Expo EAS）

打包在 **GitHub Actions** 完成：`expo prebuild` + Gradle 出 APK，上传到 Artifacts。  
**本机不用装** Node / EAS / Android SDK，也 **不需要** `EXPO_TOKEN`。

## 提交并推送

```powershell
cd d:\WorkSpace\Learn\google
git add .
git status
git commit -m "ci: build Android APK on GitHub without EAS"
git push origin main
```

## 下载 APK

1. GitHub → **Actions** → **Mobile Android APK**（等绿勾）
2. 打开成功的那次 run → **Artifacts** → 下载 `haoxiansuo-apk-release`
3. 解压得到 `haoxiansuo-1.0.0-release.apk`，拷到手机安装

也可手动：Actions → Mobile Android APK → **Run workflow** → 选 `release` 或 `debug`。

打正式标签（同样触发打包）：

```powershell
git tag -a v1.0.0 -m "好线索 1.0.0"
git push origin v1.0.0
```

若标签已存在要重打：

```powershell
git tag -d v1.0.0
git push origin :refs/tags/v1.0.0
git tag -a v1.0.0 -m "好线索 1.0.0"
git push origin v1.0.0
```

## 说明

- CI 用临时 keystore 签名 release 包，适合内测安装；上架应用商店需换成你自己的正式签名。
- `android/` 目录由 CI 生成，已在 `.gitignore`，不必提交。
