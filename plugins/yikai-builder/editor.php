<?php
declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition Direct requests do not load the CMS bootstrap. */
if (!defined('ROOT_PATH')) exit('Access Denied');

/**
 * BLOX Pro 作者端模块注册。只挂编辑器钩子，不参与保存校验或前台渲染：
 * 停用/删除插件后旧文档照常渲染、受保护字段照常保留，只是不再提供对应编辑面板。
 */
const BLOX_PRO_EDITOR_MODULES = ['query_loop', 'display_conditions', 'style_presets', 'table', 'pricing'];

/** @return list<string> 本插件提供的作者端模块 */
function blox_pro_editor_modules(): array
{
    return BLOX_PRO_EDITOR_MODULES;
}

// 隔离加载（授权探针、CLI 工具）没有钩子系统时只提供上面的声明，不注册编辑器面板。
if (!function_exists('add_action')) {
    return;
}

add_action('blox_editor_panel', static function (string $slot): void {
    if ($slot === 'element_condition') {
        require __DIR__ . '/editor/conditions-panel.php';
    } elseif ($slot === 'element_style_preset') {
        require __DIR__ . '/editor/style-preset-picker.php';
    } elseif ($slot === 'element_loop_template') {
        require __DIR__ . '/editor/loop-template-card.php';
    } elseif ($slot === 'table_grid') {
        require __DIR__ . '/editor/table-grid.php';
    } elseif ($slot === 'pricing_plans') {
        require __DIR__ . '/editor/pricing-plans.php';
    }
});

add_action('blox_editor_scripts', static function (): void {
    $channels = [];
    foreach (channelModel()->getFlatList() as $channel) {
        $type = (string) ($channel['type'] ?? '');
        $id = (int) ($channel['id'] ?? 0);
        if (empty($channel['status']) || $id < 1 || in_array($type, ['page', 'link', 'redirect'], true)) {
            continue;
        }
        $channels[] = [
            'value' => $id,
            'label' => str_repeat('— ', max(0, min(4, (int) ($channel['_level'] ?? 0))))
                . ((string) ($channel['name'] ?? '') ?: ('#' . $id)),
        ];
    }
    $data = [
        'conditionChannels' => $channels,
        'conditionText' => [
            'empty' => __('blox_display_conditions_empty'),
            'hint' => __('blox_display_conditions_hint'),
            'group' => __('blox_display_conditions_group'),
            'and' => __('blox_display_conditions_and'),
            'or' => __('blox_display_conditions_or'),
            'addGroup' => __('blox_display_conditions_add_group'),
            'addRule' => __('blox_display_conditions_add_rule'),
            'login' => __('blox_display_condition_login'),
            'date' => __('blox_display_condition_date'),
            'channel' => __('blox_display_condition_channel'),
            'url' => __('blox_display_condition_url'),
            'is' => __('blox_display_operator_is'),
            'isNot' => __('blox_display_operator_is_not'),
            'before' => __('blox_display_operator_before'),
            'on' => __('blox_display_operator_on'),
            'after' => __('blox_display_operator_after'),
            'equals' => __('blox_display_operator_equals'),
            'notEquals' => __('blox_display_operator_not_equals'),
            'contains' => __('blox_display_operator_contains'),
            'notContains' => __('blox_display_operator_not_contains'),
            'startsWith' => __('blox_display_operator_starts_with'),
            'loggedIn' => __('blox_display_value_logged_in'),
            'loggedOut' => __('blox_display_value_logged_out'),
            'selectChannel' => __('blox_display_select_channel'),
            'urlPlaceholder' => __('blox_display_url_placeholder'),
        ],
    ];
    $tableScript = __DIR__ . '/assets/blox-pro-table.js';
    echo '<script src="/plugins/yikai-builder/assets/blox-pro-table.js?v=' . (int) filemtime($tableScript) . '"></script>' . "\n";
    $pricingScript = __DIR__ . '/assets/blox-pro-pricing.js';
    echo '<script src="/plugins/yikai-builder/assets/blox-pro-pricing.js?v=' . (int) filemtime($pricingScript) . '"></script>' . "\n";
    $script = __DIR__ . '/assets/blox-pro-editor.js';
    echo '<script>window.BloxProEditorData = '
        . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
        . ";</script>\n";
    echo '<script src="/plugins/yikai-builder/assets/blox-pro-editor.js?v=' . (int) filemtime($script) . '"></script>' . "\n";
});
