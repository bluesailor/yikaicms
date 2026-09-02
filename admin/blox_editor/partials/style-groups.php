<?php
/**
 * 样式页签分组 chip 条（通用背景规划第 2 轮，2026-09-02）。
 *
 * 只在 styleGroups() 非空时出现（容器专用样式块、搜索中、只看已修改、组数不足 2
 * 都返回空并回落到平铺全显）。分组仅切分 visibleCtrls() 的通用控件循环；
 * 盒模型、可见设备等硬编码段维持常显。chip 右侧圆点 = 组内有已修改控件。
 * 交互与视觉对齐既有 home-content-groups.php。
 */

declare(strict_types=1);
?>
<template x-if="selEl && panelTab === 'style' && styleGroups().length">
    <div role="group" aria-label="<?= e(__('blox_style_groups')) ?>" data-testid="blox-style-groups"
         class="flex gap-1 border-b border-gray-200 pb-2">
        <?php foreach (['general', 'background', 'animation'] as $group): ?>
        <button type="button" x-show="styleGroups().includes('<?= e($group) ?>')"
                @click="setStyleGroup('<?= e($group) ?>')"
                :aria-pressed="effectiveStyleGroup() === '<?= e($group) ?>'"
                data-testid="blox-style-group-<?= e($group) ?>"
                class="min-w-0 flex-1 h-8 rounded px-1 text-xs font-medium whitespace-nowrap inline-flex items-center justify-center gap-1"
                :class="effectiveStyleGroup() === '<?= e($group) ?>' ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-100'">
            <span><?= e(__('blox_style_group_' . $group)) ?></span>
            <span x-show="styleGroupDot('<?= e($group) ?>')" data-testid="blox-style-group-dot-<?= e($group) ?>"
                  class="w-1.5 h-1.5 rounded-full bg-blue-500 shrink-0" aria-hidden="true"></span>
        </button>
        <?php endforeach; ?>
    </div>
</template>
