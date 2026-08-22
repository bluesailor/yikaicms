<?php
/**
 * HomeBloxBlockSchema 的 运行时映射层：横幅运行时配置与属性 / 文案覆盖 / 运行时配置覆盖 / 标题装饰与查询覆盖判定 / 数据来源选项。
 *
 * v1.18.6 拆分自 1807 行单文件（审计连续两轮点名的复杂度热点）。
 * trait 形态 = 类名/方法签名/调用方零改动的纯文件级拆分；方法体逐字节搬运，
 * 行为由 HomeBloxBlockSchemaTest 等黄金测试锁定。
 */

declare(strict_types=1);

trait HomeBloxRuntimeTrait
{
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
}
