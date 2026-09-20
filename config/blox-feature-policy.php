<?php
declare(strict_types=1);

// Official distribution policy, not a customer-editable entitlement setting.
// free: available; licensed: requires the existing blox module; disabled: unavailable.
// 2026-09-19 起五项作者端能力改为授权（v1.20.1）：插件 yikai-builder 回到 pro 清单，经插件市场受控分发。
return [
    'query_loop' => 'licensed',
    'display_conditions' => 'licensed',
    'style_presets' => 'licensed',
    'table' => 'licensed',
    'pricing' => 'licensed',
    // v1.23：全局样式类——渲染永远免费；类的创建/修改/管理器属作者端授权能力。
    // 不进 PROTECTED_FEATURES：挂类引用无冻结需求（悬挂引用渲染期无害）。
    'global_classes' => 'licensed',
];
