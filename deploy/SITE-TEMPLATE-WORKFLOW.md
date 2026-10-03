# 整站模板工作流

入口位于后台「建站向导」：整站模板、整站模板市场、主题内容、内容检查。主题管理也可进入主题内容。

## 导出与安装

导出（「把本站保存为模板包」）是专业版功能，需要有效注册码（`license_allows_site_template_export()`，与后台白标同一口径）；导入与整站模板市场免费。

整站 ZIP 包含当前主题、公开内容、栏目/产品关系、表单定义、布局、已引用的本地上传媒体，以及启用插件声明的公开数据；不导出管理员、会员、客户提交记录、站点域名、邮件凭据及授权。站外图片仍是外链，不自动下载。导出会保留原站数据。

全新站点（新版安装器记录、尚未改动）可直接导入。已有内容的站点也可以导入：导入确认步骤会说明哪些内容将被替换（管理员、会员和留言保留）、给出备份入口，管理员勾选「我已备份数据库，确认清空当前网站内容并导入模板」后才会替换。导入前显示模板和内容数量，管理员填写站点基本资料。

主题 PHP 是可执行代码：本地上传的整站包需管理员确认信任来源；从官方整站模板市场下载的包已在服务端验签、验包，不再要求这一步（替换确认仍然需要）。导入要求模板与本站的 CMS 主版本相同（如 2.x），且模板制作版本不晚于本站（2.0.4 起放宽：2.0 做的模板在 2.1、2.2 上也能导入；此前要求主.次版本相同）；表结构必须完全一致。不支持运行时配置覆盖或模板 overrides 的打包导入。主题 ZIP 与整站 ZIP 不是同一种格式。

## 插件

整站包的清单声明所需插件。导入步骤逐个列出并默认勾选：本站已有的直接启用，官方插件市场有的由站点下载、校验后安装启用（与插件页同一条链，`PluginMarketInstall`），装好后刷新预览，再导入它们的数据。主题 `required_plugins` 必须写进包的插件清单，在导入页标为「必需」、不可取消；仍缺失则拒绝导入。插件数据格式见 [SITE-TEMPLATE-PLUGIN-DATA.md](SITE-TEMPLATE-PLUGIN-DATA.md)。

限制：ZIP 32 MB、展开 48 MB、单文件 12 MB、最多 3000 文件。校验摘要、路径、文件类型、结构和数据关系后再写入。新主题和上传目录使用独立随机命名空间，不覆盖原主题文件。数据库部分使用事务。

## 恢复

导入前的内容备份和状态保存在不可直接访问的受保护记录中，重新登录后仍能查看恢复入口。仅当导入后的内容未被修改、未收到新提交等数据时允许恢复，防止覆盖后续工作。

恢复后，导入产生的主题/媒体文件保留为未使用资产；不自动删除。不要把恢复按钮当作任意时间点的全站备份，亦不包含服务器配置恢复。

## 主题内容面板

主题可增加 `content-fields.json`：

```json
{
  "version": 1,
  "fields": [
    {"key":"hero_title","type":"text","label":{"zh-CN":"首页标题","en":"Hero title","ja":"メインタイトル"},"default":"Welcome"},
    {"key":"hero_image","type":"image","label":{"zh-CN":"首页图片","en":"Hero image","ja":"メイン画像"},"default":""}
  ]
}
```

支持 text、textarea、image、url、toggle，最多 40 字段。不同语言分别保存；图片使用已有媒体选择器。模板用 `e(themeContent('hero_title', 'Welcome'))` 读取并转义；URL 字段仍须按用途使用现有 URL 安全助手。保存立即影响前台，旧页面提交会提示重新加载。布局编辑继续使用现有构建器。

### 让字段出现在 Blox 编辑器里（`area`）

字段可加可选的 `area`，声明它属于页面的哪个位置（2.0.1 起）：

| `area` | 在 Blox 里的位置 | 值存在哪 | 模板怎么读 |
|---|---|---|---|
| `home:<首页区块类型>`，如 `home:about`、`home:advantage`、`home:cta`、`home:banner`；`home:channel` 对所有栏目区块生效 | 选中该首页区块时，设置面板「内容」里多出这些控件；首页内容面板有「主题内容」一组 | 区块数据 `tc_<key>`，随首页草稿/发布走 | 区块模板里 `e($ykThemeField('about_kicker', '兜底文字'))`；元素加 `<?= $ykHomeFieldAttr('tc_about_kicker') ?>` 即可在画布里直接点改 |
| `header` / `footer` | 画布上点主题默认的网页头/网页尾，右侧打开「主题内容 · 页头/页尾」，保存即全站生效 | 同主题内容页（按语言） | 布局文件里 `e(themeContent('header_cta_text', '兜底文字'))` |
| 不写 | 只在「主题内容」页编辑 | 同上 | `themeContent()` |

取值顺序：区块覆盖 `tc_<key>`（留空＝不覆盖）→ 主题内容页的站点值 → 声明里的 `default` → 模板兜底文字。主题内容页（及页头/页尾面板）保存的空值就是空，不回退默认——想隐藏某段文字可以清空。区块控件支持 text / textarea / url / image，toggle 只在主题内容页设置。`url` 字段在保存管线里按链接规则清洗，模板输出仍用 `e()`。首页区块的值与首页其他覆盖项一样不分语言；需要按语言区分的文字放 `header` / `footer` 或不写 `area`。

```json
{"key":"about_kicker","type":"text","area":"home:about","label":{"zh-CN":"关于 · 小标签","en":"About · eyebrow"},"default":"01 / THE IDEA"}
```

```php
$themeField = isset($ykThemeField) && is_callable($ykThemeField)
    ? $ykThemeField : static fn (string $key, string $fallback = ''): string => themeContent($key, $fallback);
$fieldAttr = isset($ykHomeFieldAttr) && is_callable($ykHomeFieldAttr)
    ? $ykHomeFieldAttr : static fn (string $field): string => '';
?>
<span class="kicker"<?= $fieldAttr('tc_about_kicker') ?>><?= e($themeField('about_kicker', '01 / THE IDEA')) ?></span>
```

参考实现：官方 Havenform 模板（首屏、关于、项目、流程、行动号召的小标签与次要链接，页头按钮，页尾各栏文字）。

示例源码：`deploy/examples/studio-starter/`，包含响应式首页、7 个可编辑字段及原创 SVG 视觉资产。配套测试构造公司介绍、服务、产品、资讯、联系栏目并导出样板 ZIP；不修改客户宠物站。示例依赖 2.0.0 起的整站模板与主题内容能力，1.x 站点不支持。

## 内容检查

只读检查缺失本地图片、疑似演示文字和待补充栏目；每类最多读取 1000 条、最多显示 200 个问题。提示需要管理员判断，不等于全站通过。完整失效链接扫描沿用 SEO 助手专业版及其授权边界，不自动启用插件。

## 上架前往返验收（2.0.3+）

ThemeValidator 与导出检查全绿**不等于**模板可用：动态元素依赖的设置、编辑画布里的主题样式等，只有「真的装一遍、导进去、打开看」才发现。每个要上架的整站模板包在上架前跑一次：

```bash
php tools/site-template-roundtrip.php --package=<整站模板.zip>
```

- 默认用当前仓库搭一个全新临时站（`--cms=<发行包.zip>` 改用发行包），走真实 HTTP 安装器、再用临时站自己的导入流程导入模板；
- 检查首页、全部栏目与前 5 篇内容 / 产品：HTTP 200、无 PHP 报错、无未解析的 `{{loop.*}}` 等标签；
- 登录临时站后台，打开首页、Blox 单页、页头、页尾编辑画布：能渲染、无报错，主题有自带样式表时每种画布都加载了它；
- 报告写在包旁边的 `<包名>.roundtrip.json`，绑定模板包与 CMS 的 SHA-256；任一项失败返回非零。`--db=mysql` 用环境变量 `YK_ROUNDTRIP_MYSQL_*` 指向本机 MySQL，只建并删除 `yk_roundtrip_*` 临时库；
- 首屏位置（标题是否被挤出首屏）等需要真实浏览器的检查不在其中，仍按模板验收清单在浏览器里做。

导出检查同时会提示：页面用到的元素依赖的站点设置为空（如页脚社媒入口没填）、只属于目标站点的设置（如备案号，导入后需填写）、Blox 单页开着「正文顶部显示头图」（前台会在 Blox 内容前插入整宽封面图）。

## 验证入口

- `tests/fixtures/site-template-probe.php`：数据/媒体导出导入、关系、安全拒绝、恢复及后续修改保护；支持 SQLite 和独立临时 MySQL 库。
- `tests/smoke/site_templates.php`：仅独立测试安装站，真实上传、预览、导入、恢复、前台栏目访问。
- `tests/smoke/site_template_interactions.php`：主题内容保存、多语言隔离、非法 URL、过期编辑和真实联系表单提交。
- PHP 8.0 + MySQL 5.7 / SQLite 的数据往返已验证；完整后台渲染与 PHPUnit 一并回归。整站模板随 2.0.0 发布。

无伪静态环境的检测、兼容访问和边界见 [URL-COMPATIBILITY.md](URL-COMPATIBILITY.md)。URL 模式属于目标站运行配置，不从整站模板复制。
