<?php
declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition Direct requests do not load the CMS bootstrap. */
if (!defined('ROOT_PATH')) exit('Access Denied');

/**
 * BLOX Pro 作者端模块注册。只挂编辑器钩子，不参与保存校验或前台渲染：
 * 停用/删除插件后旧文档照常渲染、受保护字段照常保留，只是不再提供对应编辑面板。
 */
const BLOX_PRO_EDITOR_MODULES = ['query_loop', 'display_conditions', 'style_presets', 'table', 'pricing', 'global_classes', 'interactions'];

/** @return list<string> 本插件提供的作者端模块 */
function blox_pro_editor_modules(): array
{
    return BLOX_PRO_EDITOR_MODULES;
}

/**
 * 全局类表单字段：核心 BloxGlobalClasses::propertyContract() 定义键、范围与响应式，这里补上三语文案。
 *
 * @return list<array<string,mixed>>
 */
function blox_pro_class_fields(): array
{
    if (!class_exists('BloxGlobalClasses')) {
        return [];
    }
    $fields = [];
    foreach (BloxGlobalClasses::propertyContract() as $field) {
        $field['label'] = __('blox_class_prop_' . $field['key']);
        $field['group_label'] = __('blox_class_group_' . $field['group']);
        if (isset($field['options'])) {
            $field['options'] = array_map(static fn(string $value): array => [
                'value' => $value,
                'label' => __('blox_class_opt_' . str_replace('-', '_', $value)),
            ], $field['options']);
        }
        $fields[] = $field;
    }
    return $fields;
}

// 隔离加载（授权探针、CLI 工具）没有钩子系统时只提供上面的声明，不注册编辑器面板。
if (!function_exists('add_action')) {
    return;
}

add_action('blox_editor_panel', static function (string $slot): void {
    if ($slot === 'element_interactions') {
        require __DIR__ . '/editor/interactions-panel.php';
    } elseif ($slot === 'element_condition') {
        require __DIR__ . '/editor/conditions-panel.php';
    } elseif ($slot === 'element_style_preset') {
        require __DIR__ . '/editor/style-preset-picker.php';
    } elseif ($slot === 'element_style_target') {
        require __DIR__ . '/editor/class-style-target.php';
    } elseif ($slot === 'element_loop_query') {
        require __DIR__ . '/editor/loop-query-panel.php';
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
    $languages = [];
    foreach (function_exists('enabledLanguages') ? enabledLanguages() : [] as $code => $label) {
        $languages[] = ['value' => (string) $code, 'label' => (string) $label];
    }
    $data = [
        'conditionChannels' => $channels,
        'conditionLanguages' => $languages,
        'loopText' => [
            'saveAsName' => __('blox_gquery_save_prompt'),
        ],
        'conditionText' => [
            'diagnose' => __('blox_element_cond_diagnose'),
            'diagnosing' => __('blox_diag_running'),
            'diagnoseFailed' => __('blox_element_cond_failed'),
            'previewOnly' => __('blox_element_cond_preview_only'),
            'contextTitle' => __('blox_element_cond_context_title'),
            'contextHint' => __('blox_element_cond_context_hint'),
            'stale' => __('blox_element_cond_stale'),
            'invalid' => __('blox_element_cond_invalid'),
            'passed' => __('blox_element_cond_passed'),
            'blocked' => __('blox_element_cond_blocked'),
            'satisfied' => __('blox_element_cond_satisfied'),
            'unsatisfied' => __('blox_element_cond_unsatisfied'),
            'rule' => __('blox_element_cond_rule'),
            'empty' => __('blox_display_conditions_empty'),
            'emptyHint' => __('blox_display_conditions_empty_hint'),
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
            // v1.27 新条件键
            'language' => __('blox_display_condition_language'),
            'device' => __('blox_display_condition_device'),
            'datetime' => __('blox_display_condition_datetime'),
            'param' => __('blox_display_condition_param'),
            'field' => __('blox_display_condition_field'),
            'exists' => __('blox_display_operator_exists'),
            'notExists' => __('blox_display_operator_not_exists'),
            'opEmpty' => __('blox_display_operator_empty'),
            'opNotEmpty' => __('blox_display_operator_not_empty'),
            'deviceMobile' => __('blox_display_value_mobile'),
            'deviceDesktop' => __('blox_display_value_desktop'),
            'paramName' => __('blox_display_param_name_placeholder'),
            'fieldName' => __('blox_display_field_name_placeholder'),
            'cacheWarning' => __('blox_display_condition_cache_warning'),
        ],
        // V2.0.0 样式面板直接编辑全局类：字段与范围来自核心契约，这里只补文案
        'classFields' => blox_pro_class_fields(),
        'classStates' => class_exists('BloxGlobalClasses') ? array_map(
            static fn(string $state): array => ['key' => $state, 'label' => __('blox_class_state_' . $state)],
            array_keys(BloxGlobalClasses::STATES)
        ) : [],
        'classStateKeys' => class_exists('BloxGlobalClasses') ? BloxGlobalClasses::STATE_KEYS : [],
        'classText' => [
            'createNamed' => __('blox_class_create_named'),
            'editingTier' => __('blox_class_editing_tier'),
            'inherits' => __('blox_class_inherits'),
            'clear' => __('blox_class_clear_value'),
            'flexOnly' => __('blox_class_flex_only'),
            'usage' => __('blox_class_usage_short'),
            'save' => __('blox_class_save'),
            'saving' => __('blox_class_saving'),
            'saved' => __('blox_class_saved'),
            'conflictReloaded' => __('blox_class_conflict_reloaded'),
            'pending' => __('blox_class_pending'),
            'blockedElement' => __('blox_class_blocked_element'),
            'blockedPreset' => __('blox_class_blocked_preset'),
            'blockedClass' => __('blox_class_blocked_class'),
        ],
        'interactionText' => [
            'empty' => __('blox_interactions_empty'),
            'hint' => __('blox_interactions_hint'),
            'add' => __('blox_interactions_add'),
            'trigger' => __('blox_interactions_trigger'),
            'action' => __('blox_interactions_action'),
            'target' => __('blox_interactions_target'),
            'runOnce' => __('blox_interactions_run_once'),
            'scrollDepth' => __('blox_popup_scroll_depth'),
            'className' => __('blox_interactions_class_placeholder'),
            'selector' => __('blox_interactions_selector_placeholder'),
            'triggers' => [
                'click' => __('blox_interactions_trigger_click'),
                'hover' => __('blox_interactions_trigger_hover'),
                'page_load' => __('blox_interactions_trigger_page_load'),
                'enter_viewport' => __('blox_interactions_trigger_viewport'),
                'scroll' => __('blox_popup_trigger_scroll'),
            ],
            'actions' => [
                'show' => __('blox_interactions_action_show'),
                'hide' => __('blox_interactions_action_hide'),
                'toggle' => __('blox_interactions_action_toggle'),
                'add_class' => __('blox_interactions_action_add_class'),
                'remove_class' => __('blox_interactions_action_remove_class'),
                'toggle_class' => __('blox_interactions_action_toggle_class'),
                'animate' => __('blox_interactions_action_animate'),
                'open_popup' => __('blox_interactions_action_open_popup'),
                'close_popup' => __('blox_interactions_action_close_popup'),
            ],
            'targets' => [
                'self' => __('blox_interactions_target_self'),
                'parent' => __('blox_interactions_target_parent'),
                'selector' => __('blox_interactions_target_selector'),
            ],
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
