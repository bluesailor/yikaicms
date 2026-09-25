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
    // v1.28：元素交互（Interactions）——渲染永远免费；作者端归授权能力（进 PROTECTED_FEATURES 冻结）。
    'interactions' => 'licensed',
    // 2026-09-25 裁决（免费/Pro 边界）：以下两项代码在核心、不在 yikai-builder 插件里，
    // licensed_core = 注册码含构建器模块（license_owns_blox，永久回退）即可，不需要装 Pro 插件。
    // 内容维护模式（E07）：开关归专业版；授权失效时开关冻结、保护照常生效（交付给客户的结构不被解锁）。
    'maintenance_mode' => 'licensed_core',
    // 单页外框覆盖（E12：本页内容宽度 / 两侧留白 / 内容背景）：新增与修改归专业版，已有覆盖照常渲染、原样保留。
    // 页眉页脚显隐是 2.0 之前就有的免费能力，不在此列。
    'page_layout' => 'licensed_core',
];
