<?php
/**
 * HomeBloxBlockSchema 的 首页区块的 UI Schema 层：controls 定义 / 编辑器蓝图 / 可就地编辑字段路径 / 自定义区块编辑器契约。
 *
 * v1.18.6 拆分自 1807 行单文件（审计连续两轮点名的复杂度热点）。
 * trait 形态 = 类名/方法签名/调用方零改动的纯文件级拆分；方法体逐字节搬运，
 * 行为由 HomeBloxBlockSchemaTest 等黄金测试锁定。
 */

declare(strict_types=1);

trait HomeBloxSchemaControlsTrait
{

    /**
     * @return list<array<string, mixed>>
     */
    public static function controls(): array
    {
        $sourceOptions = self::sourceOptions();
        $collectionSources = array_values(array_filter(
            array_keys($sourceOptions),
            static fn (string $type): bool => str_starts_with($type, 'channel:')
        ));
        $customSources = array_values(array_filter(
            array_keys($sourceOptions),
            static fn (string $type): bool => str_starts_with($type, 'custom:')
        ));
        $limitedSources = array_merge(['banner'], $collectionSources);
        $titleSources = array_merge([
            'about', 'testimonials', 'advantage', 'cta', 'partners', 'product_categories',
        ], $collectionSources);
        $descriptionSources = array_merge(['testimonials', 'advantage', 'cta'], $collectionSources);
        $buttonSources = array_merge(['about', 'cta'], $collectionSources);

        return [
            [
                'key' => 'block_type',
                'type' => 'select',
                'label' => __('blox_home_source'),
                'default' => 'banner',
                'options' => $sourceOptions,
                'help' => __('blox_home_source_help'),
            ],
            [
                'key' => 'label',
                'type' => 'text',
                'label' => __('blox_home_block_name'),
                'default' => __('blox_home_block_label'),
                'placeholder' => __('blox_home_block_name_placeholder'),
            ],
            [
                'key' => 'custom_title',
                'type' => 'text',
                'label' => __('blox_home_override_title'),
                'default' => '',
                'required' => ['block_type', '=', $customSources],
            ],
            [
                'key' => 'custom_subtitle',
                'type' => 'textarea',
                'label' => __('pea_subtitle'),
                'default' => '',
                'rows' => 2,
                'required' => ['block_type', '=', $customSources],
            ],
            [
                'key' => 'banner_height_mode',
                'type' => 'select',
                'label' => __('blox_banner_height_mode'),
                'default' => 'inherit',
                'options' => [
                    'inherit' => __('blox_banner_height_inherit'),
                    'fixed' => __('blox_banner_height_fixed'),
                    'screen' => __('blox_banner_height_screen'),
                    'cover-header' => __('blox_banner_height_cover_header'),
                ],
                'option_icons' => [
                    'inherit' => 'settings',
                    'fixed' => 'arrows-vertical',
                    'screen' => 'maximize',
                    'cover-header' => 'layout-navbar-expand',
                ],
                'required' => ['block_type', '=', 'banner'],
                'help' => __('blox_banner_height_mode_help'),
            ],
            [
                'key' => 'banner_height_pc',
                'type' => 'number',
                'label' => __('blox_banner_height_pc'),
                'default' => 650,
                'min' => 200,
                'max' => 1600,
                'step' => 10,
                'required' => ['block_type', '=', 'banner'],
                'visible_when' => ['terms' => [['banner_height_mode', '=', 'fixed']]],
            ],
            [
                'key' => 'banner_mobile_mode',
                'type' => 'select',
                'label' => __('blox_banner_mobile_mode'),
                'default' => 'inherit',
                'options' => [
                    'inherit' => __('blox_banner_mobile_inherit'),
                    'fixed' => __('blox_banner_mobile_fixed'),
                    'hidden' => __('blox_banner_mobile_hidden'),
                ],
                'option_icons' => [
                    'inherit' => 'device-mobile',
                    'fixed' => 'arrows-vertical',
                    'hidden' => 'eye-off',
                ],
                'required' => ['block_type', '=', 'banner'],
                'help' => __('blox_banner_mobile_mode_help'),
            ],
            [
                'key' => 'banner_height_mobile',
                'type' => 'number',
                'label' => __('blox_banner_height_mobile'),
                'default' => 300,
                'min' => 180,
                'max' => 1200,
                'step' => 10,
                'required' => ['block_type', '=', 'banner'],
                'visible_when' => ['terms' => [['banner_mobile_mode', '=', 'fixed']]],
            ],
            [
                'key' => 'banner_effect',
                'type' => 'select',
                'label' => __('blox_banner_effect'),
                'default' => 'fade',
                'options' => [
                    'fade' => __('blox_banner_effect_fade'),
                    'slide' => __('blox_banner_effect_slide'),
                ],
                'option_icons' => ['fade' => 'layers-subtract', 'slide' => 'arrows-horizontal'],
                'required' => ['block_type', '=', 'banner'],
            ],
            [
                'key' => 'banner_content_motion',
                'type' => 'select',
                'label' => __('blox_banner_content_motion'),
                'default' => 'none',
                'options' => [
                    'none' => __('blox_banner_motion_none'),
                    'fade-up' => __('blox_banner_motion_fade_up'),
                    'slide-left' => __('blox_banner_motion_slide_left'),
                    'slide-right' => __('blox_banner_motion_slide_right'),
                    'zoom-in' => __('blox_banner_motion_zoom_in'),
                    'clip-reveal' => __('blox_banner_motion_clip_reveal'),
                    'blur-up' => __('blox_banner_motion_blur_up'),
                    'pop-in' => __('blox_banner_motion_pop_in'),
                ],
                'option_icons' => [
                    'none' => 'ban',
                    'fade-up' => 'arrow-up',
                    'slide-left' => 'arrow-left',
                    'slide-right' => 'arrow-right',
                    'zoom-in' => 'zoom-in',
                    'clip-reveal' => 'scan',
                    'blur-up' => 'blur',
                    'pop-in' => 'sparkles',
                ],
                'required' => ['block_type', '=', 'banner'],
                'help' => __('blox_banner_content_motion_help'),
            ],
            [
                'key' => 'banner_background_motion',
                'type' => 'select',
                'label' => __('blox_banner_background_motion'),
                'default' => 'none',
                'options' => [
                    'none' => __('blox_banner_motion_none'),
                    'zoom-in' => __('blox_banner_background_zoom_in'),
                    'zoom-out' => __('blox_banner_background_zoom_out'),
                ],
                'option_icons' => ['none' => 'ban', 'zoom-in' => 'zoom-in', 'zoom-out' => 'zoom-out'],
                'required' => ['block_type', '=', 'banner'],
            ],
            [
                'key' => 'banner_autoplay',
                'type' => 'number',
                'label' => __('blox_banner_autoplay'),
                'default' => 5,
                'min' => 0,
                'max' => 30,
                'required' => ['block_type', '=', 'banner'],
                'help' => __('blox_banner_autoplay_help'),
            ],
            [
                'key' => 'banner_speed',
                'type' => 'number',
                'label' => __('blox_banner_speed'),
                'default' => 700,
                'min' => 200,
                'max' => 2000,
                'step' => 100,
                'required' => ['block_type', '=', 'banner'],
            ],
            [
                'key' => 'banner_stagger',
                'type' => 'number',
                'label' => __('blox_banner_stagger'),
                'default' => 120,
                'min' => 0,
                'max' => 600,
                'step' => 20,
                'required' => ['block_type', '=', 'banner'],
                'help' => __('blox_banner_stagger_help'),
            ],
            [
                'key' => 'banner_navigation',
                'type' => 'checkbox',
                'label' => __('blox_banner_navigation'),
                'default' => true,
                'required' => ['block_type', '=', 'banner'],
            ],
            [
                'key' => 'banner_pagination',
                'type' => 'checkbox',
                'label' => __('blox_banner_pagination'),
                'default' => true,
                'required' => ['block_type', '=', 'banner'],
            ],
            [
                'key' => 'banner_pause_hover',
                'type' => 'checkbox',
                'label' => __('blox_banner_pause_hover'),
                'default' => true,
                'required' => ['block_type', '=', 'banner'],
            ],
            [
                'key' => 'override_layout',
                'type' => 'about_layout',
                'label' => __('blox_home_about_layout'),
                'default' => 'text_left',
                'required' => ['block_type', '=', 'about'],
            ],
            [
                'key' => 'override_breakpoint',
                'type' => 'about_breakpoint',
                'label' => __('blox_tablet_layout'),
                'default' => 'lg',
                'required' => ['block_type', '=', 'about'],
            ],
            [
                'key' => 'override_title',
                'type' => 'text',
                'label' => __('blox_home_override_title'),
                'default' => '',
                'required' => ['block_type', '=', $titleSources],
                'help' => __('blox_home_override_inherit_help'),
            ],
            [
                'key' => 'title_decor_style',
                'type' => 'select',
                'label' => __('blox_home_title_decor'),
                'default' => 'inherit',
                'options' => [
                    'inherit' => __('blox_home_title_decor_inherit'),
                    'line' => __('blox_home_title_decor_line'),
                    'dot' => __('blox_home_title_decor_dot'),
                    'none' => __('blox_home_title_decor_none'),
                ],
                'option_icons' => [
                    'inherit' => 'settings',
                    'line' => 'minus',
                    'dot' => 'point-filled',
                    'none' => 'ban',
                ],
                'required' => ['block_type', '=', $titleSources],
            ],
            [
                'key' => 'title_decor_align',
                'type' => 'select',
                'label' => __('blox_home_title_decor_align'),
                'default' => 'inherit',
                'options' => [
                    'inherit' => __('blox_home_title_decor_inherit'),
                    'left' => __('blox_home_title_decor_left'),
                    'center' => __('blox_home_title_decor_center'),
                    'right' => __('blox_home_title_decor_right'),
                ],
                'option_icons' => [
                    'inherit' => 'settings',
                    'left' => 'align-left',
                    'center' => 'align-center',
                    'right' => 'align-right',
                ],
                'required' => ['block_type', '=', $titleSources],
            ],
            [
                'key' => 'title_decor_color',
                'type' => 'color',
                'tab' => 'content',
                'label' => __('blox_home_title_decor_color'),
                'default' => '',
                'required' => ['block_type', '=', $titleSources],
                'help' => __('blox_home_title_decor_default_help'),
            ],
            [
                'key' => 'title_decor_width',
                'type' => 'number',
                'label' => __('blox_home_title_decor_width'),
                'default' => 0,
                'min' => 0,
                'max' => 240,
                'required' => ['block_type', '=', $titleSources],
                'help' => __('blox_home_title_decor_default_help'),
            ],
            [
                'key' => 'title_decor_gap',
                'type' => 'number',
                'label' => __('blox_home_title_decor_gap'),
                'default' => 0,
                'min' => 0,
                'max' => 80,
                'required' => ['block_type', '=', $titleSources],
                'help' => __('blox_home_title_decor_default_help'),
            ],
            [
                'key' => 'override_description',
                'type' => 'textarea',
                'label' => __('blox_home_override_description'),
                'default' => '',
                'required' => ['block_type', '=', $descriptionSources],
                'help' => __('blox_home_override_inherit_help'),
            ],
            [
                'key' => 'override_content',
                'type' => 'textarea',
                'label' => __('blox_home_override_content'),
                'default' => '',
                'required' => ['block_type', '=', 'about'],
                'help' => __('blox_home_override_inherit_help'),
            ],
            [
                'key' => 'override_image',
                'type' => 'image',
                'label' => __('blox_home_override_image'),
                'default' => '',
                'required' => ['block_type', '=', 'about'],
                'help' => __('blox_home_override_inherit_help'),
            ],
            [
                'key' => 'override_background',
                'type' => 'image',
                'label' => __('blox_home_stats_background'),
                'default' => '',
                'required' => ['block_type', '=', 'stats'],
            ],
            [
                'key' => 'counter_enabled',
                'type' => 'checkbox',
                'label' => __('blox_counter_on_view'),
                'default' => true,
                'required' => ['block_type', '=', 'stats'],
            ],
            [
                'key' => 'counter_start',
                'type' => 'number',
                'label' => __('blox_counter_start'),
                'default' => 0,
                'min' => 0,
                'max' => 1000000,
                'required' => ['block_type', '=', 'stats'],
            ],
            [
                'key' => 'counter_duration',
                'type' => 'number',
                'label' => __('blox_counter_duration'),
                'default' => 0,
                'min' => 0,
                'max' => 5000,
                'required' => ['block_type', '=', 'stats'],
                'help' => __('blox_counter_auto_help'),
            ],
            [
                'key' => 'stats_mobile_columns',
                'type' => 'select',
                'label' => __('blox_cols_mobile'),
                'default' => '2',
                'options' => ['1' => __('blox_n_cols', ['n' => 1]), '2' => __('blox_n_cols', ['n' => 2])],
                'option_icons' => ['1' => 'layout-list', '2' => 'layout-columns'],
                'required' => ['block_type', '=', 'stats'],
            ],
            [
                'key' => 'stats_tablet_columns',
                'type' => 'select',
                'label' => __('blox_cols_tablet'),
                'default' => '4',
                'options' => ['2' => __('blox_n_cols', ['n' => 2]), '4' => __('blox_n_cols', ['n' => 4])],
                'option_icons' => ['2' => 'layout-columns', '4' => 'grid-dots'],
                'required' => ['block_type', '=', 'stats'],
            ],
            [
                'key' => 'override_tag_title',
                'type' => 'text',
                'label' => __('blox_home_about_tag_title'),
                'default' => '',
                'required' => ['block_type', '=', 'about'],
            ],
            [
                'key' => 'override_tag_description',
                'type' => 'text',
                'label' => __('blox_home_about_tag_description'),
                'default' => '',
                'required' => ['block_type', '=', 'about'],
            ],
            [
                'key' => 'override_button_text',
                'type' => 'text',
                'label' => __('blox_home_override_button_text'),
                'default' => '',
                'required' => ['block_type', '=', $buttonSources],
                'help' => __('blox_home_override_inherit_help'),
            ],
            [
                'key' => 'override_button_url',
                'type' => 'text',
                'label' => __('blox_home_override_button_url'),
                'default' => '',
                'required' => ['block_type', '=', $buttonSources],
                'placeholder' => '/contact.html',
                'help' => __('blox_home_override_inherit_help'),
            ],
            [
                'key' => 'bg_image',
                'type' => 'image',
                'tab' => 'style',
                'label' => __('blox_home_cta_background_image'),
                'default' => '',
                'required' => ['block_type', '=', 'cta'],
            ],
            [
                'key' => 'bg_color',
                'type' => 'color',
                'tab' => 'style',
                'label' => __('blox_bg_color'),
                'default' => '',
                'required' => ['block_type', '=', 'cta'],
            ],
            [
                'key' => 'bg_overlay_color',
                'type' => 'color',
                'tab' => 'style',
                'label' => __('blox_bg_overlay_color'),
                'default' => '#0f172a',
                'required' => ['block_type', '=', 'cta'],
            ],
            [
                'key' => 'bg_overlay_opacity',
                'type' => 'range',
                'tab' => 'style',
                'label' => __('blox_overlay_opacity'),
                'default' => 55,
                'min' => 0,
                'max' => 100,
                'step' => 5,
                'required' => ['block_type', '=', 'cta'],
                'help' => __('blox_home_cta_overlay_help'),
            ],
            [
                'key' => 'text_light',
                'type' => 'checkbox',
                'tab' => 'style',
                'label' => __('shome_light_text'),
                'default' => true,
                'required' => ['block_type', '=', 'cta'],
            ],
            [
                'key' => 'limit',
                'type' => 'number',
                'label' => __('blox_home_limit'),
                'default' => 0,
                'min' => 0,
                'max' => self::MAX_ITEMS,
                'required' => ['block_type', '=', $limitedSources],
                'help' => __('blox_home_limit_help'),
            ],
            [
                'key' => 'sort',
                'type' => 'select',
                'label' => __('blox_home_sort'),
                'default' => 'inherit',
                'options' => [
                    'inherit' => __('blox_home_inherit'),
                    'recommend' => __('blox_home_sort_recommend'),
                    'latest' => __('blox_home_sort_latest'),
                ],
                'required' => ['block_type', '=', $collectionSources],
            ],
            [
                'key' => 'per_row',
                'type' => 'number',
                'label' => __('blox_home_columns'),
                'default' => 0,
                'min' => 0,
                'max' => self::MAX_COLUMNS,
                'required' => ['block_type', '=', $collectionSources],
                'help' => __('blox_home_columns_help'),
            ],
            [
                'key' => 'empty_state',
                'type' => 'select',
                'label' => __('blox_home_empty_state'),
                'default' => 'hide',
                'options' => [
                    'hide' => __('blox_home_empty_hide'),
                    'message' => __('blox_home_empty_message'),
                ],
            ],
            [
                'key' => 'empty_text',
                'type' => 'text',
                'label' => __('blox_home_empty_text'),
                'default' => __('blox_home_empty_default'),
                'required' => ['empty_state', '=', 'message'],
            ],
            [
                'key' => 'enabled',
                'type' => 'checkbox',
                'label' => __('blox_home_enabled'),
                'default' => true,
            ],
        ];
    }


    /**
     * 首页旧区块在 Blox 中呈现的虚拟内部结构。
     *
     * @return array<string, array<string, mixed>>
     */
    public static function editorBlueprints(): array
    {
        $blueprints = [
            'about' => [
                'summary' => __('blox_home_about_two_columns'),
                'reverse_key' => 'override_layout',
                'reverse_value' => 'image_left',
                'projection' => [
                    'type' => 'columns',
                    'ratio_key' => 'override_ratio',
                    'default_ratio' => '1_1',
                    'ratios' => [
                        '1_1' => ['text' => 6, 'image' => 6],
                        '5_7' => ['text' => 5, 'image' => 7],
                        '7_5' => ['text' => 7, 'image' => 5],
                        '1_2' => ['text' => 4, 'image' => 8],
                        '2_1' => ['text' => 8, 'image' => 4],
                    ],
                ],
                'groups' => [
                    [
                        'key' => 'text',
                        'label' => __('blox_home_about_text_column'),
                        'icon' => 'align-left',
                        'numbered' => true,
                        'fields' => [
                            ['key' => 'override_title', 'icon' => 'heading', 'label' => __('blox_home_override_title'), 'control' => 'text'],
                            ['key' => 'title_decor_style', 'icon' => 'minus', 'label' => __('blox_home_title_decor'), 'control' => 'select'],
                            ['key' => 'override_content', 'icon' => 'align-left', 'label' => __('blox_home_override_content'), 'control' => 'textarea'],
                            ['key' => 'override_button_text', 'icon' => 'click', 'label' => __('blox_home_override_button_text'), 'control' => 'text'],
                        ],
                    ],
                    [
                        'key' => 'image',
                        'label' => __('blox_home_about_image_column'),
                        'icon' => 'photo',
                        'numbered' => true,
                        'fields' => [
                            ['key' => 'override_image', 'icon' => 'photo', 'label' => __('blox_home_override_image'), 'control' => 'image'],
                            ['key' => 'override_tag_title', 'icon' => 'tag', 'label' => __('blox_home_about_tag_title'), 'control' => 'text'],
                            ['key' => 'override_tag_description', 'icon' => 'note', 'label' => __('blox_home_about_tag_description'), 'control' => 'text'],
                        ],
                    ],
                ],
            ],
            'stats' => [
                'summary' => __('blox_home_stats_items'),
                'groups' => [[
                    'key' => 'stats',
                    'label' => __('blox_home_stats_item'),
                    'icon' => 'chart-bar',
                    'repeat' => 4,
                    'fields' => [
                        ['key' => 'stats_items.{index}.icon', 'icon' => 'icons', 'label' => __('blox_home_stats_icon'), 'control' => 'icon', 'options' => self::statsIconOptions()],
                        ['key' => 'stats_items.{index}.number', 'icon' => 'numbers', 'label' => __('blox_home_stats_number'), 'control' => 'text'],
                        ['key' => 'stats_items.{index}.label', 'icon' => 'forms', 'label' => __('blox_home_stats_label'), 'control' => 'text'],
                    ],
                ]],
            ],
            'advantage' => [
                'summary' => __('blox_home_advantage_structure'),
                'groups' => [
                    [
                        'key' => 'heading',
                        'label' => __('blox_home_block_content'),
                        'icon' => 'heading',
                        'fields' => [
                            ['key' => 'override_title', 'icon' => 'heading', 'label' => __('blox_home_override_title'), 'control' => 'text'],
                            ['key' => 'title_decor_style', 'icon' => 'minus', 'label' => __('blox_home_title_decor'), 'control' => 'select'],
                            ['key' => 'override_description', 'icon' => 'align-left', 'label' => __('blox_home_override_description'), 'control' => 'textarea'],
                        ],
                    ],
                    [
                        'key' => 'advantages',
                        'label' => __('blox_home_advantage_item'),
                        'icon' => 'rosette-discount-check',
                        'repeat' => 4,
                        'fields' => [
                            ['key' => 'advantage_items.{index}.icon', 'icon' => 'icons', 'label' => __('blox_home_advantage_icon'), 'control' => 'icon', 'options' => self::advantageIconOptions()],
                            ['key' => 'advantage_items.{index}.title', 'icon' => 'heading', 'label' => __('blox_home_advantage_item_title'), 'control' => 'text'],
                            ['key' => 'advantage_items.{index}.description', 'icon' => 'align-left', 'label' => __('blox_home_advantage_item_description'), 'control' => 'textarea'],
                        ],
                    ],
                ],
            ],
            'cta' => [
                'summary' => __('blox_home_cta_structure'),
                'groups' => [
                    [
                        'key' => 'content',
                        'label' => __('blox_home_block_content'),
                        'icon' => 'speakerphone',
                        'fields' => [
                            ['key' => 'override_title', 'icon' => 'heading', 'label' => __('blox_home_override_title'), 'control' => 'text'],
                            ['key' => 'title_decor_style', 'icon' => 'minus', 'label' => __('blox_home_title_decor'), 'control' => 'select'],
                            ['key' => 'override_description', 'icon' => 'align-left', 'label' => __('blox_home_override_description'), 'control' => 'textarea'],
                            ['key' => 'override_button_text', 'icon' => 'click', 'label' => __('blox_home_override_button_text'), 'control' => 'text'],
                            ['key' => 'override_button_url', 'icon' => 'link', 'label' => __('blox_home_override_button_url'), 'control' => 'url'],
                        ],
                    ],
                    [
                        'key' => 'background',
                        'label' => __('blox_home_cta_background'),
                        'icon' => 'photo',
                        'tree' => false,
                        'fields' => [
                            ['key' => 'bg_image', 'icon' => 'photo', 'label' => __('blox_home_cta_background_image'), 'control' => 'image'],
                            ['key' => 'bg_color', 'icon' => 'palette', 'label' => __('blox_bg_color'), 'control' => 'color'],
                            ['key' => 'bg_overlay_color', 'icon' => 'layers-subtract', 'label' => __('blox_bg_overlay_color'), 'control' => 'color'],
                            ['key' => 'bg_overlay_opacity', 'icon' => 'adjustments-horizontal', 'label' => __('blox_overlay_opacity'), 'control' => 'range'],
                            // 旧文档兼容白名单；新编辑器不再展示语义不明确的 bg_opacity。
                            ['key' => 'bg_opacity', 'icon' => 'adjustments-horizontal', 'label' => __('blox_home_cta_overlay'), 'control' => 'range'],
                            ['key' => 'text_light', 'icon' => 'sun', 'label' => __('shome_light_text'), 'control' => 'checkbox'],
                        ],
                    ],
                ],
            ],
        ];

        $headingBlueprint = [
            'summary' => __('blox_home_heading_structure'),
            'groups' => [[
                'key' => 'heading',
                'label' => __('blox_home_block_content'),
                'icon' => 'heading',
                'fields' => [
                    ['key' => 'override_title', 'icon' => 'heading', 'label' => __('blox_home_override_title'), 'control' => 'text'],
                    ['key' => 'title_decor_style', 'icon' => 'minus', 'label' => __('blox_home_title_decor'), 'control' => 'select'],
                ],
            ]],
        ];
        foreach (['testimonials', 'partners', 'product_categories'] as $type) {
            $blueprints[$type] = $headingBlueprint;
        }
        foreach (array_keys(self::sourceOptions()) as $type) {
            if (str_starts_with($type, 'channel:')) {
                $blueprints[$type] = $headingBlueprint;
            } elseif (str_starts_with($type, 'custom:')) {
                $contract = self::customEditorContract($type);
                $blueprints[$type] = [
                    'summary' => $contract['groups'] === [] && $contract['repeaters'] === []
                        && $contract['column_repeaters'] === []
                        ? __('blox_home_heading_structure')
                        : __('blox_home_custom_columns'),
                    'groups' => array_merge([[
                        'key' => 'heading',
                        'label' => __('blox_home_block_content'),
                        'icon' => 'heading',
                        'fields' => [
                            ['key' => 'custom_title', 'icon' => 'heading', 'label' => __('blox_home_override_title'), 'control' => 'text'],
                            ['key' => 'custom_subtitle', 'icon' => 'align-left', 'label' => __('pea_subtitle'), 'control' => 'textarea'],
                        ],
                    ]], $contract['groups']),
                    'repeaters' => $contract['repeaters'],
                    'column_repeaters' => $contract['column_repeaters'],
                ];
            }
        }

        return $blueprints;
    }

    public static function isEditableFieldPath(string $type, string $path): bool
    {
        return in_array($path, self::editableFieldPaths($type), true);
    }

    public static function isCustomEditableFieldPath(string $path): bool
    {
        return preg_match(
            '/^custom_overrides\.[a-zA-Z0-9_]+\.\d+\.columns\.\d+\.(?:card_bg|elements\.\d+\.data\.(?:text|html|url|accordion_items\.\d+\.(?:question|answer)))$/',
            $path
        ) === 1;
    }

    /** @return list<string> */
    public static function editableFieldPaths(string $type): array
    {
        $blueprint = self::editorBlueprints()[$type] ?? null;
        if (!is_array($blueprint)) {
            return [];
        }
        $paths = [];
        foreach (is_array($blueprint['groups'] ?? null) ? $blueprint['groups'] : [] as $group) {
            if (!is_array($group)) {
                continue;
            }
            $repeat = max(1, min(12, (int) ($group['repeat'] ?? 1)));
            foreach (is_array($group['fields'] ?? null) ? $group['fields'] : [] as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $template = (string) ($field['key'] ?? '');
                for ($index = 0; $index < $repeat; $index++) {
                    $path = str_replace('{index}', (string) $index, $template);
                    if ($path !== '' && preg_match('/^[a-z_][a-z0-9_]*(?:\.[a-z0-9_]+)*$/', $path) === 1) {
                        $paths[] = $path;
                    }
                }
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * 自定义首页区块只暴露可安全覆盖的标准元素字段。覆盖路径带语言键，
     * 因此编辑中文价格方案不会把同一份文字强加到英文或日文首页。
     *
     * @param array<int, mixed>|null $blocks
     * @return array{groups:list<array<string,mixed>>,repeaters:list<array<string,mixed>>,column_repeaters:list<array<string,mixed>>,seeds:array<string,mixed>}
     */
    public static function customEditorContract(
        string $type,
        ?array $blocks = null,
        ?string $locale = null
    ): array {
        if (!str_starts_with($type, 'custom:')) {
            return ['groups' => [], 'repeaters' => [], 'column_repeaters' => [], 'seeds' => []];
        }
        if ($blocks === null) {
            try {
                $customData = json_decode(configJsonLang('home_custom_' . substr($type, 7)), true);
                $blocks = is_array($customData['blocks'] ?? null) ? $customData['blocks'] : [];
            } catch (Throwable) {
                $blocks = [];
            }
        }

        $localeKey = self::customLocaleKey($locale);
        $groups = [];
        $repeaters = [];
        $columnRepeaters = [];
        $seeds = ['custom_overrides' => [$localeKey => []]];
        foreach (array_slice($blocks, 0, 10) as $sectionIndex => $section) {
            if (!is_array($section)) {
                continue;
            }
            $columns = is_array($section['columns'] ?? null) ? array_slice($section['columns'], 0, 12) : [];
            $columnRepeaterKey = null;
            if (count($columns) >= 2 && self::supportsStructuralColumns($columns)) {
                $columnRepeaterKey = 'custom-columns-' . $sectionIndex;
                $sectionPrefix = 'custom_overrides.' . $localeKey . '.' . $sectionIndex . '.';
                $columnRepeaters[] = [
                    'key' => $columnRepeaterKey,
                    'label' => __('blox_home_plan_items'),
                    'icon' => 'layout-cards',
                    'items_key' => $sectionPrefix . 'columns',
                    'mode_key' => $sectionPrefix . 'columns_mode',
                    'max' => 12,
                ];
                self::setNestedValue(
                    $seeds,
                    $sectionPrefix . 'columns',
                    self::normalizeStructuralColumns($columns)
                );
            }
            foreach ($columns as $columnIndex => $column) {
                if (!is_array($column)) {
                    continue;
                }
                $prefix = 'custom_overrides.' . $localeKey . '.' . $sectionIndex
                    . '.columns.' . $columnIndex;
                $fields = [[
                    'key' => $prefix . '.card_bg',
                    'icon' => 'palette',
                    'label' => __('blox_home_custom_card_background'),
                    'control' => 'color',
                ]];
                self::setNestedValue(
                    $seeds,
                    $prefix . '.card_bg',
                    (string) ($column['card_bg'] ?? '')
                );

                $groupLabel = str_replace(':n', (string) ($columnIndex + 1), __('blox_col_word'));
                $elements = is_array($column['elements'] ?? null)
                    ? array_slice($column['elements'], 0, 50) : [];
                foreach ($elements as $elementIndex => $element) {
                    if (!is_array($element)) {
                        continue;
                    }
                    $elementType = (string) ($element['type'] ?? '');
                    $elementData = is_array($element['data'] ?? null) ? $element['data'] : [];
                    $elementPrefix = $prefix . '.elements.' . $elementIndex . '.data.';
                    if ($elementType === 'heading') {
                        $value = (string) ($elementData['text'] ?? '');
                        if (trim($value) !== '') {
                            $groupLabel = mb_substr(trim($value), 0, 40);
                        }
                        $fields[] = [
                            'key' => $elementPrefix . 'text',
                            'icon' => 'heading',
                            'label' => __('blox_field_title_short'),
                            'control' => 'text',
                        ];
                        self::setNestedValue($seeds, $elementPrefix . 'text', $value);
                    } elseif ($elementType === 'text') {
                        $value = (string) ($elementData['html'] ?? '');
                        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
                        $fields[] = [
                            'key' => $elementPrefix . 'html',
                            'icon' => 'align-left',
                            'label' => $plain !== '' ? mb_substr($plain, 0, 28) : __('blox_ctl_body'),
                            'control' => 'richtext',
                        ];
                        self::setNestedValue($seeds, $elementPrefix . 'html', $value);
                    } elseif ($elementType === 'button') {
                        $fields[] = [
                            'key' => $elementPrefix . 'text',
                            'icon' => 'click',
                            'label' => __('blox_ctl_btn_text'),
                            'control' => 'text',
                        ];
                        $fields[] = [
                            'key' => $elementPrefix . 'url',
                            'icon' => 'link',
                            'label' => __('blox_ctl_link_url'),
                            'control' => 'url',
                        ];
                        self::setNestedValue($seeds, $elementPrefix . 'text', (string) ($elementData['text'] ?? ''));
                        self::setNestedValue($seeds, $elementPrefix . 'url', (string) ($elementData['url'] ?? ''));
                    } elseif ($elementType === 'accordion') {
                        $accordionItems = array_slice(self::parseAccordionItems($elementData['items'] ?? ''), 0, 30);
                        foreach ($accordionItems as $itemIndex => $item) {
                            $itemPrefix = $elementPrefix . 'accordion_items.' . $itemIndex . '.';
                            self::setNestedValue($seeds, $itemPrefix . 'question', $item['question']);
                            self::setNestedValue($seeds, $itemPrefix . 'answer', $item['answer']);
                        }
                        $repeaters[] = [
                            'key' => 'custom-faq-' . $sectionIndex . '-' . $columnIndex . '-' . $elementIndex,
                            'label' => __('blox_home_faq_items'),
                            'icon' => 'help-circle',
                            'items_key' => $elementPrefix . 'accordion_items',
                            'mode_key' => $elementPrefix . 'accordion_mode',
                            'max' => 30,
                            'fields' => [
                                [
                                    'suffix' => 'question',
                                    'icon' => 'help-circle',
                                    'label' => __('blox_home_faq_question'),
                                    'control' => 'text',
                                ],
                                [
                                    'suffix' => 'answer',
                                    'icon' => 'message-circle',
                                    'label' => __('blox_home_faq_answer'),
                                    'control' => 'textarea',
                                ],
                            ],
                        ];
                    }
                }
                if (count($fields) > 1) {
                    $groups[] = [
                        'key' => 'custom-' . $sectionIndex . '-' . $columnIndex,
                        'label' => $groupLabel,
                        'icon' => 'columns-1',
                        'fields' => $fields,
                        'columnRepeaterKey' => $columnRepeaterKey,
                    ];
                }
            }
        }

        return [
            'groups' => $groups,
            'repeaters' => $repeaters,
            'column_repeaters' => $columnRepeaters,
            'seeds' => $seeds,
        ];
    }

}
