<?php
declare(strict_types=1);

// PHPUnit 默认策略：功能测试按「能力可用」运行，不依赖授权与 yikai-builder 插件。
// 发布策略（config/blox-feature-policy.php，v1.20.1 起全部 licensed）由 BloxFeaturePolicyTest 直接读取校验；
// 授权下的拦截与放行由独立进程探针覆盖（blox-protected-save-probe.php、blox-trusted-write-probe.php 等）。
return [
    'query_loop' => 'free',
    'display_conditions' => 'free',
    'style_presets' => 'free',
    'table' => 'free',
    'pricing' => 'free',
];
