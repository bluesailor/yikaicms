<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) exit('Access Denied');

require_once __DIR__ . '/access.php';
// 作者端模块只注册编辑器钩子；前台渲染与保存校验不依赖本插件。
require_once __DIR__ . '/editor.php';

/** Loaded by the normal active-plugin loader; never included by the public renderer. */
function blox_pro_feature_allowed(string $feature): bool
{
    return BloxProAccess::allows($feature);
}
