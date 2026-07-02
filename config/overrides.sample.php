<?php
/**
 * 站点配置覆盖层（样例）—— 站点覆盖层的一部分。
 *
 * 用法：复制本文件为 config/overrides.php，返回「设置键 => 值」数组。
 * 这里 pin 的键在 config() 中拥有最高优先级：
 *   - 直接返回该值，不读 DB、不被后台保存/在线升级覆盖；
 *   - 适合各站固定某些设置（如强制主题、锁定开关、按站文案键等）。
 *
 * 无 config/overrides.php 时机制自动关闭，零开销。
 * config/overrides.php 按站维护，不进核心仓库 / 发布包（已在 .gitignore）。
 */

declare(strict_types=1);

return [
    // 示例：强制使用某主题（覆盖 DB 里的 current_theme）
    // 'current_theme' => 'business',

    // 示例：锁定关闭某功能开关
    // 'show_price' => '0',

    // 示例：固定站点名（即使后台改了也以此为准）
    // 'site_name' => '示例企业',
];
