# Migrations

每个文件一条迁移，文件名约定：`YYYYMMDD_<id>.php`（前缀决定执行顺序）。

文件 return 一个 array，字段与 `admin/upgrade.php` 的 `$upgrades` 元素结构一致：

```php
return [
    'id'    => '20260511_xxx',
    'title' => '简短标题',
    'desc'  => '详细描述（多语种行为/幂等说明放这里）',
    'check' => function (): bool {
        // 返回 true 表示已应用，跳过
        return db()->fetchOne("SELECT 1 FROM ... LIMIT 1") !== null;
    },
    'sqls' => [
        "ALTER TABLE ...",
        "INSERT INTO ... ON DUPLICATE KEY UPDATE ...",
    ],
    // 可选：复杂迁移用 PHP 回调，返回字符串作为成功消息
    'php' => function (): string {
        // ...
        return '成功消息';
    },
];
```

`admin/upgrade.php` 启动时自动 `glob` 此目录并按文件名排序加载，与文件内 inline `$upgrades` 数组合并。

## 命名约定

- `YYYYMMDD_` 前缀必填，按日期排序
- `id` 跟文件名 stem 一致（去 `.php`），方便日志定位
- 文件名小写 + 下划线分隔

## 已知约束

- check() 必须幂等（多次调用结果一致）
- check() 抛错会被捕获并标记为 "check failed"，不影响其它迁移
- sqls 数组里的 SQL 必须能在 MySQL 和 SQLite 上都跑通（loader 会通过 `_sqlToSqlite` 转换 ALTER 语法）
- 跨多条 SQL 的事务性不保证；要求事务请用 `php` 回调
