# YikaiCMS — 开发约定（写给在本仓库工作的 AI / 开发者）

原生 **PHP 8.2+** 企业建站 CMS，**无框架**、零 Composer 运行期依赖。数据库目标 **MySQL 5.7 / MariaDB 10.x**（同时支持 SQLite）。
⚠ 运行环境是「**新 PHP + 老 MySQL**」：PHP 用 8.2+ 现代特性，但 SQL **必须兼容 MySQL 5.7**（共享主机现实），详见 §2 与 §13。
动手前先读本文件——下面是这套代码的"肌肉记忆"，照着写就和现有代码同构；别引入框架、别另起一套风格。

> 黄金法则：**先看相邻文件怎么写的，照它写。** 本仓库高度一致，模仿现有模式 > 发挥。

---

## 1. 启动与目录

每个入口（根 `*.php` / `admin/*.php`）顶部 `declare(strict_types=1);`，然后 `require ROOT_PATH . '/includes/init.php'`。
`init.php` 的加载顺序（即可用能力）：
`config/config.php`（常量、`db()` 引导）→ `includes/functions.php`（全局助手）→ `includes/models/autoload.php`（模型工厂）→ `member_auth`（前台会员）→ `hooks`（do_action/filter）→ `Compatibility` → `HtmlCache` / `HtmlPipeline`（整页缓存）→ `Abilities` + `abilities/*`（能力系统）→ `blocks/*` → `customer_service` → `plugin`。

目录：`admin/`（后台，每页 `require admin/includes/header.php` + `_footer`）、`controllers/`（前台 list/detail 控制器）、`views/`、`includes/models/`（数据层）、`themes/<主题>/{layouts,blocks,partials,pages}`、`lang/`、`migrations/`、`install/`、`plugins/`、`config/`。

## 2. 数据库 —— 一律走 `db()`，一律参数化

`db()` 返回 `Database` 单例（`config/database.php`），**永不**手写 `new PDO` / 字符串拼 SQL。
```php
db()->fetchOne($sql, $params);     // ?array
db()->fetchAll($sql, $params);     // array
db()->fetchColumn($sql, $params);  // mixed（单值/COUNT）
db()->execute($sql, $params);      // int 影响行数
db()->insert($table, $data);       // 返回 lastInsertId，$table 不带前缀
db()->update($table, $data, $where, $whereParams);
db()->delete($table, $where, $params);
db()->beginTransaction() / commit() / rollback();
db()->tableExists($table);  db()->isSqlite();  // 写跨库 SQL 时分支用
```
- **表名一律 `DB_PREFIX . 'xxx'`**（如 `'SELECT * FROM ' . DB_PREFIX . 'contents WHERE id = ?'`）。`insert/update/delete` 的 `$table` 传**不含前缀**的名（方法内部拼）。
- 占位符只用 `?`（位置参数）；绝不把变量拼进 SQL 字符串。
- 写 `ORDER BY` / `LIMIT` 等不能参数化的部分，用**白名单**映射后再拼（见 admin/users 排序写法）。
- 跨库：默认写 MySQL 语法；用到 MySQL 专有（`RAND()` 等）时用 `db()->isSqlite()` 分支或挑通用写法。

### ⚠ MySQL 5.7 兼容底线（很重要，违反会在生产炸）

目标 **MySQL 5.7 / MariaDB 10.x**（很多客户站在共享主机 my3w 上跑 5.7）。**禁止使用 5.7 不支持的特性**：
- ❌ **窗口函数**（`ROW_NUMBER() OVER`、`RANK()`、`OVER(...)` 等）
- ❌ **CTE / `WITH`**（公用表表达式，含递归 CTE）
- ❌ **`JSON_TABLE()`**
- 建表用 `utf8mb4` + **`utf8mb4_general_ci`**（**别用 `utf8mb4_0900_ai_ci`**，那是 8.0 专有，5.7/MariaDB 报错）。
- 需要"分组取 top N / 行号 / 递归"时，用子查询、`@变量` 模拟、或拆成多次查询 + PHP 处理——别图省事上 8.0 语法。
- 写完 SQL 自检一遍：**这条能在 MySQL 5.7 跑吗？** 拿不准就避开高级语法。

## 3. 数据层 —— 模型工厂

不要在控制器/页面里裸写业务 SQL；走 `includes/models/` 的模型，用工厂函数取单例：
`contentModel()`、`channelModel()`、`productModel()`、`bannerModel()`、`albumModel()`、`downloadModel()`、`formModel()`、`mediaModel()`、`adminLogModel()` … （全部在 `includes/models/autoload.php`）。
新增实体：在 `includes/models/` 加 `XxxModel.php`（继承基类 `Model`，定义 `$table`），并在 `autoload.php` 注册工厂 `xxxModel()`。

## 4. 输出与安全（红线）

- **所有输出到 HTML 的变量必须转义**：`<?= e($var) ?>`（`e(?string): string`，HTML 实体）。模板里看到裸 `<?= $x ?>` 基本就是 bug。
- **CSRF**：后台 `admin/includes/footer.php` 的 `window.fetch` 拦截器会给 POST 自动注入 `_token`；后台写操作要校验。表单放 CSRF 字段、AJAX 走拦截器，照现有页抄。
- **密码**：`password_hash` / `password_verify`，绝不明文、不自创哈希。
- **后台鉴权**：`admin/*.php` 经 `admin/includes/header.php` 做登录 + `requirePermission('xxx')` 权限校验；新后台页照抄这套。
- 登录失败有限速（throttle），安装后 `installed.lock` 锁安装器——别绕过。
- `config/config.php` 含数据库密码、**每站独有、已 gitignore**；改默认值改 `config/config.sample.php`。部署/打包永远排除 `config/config.php`、`storage/`、`uploads/`、`install/`。

## 5. 多语言 i18n

- 界面文案走 `__('key', $params)`，key 定义在 `lang/{zh-CN,en,ja}.php`。**别硬编码中文/英文 UI 串**——加 key、三语都补。
- 内容多语言：`channels/contents/products` 等表用 `lang` + `translation_group_id` 两列（同一概念跨语言共享 `translation_group_id`，源行回填为自身 id）。查内容按 `WHERE lang = ? AND translation_group_id = ?`。
- 当前语言取 `siteLang()`；`langPrefix()` / `langUrl()` 生成带语言前缀的 URL。

## 6. 配置与设置

- 读：`config('key', $default)`（走 `settings` 表 + `config/defaults.php` 出厂值）。
- 写：`settingModel()->saveBatch(['key' => 'value', ...])`（UPSERT，新键自动从 defaults 取 group/name/type）。
- 加新设置项：在 `config/defaults.php` 定义（含 group/type/name/tip），再在对应 `admin/setting_*.php` 加表单字段。

## 7. 主题

`theme_path('layouts/header.php')` 解析到当前激活主题（`themes/<active>/...`），缺失回退默认主题。结构：`layouts/`（header/footer）、`blocks/`（首页区块）、`partials/`（卡片等复用片段）、`pages/`（栏目页模板）。
**改主题模板里的 Tailwind class 后必须重编译 CSS（见 §10）。** 自定义客户主题时优先复制 default 改，别动 default 本身。

**图标**：用这三套（按优先级）——
1. **Font Awesome**：项目已**本地集成**（`assets/fontawesome/`，`<i class="fa-solid fa-xxx">`），后台和现有主题都在用，**首选**，无需引外链。
2. **Phosphor Icons**（https://phosphoricons.com/）、3. **Lucide**（https://lucide.dev/icons/）：FA 没有贴切图标时用，风格更现代/线性，适合前端营销页。
约定：**自托管、不引 CDN**（与全站「本地化、不要 CDN」一致）——用到 Phosphor/Lucide 时把所需 SVG 取下来内联或放 `assets/icons/`，不要 `<script src="cdn...">` 或外链字体。同一页面/区块图标风格尽量统一，别 FA + Lucide 混搭。

## 8. 钩子 / 插件 / 能力

WordPress 式扩展点：`do_action($hook, ...)` / `add_action`、`apply_filters($hook, $value, ...)` / `add_filter`。插件放 `plugins/`。能力系统 `register_ability(...)`（`includes/abilities/*`）。
扩展功能优先用钩子/插件挂载，不要直接改核心文件。

## 9. 缓存

前台整页 HTML 缓存：`HtmlCache` + `HtmlPipeline`。改了影响前台输出的逻辑后，注意缓存可能让你"看不到改动"——开发期可清 `storage/` 下缓存验证。

## 10. Tailwind CSS 编译（无 npm）

```bash
/mnt/d/phpstudy_pro/WWW/tailwindcss-windows-x64.exe \
  -i assets/css/src/app.css -o assets/css/tailwind.css --minify
```
- v4，`app.css` 里 `@source` 自动扫描 `*.php / admin/ / includes/ / themes/ / plugins/` 提取 class。改 PHP 模板新增了 class 就要重编译，否则新 class 没样式。
- **WSL 坑**：这个 Windows exe 不认 `/mnt/...` 绝对路径（会当成 `D:\mnt\...`）。必须 `cd` 进项目目录用相对路径：`cd <项目> && ../tailwindcss-windows-x64.exe -i assets/css/src/app.css -o assets/css/tailwind.css --minify`。
- 颜色 token、自定义组件在 `app.css` 的 `@theme` / `@layer`。`brand-*` 是主色板。

## 11. 数据库升级 / 迁移

- **结构变更走两条幂等路径之一，绝不让用户手 SQL**：
  1. `migrations/YYYYMMDD_xxx.php` — `return ['name'=>'…','up'=>function(PDO $db){…}]`，`up()` 内用 `SHOW COLUMNS LIKE` / `information_schema` 自守卫，可重复执行。
  2. `admin/upgrade.php` — 后台「升级」页的内联补丁（`$upgrades` 数组：版本 → items，每项 `id/title/desc/check(PDO):bool/execute(PDO):string`），管理员点执行。
- 加列/加表都先 `check()` 判存在再 `execute()`。**纯加列、不删列、不改既有列类型** 是默认安全姿势。
- `install/sql/mysql.sql`、`sqlite.sql` 只用于全新安装；改它不影响已装站点（已装站要变更必须配一条 migration / upgrade 补丁）。

## 12. 发版

- 版本号在 `config/config.sample.php` 的 `CMS_VERSION`（`config.php` per-install 不计）；交付客户站可带后缀如 `1.7.7-客户名`。
- 打包：`build.sh`（扁平 htdocs zip + sha256，自动排除 config.php/storage/uploads/install/tests）。
- 升级服务器 `update.yikaicms.com`：后台「在线更新」检查版本；`api/update/check.php` 同时按域名登记安装（见 install 注册表）。GitHub release 用 `gh release create`。

## 13. 编码规范（PHP 8.2+）

- **目标 PHP 8.2+**。
- **必须**：文件首行 `declare(strict_types=1);`（仓库 100% 覆盖，保持）；函数/方法**全类型标注**（参数 + 返回），可空用 `?type`。
- **推荐用起来**：
  - `readonly` 属性 —— 值对象 / DTO / 配置载体，构造后不可变。
  - `enum` —— 替代散落的字符串/常量状态（如状态码、类型枚举）。
  - `match` —— 替代长 `switch`，严格匹配、有返回值。
- **避免**：过度抽象、过度设计。不为"将来可能"提前造接口/工厂/分层；**保持简单直白**，跟现有代码同构即可。能一个函数解决就别整一套类体系。
- 命名：函数 `camelCase`，常量 `UPPER_SNAKE`，DB 列沿用既有风格。注释用中文、说"为什么"而非复述代码。
- 不引入新依赖、不上框架、不改全局风格。能用现有助手就别重造。
- ⚠ PHP 可以很新，但 **SQL 必须 MySQL 5.7 兼容**（见 §2 底线）——两者别混淆。

## 速查："我要加一个 X"

- **加一张表**：写 migration（§11）或 admin/upgrade.php 补丁；模型放 `includes/models/` + 注册工厂。
- **加一个后台页**：复制相邻 `admin/xxx.php`，`header.php`+`requirePermission`，写操作校验 CSRF，输出 `e()`。
- **加一个设置项**：`config/defaults.php` 定义 + `admin/setting_*.php` 加字段，读 `config()` 写 `saveBatch()`。
- **加一段 UI 文案**：`lang/*.php` 三语加 key，模板用 `__()`。
- **改前台样子**：改 `themes/<主题>/` 模板，然后重编译 Tailwind（§10）。
