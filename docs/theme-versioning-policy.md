# 主题与模板版本规范

本文约束 YikaiCMS 主程序、主题包、Blox 远程模板之间的版本关系。目标是让“资源自身是否有更新”和“当前主程序是否能安全使用该资源”分开判断，避免主题或模板在较新主程序能力上开发，却被旧站点安装后出现样式、渲染或编辑器行为不一致。

## 单一版本来源

- 主程序版本只认 `config/version.php` 中的 `CMS_VERSION`。
- 主题版本只认主题目录下 `theme.json` 的 `version`。
- Blox 远程模板包版本只认模板包元数据里的 `version`。
- 资源兼容的最低主程序版本必须单独声明，不用资源自身版本号反推。

## 主题版本规则

主题 `theme.json` 必须包含：

```json
{
  "schema_version": 1,
  "version": "1.0.6",
  "requires_cms": ">=1.19.3",
  "requires_php": ">=8.0"
}
```

规则：

- `version` 是主题自身版本，使用三段 SemVer，例如 `1.0.6`。
- `requires_cms` 是最低 YikaiCMS 主程序版本，格式使用 `>=x.y.z`。
- 市场主题源码位于 `marketplace/themes/{slug}`，发布前必须把 `requires_cms` 同步到当前 `CMS_VERSION`。
- 核心内置 `default` 主题可以声明更低的 `requires_cms`，因为它随主程序发行并承担旧站升级兼容。
- 市场主题每次依赖新的主题钩子、Blox 渲染行为、设计 token、前台脚本加载、模板解析或后台编辑器能力时，必须提升自身 `version`，并确认 `requires_cms` 不低于引入这些能力的主程序版本。

## Blox 远程模板规则

Blox 远程模板包必须区分：

- `version`：模板包自身版本。
- `min_cms_version`：使用该模板所需的最低主程序版本。
- `schema_version`：模板数据结构版本。

当模板依赖新元素、新 Style Schema、新响应式值、新背景视频、动态数据能力、区域模板命中规则或前台 CSS 生成能力时，必须提升 `min_cms_version`。

## 发布纪律

每次发布主程序或市场主题时按以下顺序检查：

1. 先确认 `config/version.php` 的 `CMS_VERSION`。
2. 再检查 `marketplace/themes/*/theme.json` 的 `version` 和 `requires_cms`。
3. 如果主题代码改动影响前台输出、Blox 画布、主题设置、设计 token 或资源加载，提升主题 `version`。
4. 如果主题依赖当前主程序新增能力，确保 `requires_cms` 为 `>=当前 CMS_VERSION`。
5. 主题打包、签名、发布时，包内 `theme.json` 必须和源码一致。

## 测试门禁

本仓库的主题校验测试负责保障：

- `theme.json` 必须是合法 JSON。
- `schema_version` 不得高于当前校验器支持版本。
- `version` 必须是三段 SemVer。
- `requires_cms` / `requires_php` 不满足当前环境时拒绝安装。
- 市场主题源码的 `requires_cms` 必须等于 `>=CMS_VERSION`，主程序升版后必须同步市场主题兼容声明。

## 不要做的事

- 不要把主题版本号和主程序版本号写成同一个号。主题 `1.0.6` 可以对应主程序 `1.19.3`。
- 不要只改主题代码但不改主题 `version`。
- 不要让市场主题缺少 `requires_cms`。
- 不要用“当前测试能过”替代兼容声明；旧站安装包时只看元数据和校验器。
