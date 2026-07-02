# lang/overrides/ — 站点语言覆盖层

在此放语言覆盖文件，可在**不修改核心 `lang/*.php`** 的前提下改词/加词，核心升级不冲突。

## 文件与优先级
`loadLangData()` 在加载核心语言后，按顺序 `array_merge` 覆盖：

1. `lang/overrides/all.php` —— 对**所有语言**生效
2. `lang/overrides/{lang}.php` —— 仅对该语言生效（如 `zh-CN.php` / `en.php` / `ja.php`），优先级更高

每个文件返回「翻译键 => 文案」数组。

## 用法示例
`lang/overrides/zh-CN.php`：
```php
<?php
return [
    'breadcrumb_home' => '首页',        // 覆盖核心已有键
    'my_custom_label' => '本站自定义文案', // 新增本站专用键
];
```

## 说明
- 覆盖文件按站维护，**不进核心仓库 / 发布包**（已在 .gitignore，仅保留本 README）。
- 配套：视图覆盖见 `overrides/README.md`，配置覆盖见 `config/overrides.sample.php`。
