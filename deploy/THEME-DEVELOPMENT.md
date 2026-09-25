# YikaiCMS 主题与构建器模板开发指南

适用对象：主题作者、项目交付开发者、易开网页构建器（Yikai Builder）模板制作者。

本指南按 YikaiCMS `2.0.0`、提交 `e751fd9f8af8acb12832b273ad7b07e30f10db4a` 的实际代码重新核对。产品运行下限为 PHP 8.0；数据库兼容目标为 MySQL 5.7 / MariaDB 10.x，同时支持 SQLite。后续版本如有变化，以目标版本源码和测试为准。

本文只讲现有扩展边界，不介绍如何修改核心，也不把尚未存在的约定写成接口。

## 1. 先理解三种不同的扩展物

| 扩展物 | 形式 | 适合解决的问题 |
|---|---|---|
| PHP 主题 | `theme.json`、PHP 布局/区块/片段、CSS/JS/图片 | 整站外观、页头页脚、传统首页区块、列表卡片等 |
| 单站覆盖 | `overrides/` 下与主题相同的相对路径 | 某一个客户站的局部定制 |
| 构建器模板 | `yikaicms-blox-template` JSON | 可视化编辑的区块、页面、页头、页脚、弹窗等 |

这三者不能混为一谈：

- PHP 主题 ZIP 不能当作构建器模板导入。
- 构建器模板 JSON 不会自动安装主题文件、PHP 代码或上传目录中的媒体。
- `overrides/` 的优先级高于当前主题，适合单站交付，不适合作为可分发主题的源码目录。
- 主题负责展示，不应复制文章、产品、会员、表单等业务逻辑。

另有一种交付物是**整站模板**：主题 + 栏目、内容、产品、表单定义、被引用媒体与声明的插件数据，打成一个包，在新站一次导入。流程与限制见 [整站模板工作流](./SITE-TEMPLATE-WORKFLOW.md)。v2.0.0 起「把本站保存为模板包」（导出）是专业版功能，导入与模板市场免费；导入要求模板与站点处于同一 CMS 版本线（主.次 版本相同，如 2.0.x）且模板制作版本不晚于站点。

## 2. 源码目录与运行目录

当前仓库把官方主题源码和客户站运行目录分开管理：

```text
themes/default/                  核心默认主题源码和运行目录
marketplace/themes/<slug>/       官方可选主题源码
themes/<slug>/                   站点安装后的主题运行目录
overrides/                       当前站点的最高优先级覆盖
```

具体规则：

1. 默认主题只在 [`themes/default/`](../themes/default/) 维护。
2. Business、Minimal、Aurora、Trade 等官方可选主题只在 [`marketplace/themes/`](../marketplace/themes/) 维护，不要反向编辑运行目录中的副本。
3. 完整安装包会由 [`build.sh`](../build.sh) 把指定的随包主题暂存到包内；当前随包主题是 Business 和 Minimal。Aurora、Trade 仍由主题市场提供。
4. 客户安装后的非默认主题属于站点运行数据，升级包不应无条件覆盖。
5. 修改官方可选主题后，应同步提升其 `theme.json.version`；使用了较新的 CMS 能力时，还要把 `requires_cms` 提高到真实最低版本。

## 3. 一个可安装主题的最小结构

```text
acme-corporate/
  theme.json
  layouts/
    header.php
    footer.php
```

`theme.json`、`layouts/header.php`、`layouts/footer.php` 是运行时识别主题的最低条件。缺少其中任何一个，`ThemeRuntime` 都不会把该目录当作可用主题，站点会整体回退到 `default`。

实际项目建议使用下面的结构：

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
    stats.php
  partials/
    article-card.php
    product-card.php
    pagination.php
  assets/
    css/
      theme.css
    js/
      theme.js
    images/
      screenshot.jpg
  README.md
  CHANGELOG.md
```

以下文件名没有自动加载约定：

- 放入 `functions.php` 不会自动执行。
- 放入 `lang/*.php` 不会自动注册语言包。
- 随意新增 `pages/foo.php` 不会自动创建 `/foo` 路由。

需要 PHP 行为扩展时，应做成插件并通过钩子挂载；主题本身尽量只保留展示模板和静态资源。

## 4. `theme.json` 清单

### 4.1 推荐示例

```json
{
  "schema_version": 1,
  "name": "企业主题",
  "name_en": "Corporate Theme",
  "name_ja": "コーポレートテーマ",
  "description": "适合企业官网的通用主题",
  "description_en": "A general-purpose corporate website theme",
  "description_ja": "企業サイト向けの汎用テーマ",
  "version": "1.0.0",
  "author": "Acme Studio",
  "category": "general",
  "requires_cms": ">=1.20.1",
  "requires_php": ">=8.0.0",
  "required_plugins": [],
  "screenshot": "assets/images/screenshot.jpg",
  "design_tokens": "design-tokens.json"
}
```

`requires_cms` 应填写实际能力下限，不要机械复制示例中的当前版本。

### 4.2 当前校验规则

| 字段 | 当前规则 |
|---|---|
| `schema_version` | 当前支持 `1`；缺失会按旧版清单处理并产生警告，未来版本号会报错 |
| `name` | 必填 |
| `version` | schema v1 必填，必须是三段式 SemVer，例如 `1.2.3` |
| `author` | schema v1 必填 |
| `requires_cms` | 可选但建议声明；安装时会与当前 CMS 版本比较 |
| `requires_php` | 可选但建议声明；安装时会与当前 PHP 版本比较 |
| `required_plugins` | 插件 slug 数组；单独安装主题时，缺失或未启用会阻止安装。主题随整站模板分发时，这些插件还必须写进整站包的插件清单；导入步骤会把它们标为「必需」并随导入一起安装启用，仍缺失则拒绝导入 |
| `category` | 可选；内置值见下方 |
| `name_en`、`name_ja` | 缺失会警告，不阻止安装 |
| `description_en`、`description_ja` | 缺失会警告，不阻止安装 |
| `screenshot` | 相对主题根目录；声明后文件不存在会警告 |
| `design_tokens` | 设计色板文件名；只能是主题根目录下的安全文件名 |

当前内置分类：

```text
general, manufacturing, trade, tech, creative, services, retail
```

版本约束解析器支持 `>=`、`<=`、`>`、`<`、`=`、`^`、`~`。为避免把它误认为 Composer 的完整语义，主题清单建议只使用简单、明确的比较式，例如 `>=1.20.1`。

旧字段 `locales`、`supports`、`colors` 目前只会产生弃用警告。不要在新主题中继续使用它们。

### 4.3 设计色板

如果声明了 `design_tokens`，可参考 [`themes/default/design-tokens.json`](../themes/default/design-tokens.json)：

```json
{
  "colors": {
    "primary": "#2563EB",
    "secondary": "#0F172A"
  },
  "preview": ["#2563EB", "#0F172A", "#F8FAFC"],
  "palettes": [
    {
      "name": "品牌蓝",
      "name_en": "Brand Blue",
      "name_ja": "ブランドブルー",
      "primary": "#2563EB",
      "secondary": "#0F172A"
    }
  ]
}
```

颜色必须使用六位十六进制形式 `#RRGGBB`。预览颜色最多取五个；无有效 `palettes` 时，系统会根据主色和辅色生成一个回退色板。

## 5. 模板是怎样被找到的

调用 `theme_path('相对路径')` 时，当前代码按下面顺序查找：

```text
overrides/<相对路径>
themes/<当前主题>/<相对路径>
核心回退路径
```

核心回退规则不是任意目录搜索：

| 请求路径 | 核心回退位置 |
|---|---|
| `layouts/header.php`、`layouts/footer.php` | `includes/header.php`、`includes/footer.php` |
| `pages/foo.php` | 项目根目录 `foo.php` |
| 其他相对路径 | `includes/<相对路径>` |

`theme_path()` 即使最终文件不存在也会返回计算出的路径；需要“存在才使用”时调用 `theme_path_optional()`。

### 5.1 主题不会替换整套路由

`article.php`、`product.php`、`list.php`、`page.php`、`search.php` 等根入口仍负责取数、权限、SEO 和页面流程。主题只替换入口明确加载的布局、区块和片段。

因此：

- 新增同名 PHP 文件不等于新增路由。
- 不要在主题里重新查询文章或产品来绕过控制器。
- 要覆盖某一块之前，先搜索该入口实际调用的 `theme_path()`。

### 5.2 区块覆盖

传统首页会按配置加载主题区块。核心可回退区块位于 [`includes/blocks/`](../includes/blocks/)：

```text
about.php
advantage.php
banner.php
channel.php
cta.php
partners.php
product_categories.php
stats.php
testimonials.php
timeline.php
```

主题只需提供真正要改变的文件。例如：

```text
themes/acme-corporate/blocks/banner.php
themes/acme-corporate/blocks/cta.php
```

其余区块会回退到核心实现。后台显示的“区块覆盖率”也正是按主题 `blocks/*.php` 与核心区块文件比较得出。

开始改造前，应复制当前核心或默认主题中的同名文件，保留控制器准备好的变量约定。例如：

| 模板 | 主要输入 |
|---|---|
| `blocks/banner.php` | `$banners`、`$block` |
| `blocks/about.php` | `$aboutChannel`、`$block` |
| `blocks/testimonials.php` | `$testimonials`、`$block` |
| `blocks/channel.php` | `$currentChannel`、`$block` |
| `blocks/partners.php` | `$links`、`$block` |

不要把这些变量改成主题专属查询，否则后台排序、多语言、缓存和构建器接管都可能失效。

### 5.3 片段覆盖

核心片段位于 [`includes/partials/`](../includes/partials/)。常见输入包括：

| 片段 | 主要输入 |
|---|---|
| `article-card.php`、`article-grid-card.php` | `$item`，可选 `$listOpts` |
| `product-card.php` | `$item`、`$isProductType` |
| `case-card.php` | `$item` |
| `job-card.php` | `$item` |
| `pagination.php` | `$page`、`$total`、`$perPage`、`$totalPages`、`$pageUrl` |
| `page-hero.php` | `$channel`、`$breadcrumbItems` |
| `breadcrumb.php` | `$breadcrumbItems`，可选 `$style` |
| `right_sidebar.php` | `$rightSidebarTitle`、`$rightSidebarChannels`、`$channelId`，可选 `$rightSidebarActiveId` |
| `404.php` | `$notFoundMessage` |

卡片模板列表由系统扫描下面三个位置的 `*-card.php` 得到：

```text
overrides/partials/
themes/<当前主题>/partials/
includes/partials/
```

## 6. 页头和页脚是运行时契约

最稳妥的做法是从当前 [`themes/default/layouts/header.php`](../themes/default/layouts/header.php) 和 [`footer.php`](../themes/default/layouts/footer.php) 复制，再改结构和样式。不要从只有 `<html>`、`<body>` 的演示片段开始生产主题。

### 6.1 页头必须保留的能力

当前页头负责或配合处理：

- 页面标题、关键词、描述、canonical、Open Graph、Twitter Card 和 JSON-LD。
- 多语言 `hreflang` 和语言切换。
- favicon、核心样式、主题样式与页面附加样式。
- `ThemeSettings::css()` 输出的站点外观变量。
- `ik_head`、`render_head`、`ik_header_after` 钩子。
- 后台配置的自定义 `<head>` 代码。
- 构建器发布的页头，以及原生导航回退。
- 登录管理员的可视化编辑标记。
- 页面级“隐藏页头”设置。

页面入口可能在加载页头前设置这些变量：

```php
$pageTitle;
$pageKeywords;
$pageDescription;
$canonicalUrl;
$ogType;
$ogImage;
$jsonLd;
$currentChannelId;
$currentSlug;
$navChannels;
$extraCss;
```

主题可以改变它们的渲染方式，但不应删掉对应能力。

### 6.2 页脚必须保留的能力

当前页脚负责或配合处理：

- 正确关闭 `<main>`、`<body>` 和 `<html>`。
- 构建器发布的页脚，以及原生页脚回退。
- 页面附加脚本 `$extraJs`。
- `ik_footer_scripts` 钩子。
- 在线客服渲染。
- 后台配置的自定义 body 代码。

如果构建器页头或页脚没有发布内容，原生主题布局必须仍然完整可用。

## 7. CSS、静态资源和站点外观设置

### 7.1 主题资源地址

使用 `theme_asset()` 生成带版本参数的地址：

```php
<link rel="stylesheet" href="<?= e(theme_asset('css/theme.css')) ?>">
<script src="<?= e(theme_asset('js/theme.js')) ?>" defer></script>
```

查找规则：

1. 当前主题存在 `assets/<文件>` 时，返回 `/themes/<主题>/assets/<文件>`。
2. 否则回退到核心 `/assets/<文件>`。

`theme_asset()` 不从 `overrides/assets/` 读取资源。需要单站资源时，应使用明确的站点资源路径，或把资源放入该主题目录。

所有资源应随包自托管，不要依赖 CDN。图标优先沿用所复制基础主题已经加载的本地图标集，避免同一页面重复加载多套字体。

### 7.2 Tailwind 的真实构建范围

核心样式唯一入口是 [`assets/css/src/app.css`](../assets/css/src/app.css)，当前固定使用 Tailwind CSS `4.3.3`。它会扫描根 PHP、`includes/`、`admin/`、`plugins/`、`themes/` 和 `marketplace/themes/` 中的 PHP 文件。

修改仓库内官方主题的 Tailwind class 后，运行：

```bash
bash tools/build_css.sh
```

脚本会拒绝版本不匹配的 Tailwind CLI，并生成 `assets/css/tailwind.css`。

第三方独立主题不能假设客户会重新编译核心 CSS。分发时应把主题自己的、已构建且作用域清晰的 CSS 放入 `assets/css/theme.css`。也不要手改生成文件 `assets/css/tailwind.css`。

### 7.3 尊重后台外观设置

`ThemeSettings` 会输出整站布局、字体、间距、按钮、背景和响应式设置。主题应尽量消费这些变量，而不是用大量 `!important` 覆盖：

```css
.yk-theme-shell {
  max-width: var(--yk-content-max-width);
  padding-inline: var(--yk-content-gutter);
  background: var(--yk-content-bg);
}

.yk-theme-button {
  border-radius: var(--yk-button-radius);
  background: var(--yk-button-bg);
  color: var(--yk-button-text);
}

.yk-theme-button:hover {
  background: var(--yk-button-hover-bg);
}
```

可用变量还包括：

```text
--yk-html-font-size
--yk-section-padding-y
--yk-site-bg
--yk-content-bg
```

## 8. 数据、安全、多语言和缓存

主题虽然主要负责展示，仍必须遵守核心安全边界。

### 8.1 输出转义

普通文本和属性使用 `e()`：

```php
<h2><?= e($item['title'] ?? '') ?></h2>
<a href="<?= e($item['url'] ?? '#') ?>"><?= e(__('read_more')) ?></a>
```

不要直接输出数据库字段。确实要输出富文本时，应复用相邻核心模板已经使用的清洗和渲染路径，不要自行放开原始 HTML。

### 8.2 数据边界

- 优先使用控制器传入的数据，不在模板中写业务 SQL。
- 确需读取数据时，使用现有模型工厂和 `db()`，始终参数化。
- 不在主题中实现登录、权限、支付、表单写入或文件上传。
- 不动态包含用户提供的路径，不使用 `eval`。

### 8.3 多语言和链接

- 界面文字使用 `__('key')`，并在核心支持的语言文件中补齐中文、英文、日文。
- 站内语言链接使用 `langUrl()` 等现有助手，不手工拼接语言前缀。
- URL、标题、摘要等字段沿用控制器或模型已经准备好的值。

可分发主题本身没有自动加载的私有语言包机制。如主题需要新增固定界面文案，应与插件或核心语言键方案一起设计，而不是在模板里硬编码三套分支。

### 8.4 缓存

前台有整页 HTML 缓存。主题改动没有立即显示时，应先确认：

1. 当前启用的主题是否正确。
2. `overrides/` 是否仍覆盖同名文件。
3. Tailwind 是否已经重编译。
4. 前台缓存是否已经清理。
5. 浏览器是否仍使用旧的 CSS/JS。

## 9. 易开网页构建器模板

界面对外名称是“易开网页构建器”或“Yikai Builder”；源码和模板包仍使用 Blox 命名。

### 9.1 当前模板类型

当前代码支持：

```text
section
page
header
footer
popup
archive
search
error404
product-detail
article-detail
```

其中页头、页脚、404、详情等模板会由对应运行时挂载点接管；没有已发布模板时，PHP 主题必须提供可工作的回退布局。

### 9.2 包格式

格式标识为 `yikaicms-blox-template`，当前包版本为 `1`，导入文件上限为 2,000,000 字节。一个最小区块示例：

```json
{
  "format": "yikaicms-blox-template",
  "version": 1,
  "type": "section",
  "name": "Basic heading",
  "requires": {
    "elements": ["heading", "text", "button"],
    "plugins": []
  },
  "document": {
    "schema": 1,
    "settings": {},
    "sections": [
      {
        "type": "section",
        "settings": {
          "padding": "lg",
          "max_width": "narrow",
          "gap": "sm",
          "align_items": "center",
          "justify_items": "center",
          "bg_color": "#ffffff"
        },
        "columns": [
          {
            "elements": [
              {
                "type": "heading",
                "data": {
                  "text": "一句话说清这一段讲什么",
                  "level": "h2",
                  "align": "center"
                }
              },
              {
                "type": "text",
                "data": {
                  "html": "<p>补充说明文字。</p>",
                  "align": "center"
                }
              },
              {
                "type": "button",
                "data": {
                  "text": "了解更多",
                  "url": "",
                  "variant": "outline",
                  "align": "center"
                }
              }
            ]
          }
        ]
      }
    ]
  }
}
```

完整示例见 [`templates/blox/`](../templates/blox/)。

### 9.3 不要手写复杂模板包

推荐流程：

1. 在目标版本的构建器中创建内容。
2. 保存并在前台验证桌面、平板和手机布局。
3. 从系统导出模板包。
4. 在一个干净站点重新导入验证。
5. 检查 `requires.elements`、`requires.plugins` 和设计依赖是否准确。

导入器会校验模板类型、名称、元素、插件和设计依赖，并拒绝 `code` 元素。导入结果先作为草稿保存，发布是另一项操作。不要把站点专属媒体 URL、跨站组件库引用或敏感数据放入模板包。

元素、区块和区块标题的「高级」设置（HTML ID、CSS 类、自定义属性、自定义 CSS）会随文档一起保存和导出。自定义 CSS 只接受净化后的样式：不能出现 `<`、`@import`、`expression()`、`javascript:` 或任何外部地址（`//`），花括号必须配平；`%root%` 指代本元素。新增或修改自定义 CSS 需要「全站设计」权限；类名 `yk-` 前缀与属性 `data-yk*` 留给系统。

## 10. 打包、安装、升级和删除

### 10.1 ZIP 结构

主题 ZIP 必须只有一个顶层主题目录：

```text
acme-corporate.zip
└─ acme-corporate/
   ├─ theme.json
   ├─ layouts/
   │  ├─ header.php
   │  └─ footer.php
   └─ ...
```

错误示例：

```text
theme.json                         缺少顶层目录
package/acme-corporate/theme.json  多包了一层 package
```

顶层目录名应与主题 slug 一致，只使用小写字母、数字和连字符。

### 10.2 安装器会检查什么

当前安装器会：

- 要求单一、安全的顶层目录。
- 检查 `theme.json`、页头、页脚和版本约束。
- 拒绝路径穿越、符号链接和保留的市场来源回执路径。
- 限制 ZIP 条目数、单文件大小、解压总量和异常压缩比。
- 先解压到暂存目录，校验通过后再替换目标目录。
- 替换失败时尝试恢复旧主题。
- 阻止普通本地包覆盖来源不同的已安装主题。

市场下载还受 50 MB 包大小上限、文件大小、SHA-256 和签名校验约束。官方市场包名遵循：

```text
<slug>-v<version>.zip
```

`.yikai-market-origin.json` 是安装器写入的来源回执，主题作者不得把它放进 ZIP。

### 10.3 默认主题和活动主题保护

- `default` 不能通过普通本地主题包覆盖或删除；只允许符合规则的官方更新。
- 当前正在使用的主题不能删除。
- 删除前会再次校验 slug、真实路径和主题根目录边界。

开发时不要靠手工删除目录模拟后台删除结果；应分别验证安装、升级、切换和删除流程。

## 11. 推荐开发流程

### 11.1 创建主题

1. 复制 [`themes/default/`](../themes/default/) 或最接近需求的 [`marketplace/themes/`](../marketplace/themes/) 主题源码。
2. 立即修改目录 slug、`theme.json`、截图和版本号。
3. 先保留完整页头页脚运行时契约，再调整结构和视觉。
4. 只覆盖真正需要改变的 `blocks/` 和 `partials/`。
5. 使用 `theme_asset()` 加载主题资源。
6. 使用 `ThemeSettings` 变量适配后台外观设置。
7. 如修改了官方主题中的 Tailwind class，运行 `bash tools/build_css.sh`。
8. 按单一顶层目录打 ZIP，在干净站点试装。

### 11.2 最低验收清单

- [ ] `theme.json` 能被 `ThemeValidator` 校验，无阻断错误。
- [ ] PHP 8.0 语法可用，没有 PHP 8.1+ 专属语法/API。
- [ ] 未启用构建器页头页脚时，原生页头页脚完整。
- [ ] 启用构建器页头页脚时，没有重复导航或重复闭合标签。
- [ ] 首页传统区块与构建器首页都能正常显示。
- [ ] 文章列表、产品列表、详情、搜索、404 和分页正常。
- [ ] 中文、英文、日文页面没有新增的硬编码界面文案。
- [ ] 所有动态文本和属性均正确转义。
- [ ] 后台站点宽度、字体、按钮、背景和响应式设置仍生效。
- [ ] 桌面、平板、手机宽度均检查过导航、卡片、表格和长文本。
- [ ] CSS、JS、图片和字体均为本地资源，无 CDN 依赖。
- [ ] 清理缓存后再次验证前台。
- [ ] ZIP 可全新安装、覆盖升级和回滚，活动主题不能被误删。
- [ ] 构建器模板可导出、在干净站点导入，并作为草稿再次编辑。

### 11.3 相关自动化测试

主题机制的主要测试位于：

```text
tests/Unit/ThemeRuntimeTest.php
tests/Unit/ThemeValidatorTest.php
tests/Unit/ThemeTemplateResolutionTest.php
tests/Unit/ThemeInstallerTest.php
tests/Unit/ThemeMarketTest.php
tests/Unit/ThemePaletteTest.php
tests/Unit/ThemeSettingsTest.php
tests/Unit/ThemePackagingPolicyTest.php
```

修改主题基础设施时，至少运行对应测试；合并前仍应按仓库开发约定执行全量门禁。只改某个主题视觉时，也必须在该工作树的真实站点前台检查受影响页面。

## 12. 代码索引

| 主题 | 当前实现 |
|---|---|
| 当前主题与路径解析 | [`includes/functions.php`](../includes/functions.php)、[`includes/ThemeRuntime.php`](../includes/ThemeRuntime.php) |
| 清单校验与覆盖率 | [`includes/ThemeValidator.php`](../includes/ThemeValidator.php) |
| 本地 ZIP 安装、升级、删除 | [`includes/ThemeInstaller.php`](../includes/ThemeInstaller.php) |
| 市场目录和下载校验 | [`includes/ThemeMarket.php`](../includes/ThemeMarket.php) |
| 安装来源回执 | [`includes/MarketInstallOrigin.php`](../includes/MarketInstallOrigin.php) |
| 设计色板 | [`includes/ThemePalette.php`](../includes/ThemePalette.php) |
| 站点外观设置 | [`includes/ThemeSettings.php`](../includes/ThemeSettings.php) |
| 默认主题参考 | [`themes/default/`](../themes/default/) |
| 官方可选主题源码 | [`marketplace/themes/`](../marketplace/themes/) |
| 核心区块与片段 | [`includes/blocks/`](../includes/blocks/)、[`includes/partials/`](../includes/partials/) |
| 构建器模板模型 | [`includes/models/BloxTemplateModel.php`](../includes/models/BloxTemplateModel.php) |
| 构建器模板导入导出 | [`includes/builder/BloxTemplateImporter.php`](../includes/builder/BloxTemplateImporter.php) |
| 元素 / 区块高级设置与自定义 CSS 净化 | [`includes/builder/BloxCustomCode.php`](../includes/builder/BloxCustomCode.php) |
| 整站模板导出、导入与插件处理 | [`includes/SiteTemplateService.php`](../includes/SiteTemplateService.php)、[`includes/SiteTemplateArchive.php`](../includes/SiteTemplateArchive.php) |
| 构建器模板示例 | [`templates/blox/`](../templates/blox/) |
| 核心 CSS 构建 | [`tools/build_css.sh`](../tools/build_css.sh)、[`assets/css/src/app.css`](../assets/css/src/app.css) |
| 安装包主题策略 | [`build.sh`](../build.sh)、[`marketplace/README.md`](../marketplace/README.md) |

## 13. 常见错误

| 现象 | 优先检查 |
|---|---|
| 切换主题后仍显示默认主题 | `theme.json`、页头、页脚是否齐全，slug 是否合法 |
| 改了模板但前台不变 | `overrides/`、当前主题、HTML 缓存、浏览器缓存 |
| 新 Tailwind class 没样式 | 是否用 4.3.3 重新运行 `tools/build_css.sh` |
| 本地可见，装包后资源 404 | ZIP 路径、文件名大小写、是否使用 `theme_asset()` |
| 后台外观设置失效 | 是否删除 `ThemeSettings::css()` 或用高优先级 CSS 覆盖变量 |
| 构建器页头出现双导航 | 主题页头是否保留了“构建器优先、原生回退”的判断 |
| 主题升级被拒绝 | 版本未提高、来源不一致、CMS/PHP/插件约束不满足 |
| 主题无法删除 | 它是 `default`、当前活动主题，或路径不在主题根目录内 |
| 模板 JSON 导入失败 | 类型、元素、插件、设计依赖、文件大小或 `code` 元素不符合规则 |

主题开发的核心原则只有三条：保留运行时契约、只覆盖展示层、让缺失部分能够安全回退。遵守这三条，主题才能同时适配传统页面、易开网页构建器、后台外观设置和后续升级。
