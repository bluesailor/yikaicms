<?php
/**
 * 首页 Blox 动态区块的数据契约。
 *
 * 当前只开放固定首页来源与栏目列表的安全查询子集。所有值在进入主题模板前
 * 都会归一化，避免把编辑器数据直接转换成 SQL 或任意模板路径。
 */

declare(strict_types=1);

final class HomeBloxBlockSchema
{
    public const MAX_ITEMS = 24;
    public const MAX_COLUMNS = 8;


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
                'key' => 'banner_height_mobile',
                'type' => 'number',
                'label' => __('blox_banner_height_mobile'),
                'default' => 300,
                'min' => 180,
                'max' => 1200,
                'step' => 10,
                'required' => ['block_type', '=', 'banner'],
                'visible_when' => ['terms' => [['banner_height_mode', '=', 'fixed']]],
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

    public static function customLocaleKey(?string $locale = null): string
    {
        $locale = $locale ?? (function_exists('siteLang') ? siteLang() : 'zh-CN');
        return preg_replace('/[^a-zA-Z0-9_]+/', '_', str_replace('-', '_', $locale)) ?: 'default';
    }

    /** @param array<int,mixed> $blocks @param array<string,mixed> $data @return array<int,mixed> */
    public static function applyCustomOverrides(array $blocks, array $data, ?string $locale = null): array
    {
        $overrides = $data['custom_overrides'][self::customLocaleKey($locale)] ?? null;
        if (!is_array($overrides)) {
            return $blocks;
        }
        foreach ($blocks as $sectionIndex => &$section) {
            if (!is_array($section) || !is_array($section['columns'] ?? null)) {
                continue;
            }
            $sectionOverride = $overrides[$sectionIndex] ?? null;
            if (is_array($sectionOverride)
                && ($sectionOverride['columns_mode'] ?? '') === 'custom'
                && is_array($sectionOverride['columns'] ?? null)) {
                $section['columns'] = array_values($sectionOverride['columns']);
            }
            foreach ($section['columns'] as $columnIndex => &$column) {
                if (!is_array($column)) {
                    continue;
                }
                $columnOverride = $overrides[$sectionIndex]['columns'][$columnIndex] ?? null;
                if (!is_array($columnOverride)) {
                    continue;
                }
                if (array_key_exists('card_bg', $columnOverride)) {
                    $column['card_bg'] = (string) $columnOverride['card_bg'];
                }
                if (!is_array($column['elements'] ?? null)) {
                    continue;
                }
                foreach ($column['elements'] as $elementIndex => &$element) {
                    if (!is_array($element)) {
                        continue;
                    }
                    $value = $columnOverride['elements'][$elementIndex]['data'] ?? null;
                    if (!is_array($value)) {
                        continue;
                    }
                    $element['data'] = is_array($element['data'] ?? null) ? $element['data'] : [];
                    $allowed = match ((string) ($element['type'] ?? '')) {
                        'heading' => ['text'],
                        'text' => ['html'],
                        'button' => ['text', 'url'],
                        default => [],
                    };
                    foreach ($allowed as $key) {
                        if (array_key_exists($key, $value)) {
                            $element['data'][$key] = (string) $value[$key];
                        }
                    }
                    if (($element['type'] ?? '') === 'accordion'
                        && ($value['accordion_mode'] ?? '') === 'custom') {
                        $items = [];
                        foreach (array_slice((array) ($value['accordion_items'] ?? []), 0, 30) as $item) {
                            if (!is_array($item)) {
                                continue;
                            }
                            $question = self::sanitizeAccordionPart((string) ($item['question'] ?? ''), 500, true);
                            $answer = self::sanitizeAccordionPart((string) ($item['answer'] ?? ''), 5000);
                            $items[] = ['question' => $question, 'answer' => $answer];
                        }
                        $element['data']['items'] = $items;
                    } elseif (($element['type'] ?? '') === 'accordion'
                        && is_array($value['accordion_items'] ?? null)) {
                        $items = self::parseAccordionItems($element['data']['items'] ?? '');
                        foreach (array_slice($value['accordion_items'], 0, 30, true) as $itemIndex => $itemOverride) {
                            if (!is_numeric($itemIndex) || !isset($items[(int) $itemIndex]) || !is_array($itemOverride)) {
                                continue;
                            }
                            foreach (['question', 'answer'] as $field) {
                                if (array_key_exists($field, $itemOverride)) {
                                    $items[(int) $itemIndex][$field] = self::sanitizeAccordionPart(
                                        (string) $itemOverride[$field],
                                        $field === 'question' ? 500 : 5000,
                                        $field === 'question'
                                    );
                                }
                            }
                        }
                        $element['data']['items'] = $items;
                    }
                }
                unset($element);
            }
            unset($column);
        }
        unset($section);
        return $blocks;
    }

    /** @param array<string,mixed> $target */
    private static function setNestedValue(array &$target, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $cursor = &$target;
        foreach ($parts as $index => $part) {
            if ($index === count($parts) - 1) {
                $cursor[$part] = $value;
                break;
            }
            if (!is_array($cursor[$part] ?? null)) {
                $cursor[$part] = [];
            }
            $cursor = &$cursor[$part];
        }
        unset($cursor);
    }

    /** @return list<array{question:string,answer:string}> */
    private static function parseAccordionItems(mixed $value): array
    {
        $items = [];
        if (is_array($value)) {
            foreach (array_slice($value, 0, 30) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $question = self::sanitizeAccordionPart((string) ($item['question'] ?? ''), 500, true);
                if ($question !== '') {
                    $items[] = [
                        'question' => $question,
                        'answer' => self::sanitizeAccordionPart((string) ($item['answer'] ?? ''), 5000),
                    ];
                }
            }
            return $items;
        }
        foreach (preg_split('/\r\n|\r|\n/', (string) $value) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$question, $answer] = array_pad(explode('|', $line, 2), 2, '');
            $question = self::sanitizeAccordionPart($question, 500, true);
            if ($question !== '') {
                $items[] = [
                    'question' => $question,
                    'answer' => self::sanitizeAccordionPart($answer, 5000),
                ];
            }
        }
        return $items;
    }

    private static function sanitizeAccordionPart(string $value, int $maxLength, bool $singleLine = false): string
    {
        $value = trim(strip_tags($value));
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        if ($singleLine) {
            $value = (string) preg_replace('/\s+/u', ' ', $value);
        } else {
            $value = (string) preg_replace('/[^\S\n]{2,}/u', ' ', $value);
            $value = (string) preg_replace('/\n{3,}/u', "\n\n", $value);
        }
        return mb_substr($value, 0, $maxLength);
    }


    /**
     * @return list<array{icon:string,number:string,label:string}>
     * @psalm-suppress PossiblyUnusedMethod 调用方在付费 blox_editor.php（CI 公开扫描不含该文件）。
     */
    public static function statsSeedItems(): array
    {
        $defaults = [
            ['icon' => 'award', 'number' => '10+', 'label' => __('home_stat_1')],
            ['icon' => 'users', 'number' => '1000+', 'label' => __('home_stat_2')],
            ['icon' => 'briefcase', 'number' => '50+', 'label' => __('home_stat_3')],
            ['icon' => 'thumb-up', 'number' => '100%', 'label' => __('home_stat_4')],
        ];
        $configured = json_decode((string) config('home_stats', ''), true);
        $items = [];
        for ($index = 0; $index < 4; $index++) {
            $number = (string) config('home_stat_' . ($index + 1) . '_num', $defaults[$index]['number']);
            $label = self::localizedConfig('home_stat_' . ($index + 1) . '_text', 'home_stat_' . ($index + 1), $defaults[$index]['label']);
            if (is_array($configured) && is_array($configured[$index] ?? null)) {
                $entry = $configured[$index];
                $number = (string) ($entry['number'] ?? $number) . (string) ($entry['suffix'] ?? '');
                $label = (string) ($entry['label'] ?? $label);
            }
            $items[] = [
                'icon' => (string) config('home_stat_' . ($index + 1) . '_icon', $defaults[$index]['icon']),
                'number' => $number,
                'label' => $label,
            ];
        }
        return $items;
    }

    /**
     * @return list<array{icon:string,title:string,description:string}>
     * @psalm-suppress PossiblyUnusedMethod 调用方在付费 blox_editor.php（CI 公开扫描不含该文件）。
     */
    public static function advantageSeedItems(): array
    {
        $defaults = [
            ['icon' => 'check-circle', 'title' => __('home_adv_1_title'), 'description' => __('home_adv_1_desc')],
            ['icon' => 'academic-cap', 'title' => __('home_adv_2_title'), 'description' => __('home_adv_2_desc')],
            ['icon' => 'briefcase', 'title' => __('home_adv_3_title'), 'description' => __('home_adv_3_desc')],
            ['icon' => 'users', 'title' => __('home_adv_4_title'), 'description' => __('home_adv_4_desc')],
        ];
        $configured = json_decode((string) config('home_advantages', ''), true);
        $items = [];
        for ($index = 0; $index < 4; $index++) {
            $entry = is_array($configured) && is_array($configured[$index] ?? null) ? $configured[$index] : [];
            $items[] = [
                'icon' => (string) ($entry['icon'] ?? config('home_adv_' . ($index + 1) . '_icon', $defaults[$index]['icon'])),
                'title' => (string) ($entry['title'] ?? self::localizedConfig('home_adv_' . ($index + 1) . '_title', 'home_adv_' . ($index + 1) . '_title', $defaults[$index]['title'])),
                'description' => (string) ($entry['description'] ?? $entry['desc'] ?? self::localizedConfig('home_adv_' . ($index + 1) . '_desc', 'home_adv_' . ($index + 1) . '_desc', $defaults[$index]['description'])),
            ];
        }
        return $items;
    }

    private static function localizedConfig(string $key, string $fallbackKey, string $fallback): string
    {
        if (function_exists('configLang')) {
            return (string) configLang($key, $fallbackKey);
        }

        $value = (string) config($key, '');
        return $value !== '' ? $value : $fallback;
    }

    /** @return list<string> */
    private static function advantageIconOptions(): array
    {
        return [
            'check-circle', 'shield-check', 'academic-cap', 'briefcase', 'users',
            'star', 'heart', 'globe', 'clock', 'cog', 'chart-bar', 'thumb-up',
            'phone', 'bolt', 'sparkles', 'truck',
        ];
    }

    /** @return list<string> */
    private static function statsIconOptions(): array
    {
        return [
            'award', 'users', 'briefcase', 'thumb-up', 'star', 'heart', 'target',
            'shield-check', 'trending-up', 'world', 'building', 'calendar', 'clock',
            'rocket', 'trophy', 'chart-bar', 'headset', 'check', 'activity', 'bolt',
            'bulb', 'certificate', 'chart-pie', 'circle-check', 'coin', 'crown',
            'database', 'device-desktop', 'diamond', 'flag', 'gift', 'globe', 'home',
            'leaf', 'map-pin', 'package', 'phone', 'plant', 'school', 'server',
            'settings', 'shopping-cart', 'sparkles', 'tool', 'truck', 'user-star',
            'wallet', 'wifi', 'none',
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        $type = trim((string) ($data['block_type'] ?? ''));
        $data['block_type'] = $type;
        $data['enabled'] = !empty($data['enabled']);
        $data['limit'] = max(0, min(self::MAX_ITEMS, (int) ($data['limit'] ?? 0)));
        $data['per_row'] = max(0, min(self::MAX_COLUMNS, (int) ($data['per_row'] ?? 0)));

        $sort = (string) ($data['sort'] ?? 'inherit');
        $data['sort'] = in_array($sort, ['inherit', 'recommend', 'latest'], true) ? $sort : 'inherit';

        $emptyState = (string) ($data['empty_state'] ?? 'hide');
        $data['empty_state'] = $emptyState === 'message' ? 'message' : 'hide';
        $emptyText = trim(strip_tags((string) ($data['empty_text'] ?? '')));
        $data['empty_text'] = mb_substr($emptyText, 0, 200);

        foreach (['custom_title' => 200, 'custom_subtitle' => 500] as $key => $length) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = trim(strip_tags((string) $data[$key]));
            $data[$key] = mb_substr($value, 0, $length);
        }
        if (str_starts_with($type, 'custom:')) {
            $data['custom_overrides'] = self::normalizeCustomOverrides($data['custom_overrides'] ?? null);
        } else {
            unset($data['custom_overrides']);
        }

        foreach ([
            'override_title' => 200,
            'override_description' => 500,
            'override_content' => 2000,
            'override_tag_title' => 100,
            'override_tag_description' => 200,
            'override_button_text' => 100,
        ] as $key => $length) {
            $value = trim(strip_tags((string) ($data[$key] ?? '')));
            $data[$key] = mb_substr($value, 0, $length);
        }
        $aboutLayout = (string) ($data['override_layout'] ?? 'text_left');
        $data['override_layout'] = $aboutLayout === 'image_left' ? 'image_left' : 'text_left';
        $aboutRatio = (string) ($data['override_ratio'] ?? '1_1');
        $data['override_ratio'] = in_array($aboutRatio, ['1_1', '5_7', '7_5', '1_2', '2_1'], true)
            ? $aboutRatio
            : '1_1';
        $data['override_breakpoint'] = (string) ($data['override_breakpoint'] ?? 'lg') === 'md' ? 'md' : 'lg';
        $data['override_image'] = self::safeUrl((string) ($data['override_image'] ?? ''), false);
        $data['override_background'] = self::safeUrl((string) ($data['override_background'] ?? ''), false);
        $data['override_button_url'] = self::safeUrl((string) ($data['override_button_url'] ?? ''), true);
        $decorStyle = (string) ($data['title_decor_style'] ?? 'inherit');
        $data['title_decor_style'] = in_array($decorStyle, ['inherit', 'line', 'dot', 'none'], true)
            ? $decorStyle
            : 'inherit';
        $decorAlign = (string) ($data['title_decor_align'] ?? 'inherit');
        $data['title_decor_align'] = in_array($decorAlign, ['inherit', 'left', 'center', 'right'], true)
            ? $decorAlign
            : 'inherit';
        $data['title_decor_color'] = AbstractElement::cssColor($data['title_decor_color'] ?? null) ?? '';
        $data['title_decor_width'] = max(0, min(240, (int) ($data['title_decor_width'] ?? 0)));
        $data['title_decor_gap'] = max(0, min(80, (int) ($data['title_decor_gap'] ?? 0)));
        $hadLegacyOpacity = array_key_exists('bg_opacity', $data);
        $hadOverlayColor = array_key_exists('bg_overlay_color', $data);
        $hadOverlayOpacity = array_key_exists('bg_overlay_opacity', $data);
        $data['bg_image'] = self::safeUrl((string) ($data['bg_image'] ?? ''), false);
        $data['bg_color'] = AbstractElement::cssColor(
            trim(strip_tags((string) ($data['bg_color'] ?? '')))
        ) ?? '';
        $data['bg_opacity'] = max(0, min(100, (int) ($data['bg_opacity'] ?? 100)));
        if ($type === 'cta') {
            $legacyOverlayColor = $data['bg_color'] !== '' ? $data['bg_color'] : '#000000';
            $data['bg_overlay_color'] = AbstractElement::cssColor(
                trim(strip_tags((string) ($data['bg_overlay_color'] ?? '')))
            )
                ?? ($hadOverlayColor ? '' : $legacyOverlayColor);
            if ($hadOverlayOpacity) {
                $overlayOpacity = (int) $data['bg_overlay_opacity'];
            } elseif ($hadLegacyOpacity) {
                // 旧 getBlockBg() 在没有 bg_color 时使用了反向的“图片可见度”语义。
                $overlayOpacity = $data['bg_color'] !== ''
                    ? $data['bg_opacity']
                    : 100 - $data['bg_opacity'];
            } else {
                $overlayOpacity = 55;
            }
            $data['bg_overlay_opacity'] = max(0, min(100, $overlayOpacity));
        } else {
            unset($data['bg_overlay_color'], $data['bg_overlay_opacity']);
        }
        $data['text_light'] = !empty($data['text_light']);
        $data['layout'] = (string) ($data['layout'] ?? 'container') === 'full' ? 'full' : 'container';
        $data['counter_enabled'] = !array_key_exists('counter_enabled', $data) || !empty($data['counter_enabled']);
        $data['counter_start'] = max(0, min(1000000, (int) ($data['counter_start'] ?? 0)));
        $data['counter_duration'] = max(0, min(5000, (int) ($data['counter_duration'] ?? 0)));
        $mobileColumns = (string) ($data['stats_mobile_columns'] ?? '2');
        $data['stats_mobile_columns'] = in_array($mobileColumns, ['1', '2'], true) ? $mobileColumns : '2';
        $tabletColumns = (string) ($data['stats_tablet_columns'] ?? '4');
        $data['stats_tablet_columns'] = in_array($tabletColumns, ['2', '4'], true) ? $tabletColumns : '4';

        if ($type === 'banner') {
            $data = array_replace($data, self::bannerRuntimeConfig($data));
        }

        $stats = [];
        $statDefaults = [
            ['icon' => 'award', 'number' => '10+', 'label' => __('home_stat_1')],
            ['icon' => 'users', 'number' => '1000+', 'label' => __('home_stat_2')],
            ['icon' => 'briefcase', 'number' => '50+', 'label' => __('home_stat_3')],
            ['icon' => 'thumb-up', 'number' => '100%', 'label' => __('home_stat_4')],
        ];
        foreach (array_slice(is_array($data['stats_items'] ?? null) ? $data['stats_items'] : [], 0, 4) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $icon = strtolower(trim((string) ($item['icon'] ?? $statDefaults[$index]['icon'])));
            $stats[] = [
                'icon' => preg_match('/^(?:none|(?:bi:)?[a-z0-9-]{1,60})$/', $icon) === 1 ? $icon : $statDefaults[$index]['icon'],
                'number' => mb_substr(trim(strip_tags((string) ($item['number'] ?? $statDefaults[$index]['number']))), 0, 50),
                'label' => mb_substr(trim(strip_tags((string) ($item['label'] ?? $statDefaults[$index]['label']))), 0, 100),
            ];
        }
        if ($type === 'stats') {
            while (count($stats) < 4) {
                $stats[] = $statDefaults[count($stats)];
            }
        }
        $data['stats_items'] = $stats;

        $advantages = [];
        $advantageDefaults = [
            ['icon' => 'check-circle', 'title' => __('home_adv_1_title'), 'description' => __('home_adv_1_desc')],
            ['icon' => 'academic-cap', 'title' => __('home_adv_2_title'), 'description' => __('home_adv_2_desc')],
            ['icon' => 'briefcase', 'title' => __('home_adv_3_title'), 'description' => __('home_adv_3_desc')],
            ['icon' => 'users', 'title' => __('home_adv_4_title'), 'description' => __('home_adv_4_desc')],
        ];
        foreach (array_slice(is_array($data['advantage_items'] ?? null) ? $data['advantage_items'] : [], 0, 4) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $icon = strtolower(trim((string) ($item['icon'] ?? $advantageDefaults[$index]['icon'])));
            $advantages[] = [
                'icon' => preg_match('/^(?:bi:)?[a-z0-9-]{1,60}$/', $icon) === 1 ? $icon : $advantageDefaults[$index]['icon'],
                'title' => mb_substr(trim(strip_tags((string) ($item['title'] ?? $advantageDefaults[$index]['title']))), 0, 100),
                'description' => mb_substr(trim(strip_tags((string) ($item['description'] ?? $advantageDefaults[$index]['description']))), 0, 300),
            ];
        }
        if ($type === 'advantage') {
            while (count($advantages) < 4) {
                $advantages[] = $advantageDefaults[count($advantages)];
            }
        }
        $data['advantage_items'] = $advantages;

        $testimonials = [];
        foreach (array_slice(is_array($data['testimonial_items'] ?? null) ? $data['testimonial_items'] : [], 0, 12) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $testimonials[] = [
                'avatar' => self::safeUrl((string) ($item['avatar'] ?? ''), false),
                'name' => mb_substr(trim(strip_tags((string) ($item['name'] ?? ''))), 0, 100),
                'company' => mb_substr(trim(strip_tags((string) ($item['company'] ?? ''))), 0, 150),
                'content' => mb_substr(trim(strip_tags((string) ($item['content'] ?? ''))), 0, 1000),
            ];
        }
        $data['testimonial_items'] = $testimonials;

        if ($type === 'product_carousel') {
            $data['title'] = mb_substr(trim(strip_tags((string) ($data['title'] ?? ''))), 0, 200);
            $data['per_row'] = max(1, min(6, (int) ($data['per_row'] ?? 4)));
            $autoplay = (int) ($data['autoplay'] ?? 0);
            $data['autoplay'] = $autoplay > 0 ? max(2, min(30, $autoplay)) : 0;
            $data['product_ids'] = array_slice(array_values(array_unique(array_filter(
                array_map('intval', is_array($data['product_ids'] ?? null) ? $data['product_ids'] : []),
                static fn (int $id): bool => $id > 0
            ))), 0, 100);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, int|string|bool>
     */
    public static function bannerRuntimeConfig(array $data): array
    {
        $effect = (string) ($data['banner_effect'] ?? 'fade');
        $contentMotion = (string) ($data['banner_content_motion'] ?? 'none');
        $backgroundMotion = (string) ($data['banner_background_motion'] ?? 'none');
        $heightMode = (string) ($data['banner_height_mode'] ?? 'inherit');
        $autoplay = (int) ($data['banner_autoplay'] ?? 5);

        return [
            'banner_height_mode' => in_array($heightMode, ['inherit', 'fixed', 'screen', 'cover-header'], true)
                ? $heightMode
                : 'inherit',
            'banner_height_pc' => max(200, min(1600, (int) ($data['banner_height_pc'] ?? 650))),
            'banner_height_mobile' => max(180, min(1200, (int) ($data['banner_height_mobile'] ?? 300))),
            'banner_effect' => in_array($effect, ['fade', 'slide'], true) ? $effect : 'fade',
            'banner_content_motion' => in_array(
                $contentMotion,
                ['none', 'fade-up', 'slide-left', 'slide-right', 'zoom-in', 'clip-reveal', 'blur-up', 'pop-in'],
                true
            ) ? $contentMotion : 'none',
            'banner_background_motion' => in_array($backgroundMotion, ['none', 'zoom-in', 'zoom-out'], true)
                ? $backgroundMotion
                : 'none',
            'banner_autoplay' => $autoplay > 0 ? max(2, min(30, $autoplay)) : 0,
            'banner_speed' => max(200, min(2000, (int) ($data['banner_speed'] ?? 700))),
            'banner_stagger' => max(0, min(600, (int) ($data['banner_stagger'] ?? 120))),
            'banner_navigation' => !array_key_exists('banner_navigation', $data) || !empty($data['banner_navigation']),
            'banner_pagination' => !array_key_exists('banner_pagination', $data) || !empty($data['banner_pagination']),
            'banner_pause_hover' => !array_key_exists('banner_pause_hover', $data) || !empty($data['banner_pause_hover']),
        ];
    }

    /**
     * 将传统轮播分组映射到统一的 Banner 运行参数。
     *
     * @param array<string, mixed> $group
     * @return array<string, int|string|bool>
     */
    public static function bannerGroupRuntimeConfig(array $group): array
    {
        $delay = max(0, min(30000, (int) ($group['autoplay_delay'] ?? 5000)));
        $heightMode = (string) ($group['height_mode'] ?? '');
        if (!in_array($heightMode, ['fixed', 'screen', 'cover-header'], true)) {
            $heightMode = !empty($group['fullscreen']) ? 'screen' : 'fixed';
        }

        return self::bannerRuntimeConfig([
            'banner_height_mode' => $heightMode,
            'banner_height_pc' => $group['height_pc'] ?? 500,
            'banner_height_mobile' => $group['height_mobile'] ?? 250,
            'banner_effect' => $group['effect'] ?? 'fade',
            'banner_content_motion' => $group['content_motion'] ?? 'none',
            'banner_background_motion' => $group['background_motion'] ?? 'none',
            'banner_autoplay' => $delay > 0 ? (int) round($delay / 1000) : 0,
            'banner_speed' => $group['speed'] ?? 700,
            'banner_stagger' => $group['stagger'] ?? 120,
            'banner_navigation' => !array_key_exists('navigation', $group) || !empty($group['navigation']),
            'banner_pagination' => !array_key_exists('pagination', $group) || !empty($group['pagination']),
            'banner_pause_hover' => !array_key_exists('pause_hover', $group) || !empty($group['pause_hover']),
        ]);
    }

    /** @param array<string, mixed> $block */
    public static function bannerRuntimeAttributes(array $block): string
    {
        $config = self::bannerRuntimeConfig($block);
        $autoplayMs = (int) $config['banner_autoplay'] * 1000;
        $holdMs = $autoplayMs > 0 ? $autoplayMs + (int) $config['banner_speed'] : 6000;
        $attributes = [
            'data-blox-banner' => '',
            'data-blox-height-mode' => (string) $config['banner_height_mode'],
            'data-blox-effect' => (string) $config['banner_effect'],
            'data-blox-autoplay' => (string) $config['banner_autoplay'],
            'data-blox-speed' => (string) $config['banner_speed'],
            'data-blox-navigation' => $config['banner_navigation'] ? '1' : '0',
            'data-blox-pagination' => $config['banner_pagination'] ? '1' : '0',
            'data-blox-pause-hover' => $config['banner_pause_hover'] ? '1' : '0',
            'data-blox-content-motion' => (string) $config['banner_content_motion'],
            'data-blox-background-motion' => (string) $config['banner_background_motion'],
        ];
        $html = '';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name;
            if ($value !== '') {
                $html .= '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
            }
        }
        $style = '--blox-banner-speed:' . (int) $config['banner_speed'] . 'ms;'
            . '--blox-banner-stagger:' . (int) $config['banner_stagger'] . 'ms;'
            . '--blox-banner-hold:' . $holdMs . 'ms;'
            . '--blox-banner-height-pc:' . (int) $config['banner_height_pc'] . 'px;'
            . '--blox-banner-height-mobile:' . (int) $config['banner_height_mobile'] . 'px;'
            . '--blox-banner-offset:70px';

        return $html . ' style="' . $style . '"';
    }

    /** @param array<string, mixed> $block */
    public static function overrideText(array $block, string $key, string $fallback): string
    {
        $value = trim((string) ($block['override_' . $key] ?? ''));
        return $value !== '' ? $value : $fallback;
    }

    /**
     * 将区块私有内容映射为旧主题模板读取的配置键。
     *
     * @param array<string, mixed> $block
     * @return array<string, string>
     */
    public static function runtimeConfigOverrides(array $block): array
    {
        $type = (string) ($block['block_type'] ?? '');
        $map = match ($type) {
            'about' => [
                'override_layout' => 'home_about_layout',
                'override_ratio' => 'home_about_ratio',
                'override_breakpoint' => 'home_about_breakpoint',
                'override_title' => 'home_about_title',
                'override_content' => 'home_about_content',
                'override_image' => 'home_about_image',
                'override_tag_title' => 'home_about_tag_title',
                'override_tag_description' => 'home_about_tag_desc',
                'override_button_text' => 'home_about_button',
                'override_button_url' => 'home_about_link',
            ],
            'advantage' => [
                'override_title' => 'home_advantage_title',
                'override_description' => 'home_advantage_desc',
            ],
            'testimonials' => [
                'override_title' => 'home_testimonials_title',
                'override_description' => 'home_testimonials_desc',
            ],
            'cta' => [
                'override_title' => 'home_cta_title',
                'override_description' => 'home_cta_desc',
                'override_button_text' => 'home_cta_button',
                'override_button_url' => 'home_cta_link',
            ],
            'partners' => ['override_title' => 'home_links_title'],
            default => [],
        };

        $overrides = [];
        foreach ($map as $sourceKey => $configKey) {
            $value = trim((string) ($block[$sourceKey] ?? ''));
            if ($value === '') {
                continue;
            }
            $overrides[$configKey] = $value;
            if (in_array($sourceKey, ['override_title', 'override_description', 'override_content', 'override_tag_title', 'override_tag_description'], true)) {
                $overrides[$configKey . '_' . siteLang()] = $value;
            }
        }
        if (self::supportsTitleDecoration($type)) {
            $decorStyle = (string) ($block['title_decor_style'] ?? 'inherit');
            $decorAlign = (string) ($block['title_decor_align'] ?? 'inherit');
            $overrides['home_title_decor_style'] = in_array($decorStyle, ['inherit', 'line', 'dot', 'none'], true)
                ? $decorStyle
                : 'inherit';
            $overrides['home_title_decor_align'] = in_array($decorAlign, ['inherit', 'left', 'center', 'right'], true)
                ? $decorAlign
                : 'inherit';
            $overrides['home_title_decor_color'] = AbstractElement::cssColor($block['title_decor_color'] ?? null) ?? '';
            $overrides['home_title_decor_width'] = (string) max(0, min(240, (int) ($block['title_decor_width'] ?? 0)));
            $overrides['home_title_decor_gap'] = (string) max(0, min(80, (int) ($block['title_decor_gap'] ?? 0)));
        }
        if ($type === 'advantage') {
            foreach (array_slice(is_array($block['advantage_items'] ?? null) ? $block['advantage_items'] : [], 0, 4) as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $number = $index + 1;
                $overrides['home_adv_' . $number . '_icon'] = (string) ($item['icon'] ?? '');
                $overrides['home_adv_' . $number . '_title'] = (string) ($item['title'] ?? '');
                $overrides['home_adv_' . $number . '_title_' . siteLang()] = (string) ($item['title'] ?? '');
                $overrides['home_adv_' . $number . '_desc'] = (string) ($item['description'] ?? '');
                $overrides['home_adv_' . $number . '_desc_' . siteLang()] = (string) ($item['description'] ?? '');
            }
        }
        if ($type === 'stats') {
            $counterEnabled = !array_key_exists('counter_enabled', $block) || !empty($block['counter_enabled']);
            $overrides['home_stat_counter_enabled'] = $counterEnabled ? '1' : '0';
            $overrides['home_stat_counter_start'] = (string) max(0, min(1000000, (int) ($block['counter_start'] ?? 0)));
            $overrides['home_stat_counter_duration'] = (string) max(0, min(5000, (int) ($block['counter_duration'] ?? 0)));
            $mobileColumns = (string) ($block['stats_mobile_columns'] ?? '2');
            $tabletColumns = (string) ($block['stats_tablet_columns'] ?? '4');
            $overrides['home_stat_mobile_columns'] = in_array($mobileColumns, ['1', '2'], true) ? $mobileColumns : '2';
            $overrides['home_stat_tablet_columns'] = in_array($tabletColumns, ['2', '4'], true) ? $tabletColumns : '4';
            $background = trim((string) ($block['override_background'] ?? ''));
            if ($background !== '') {
                $overrides['home_stat_bg'] = $background;
            }
            foreach (array_slice(is_array($block['stats_items'] ?? null) ? $block['stats_items'] : [], 0, 4) as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $number = $index + 1;
                $overrides['home_stat_' . $number . '_icon'] = (string) ($item['icon'] ?? '');
                $overrides['home_stat_' . $number . '_num'] = (string) ($item['number'] ?? '');
                $overrides['home_stat_' . $number . '_text'] = (string) ($item['label'] ?? '');
                $overrides['home_stat_' . $number . '_text_' . siteLang()] = (string) ($item['label'] ?? '');
            }
        }
        return $overrides;
    }

    public static function supportsTitleDecoration(string $type): bool
    {
        return str_starts_with($type, 'channel:') || in_array($type, [
            'about', 'testimonials', 'advantage', 'cta', 'partners', 'product_categories',
        ], true);
    }

    /** @param array<string, mixed> $data */
    public static function hasQueryOverride(array $data): bool
    {
        return (int) ($data['limit'] ?? 0) > 0
            || (int) ($data['per_row'] ?? 0) > 0
            || in_array((string) ($data['sort'] ?? ''), ['recommend', 'latest'], true);
    }

    /** @return array<string, string> */
    public static function sourceOptions(): array
    {
        $options = [
            'banner' => __('blox_home_source_banner'),
            'about' => __('blox_home_source_about'),
            'stats' => __('blox_home_source_stats'),
            'testimonials' => __('blox_home_source_testimonials'),
            'advantage' => __('blox_home_source_advantage'),
            'cta' => __('blox_home_source_cta'),
            'partners' => __('blox_home_source_partners'),
            'product_categories' => __('blox_home_source_product_categories'),
        ];

        try {
            $configured = json_decode((string) config('home_blocks_config', ''), true);
            foreach (is_array($configured) ? $configured : [] as $block) {
                $type = trim((string) ($block['type'] ?? ''));
                if ($type === '' || isset($options[$type]) || str_starts_with($type, 'channel:')) {
                    continue;
                }
                $options[$type] = class_exists(HomeBloxDocument::class)
                    ? HomeBloxDocument::legacyLabel($type)
                    : $type;
            }
        } catch (Throwable) {
            // 安装早期或无数据库测试环境仅保留内置来源。
        }

        try {
            $channels = channelModel()->getByParent(0, true, false, siteLang());
        } catch (Throwable) {
            $channels = [];
        }

        foreach ($channels as $channel) {
            $id = (int) ($channel['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $name = trim((string) ($channel['name'] ?? ''));
            $kind = ($channel['type'] ?? '') === 'product'
                ? __('blox_home_source_product')
                : __('blox_home_source_content');
            $options['channel:' . $id] = $kind . ' · ' . ($name !== '' ? $name : '#' . $id);
        }

        return $options;
    }

    /** @param array<int,mixed> $columns */
    private static function supportsStructuralColumns(array $columns): bool
    {
        foreach ($columns as $column) {
            if (!is_array($column) || !is_array($column['elements'] ?? null) || $column['elements'] === []) {
                return false;
            }
            $hasHeading = false;
            foreach ($column['elements'] as $element) {
                $type = is_array($element) ? (string) ($element['type'] ?? '') : '';
                if (!in_array($type, ['heading', 'text', 'button'], true)) {
                    return false;
                }
                $hasHeading = $hasHeading || $type === 'heading';
            }
            if (!$hasHeading) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array<string,mixed>> */
    private static function normalizeStructuralColumns(array $columns): array
    {
        $clean = [];
        foreach (array_slice(array_values($columns), 0, 12) as $column) {
            if (!is_array($column)) {
                continue;
            }
            $normalized = ['elements' => []];
            if (array_key_exists('card_bg', $column)) {
                $normalized['card_bg'] = AbstractElement::cssColor($column['card_bg']) ?? '';
            }
            foreach (array_slice((array) ($column['elements'] ?? []), 0, 50) as $element) {
                if (!is_array($element)) {
                    continue;
                }
                $type = (string) ($element['type'] ?? '');
                $data = is_array($element['data'] ?? null) ? $element['data'] : [];
                if ($type === 'heading') {
                    $level = in_array(($data['level'] ?? ''), ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)
                        ? (string) $data['level'] : 'h3';
                    $align = in_array(($data['align'] ?? ''), ['left', 'center', 'right'], true)
                        ? (string) $data['align'] : 'left';
                    $normalized['elements'][] = [
                        'type' => 'heading',
                        'data' => [
                            'text' => mb_substr(trim(strip_tags((string) ($data['text'] ?? ''))), 0, 500),
                            'level' => $level,
                            'align' => $align,
                        ],
                    ];
                } elseif ($type === 'text') {
                    $normalized['elements'][] = [
                        'type' => 'text',
                        'data' => ['html' => self::sanitizeCustomHtml(substr((string) ($data['html'] ?? ''), 0, 50_000))],
                    ];
                } elseif ($type === 'button') {
                    $normalized['elements'][] = [
                        'type' => 'button',
                        'data' => [
                            'text' => mb_substr(trim(strip_tags((string) ($data['text'] ?? ''))), 0, 500),
                            'url' => self::safeUrl((string) ($data['url'] ?? ''), true),
                            'new_tab' => !empty($data['new_tab']),
                        ],
                    ];
                }
            }
            if ($normalized['elements'] !== []) {
                $clean[] = $normalized;
            }
        }
        return $clean;
    }

    /** @return array<string,mixed> */
    private static function normalizeCustomOverrides(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $clean = [];
        foreach (array_slice($value, 0, 10, true) as $locale => $sections) {
            $localeKey = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) $locale) ?: '';
            if ($localeKey === '' || !is_array($sections)) {
                continue;
            }
            foreach (array_slice($sections, 0, 10, true) as $sectionIndex => $section) {
                if (!is_numeric($sectionIndex) || !is_array($section)) {
                    continue;
                }
                $columns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
                if (($section['columns_mode'] ?? '') === 'custom') {
                    $sectionPrefix = $localeKey . '.' . (int) $sectionIndex . '.';
                    self::setNestedValue($clean, $sectionPrefix . 'columns_mode', 'custom');
                    self::setNestedValue(
                        $clean,
                        $sectionPrefix . 'columns',
                        self::normalizeStructuralColumns($columns)
                    );
                    continue;
                }
                foreach (array_slice($columns, 0, 12, true) as $columnIndex => $column) {
                    if (!is_numeric($columnIndex) || !is_array($column)) {
                        continue;
                    }
                    $prefix = $localeKey . '.' . (int) $sectionIndex . '.columns.' . (int) $columnIndex;
                    if (array_key_exists('card_bg', $column)) {
                        self::setNestedValue(
                            $clean,
                            $prefix . '.card_bg',
                            AbstractElement::cssColor($column['card_bg']) ?? ''
                        );
                    }
                    $elements = is_array($column['elements'] ?? null) ? $column['elements'] : [];
                    foreach (array_slice($elements, 0, 50, true) as $elementIndex => $element) {
                        if (!is_numeric($elementIndex) || !is_array($element)) {
                            continue;
                        }
                        $elementData = is_array($element['data'] ?? null) ? $element['data'] : [];
                        $elementPrefix = $prefix . '.elements.' . (int) $elementIndex . '.data.';
                        foreach (['text' => 500, 'url' => 1000] as $key => $maxLength) {
                            if (!array_key_exists($key, $elementData)) {
                                continue;
                            }
                            $raw = (string) $elementData[$key];
                            $sanitized = $key === 'url'
                                ? self::safeUrl($raw, true)
                                : mb_substr(trim(strip_tags($raw)), 0, $maxLength);
                            self::setNestedValue($clean, $elementPrefix . $key, $sanitized);
                        }
                        if (array_key_exists('html', $elementData)) {
                            $html = substr((string) $elementData['html'], 0, 50_000);
                            self::setNestedValue($clean, $elementPrefix . 'html', self::sanitizeCustomHtml($html));
                        }
                        $accordionMode = ($elementData['accordion_mode'] ?? '') === 'custom';
                        $accordionItems = is_array($elementData['accordion_items'] ?? null)
                            ? $elementData['accordion_items'] : [];
                        if ($accordionMode) {
                            self::setNestedValue($clean, $elementPrefix . 'accordion_mode', 'custom');
                            self::setNestedValue($clean, $elementPrefix . 'accordion_items', []);
                        }
                        $itemSource = $accordionMode
                            ? array_values(array_slice($accordionItems, 0, 30, true))
                            : array_slice($accordionItems, 0, 30, true);
                        foreach ($itemSource as $itemIndex => $item) {
                            if (!is_numeric($itemIndex) || !is_array($item)) {
                                continue;
                            }
                            foreach (['question' => 500, 'answer' => 5000] as $key => $maxLength) {
                                if (!array_key_exists($key, $item)) {
                                    continue;
                                }
                                self::setNestedValue(
                                    $clean,
                                    $elementPrefix . 'accordion_items.' . (int) $itemIndex . '.' . $key,
                                    self::sanitizeAccordionPart(
                                        (string) $item[$key],
                                        $maxLength,
                                        $key === 'question'
                                    )
                                );
                            }
                        }
                    }
                }
            }
        }
        return $clean;
    }

    private static function sanitizeCustomHtml(string $html): string
    {
        if (function_exists('sanitizeHtml')) {
            return sanitizeHtml($html);
        }
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = strip_tags($html, '<p><br><b><i><u><s><em><strong><small><sub><sup><h1><h2><h3><h4><h5><h6><ul><ol><li><a><div><span>');
        $html = (string) preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*)/i', '', $html);
        return (string) preg_replace('/(?:href|src)\s*=\s*["\']?\s*javascript\s*:/i', 'data-removed="1"', $html);
    }

    private static function safeUrl(string $value, bool $allowActionSchemes): string
    {
        $value = trim(strip_tags($value));
        if ($value === '' || mb_strlen($value) > 1000) {
            return '';
        }
        if (str_starts_with($value, '/') || str_starts_with($value, '#')
            || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        if ($allowActionSchemes && preg_match('#^(?:mailto|tel):#i', $value) === 1) {
            return $value;
        }
        return '';
    }
}
