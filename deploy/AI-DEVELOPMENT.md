# YikaiCMS 扩展开发：AI 阅读入口

更新：2026-10-06，按 v2.0.5（按发版前的 main `f729a9cd` 核对）核对；上一轮基线为 v2.0.4（`caf137b0`）。适用于不同 AI 编程助手和人工开发者，不依赖特定工具、账号或本机路径。

## 阅读顺序

1. 先读本文。仓库根目录目前没有 AGENTS.md；目标仓库另有协作说明时一并阅读。
2. 开发业务插件：读 [插件开发指南](./PLUGIN-DEVELOPMENT.md)。
3. 开发网站模板/主题/整站模板：先读 [模板制作入口](./TEMPLATE-AUTHORING.md)（规则摘要、格式版本、检查命令、任务卡），再按交付物读 [模板开发指南](./THEME-DEVELOPMENT.md) 或 [整站模板工作流](./SITE-TEMPLATE-WORKFLOW.md)。
4. 插件要让自己的公开数据随整站模板导出导入：再读 [整站模板中的插件数据](./SITE-TEMPLATE-PLUGIN-DATA.md)（英文）。
5. 阅读指南指定的实际源码和相邻实现，再给出修改范围并动手。

2.0.4 起常用的现成能力，先查它们再决定要不要自己写：扩展字段（`includes/ExtFields.php`，插件指南 8.1、模板指南 8.5）、自定义网址与 301 跳转（`ProductRouteModel`、`includes/Redirects.php`，支持 `/旧目录/*` 前缀规则）、从 WordPress 迁移（命令行 `tools/wp-import.php`，含 Contact Form 7 表单、菜单、ACF 字段与旧地址兜底；2.0.5 起支持 Betheme / Muffin 页面、`--type-map` 自定义类型映射与迁移程度报告）。
2.0.6（热修复）：控制台「检查更新」恢复（升级接口只认 POST + CSRF），控制台顶部提醒收进后台右上角铃铛（`includes/AdminNotices.php`，已知最新版本由 `includes/UpdateNotice.php` 记录）；扩展接口不变。
2.0.5 起：多语言网址统一走 `LocalizedUrl`（内容、产品、栏目的各语言网址、hreflang、语言切换与 canonical，插件指南 5.5），设计变量扩到间距 / 容器宽度 / 排版（`BloxDesignScale`、`BloxDesignType`，模板指南 9.x），后台文章分类可由插件接管（`admin_article_categories` 过滤器）。

本文档中的源码路径相对于项目根目录。指南是接入说明，不覆盖用户授权或目标版本真实接口；找不到接口时说明差异，不要虚构替代 API。

## 范围

只覆盖普通 CMS 业务插件与 PHP 网站主题。**不涉及易开网页构建器（Yikay Builder）的编辑器插件开发**：不开发元素注册器、编辑器控件、画布扩展、编辑器模板提供器或修改编辑器内核。v1.20.0 起可视化编辑器对外名称为「易开网页构建器」，源码类名、模板包格式和数据表仍沿用 BLOX/Blox 命名（如 `BloxTemplateModel`、`yikaicms-blox-template`），搜索代码时两个名称都要查。网站主题应保留已有系统兼容调用，但这不构成编辑器扩展任务。

专业授权能力（渲染永远免费，收费的只是作者端的创建与修改）：

- 易开网页构建器的循环模板（含 2.0.3 新增的查询筛选元素）、显示条件、样式预设、表格、价格方案、全局类、元素交互，由 `yikai-builder`（易开网页构建器 Pro）插件提供，经插件市场分发、不随完整包。
- v2.0.0 起，内容维护模式、单页外框覆盖（本页内容宽度、两侧留白、内容背景）与整站模板导出同属专业授权；2.0.4 起高级字段（重复器、字段组、关联、条件逻辑、按分类挂载，以及栏目 / 产品分类 / 全站选项字段）也在此列，只限新建与修改字段定义，已有字段照常显示和填写。这几项代码在核心、按注册码判定（`config/blox-feature-policy.php` 的 `licensed_core` 档，以及 `license_allows_site_template_export()`），不需要 Pro 插件。
- 免费：整站模板导入与模板市场、商城插件（`shop`）以及其余全部能力。

插件与主题不要依赖上述专业能力，也不要复制或绕过它们的授权检查（`BloxFeaturePolicy`、`license_*` 函数）。

## 通用要求

- **命名约定（2.0.4 起）**：可视化构建器的英文及所有外语名称是 **Yikay Builder**，中文仍叫「易开网页构建器」，界面文案里不再出现「Blox」。核心的构建器前台 / 编辑器静态资源由 `assets/js/blox-*.js`、`assets/css/blox-*.css` 改名为 `assets/js/yikay-*.js`、`assets/css/yikay-*.css`；新增资源用 `yikay-` 前缀。老主题经 `BloxAssetCollector::addScript()` / `addStyle()` 登记的旧文件名会自动换成新文件，模板里直接写死的旧地址需要自己改。源码类名（`Blox*`）、数据表与设置键（`blox_*`）、钩子名（`blox_icon_sets` 等）、前台 CSS 类名（`blox-*`）、模板包格式（`yikaicms-blox-template`）、后台地址（`admin/blox_*.php`）**保持不变**，不要自行改名。

- **运行下限 PHP 8.0**（`RuntimeRequirements::PHP_MINIMUM`、composer `php >=8.0`），推荐 8.2 或更高。扩展代码必须能在 PHP 8.0 上加载运行：不要使用 enum、readonly 属性、`never` 返回类型、纯交集类型、first-class callable 语法等 8.1+ 特性，除非在 plugin.json / theme.json 声明更高的 `requires_php`，并在运行时受控检查。
- 原生 PHP，不引入框架。SQL 兼容 MySQL 5.7 / MariaDB 10.x；需要支持 SQLite 的路径使用现有数据库分支。
- 不使用 CTE、窗口函数、JSON_TABLE 或 MySQL 8 专属排序规则。表名使用 DB_PREFIX，值使用参数绑定。
- 新 PHP 文件声明 strict_types；复用模型、db()、配置、权限、CSRF、多语言和缓存接口，不另造一套用户或数据库连接。
- 普通 HTML 文本和属性输出必须 e() 转义；URL 校验与 HTML 转义是不同步骤。富文本仅使用系统受控渲染链。
- 后台富文本编辑器自 v1.20.0 起为随包 HugeRTE（`assets/hugerte/`），`/assets/tinymce/` 已移除。新代码使用全局 `hugerte`；`assets/js/rich-editor.js` 仅为第三方旧代码保留 `window.tinymce` 别名。不要硬编码 TinyMCE 资源路径；依赖编辑器插件或 API 的扩展需在目标版本实测。
- 中文、英文、日文界面文案齐全。资源自托管，不依赖公共 CDN。
- 多语言：语言代码只用 `includes/i18n/LanguageRegistry.php` 登记的，不另写语言列表；站内链接用 `langUrl()` / `langPrefix()`，不手拼 `/en/`（站点可能启用了语言域名）；前台样式用逻辑方向（`ms-*`、`text-start` 等），阿拉伯语页面才能镜像。繁体中文（zh-TW）是简体页面的整页转换，会原样提交回服务器比对的数据按 [多语言部署](./LANGUAGES.md) 第四节处理。
- 繁体中文：界面文案写进 `lang/zh-CN.php` 后用 `php tools/i18n/zh-tw.php` 生成 `zh-TW.php`（2.0.4 起，单测会拦过期的繁体包）；内容只存简体，不要另建繁体内容。
- 无障碍：新页面与模板按 WCAG 2.2 AA 写——表单控件有关联标签、图标按钮有 `aria-label`、标题不跳级、小字对比度至少 4.5:1、点击区域不小于 24px；要点见主题指南 8.6 与插件指南 5.6。
- 不修改 config/config.php、管理员凭据、安装锁或用户数据；不重置站点图片、视频、配色和内容。
- 不修改核心文件（升级会覆盖的文件，含 `themes/default/` 与随包插件），定制放插件、非默认主题或 `overrides/`。2.0.3 起升级前会按包内哈希清单（`config/release-manifest.php`，2.0.3 时叫 `config/release-files.php`）比对：新版本要覆盖的文件若被本站改过，在线升级列出文件、等站长确认，自动升级与远程升级直接跳过这次升级。
- 只修改被授权的目录；发现已有改动不得回退。遵守目标仓库的工作树与提交约定。
- 未获明确授权不安装启用、不改生产数据库、不部署、不上架市场。开发交付与市场发布分开。

## 可交给 AI 的任务模板

```text
请先阅读 deploy/AI-DEVELOPMENT.md，以及其中对应的插件或主题指南。
目标 CMS 版本：由我指定；未指定时读取 config/version.php 并报告。
扩展类型：普通业务插件 / PHP 网站主题。
名称与 slug：……（不得与官方插件/主题同名）
业务目标及页面：……
允许修改的目录：……
参考界面或现有模块：……
需要保留的站点数据：……
是否允许安装到开发站：……

先核对真实接口，不涉及易开网页构建器（Yikay Builder）编辑器插件开发。
代码须兼容 PHP 8.0；完成代码、必要的本地资源、README 和 CHANGELOG；
报告修改清单、最低兼容版本（CMS/PHP）、简单验证结果及未验证项，不自动发布。
```

## 交付要求

交付完整插件/主题目录，说明安装入口、配置项、依赖、数据存储和升级行为。日常只做相关 PHP 语法与功能冒烟（至少在 PHP 8.0 下做一次 `php -l`）；正式合并或发布时遵守仓库完整门禁。未执行的测试不能标为通过，参考截图不能当作实际成品截图。
