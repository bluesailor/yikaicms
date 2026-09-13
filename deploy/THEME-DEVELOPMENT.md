# YikaiCMS 主题与模板开发指南

文档版本：0.2。更新：2026-09-13。对象：主题作者、建站开发者、BLOX 模板制作人员。

源码核对基线：项目主仓，HEAD `c97ca4294773bcf3921f9613a7968b83ca84a0d6`，CMS 1.19.9。开发分支的新能力在文中单独标识，不作为所有客户版本都支持的承诺。本文仅编写指南，未安装示例主题、未运行发版验收。

本文由原完整指南整理入项目，排除 BLOX 编辑器插件开发。原文 HEAD 与版本是历史核对基线，不表示当前已重新验收；实际接口以目标版本为准。先读 [AI 阅读入口](./AI-DEVELOPMENT.md)，源码链接相对于 deploy 目录。

## 1. 先分清三类产物

| 产物 | 技术形式 | 用途 |
|---|---|---|
| PHP 主题 | theme.json + layouts/blocks/partials/assets | 网站整体视觉、页头页脚和可覆盖的页面片段 |
| 站点覆盖 | overrides/ 内的受控 PHP 文件 | 单个客户站的定制，不直接改核心或官方主题 |
| BLOX 模板 | 受控 JSON 文档与元素依赖 | 可视化编辑的页面、区块、页头、页脚、弹窗 |

PHP 主题 ZIP 不是 BLOX JSON 包。不要把一整张 HTML 页面塞进一个文字元素，就称为可编辑模板；也不要为了更换视觉修改产品查询、会员权限或提交业务。

## 2. 源码与运行目录的边界

- 核心默认主题源码：`themes/default/`。
- 官方可安装主题源码：`marketplace/themes/<slug>/`，如 Business、Minimal、Aurora、Trade。
- 客户安装后的运行目录：`themes/<slug>/`。除核心默认主题外，不把客户目录当作官方源码维护，升级也不能随意覆盖客户定制。
- 单站覆盖：`overrides/`。它可能优先于主题，因此切换主题后仍生效；排查样式时不要遗漏。
- 官方主题和编辑器功能分别提交。正式版本提升 theme.json.version，需要新 CMS 能力时提高 requires_cms 并声明实际依赖。

完整安装包由构建流程选择性纳入官方主题，主题作者不能以“本机 themes 下有”代替安装包实际清单。

## 3. 推荐主题结构

```text
acme-corporate/
  theme.json
  design-tokens.json
  layouts/
    header.php
    footer.php
  blocks/
    banner.php
    about.php
    cta.php
  partials/
    product-card.php
  assets/
    css/theme.css
    js/theme.js
    images/screenshot.jpg
  README.md
  CHANGELOG.md
```

header.php、footer.php 是校验要求的基础文件。其他文件根据实际覆盖范围提供，不必为了凑目录复制全部核心代码。

`pages/` 可用于项目已有调用点，但**创建一个文件不会自动建立路由**。YikaiCMS 不是 WordPress 的文件名模板层级系统；开发时必须找到入口实际调用的 `theme_path()`。

同样，不要假设 `functions.php`、自定义 `lang/` 或任意 schema 文件放进主题就会自动执行/注册。没有明确加载器的约定不能写成接口。

## 4. theme.json

```json
{
  "schema_version": 1,
  "name": "企业主题示例",
  "name_en": "Corporate Example",
  "name_ja": "企業サイトのサンプル",
  "description": "企业网站主题开发起点。",
  "description_en": "A starting point for corporate websites.",
  "description_ja": "企業サイト向けテーマの開発例です。",
  "version": "1.0.0",
  "author": "Your Team",
  "category": "general",
  "requires_cms": ">=1.19.9",
  "requires_php": ">=8.2.0",
  "required_plugins": [],
  "screenshot": "assets/images/screenshot.jpg",
  "design_tokens": "design-tokens.json"
}
```

以上最低版本只是本指南示例基线，不代表已经验证该主题。

当前 ThemeValidator 的关键规则：

- schema_version 当前为 1；name 必须存在。v1 要求 version、author，version 为三段版本号。
- 目录 slug 使用小写字母、数字、连字符。
- 基础文件为 layouts/header.php、layouts/footer.php。
- requires_cms / requires_php 使用实际支持的简单约束，推荐明确 `>=x.y.z`。当前解析器不等同 Composer，不能依赖复杂表达式或 `^` 的 Composer 上界语义。
- required_plugins 用于声明真实依赖；仍应测试插件缺失/停用时的表现。
- screenshot 指向包内真实文件。封面应来自实际主题效果，不是实现中不存在的设计稿。
- 已废弃的 supports/locales/colors 不应继续添加；区块覆盖由文件系统推导，颜色改用 design-tokens.json，演示内容不是 locales 自动导入。
- category 当前词表包括 general、manufacturing、trade、tech、creative、services、retail。

旧 schema 包存在宽松兼容，不等于新包可以省略必要字段。错误和警告分别处理，不要把“能解压”当作兼容验证。

## 5. 模板和资源到底如何解析

### 5.1 当前主题选择

`currentTheme()` 使用 `ThemeRuntime::resolve()`。请求主题缺少 theme.json 或基础头尾时，整个主题选择回退 default。

### 5.2 单个模板文件

`theme_path('blocks/about.php')` 的当前解析顺序：

1. 站点 `overrides/blocks/about.php`。
2. 当前有效主题 `themes/<current>/blocks/about.php`。
3. 核心对应回退文件。

核心回退规则为：

| 请求 | 核心回退 |
|---|---|
| `layouts/header.php` | `includes/header.php` |
| `layouts/footer.php` | `includes/footer.php` |
| `blocks/about.php` | `includes/blocks/about.php` |
| `partials/example.php` | `includes/partials/example.php` |
| `pages/example.php` | 站点根目录 `example.php`，仍需实际调用点 |

**不要写成“任何文件缺失都去 themes/default 查找”。** 整体主题选择回退与单文件解析是两件不同的事。

`theme_path()` 返回路径不保证最终文件存在；可选片段使用 `theme_path_optional()`，返回 null 时处理降级。传入的文件名必须由代码常量或白名单产生，不能直接使用 GET/POST 拼 include 路径。

### 5.3 资源 URL

```php
<link rel="stylesheet" href="<?= e(theme_asset('css/theme.css')) ?>">
<script src="<?= e(theme_asset('js/theme.js')) ?>" defer></script>
```

theme_asset 先查当前主题 assets，缺失时使用核心 `/assets/` 对应路径，并经 assetVer 处理。它不意味着 overrides 的资源会自动覆盖主题文件，也不会自动生成缺失资源；正式包必须检查真实资源。

## 6. 最稳妥的起步方式

1. 在自己的开发工作树制作新 slug，保留清晰源码目录，不直接改线上客户主题。
2. 阅读 default 的完整 header/footer 与目标官方主题，而不是只复制页面截图中的 HTML。
3. 从完整基础壳开始，保留元信息、语言导航、资源、钩子、BLOX 区域/管理入口的现有逻辑，再缩小范围修改视觉。
4. 首先完成一个首页区块和一个列表卡片；确认数据、空值、移动端及多语言后再扩展其他页面。
5. 通过后台当前主题安装/切换流程验收。直接写 current_theme 数据库值不能替代完整激活流程验证。

主题 PHP 新代码应声明严格类型；变量输出用 `e()`。只有来自已有受控渲染器的 HTML 才能直接输出，不能用“管理员输入”作为任意 HTML 永久可信的理由。

## 7. 数据与模板的职责

页面入口/控制器准备数据，模板负责呈现。优先复用现有上下文，不在每个卡片重新读取分类、正文或全站配置。

当前产品详情由 ProductDetailController 准备产品、分类、相册、参数及关联内容；文章入口使用 ContentDetailController，但 `article.php` 会映射为 `$article` 等变量。变量名必须跟真实调用点一致，不保证所有模板都有 `$content`。

例如以下片段仅适用于调用方已经提供 `$product` 的卡片位置：

```php
<?php
declare(strict_types=1);
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}
$title = (string) ($product['title'] ?? '');
$url = productPrettyUrl($product);
?>
<article class="acme-product-card">
    <h3><a href="<?= e($url) ?>"><?= e($title) ?></a></h3>
    <?php if ((string) ($product['summary'] ?? '') !== ''): ?>
        <p><?= e((string) $product['summary']) ?></p>
    <?php endif; ?>
</article>
```

这不是自动生效文件；应在既有产品列表卡片调用处使用相同变量契约。完整产品卡片再接入项目现有图片 helper、无图状态与资源比例，不能把图片路径和产品名称写死。

- URL 使用项目路由 helper，如产品的 productPrettyUrl、语言的 langUrl/langPrefix，而不是写死 slug 规则。
- 字段缺失时隐藏可选项或使用受控占位，不显示假价格、假参数、假统计。
- 主题不负责绕过会员正文限制、重建询价接口或修改浏览计数。
- 有动态 HTML 时使用既有正文/区块渲染器，保留净化与过滤链；不要把整篇正文用描述字段规则重新清洗而丢失合法结构。

## 8. 首页区块、背景和视频

首页当前有明确的区块映射/渲染流程。新增一个 blocks 文件不等于后台自动出现同名区块，需先找到 index.php 的映射及对应配置。

- 复用 `$block` 和现有背景 helper，避免另写一套只在主题中有效的背景配置。
- 区分背景色、图片、遮罩和视频；颜色选择不能误删用户已选择的图片/视频。
- 视频保持静音、适当自动播放策略、poster 和失败回退；触屏/节流/禁用自动播放时也应有可读背景。
- 用户配置的 banner 视频和 CTA 视频属于站点数据，主题升级不得重置。
- 头尾钩子 ik_head / ik_footer_scripts 必须按现有壳保留且避免重复。缺失会使插件资源和功能消失。
- 经典首页、BLOX 首页、自定义页头/页脚分别验收，不能只看一种组合。

## 9. 颜色与样式设置

design-tokens.json 的当前简化结构：

```json
{
  "schema_version": 1,
  "colors": {
    "primary": "#2563EB",
    "secondary": "#0F766E"
  },
  "preview": ["#2563EB", "#0F766E", "#FFFFFF"],
  "palettes": [
    {
      "name": "企业蓝绿",
      "name_en": "Corporate Blue and Green",
      "name_ja": "ブルーとグリーン",
      "primary": "#2563EB",
      "secondary": "#0F766E"
    }
  ]
}
```

ThemePalette 实际读取颜色与预设。不要把任意自定义 CSS 变量写进该 JSON，就认为核心会自动注入。design_tokens 当前要求主题根下的安全文件名，不使用跨目录路径。

ThemeSettings 管理站点/主题样式配置，包含布局、排版、间距、按钮和响应式等；它与 BLOX 的命名样式/颜色 token 有不同职责。不要因为名称相近就直接覆盖其 JSON。

主题升级只调整出厂视觉和代码，用户已保存设置优先保留。新 token 或设置字段先定义规范化、默认值和读取位置，再接控件。

## 10. Tailwind 与独立 CSS

项目当前构建入口：

```text
bash tools/build_css.sh
```

当前脚本要求 Tailwind 4.3.3，输入 assets/css/src/app.css，输出 assets/css/tailwind.css。以脚本实际版本为准，不手工编辑编译结果。

- 开发修改模板增加 Tailwind class 后应重新编译；动态拼接如 `bg-` + 用户值通常不能被静态扫描可靠发现，使用受控完整 class 映射或既有安全内联变量。
- 官方源码位于 marketplace 时，核实扫描是否包含该目录或构建暂存结果，不能只因为运行 themes 目录能显示就认定发布包正确。
- 第三方主题不能要求客户每次安装都重编核心 CSS。自带必要的作用域 CSS，避免重复引入一整套会重置全站样式的 preflight。
- JS/CSS/字体/图标自托管。主题脚本只初始化自己的节点，幂等，避免覆盖核心或 BLOX 的全局事件。
- 桌面、平板、手机，以及长中文/英文/日文均检查；图片比例和控制尺寸稳定，不让 hover 或加载状态推动布局。

## 11. BLOX 模板制作

### 11.1 版本与类型

核对的主仓 1.19.9 类型为 `section`、`page`、`header`、`footer`、`popup`。本地开发树已经出现 `product-detail` 基础接入；文章详情与更完整条件仍在后续任务中。发布包以目标版本的 BloxTemplateModel::TYPES 为准，不能提前承诺 `article-detail`。

### 11.2 最小 JSON 包

```json
{
  "format": "yikaicms-blox-template",
  "version": 1,
  "type": "section",
  "name": "简洁标题区块",
  "thumbnail": "",
  "requires": {
    "elements": ["heading"],
    "plugins": []
  },
  "document": [
    {
      "type": "section",
      "settings": {
        "padding": "md",
        "max_width": "default"
      },
      "columns": [
        {
          "span": 12,
          "elements": [
            {
              "type": "heading",
              "data": { "text": "区块标题", "level": "h2" }
            }
          ]
        }
      ]
    }
  ]
}
```

示例参照仓库既有导入 fixture。正式制作优先在编辑器完成并导出，再用 BloxTemplateImporter 检查，不手写整站复杂 JSON。

- 包格式 version 和文档 schema 是两层概念，不与 CMS/theme 版本混用。
- 文档也可能使用包含 schema/settings/sections 的信封，以保留文档级设置；由当前导出器生成，避免手工丢字段。
- 当前导入器 JSON 限制为 2,000,000 字节；不得靠嵌入 base64 大图规避媒体管理。
- requires 除元素/插件外还可能包含设计依赖；由导出器推导/合并，不能删掉依赖让包看上去可用。
- 导入创建草稿，不应据此宣称已发布或已应用到网站。导出默认优先已发布数据，没有已发布时才可能使用草稿；编辑后要核对导出的究竟是哪版。
- 图片、视频、缩略图必须有合法可分发来源；引用本机 uploads 路径不意味着 JSON 自动包含媒体文件。需要在既有媒体/分发流程中一并处理并实际验证。
- 使用通用元素组合 FAQ、按钮、标题和联系区块，不为每个预置块单独写不可编辑的 HTML。
- header/footer/popup 条件规则与页面文档结构分开核对。跨站内容 ID、栏目 ID、预览样本不能直接作为新站有效绑定。

### 11.3 发布与默认回退

草稿保存、预览、发布、应用条件不是同一个动作。模板作者必须真实测试重新打开与前台结果，不能只确认编辑器即时预览。

产品和文章详情模板以目标版本实际实现为准，不把内部未来规划当作稳定接口。

## 12. 打包、升级和市场交付

PHP 主题 ZIP 使用单一 slug 目录，例如 `acme-corporate/theme.json`；包含基础头尾、使用到的资源及封面。不要包含站点 config、数据库导出、用户 uploads、.git、测试材料和密钥。

ThemeValidator 做清单/兼容检查，ThemeInstaller 处理包安装；市场下载的真实性、哈希/签名与目录一致性属于市场发行链。不能把本地 ZIP 安装当作完成官方市场签名发布。

发布检查：

1. 源码 theme.json.version、市场目录版本、ZIP 内版本一致。
2. requires_cms、requires_php、插件依赖与实际功能一致。
3. 包文件清单、必要媒体、SHA-256 与官方签名流程核对。
4. 新装和覆盖升级均验证；客户保存的配色、内容、视频、BLOX 自定义模板不被重置。
5. theme 切换失败或目录缺失时有可用回退，不出现半套头尾混用。
6. 版本日志写清改动与最低依赖，正式市场发布需明确授权。

用户演示内容与主题代码分离。不要通过安装主题导入一份生产数据库，也不要修改管理员账号或安装锁。

## 13. 最小验收清单

- 主题目录校验无 errors，warnings 有解释。
- 首页、产品列表/详情、文章详情、单页、404 正常；非空内容与无图/无数据状态都有检查。
- 三个视口和三种界面语言，无文字溢出、双重画布滚动或导航遮挡。
- 页头页脚钩子、插件资源、BLOX 区域和经典布局切换正常。
- 图片/视频正常，视频不能播放时 poster 和文字仍可读。
- 缓存失效后前台与编辑结果一致，匿名与管理员状态分别检查。
- 模板 JSON 导入、保存、发布、导出再导入可用，缺依赖明确报错。
- 主题更新保留客户数据和自定义文件边界。

相关测试可从 ThemeValidatorTest、ThemeTemplateResolutionTest、ThemeMarketTest、ThemeInstallerTest、BloxTemplateImporterTest 开始。日常只跑相关用例；正式集成/交付遵守完整门禁，本文不宣称这些测试已执行。

## 14. 源码索引

- [主题路径和资源 helper](../includes/functions.php)
- [主题有效性回退](../includes/ThemeRuntime.php)
- [主题清单校验](../includes/ThemeValidator.php)
- [主题安装器](../includes/ThemeInstaller.php)
- [颜色预设](../includes/ThemePalette.php)
- [主题样式设置](../includes/ThemeSettings.php)
- [默认主题清单](../themes/default/theme.json)
- [默认主题页头](../themes/default/layouts/header.php)
- [默认主题页脚](../themes/default/layouts/footer.php)
- [BLOX 模板导入导出](../includes/builder/BloxTemplateImporter.php)
- [BLOX 模板类型](../includes/models/BloxTemplateModel.php)
- [BLOX 文档规范化](../includes/builder/BloxDocumentPipeline.php)
- [Tailwind 构建脚本](../tools/build_css.sh)

维护规则：核心加载、清单 schema、导入格式或稳定类型变化时同步修订本指南；保持“实际接口”和“未来规划”分开，不用旧注释替代当前调用链。
