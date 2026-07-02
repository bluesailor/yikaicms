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
