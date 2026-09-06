<?php
/**
 * HomeBloxBlockSchema 的 归一化层：种子数据 / 本地化配置读取 / 图标选项 / normalize 主流程 / 结构化列归一。
 *
 * v1.18.6 拆分自 1807 行单文件（审计连续两轮点名的复杂度热点）。
 * trait 形态 = 类名/方法签名/调用方零改动的纯文件级拆分；方法体逐字节搬运，
 * 行为由 HomeBloxBlockSchemaTest 等黄金测试锁定。
 */

declare(strict_types=1);

trait HomeBloxNormalizerTrait
{
    /** @psalm-suppress PossiblyUnusedMethod 调用点在 admin/blox_editor.php 首页种子（拆分前由旧路径 baseline 覆盖） */
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
        if (array_key_exists('home_surface', $data)) {
            $data['home_surface'] = in_array($data['home_surface'], ['auto', 'light', 'dark', 'custom'], true)
                ? $data['home_surface'] : 'auto';
        }
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
            $data = array_replace($data, BannerContentLayout::normalize($data));
            $data = array_replace($data, self::bannerRuntimeConfig($data));
        }

        $stats = [];
        // 兜底值必须来自「首页设置」的 home_stat_* 配置，不能写死。
        // 写死的后果：出厂文档里 stats_items 是空的（正常状态），这里把硬编码值填进去，
        // 再经 runtimeConfigOverrides() 当成配置覆盖压给主题——后台改统计数值/文案
        // 前台永远不生效，只显示 10+/1000+/50+/100% 和语言包文案。
        // statsSeedItems() 读的正是这几个设置，与编辑器种子同一来源。
        $statDefaults = self::statsSeedItems();
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
     * 拆分注：原上一方法的 docblock 在段切割时归了前段，此处按真实签名补正。
     * @param array<int,mixed> $columns
     */
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
}
