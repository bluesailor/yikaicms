# 模板制作入口（先读这一页）

本页是制作 YikaiCMS 模板的**唯一入口**：只放规则摘要、格式契约、检查命令和任务卡；细节链接到各专题文档。
人和 AI 编程助手都从这里开始。专题文档与本页冲突时，以本页列出的「单一来源」文件为准，并修正那份文档。

## 一、三种模板，分清再动手

| 交付物 | 是什么 | 详细说明 |
|---|---|---|
| 主题 | `themes/<slug>` 或 `marketplace/themes/<slug>`：PHP 布局、样式、`theme.json` | [THEME-DEVELOPMENT.md](./THEME-DEVELOPMENT.md) |
| 整站模板 | 一个 ZIP：主题 + 栏目、内容、产品、设置、Blox 文档、媒体；可在模板市场销售 | [SITE-TEMPLATE-WORKFLOW.md](./SITE-TEMPLATE-WORKFLOW.md)、[SITE-TEMPLATE-PLUGIN-DATA.md](./SITE-TEMPLATE-PLUGIN-DATA.md) |
| Blox 模板 | 单个页面或区块的 JSON 包（精品区块、页面预设） | [TEMPLATE-PRESETS-MOTION.md](./TEMPLATE-PRESETS-MOTION.md)、[TEMPLATE-USABILITY.md](./TEMPLATE-USABILITY.md) |

## 二、单一来源（不要在别处另写一份）

| 内容 | 唯一来源 | 读取方式 |
|---|---|---|
| 支持哪些语言、语言名、书写方向 | `includes/i18n/LanguageRegistry.php` | `LanguageRegistry::codes()` 等；后台可用语言用 `availableLanguages()` |
| 行业分类 | `config/template-categories.php` | `TemplateCategories::keys()` / `label()` |
| 模板市场目录的多语文字字段 | `SiteTemplateMarket::localizedTextKeys()` | 目录准备工具与后台市场页共用 |
| 版本兼容 | 本页第四节 + `theme-versioning` 规则（主题 `version` / `requires_cms`） | — |

新增语言、新增行业、新增目录字段，都只改上面这几处；校验器、市场、工具会跟着生效。

## 三、规则摘要

**多语言**
- 语言代码只用注册表里的（`zh-CN`、`en`、`ja`、`ko`、`ar`……），不用 `zh` 这类简写。
- 清单类文件（`theme.json`、市场目录）的多语字段一律平铺成 `<字段>_<语言代码>`：`name_ko`、`description_ar`。无后缀的是中文。
- 嵌套写法只用在 `content-fields.json` 和精品区块：`{"zh-CN": "…", "en": "…"}`。
- 缺译时的回落顺序全站统一：本语言 → 英文（中文、日语除外）→ 中文。不要自己再写一套回落。
- 整站模板的 `languages`、演示语言入口、包内 `enabled_languages` 三者一致，**只列已经逐页验收过的语言**；不为了显示切换器而启用空语言。多语言内容的完整要求见 [SITE-TEMPLATE-WORKFLOW.md](./SITE-TEMPLATE-WORKFLOW.md) 的「上架制作规范」。

**样式与方向**
- 用 Tailwind 的逻辑方向类（`ms-`、`me-`、`ps-`、`pe-`、`start-`、`end-`、`text-start`、`text-end`），不写死左右，阿拉伯语页面才能自动镜像；规则见 [THEME-DEVELOPMENT.md §8.3](./THEME-DEVELOPMENT.md)。
- 颜色用主题设计变量（`design-tokens.json` / 站点外观设置），不写死色值。
- 自定义 CSS 类加 `yk-` 前缀；主题样式表用 `theme.json` 的 `stylesheets` 声明，不在页头手拼 `$extraCss`。

**内容与链接**
- 跨语言链接用 `langUrl()`、`langPrefix()`，不硬编码 `/en/`、`?lang=` 或演示站域名（站点可能启用了语言域名）。
- 页面主体尽量用原生可编辑元素和 CMS 数据，避免整页 Code 元素。
- 站点名、联系方式、品牌素材都要能在导入后替换，不留演示站的真实资料。

## 四、格式契约与版本

| 格式 | 版本字段 | 当前值 | 读到更新的版本时 |
|---|---|---|---|
| `theme.json` | `schema_version` | 1 | 校验报错，不安装 |
| `content-fields.json` | `version` | 1（必须严格等于 1） | 整份字段声明作废 |
| 整站模板包 | `format` = `yikaicms-site-template`，`version` | 1、2（2 = 带插件数据） | 拒绝导入 |
| 整站模板包的 CMS 版本 | 清单 `cms` | 必须与站点**同一个次版本**且不高于站点（2.0.x 只导入到 2.0.x） | 拒绝导入（市场标为不可用） |
| Blox 模板包 | `format` = `yikaicms-blox-template`，`version` | 1（严格相等） | 拒绝导入 |
| 市场目录 | 响应 `protocol_version`，条目 `format_version` | 1；条目 1、2 | 目录作废 / 条目标为不可用 |

约定（新增字段时照此办理）：
1. **加字段不升版本号**：旧代码忽略不认识的字段；校验器对未知字段最多给警告，不能报错。
2. **改字段含义或删字段才升版本号**，并在本表登记；读取方遇到更高版本要明确拒绝、给出原因，不能静默忽略。
3. **待定（2.1 之前必须决定）**：整站模板只能导入到同一次版本，2.1 发布后所有 2.0.x 模板将不可导入。方案二选一——导入时把 2.0 数据结构迁移到 2.1，或发版前把演示站批量升级后重新导出。

## 五、检查命令（交付前全部跑过）

| 命令 | 查什么 |
|---|---|
| 后台安装主题，或调用 `ThemeValidator::validateDir()` | `theme.json` 字段、分类、语言后缀、必需文件 |
| 后台「导出整站模板」的检查页 | 私有数据、启用语言、插件依赖 |
| `php tools/site-template-roundtrip.php --package=<整站模板.zip> --port=<空闲端口>` | 全新安装 → 导入 → **每种启用语言**的首页、栏目、内容、产品；`<html lang/dir>`、空语言、非汉字语言页面里的中文残留；编辑画布 |
| `php tools/check_rtl.php --file=<路径>` | 新写的模板与样式里有没有写死左右；仓库内的主题与插件由单测守住数量只减不增 |
| 浏览器 1440px 与 390px 各看一遍 | 首屏、长标题、移动菜单、语言切换、当前导航（工具查不了） |

## 六、任务卡（交给 AI 制作模板时照填）

```text
交付物：主题 / 整站模板 / Blox 模板（三选一）
slug：            行业分类（config/template-categories.php 的键）：
承诺语言（注册表代码，只写会逐页验收的）：
基于的 CMS 版本：           依赖插件：
参考资料：本页 + （按交付物）THEME-DEVELOPMENT.md / SITE-TEMPLATE-WORKFLOW.md
完成标准：第五节全部检查通过；往返工具报告与浏览器截图随交付提交；不覆盖已发布的同名版本包
不要做：另写语言列表或分类列表；写死左右、色值、演示域名、/en/ 前缀；把可翻译正文写进共享的 Code 片段
```
