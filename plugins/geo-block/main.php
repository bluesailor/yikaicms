<?php
declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition Direct requests do not load the CMS bootstrap. */
if (!defined('ROOT_PATH')) exit('Access Denied');

require_once __DIR__ . '/GeoBlock.php';

// init 在整页缓存命中之前触发（前台、API、表单提交等入口都会经过）；后台页面不触发 init。
// 优先级 1：先于其他插件的 init 逻辑（如 SEO 跳转），被拦截的访客不再触发任何后续处理。
add_action('init', static function (): void {
    GeoBlock::enforce();
}, 1);
