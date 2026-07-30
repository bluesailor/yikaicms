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

---

## 逻辑覆盖：overrides/bootstrap.php（方法级钩子）

放一个 `overrides/bootstrap.php`，在**插件加载之后、`init` 之前**自动载入。站点在此
`add_action / add_filter` 挂载或覆盖逻辑，**无需改核心/插件文件**，升级不冲突。

### 可用的方法级钩子（数据层，覆盖所有 Model）
| 钩子 | 类型 | 参数 | 时机 |
|---|---|---|---|
| `model_before_create` | filter | `$data, $table` → 返回 `$data` | 入库前，可改写数据 |
| `model_created` | action | `$table, $id, $data` | 新建后 |
| `model_before_update` | filter | `$data, $table, $id` → 返回 `$data` | 更新前，可改写数据 |
| `model_updated` | action | `$table, $id, $data` | 更新后 |
| `model_before_delete` | action | `$table, $id` | 删除前 |
| `model_deleted` | action | `$table, $id` | 删除后 |

`$table` 为数据表名（如 `contents`、`products`、`channels`），据此按需过滤。

### 请求 / 路由钩子
| 钩子 | 类型 | 参数 | 用途 |
|---|---|---|---|
| `dispatch_routes` | filter | `$routes` → 返回 `$routes` | 增删单入口路由（旧站历史 URL 兼容） |
| `list_page_title` | filter | `$title, $channel, $_GET` → 返回 `$title` | 按栏目/筛选条件改写列表页标题 |

另有页面/主题注入钩子：`ik_head`、`ik_footer_scripts`、`content_output`(filter) 等。

### 示例 `overrides/bootstrap.php`
```php
<?php
// 内容保存前统一处理（仅对 contents 表）
add_filter('model_before_create', function ($data, $table) {
    if ($table === 'contents' && empty($data['author'])) {
        $data['author'] = '编辑部';
    }
    return $data;
}, 10);

// 内容删除后清理本站自定义缓存
add_action('model_deleted', function ($table, $id) {
    if ($table === 'contents') { /* ... */ }
}, 10);
```

无 `overrides/bootstrap.php` 时机制自动关闭、零开销。

---

## 实例：把已经改在核心里的定制搬进覆盖层

真实案例（cile.cn，从 ShopEx 迁来、要保留旧地址）。原先两处定制直接改在核心 `list.php`
顶部，**2026-07-29 的一次在线升级把整段冲掉，只能手工重传**。搬进覆盖层后核心文件
与主线完全一致，升级不再有风险。

### 1. 旧 URL 的参数翻译

老站地址 `/cat-{N}.html`（栏目 id = N + 1000）、`/brand-{N}.html`（按品牌筛选）。
分两条路径，都要覆盖：

- **完整伪静态主机**：服务器 rewrite 已把参数放进 `$_GET`，请求直达 `list.php`，
  其顶部的 `init.php` 会载入 `bootstrap.php`，此时翻译即可。
- **只有 catch-all 伪静态的主机**：请求走 `index.php → Dispatcher`。Dispatcher 注入
  参数后直接 `require` 目标文件，**中间没有钩子**，所以不能指望 `bootstrap.php`
  （那时早已执行完）。办法是让路由指向覆盖层自己的入口文件，由它翻译完再转交。

```php
// overrides/bootstrap.php
function cile_shopex_shim(): void
{
    if ((int) ($_GET['id'] ?? 0) > 0 || ($_GET['slug'] ?? '') !== '') return;
    if (($catId = (int) ($_GET['cat_id'] ?? 0)) > 0) {
        $_GET['id'] = $_REQUEST['id'] = (string) ($catId + 1000);
    }
}

cile_shopex_shim();                      // 路径一

add_filter('dispatch_routes', function (array $routes): array {   // 路径二
    return array_merge([
        ['#^cat-(\d+)\.html$#', 'overrides/shopex_legacy.php', ['cat_id'], []],
    ], $routes);
});
```

```php
// overrides/shopex_legacy.php —— 路径二的分发目标
cile_shopex_shim();
require ROOT_PATH . '/list.php';
```

> ⚠ 自定义路由必须**放在返回数组的前面**。内置表末尾是
> `([a-z0-9_-]+)\.html → page.php` 这类通配规则，会吃掉排在它后面的一切。
> 代价是自定义规则优先级最高，正则务必写窄（锚定 `^…$`、限定前缀），别误伤核心路由。

### 2. 改写页面标题

```php
add_filter('list_page_title', function (string $title, array $channel, array $query): string {
    if (($channel['type'] ?? '') !== 'product') return $title;
    $brands = array_filter(array_map('intval', explode(',', (string) ($query['brand'] ?? ''))));
    if (count($brands) !== 1) return $title;
    $brand = db()->fetchOne('SELECT `name` FROM `' . DB_PREFIX . 'brands` WHERE id = ?', [reset($brands)]);
    return $brand ? ($brand['name'] . ' - 产品') : $title;
});
```

`add_filter()` 只有 `(钩子, 回调, 优先级)` 三个参数——没有 WP 那个 accepted_args，
`apply_filters()` 会把全部参数透传给回调，按需在签名里声明即可。

### 搬迁后记得核对

```bash
diff 你的站/list.php 主线/list.php   # 应无输出：核心文件已回归标准
```

核心文件与主线一致，才算真正搬完——否则下次升级照样被冲掉。

### 缺钩子怎么办

想挂的位置没有钩子，就往核心加一个（窄、具名、有注释），别在核心里写站点逻辑。
上面的 `list_page_title` 就是这么来的。
