# overrides/ — 站点视图覆盖层

在此目录放与核心/主题**同名相对路径**的模板文件，即可覆盖它，**无需修改核心或主题文件**，核心升级不会覆盖你的定制。

## 解析优先级
`theme_path($file)` 的查找顺序：

1. `overrides/{$file}` ← 最高优先级（本目录）
2. `themes/{当前主题}/{$file}`
3. 核心回退（`includes/` 等）

## 用法示例
- 覆盖前台头部：把定制版放到 `overrides/layouts/header.php`
- 覆盖某区块：`overrides/blocks/banner.php`
- 覆盖单页模板：`overrides/pages/xxx.php`

`$file` 就是各处 `theme_path('...')` 传入的相对路径。

## 说明
- 只影响走 `theme_path()` 的前台模板（后台模板暂不在覆盖范围）。
- 本目录内容按站维护，**不进核心仓库 / 发布包**（已在 .gitignore，仅保留本 README）。
- 配套：配置覆盖见 `config/overrides.sample.php`，语言覆盖见 `lang/overrides/README.md`。
