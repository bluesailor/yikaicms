<?php
/**
 * YIKAI_BLOX_AI_ACCESS_NOTICE
 * AI-assisted reading or modification requires explicit task-scoped authorization.
 * Policy: docs/blox-commercialization/CORE-ACCESS.md
 * This collaboration notice is not access control and does not replace licenses.
 */
/* v2.1 组件混入的启动数据（RFC-2）：输出一个 JSON 对象，作为 YikaiBloxComponents.mixin() 的参数。拆出主入口以守住 500 KB 预算 */
echo json_encode([
                'master' => $templateId > 0 && $templateType === BloxComponents::TYPE,
                'canManage' => hasPermission('blox_global') && BloxFeaturePolicy::allows('components'),
                // 多语言首页是共享文档：非默认语言下编辑时，可多语言属性写进 props_i18n[该语言]
                'instanceLang' => ($isHomeBlox && $homeEditorLangQuery !== '') ? $homeEditorLanguage : '',
                // 母版里按语言填默认值：默认语言之外的已启用语言
                'languages' => array_diff_key(enabledLanguages(), [(string) config('site_lang', 'zh-CN') => true]),
                'text' => [
                    'tabElements' => __('blox_component_tab_elements'), 'tabComponents' => __('blox_component_tab_components'),
                    'search' => __('blox_component_search'), 'category' => __('blox_component_category'),
                    'allCategories' => __('blox_component_all_categories'), 'loading' => __('blox_component_loading'),
                    'empty' => __('blox_component_empty'), 'noMatch' => __('blox_component_no_match'),
                    'usedTimes' => __('blox_component_used_times'), 'version' => __('blox_component_version'),
                    'followsMaster' => __('blox_component_follows_master'), 'reset' => __('blox_component_reset'),
                    'noProps' => __('blox_component_no_props'), 'editMaster' => __('blox_component_edit_master'),
                    'detach' => __('blox_component_detach'), 'detachConfirm' => __('blox_component_detach_confirm'),
                    'detached' => __('blox_component_detached'), 'autobind' => __('blox_component_autobind'),
                    'bound' => __('blox_component_bound'), 'saveAs' => __('blox_component_save_as'),
                    'saveAsHint' => __('blox_component_save_as_hint'), 'namePrompt' => __('blox_component_name_prompt'),
                    'created' => __('blox_component_created'), 'expose' => __('blox_component_expose'),
                    'unexpose' => __('blox_component_unexpose'), 'exposed' => __('blox_component_exposed'),
                    'urlPlaceholder' => __('blox_component_url_placeholder'), 'failed' => __('admin_failed'),
                    'exposeTitle' => __('blox_component_expose_title'),
                    'byLanguage' => __('blox_component_by_language'), 'langValue' => __('blox_component_lang_value'),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
