<?php
declare(strict_types=1);
?>
<button type="button" data-testid="blox-left-panel-toggle"
        class="blox-structure-collapse h-7 w-7 shrink-0 rounded text-gray-400 hover:bg-gray-100 hover:text-gray-700 inline-flex items-center justify-center"
        :title="leftPanelCollapsed ? <?= e($jt('blox_left_panel_expand')) ?> : <?= e($jt('blox_left_panel_collapse')) ?>"
        :aria-label="leftPanelCollapsed ? <?= e($jt('blox_left_panel_expand')) ?> : <?= e($jt('blox_left_panel_collapse')) ?>"
        :aria-expanded="String(!leftPanelCollapsed)" aria-controls="blox-left-panel-content"
        @click="toggleLeftPanel()">
    <i class="ti text-sm" :class="leftPanelCollapsed ? 'ti-chevron-right' : 'ti-chevron-left'" aria-hidden="true"></i>
</button>
