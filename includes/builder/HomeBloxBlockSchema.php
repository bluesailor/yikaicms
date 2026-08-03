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
                'key' => 'override_layout',
                'type' => 'select',
                'label' => __('blox_home_about_layout'),
                'default' => 'text_left',
                'options' => [
                    'text_left' => __('blox_home_about_text_left'),
                    'image_left' => __('blox_home_about_image_left'),
                ],
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
        $data['override_image'] = self::safeUrl((string) ($data['override_image'] ?? ''), false);
        $data['override_background'] = self::safeUrl((string) ($data['override_background'] ?? ''), false);
        $data['override_button_url'] = self::safeUrl((string) ($data['override_button_url'] ?? ''), true);
        $data['bg_image'] = self::safeUrl((string) ($data['bg_image'] ?? ''), false);
        $data['bg_color'] = mb_substr(trim(strip_tags((string) ($data['bg_color'] ?? ''))), 0, 200);
        $data['bg_opacity'] = max(0, min(100, (int) ($data['bg_opacity'] ?? 100)));
        $data['text_light'] = !empty($data['text_light']);
        $data['layout'] = (string) ($data['layout'] ?? 'container') === 'full' ? 'full' : 'container';

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
                'icon' => preg_match('/^(?:none|[a-z0-9-]{1,60})$/', $icon) === 1 ? $icon : $statDefaults[$index]['icon'],
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
                'icon' => preg_match('/^[a-z0-9-]{1,60}$/', $icon) === 1 ? $icon : $advantageDefaults[$index]['icon'],
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
