# YikaiCMS 插件开发指南

文档版本：0.3。更新：2026-09-18。对象：为 YikaiCMS 编写业务扩展和后台工具的开发者。

本文根据当前主仓源码整理，不是 WordPress 插件教程。源码核对基线：YikaiCMS v2.0.0，提交 `95ff9bedecdd454a6998195ce025afd32c946460`（0.2 版历史基线为 v1.19.9 `c97ca429`）。本版指南已按当前的插件依赖声明、整站模板插件数据接入、插件安装与来源回执规则复核。代码须兼容 PHP 8.0（产品运行下限），推荐 8.2+；数据库兼容 MySQL 5.7 / MariaDB 10.x 与 SQLite。

示例是开发起点，尚未安装到站点或进行浏览器验收。发布前应针对目标 CMS 版本验证。本指南不把开发分支的未发布能力视为稳定公共接口。

本文由原完整指南整理入项目，排除易开网页构建器（Yikai Builder，源码中仍称 BLOX）的编辑器插件开发。原文 HEAD 与版本是历史核对基线，不表示当前已重新验收；实际接口以目标版本为准。先读 [AI 阅读入口](./AI-DEVELOPMENT.md)，源码链接相对于 deploy 目录。

## 1. 什么时候使用插件

| 需求 | 推荐方式 |
|---|---|
| 增加业务功能、后台管理工具、第三方服务连接 | 插件 |
| 调整整个网站的视觉和页面片段 | 主题，见[主题与模板指南](./THEME-DEVELOPMENT.md) |
| 仅一个站点的少量模板覆盖 | 已有 overrides 覆盖层 |
| 修改 CMS 内核行为但没有现成扩展点 | 先提出最小扩展点，不直接复制一份内核到插件里 |

插件与 CMS 在同一 PHP 进程运行，不是隔离沙箱。安装 PHP 插件意味着信任其代码；不要安装来源不明的包。

## 2. 目录和命名

以 `acme-note` 为例，安装目录为站点的 `plugins/acme-note/`：

```text
acme-note/
  plugin.json
  register.php          可选：注册钩子
  main.php              可选：运行期逻辑或钩子注册
  admin.php             可选：后台配置页
  lang/
    zh-CN.php
    en.php
    ja.php
  assets/
    note.css
  README.md
  CHANGELOG.md
```

- slug 使用小写字母、数字、连字符，不能以连字符开头或结尾。不要使用中文、空格、下划线或路径分隔符。
- `plugin.json` 的 slug 来自目录名，不是 manifest 中任意填写的覆盖值。
- 当前自动入口是 `register.php` 和 `main.php`，不是 `plugin.php`。
- `_example` 目录属于参考资料，其下划线名称不符合正常插件加载规则。复制后必须改成合法 slug，并修订示例代码。
- 函数、类、设置键、CSS 类、DOM ID 和语言键都加插件前缀，避免与核心或其他插件冲突。
- 每个新 PHP 文件添加 `declare(strict_types=1);`。非独立入口应检查 `ROOT_PATH`，禁止被公网直接调用。

## 3. plugin.json

```json
{
  "name": "站点提示",
  "name_en": "Site Note",
  "name_ja": "サイトのお知らせ",
  "version": "1.0.0",
  "description": "在页脚显示一条可配置的纯文字提示。",
  "description_en": "Displays a configurable plain-text footer note.",
  "description_ja": "フッターに設定可能なテキストを表示します。",
  "author": "Your Team",
  "requires_php": "8.0",
  "requires_cms": "1.20.0"
}
```

使用清晰的三段版本号。兼容版本应填写实际验证过的最低版本，不能因为某个旧示例写了 1.0.0 就沿用。`requires_php` 默认写产品下限 8.0；只有代码确实用到 8.1+ 语法或函数时才提高，并在运行时受控检查。

付费插件在 plugin.json 中另加 `tier` 与 `module`（授权模块名）：`freemium` 表示基础功能免费、可直接安装，Pro 能力在运行时用 `license_has_module()` 判断；`pro` 表示整包付费，插件市场只向持有该模块且未过期的授权下发下载地址。两者都需在运行时自行检查授权，市场下载闸不能代替运行时判断。

当前插件安装/启用流程的校验能力与主题校验器不同，不能把 `requires_php` / `requires_cms` 当成所有路径都会严格拦截的保证。依赖特定类或能力时，运行时也要受控检查并在后台提示，不允许直接致命错误。

## 4. 加载顺序与生命周期

`includes/plugin.php` 从数据库读取已启用插件，再执行两个阶段：

1. 对全部已启用插件加载语言包及 `register.php`。
2. 对全部已启用插件加载 `main.php`。
3. 触发 `plugins_loaded`。

重要区别：不是 A 插件 register/main 都执行完才加载 B。不要依赖数据库返回顺序，跨插件调用应显式检查对方能力。

前台初始化结束会触发 `init`。后台有自己的鉴权和加载流程，不能假设所有前台钩子也在后台触发。CLI 也可能加载不同上下文。

当前核心的启用/停用主要更新插件状态。**不要假设存在 WordPress 的 `register_activation_hook()`，或自动调用 `install.php` / `uninstall.php` 的机制。** 写入这些文件并不会自动获得生命周期执行。

首次初始化或数据升级应设计为显式、鉴权、幂等的管理动作；不得每次前台访问运行 DDL。核心迁移与插件自身初始化不是一回事。

## 5. 最小插件：纯文字页脚提示

### 5.1 main.php

```php
<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

add_action('ik_footer_scripts', static function (): void {
    $text = trim((string) config('acme_note_text', ''));
    if ($text === '') {
        return;
    }

    echo '<link rel="stylesheet" href="'
        . e(assetVer('/plugins/acme-note/assets/note.css')) . '">';
    echo '<p class="acme-note">' . e($text) . '</p>';
});
```

### 5.2 assets/note.css

```css
.acme-note {
  margin: 0;
  padding: 12px 16px;
  color: #334155;
  background: #f3f4f6;
  font-size: 14px;
  line-height: 1.6;
  text-align: center;
  overflow-wrap: anywhere;
}
```

此例仅展示钩子、设置读取、输出转义和自托管资源；没有使用第三方脚本。实际项目可将 CSS 放到 `ik_head` 输出，避免页尾才加载导致闪动。样式应只作用于插件命名空间。

### 5.3 admin.php

由 `/admin/plugin_page.php?plugin=acme-note` 加载，不独立开放插件 PHP URL。当前路由要求登录、插件启用及 `requirePermission('*')`，插件自己包含后台 header/footer。

```php
<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

checkLogin();
requirePermission('*');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verifyCsrf();
    $raw = $_POST['note'] ?? '';
    if (!is_string($raw)) {
        error(__('admin_illegal_request'), 422);
    }
    $text = mb_substr(trim($raw), 0, 300);
    settingModel()->set('acme_note_text', $text, 'plugin');
    HtmlCache::invalidate();
    header('Location: /admin/plugin_page.php?plugin=acme-note');
    exit;
}

$text = (string) config('acme_note_text', '');
require ROOT_PATH . '/admin/includes/header.php';
?>
<form method="post">
    <?= csrfField() ?>
    <label for="acme-note-text"><?= e(__('acme_note_label')) ?></label>
    <textarea id="acme-note-text" name="note" maxlength="300"><?= e($text) ?></textarea>
    <button type="submit"><?= e(__('acme_note_save')) ?></button>
</form>
<?php require ROOT_PATH . '/admin/includes/footer.php'; ?>
```

这是无装饰的功能骨架，正式配置页应沿用相邻后台表单样式，补充保存反馈。`csrfField()` 返回系统生成的可信 HTML，所以直接输出；普通文本仍用 `e()`。

后台已有 POST CSRF 校验和同源 fetch 注入支持，但这不意味着独立 AJAX/公网接口也自动安全。每个独立入口均需检查自己的初始化和鉴权链。

### 5.4 语言包

`lang/zh-CN.php`：

```php
<?php
declare(strict_types=1);
return [
    'acme_note_label' => '页脚提示',
    'acme_note_save' => '保存',
];
```

`lang/en.php` 返回同样两键，值为 `Footer note`、`Save`；`lang/ja.php` 值为 `フッターのお知らせ`、`保存`。每个文件同样声明严格类型并返回数组。

插件语言加载先以 zh-CN 兜底，再合并当前语言；核心已有键不会被插件覆盖。因此必须使用插件前缀，不能通过同名键偷偷重写核心文案。

## 6. 钩子 API 与实际覆盖范围

```php
add_action(string $hook, callable $callback, int $priority = 10): void;
do_action(string $hook, mixed ...$args): void;
add_filter(string $hook, callable $callback, int $priority = 10): void;
apply_filters(string $hook, mixed $value, mixed ...$args): mixed;
remove_action(string $hook, callable $callback, int $priority = 10): bool;
has_action(string $hook): bool;
has_filter(string $hook): bool;
```

数值较小的 priority 先执行。Filter 必须返回处理后的值。这里没有 WordPress 的第四个 `accepted_args` 参数，也不要假设有未核对的 `remove_filter()` 等接口。

| 钩子 | 用途 | 边界 |
|---|---|---|
| `plugins_loaded` | 所有已启用插件加载结束 | 不保证任意后台页面已经渲染 |
| `init` | 前台初始化完成 | 不等于后台/CLI 通用初始化 |
| `ik_head` | 头部资源或元信息 | 主题必须保留调用位置 |
| `ik_footer_scripts` | 页尾脚本/附加输出 | 主题必须保留调用位置，不能重复执行 |
| `admin_sidebar` filter | 修改后台菜单数据 | 菜单可见不是服务端权限检查 |
| `content_output` filter | 修改经过该过滤器的正文 | 当前已核对 `detail.php`；不能据此保证所有文章路由都会触发 |

旧示例注释列出的 `admin_init`、内容保存钩子等，不能单凭注释认定所有入口都触发。开发时用 `rg` 找真实 `do_action` / `apply_filters` 调用，核实参数、事务时机和路由覆盖；挂了回调不等于事件会发生。

## 7. 后台菜单

最简单的插件可以仅使用插件列表中的管理入口。确需菜单时，可在 register.php 注册 `admin_sidebar` filter，在现有分组的 items 中追加插件项：

```php
add_filter('admin_sidebar', static function (array $menu): array {
    if (!hasPermission('*') || !isset($menu['appearance'])) {
        return $menu;
    }
    $menu['appearance']['items'][] = [
        'key' => 'acme_note',
        'label' => __('acme_note_label'),
        'url' => '/admin/plugin_page.php?plugin=acme-note',
        'priority' => 90,
    ];
    return $menu;
});
```

另一现有接口是 `register_admin_menu($groupKey, $item, $groupDefaults)`，定义于后台的 sidebar_menu_api.php。必须在该 helper 已加载且 header 解析菜单之前调用；不要照搬历史示例挂到未经核实的 `admin_init`。

菜单过滤发生在当前排序阶段之后时，如需精确排序，应在 filter 中维护该分组顺序；不要认为追加项的 priority 会被自动再次排序。菜单隐藏不能取代路由的 `requirePermission()`。

## 8. 配置、数据和升级

- 读取配置：`config('acme_key', $default)`；插件独立设置可使用 `settingModel()->set('acme_key', $value, 'plugin')`。多个值需事务时沿用模型/数据库能力。
- 核心出厂设置才加入核心 defaults；第三方插件不要要求客户手改 `config/defaults.php` 或包含密码的 `config/config.php`。
- 业务 SQL 通过模型和 `db()`，表名使用 `DB_PREFIX`，输入用 `?` 参数。禁止拼用户输入、CTE、窗口函数和 MySQL 8 专用排序规则。
- 自建表使用插件前缀并有明确的 schema version。升级先检查是否已应用，重复执行安全；数据库迁移不能偷偷在每个访客请求里触发。
- CMS 核心迁移由 `Migrator::loadAll()` 管理。插件目录内放一个 migrations 文件夹，并不会自动被核心扫描；需明确自己受控的升级入口或经审核的核心迁移接入。
- 整站模板可以声明插件依赖。插件只有在明确提供可移植数据时才参与数据导出：用 `site_template_plugin_export` filter 返回符合当前契约的数据，并用 `site_template_plugin_import` action 接收导入数据；默认空结果表示不导出。只包含可公开迁移的数据，不包含凭据、订单、支付通知或会员地址。可参考 `plugins/shop/register.php` 与 `plugins/shop/lib/site-template.php`。
- 停用不等于清空数据。卸载/清除数据应是另一个有明确提示和授权的动作。
- 当前 ZIP 安装可能替换已有同名插件目录，**不要把用户上传、配置数据库、授权密钥或业务数据写进插件源码目录**。使用模型或站点运行数据目录，并设计访问控制。
- 输出变化要处理缓存；含会员或个人信息的输出不可进入公共整页缓存。不要以禁用全站缓存代替正确的缓存边界。

## 9. 安装、调试和打包

本地使用合法目录后，在后台插件管理页安装/启用；启用和停用会改数据库，请仅在开发站进行。现有 CLI：

```text
php bin/yikai.php plugin:list
php bin/yikai.php plugin:enable acme-note
php bin/yikai.php plugin:disable acme-note
```

ZIP 应包含唯一插件目录，例如 `acme-note/plugin.json`，不能把 plugin.json 裸放 ZIP 根部。包中不应有其他插件或站点路径，不包含 config.php、.git、依赖缓存、日志、测试登录态和密钥。

v1.20.0 起安装由 `PluginInstaller` 统一处理，并在插件目录写入来源回执 `.yikai-market-origin.json`（local / official / community）：

- 回执由站点生成。包内不得自带该文件，含此路径的 ZIP 会被拒绝。
- 本地上传的插件记为 local。之后市场不会以官方或社区更新覆盖它（来源不符会拒绝），因此**不要使用官方插件已占用的 slug**，也不要把本地改版冒充官方版本。
- 从插件市场安装时：只接受官方包地址或市场下载令牌，单包不超过 20 MB（`PluginMarketPackage::MAX_PACKAGE_BYTES`），并拒绝安装低于已装版本的包。
- 替换已有目录前安装器会备份原目录，但这不改变「不要把业务数据写进插件目录」的要求。

v1.20.1 起，安装种子（`install/sql/*.sql`）只登记随完整包提供的插件；不随包的插件（含官方市场插件）不得写进种子或迁移里预先启用，否则新装站点会留下没有目录的启用记录。需要预装的插件应随包分发，其余由站点从插件市场按需安装。


如果只是新增文本设置，不反复运行全站测试；涉及权限、数据库或渲染共用路径时增加针对性测试。正式合并/发布前仍按项目完整门禁执行。

官方市场的目录、包、哈希和发布权限属于发行流程。开发完成不等于已获发布授权；不要从开发机直接覆盖市场生产文件。

## 10. 常见问题

| 现象 | 先检查 |
|---|---|
| 安装后没效果 | 数据库是否启用、slug 是否合法、入口是否 register/main、主题是否调用钩子 |
| 后台看不到配置页 | 是否有 admin.php、正确 plugin 参数、登录/权限、启用状态 |
| 保存 403 | CSRF 字段/请求头、会话是否过期，不要关掉鉴权 |
| 改完前台不变 | 公共 HTML 缓存、静态页、资源版本、当前主题和路由 |
| 升级后数据丢失 | 数据是否误存在被替换的插件目录 |
| 钩子从不触发 | 找真实触发位置，不能只看示例注释或注册代码 |

## 11. 源码索引

- [插件加载器](../includes/plugin.php)
- [钩子 API](../includes/hooks.php)
- [插件管理与 ZIP 安装](../admin/plugin.php)
- [插件安装器](../includes/PluginInstaller.php)
- [安装来源回执](../includes/MarketInstallOrigin.php)
- [市场插件包限制](../includes/PluginMarketPackage.php)
- [后台插件路由](../admin/plugin_page.php)
- [后台鉴权](../admin/includes/auth.php)
- [设置模型](../includes/models/SettingModel.php)
- [后台菜单接口](../admin/includes/sidebar_menu_api.php)
- [插件 CLI](../includes/commands/plugin.php)

后续维护：接口发生变化时同时更新本指南和对应示例；历史插件中的宽松 HTML 输出、旧 PHP 注释、未验证钩子不能当作新开发的安全标准。
