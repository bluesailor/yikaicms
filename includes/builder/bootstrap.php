<?php
/**
 * YikaiCMS 页面构建器引导：加载基类 + 元素 + 注册表 + 渲染器。
 * 从 includes/init.php require。设计见 yikaicms-docs/design-page-builder.md 与 plan-page-builder-p1.md。
 */

declare(strict_types=1);

require_once __DIR__ . '/AbstractElement.php';

foreach (glob(__DIR__ . '/elements/*.php') ?: [] as $__elFile) {
    require_once $__elFile;
}

require_once __DIR__ . '/BuilderRegistry.php';
require_once __DIR__ . '/BlocksLibrary.php';
require_once __DIR__ . '/BlockRenderer.php';
