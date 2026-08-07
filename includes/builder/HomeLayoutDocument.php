<?php
/**
 * 首页 Blox 文档。
 *
 * P0 只保存首页布局草稿，不改变线上首页的旧渲染链路。首页动态区块先以
 * home-block 引用节点进入 Blox 树，后续阶段再把这些引用替换为真正的动态元素。
 */

declare(strict_types=1);

final class HomeLayoutDocument
{
    public const DATA_KEY = 'home_layout_data';
    public const ACTIVE_KEY = 'home_layout_active';
    public const PUBLISHED_KEY = 'home_layout_published';
    public const HISTORY_KEY = 'home_layout_history';
    private const VERSION = 1;
    private const MAX_JSON_BYTES = 2_000_000;

    /** @return array{version:int,source:string,active:bool,updated_at:int,sections:array<int,array<string,mixed>>} */
    public static function load(): array
    {
        $raw = trim((string) config(self::DATA_KEY, ''));
        if ($raw !== '' && strlen($raw) <= self::MAX_JSON_BYTES) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $hasDocumentSections = isset($decoded['sections']) && is_array($decoded['sections']);
                $sections = self::extractSections($decoded);
                if ($hasDocumentSections || $sections !== []) {
                    return [
                        'version'    => (int) ($decoded['version'] ?? self::VERSION),
                        'source'     => (string) ($decoded['source'] ?? 'layout'),
                        'active'     => self::isActive(),
                        'updated_at' => (int) ($decoded['updated_at'] ?? 0),
                        'sections'   => self::normalizeSections($sections),
                    ];
                }
            }
        }

        return [
            'version'    => self::VERSION,
            'source'     => 'legacy',
            'active'     => self::isActive(),
            'updated_at' => 0,
            'sections'   => self::legacySections(),
        ];
    }
public static function isActive(): bool
    {
        return (string) config(self::ACTIVE_KEY, '0') === '1';
    }

    public static function hasPublished(): bool
    {
        return self::readStoredDocument(self::PUBLISHED_KEY) !== null;
    }

    /** @return array{version:int,source:string,active:bool,updated_at:int,sections:array<int,array<string,mixed>>} */
    /** @psalm-suppress PossiblyUnusedMethod The public homepage entry point is invoked from the root index script. */
    public static function loadPublished(): array
    {
        return self::readStoredDocument(self::PUBLISHED_KEY) ?? self::load();
    }
/**
     * Publishes the current draft atomically and keeps the previous publication
     * as a bounded rollback point.
     *
     * @return array{active:bool,has_published:bool,sections:int}
     */
    public static function publishDraft(): array
    {
        $draft = self::load();
        $previous = self::readStoredDocument(self::PUBLISHED_KEY);
        $history = self::readHistory();

        if ($previous !== null) {
            array_unshift($history, $previous);
            $history = array_slice($history, 0, 10);
        }

        $db = db();
        $db->beginTransaction();
        try {
            settingModel()->set(
                self::PUBLISHED_KEY,
                json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'home'
            );
            settingModel()->set(
                self::HISTORY_KEY,
                json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'home'
            );
            settingModel()->set(self::ACTIVE_KEY, '1', 'home');
            if (class_exists(HomeBloxDocument::class)) {
                settingModel()->set(HomeBloxDocument::ACTIVE_KEY, '0', 'home');
            }
            $db->commit();
            do_action('data_changed', DB_PREFIX . 'settings', 0);
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return [
            'active' => true,
            'has_published' => true,
            'sections' => count($draft['sections']),
        ];
    }

    /**
     * Disables the Blox homepage publication and restores the legacy homepage
     * without deleting the draft or the last published document.
     *
     * @return array{active:bool,has_published:bool,sections:int}
     */
    public static function rollbackToLegacy(): array
    {
        $db = db();
        $db->beginTransaction();
        try {
            settingModel()->set(self::ACTIVE_KEY, '0', 'home');
            $db->commit();
            do_action('data_changed', DB_PREFIX . 'settings', 0);
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        return [
            'active' => false,
            'has_published' => self::hasPublished(),
            'sections' => count(self::load()['sections']),
        ];
    }

    /**
     * 保存编辑器提交的 sections。P0 不启用首页，只写入草稿。
     *
     * @return array{version:int,source:string,active:bool,updated_at:int,sections:array<int,array<string,mixed>>}
     */
    public static function saveDraft(string $blocksJson): array
    {
        $processed = BloxDocumentPipeline::process($blocksJson, 'home');
        $document = [
            'version'    => self::VERSION,
            'source'     => 'layout',
            'active'     => self::isActive(),
            'updated_at' => time(),
            'sections'   => $processed['sections'],
        ];

        settingModel()->set(
            self::DATA_KEY,
            json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'home'
        );

        return $document;
    }

    /** @return array<string,mixed>|null */
    private static function readStoredDocument(string $key): ?array
    {
        $raw = trim((string) config($key, ''));
        if ($raw === '' || strlen($raw) > self::MAX_JSON_BYTES) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['sections']) || !is_array($decoded['sections'])) {
            return null;
        }

        return [
            'version' => (int) ($decoded['version'] ?? self::VERSION),
            'source' => (string) ($decoded['source'] ?? 'layout'),
            'active' => self::isActive(),
            'updated_at' => (int) ($decoded['updated_at'] ?? 0),
            'sections' => self::normalizeSections(self::extractSections($decoded)),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function readHistory(): array
    {
        $raw = trim((string) config(self::HISTORY_KEY, ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            static fn (mixed $item): bool => is_array($item) && isset($item['sections']) && is_array($item['sections'])
        ));
    }

    /** @param array<string|int,mixed> $data @return array<int,array<string,mixed>> */
    private static function extractSections(array $data): array
    {
        $sections = isset($data['sections']) && is_array($data['sections'])
            ? $data['sections']
            : $data;

        return array_values(array_filter($sections, static fn (mixed $section): bool => is_array($section)));
    }

    /** @param array<int,array<string,mixed>> $sections @return array<int,array<string,mixed>> */
    private static function normalizeSections(array $sections): array
    {
        $out = [];
        foreach ($sections as $si => $section) {
            $columns = is_array($section['columns'] ?? null) ? $section['columns'] : [];
            $normalizedColumns = [];
            foreach ($columns as $ci => $column) {
                if (!is_array($column)) {
                    continue;
                }
                $elements = is_array($column['elements'] ?? null) ? $column['elements'] : [];
                $normalizedElements = [];
                foreach ($elements as $ei => $element) {
                    if (!is_array($element)) {
                        continue;
                    }
                    $normalizedElements[] = [
                        'id'   => (string) ($element['id'] ?? 'home_e_' . $si . '_' . $ci . '_' . $ei),
                        'type' => (string) ($element['type'] ?? 'home-block'),
                        'data' => is_array($element['data'] ?? null) ? $element['data'] : [],
                    ];
                }
                $normalizedColumn = [
                    'id'       => (string) ($column['id'] ?? 'home_c_' . $si . '_' . $ci),
                    'elements' => $normalizedElements,
                ];
                if (isset($column['span'])) {
                    $normalizedColumn['span'] = (int) $column['span'];
                }
                if (isset($column['card_bg'])) {
                    $normalizedColumn['card_bg'] = (string) $column['card_bg'];
                }
                $normalizedColumns[] = $normalizedColumn;
            }

            $settings = is_array($section['settings'] ?? null) ? $section['settings'] : [];
            if (!array_key_exists('container_gutter', $settings)
                && self::isLegacyFullWidthDynamicSection($section, $settings, $normalizedColumns)) {
                $settings['container_gutter'] = 'none';
            }

            $normalizedSection = [
                'id'       => (string) ($section['id'] ?? 'home_s_' . $si),
                'type'     => (string) ($section['type'] ?? 'section'),
                'settings' => $settings,
                'columns'  => $normalizedColumns,
            ];
            if (isset($section['name'])) {
                $normalizedSection['name'] = (string) $section['name'];
            }
            $out[] = $normalizedSection;
        }
        return $out;
    }

    /**
     * 识别由旧首页配置懒生成的单区块包装层，为已保存草稿补齐无左右留白设置。
     *
     * @param array<string,mixed> $section
     * @param array<string,mixed> $settings
     * @param array<int,array<string,mixed>> $columns
     */
    private static function isLegacyFullWidthDynamicSection(array $section, array $settings, array $columns): bool
    {
        if (!str_starts_with((string) ($section['id'] ?? ''), 'home_s_')
            || ($settings['max_width'] ?? '') !== 'full'
            || ($settings['padding'] ?? '') !== 'none'
            || trim((string) ($settings['title'] ?? '')) !== ''
            || trim((string) ($settings['subtitle'] ?? '')) !== ''
            || count($columns) !== 1) {
            return false;
        }

        $elements = $columns[0]['elements'] ?? [];
        return count($elements) === 1
            && ($elements[0]['type'] ?? '') === 'home-block'
            && str_starts_with((string) ($elements[0]['id'] ?? ''), 'home_e_');
    }

    /** @return array<int,array<string,mixed>> */
    private static function legacySections(): array
    {
        $blocks = json_decode((string) config('home_blocks_config', ''), true);
        if (!is_array($blocks) || $blocks === []) {
            $blocks = [
                ['type' => 'banner', 'enabled' => true],
                ['type' => 'about', 'enabled' => true],
                ['type' => 'stats', 'enabled' => true],
                ['type' => 'channels', 'enabled' => true],
                ['type' => 'testimonials', 'enabled' => true],
                ['type' => 'advantage', 'enabled' => true],
                ['type' => 'cta', 'enabled' => true],
            ];
        }

        $sections = [];
        foreach (array_values($blocks) as $index => $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = trim((string) ($block['type'] ?? ''));
            if ($type === '') {
                continue;
            }
            if (!empty($block['enabled']) && str_starts_with($type, 'custom:')) {
                $customSections = self::legacyCustomSections($type);
                if ($customSections !== []) {
                    array_push($sections, ...$customSections);
                    continue;
                }
            }
            $sections[] = [
                'id'       => 'home_s_' . $index,
                'type'     => 'section',
                'name'     => self::legacyLabel($type),
                'settings' => [
                    'title'     => '',
                    'subtitle'  => '',
                    'padding'   => 'none',
                    'max_width' => 'full',
                    'container_gutter' => 'none',
                    'gap'       => 'none',
                ],
                'columns' => [[
                    'id' => 'home_c_' . $index,
                    'elements' => [[
                        'id'   => 'home_e_' . $index,
                        'type' => 'home-block',
                        'data' => self::legacyBlockData($type, !empty($block['enabled']), $block),
                    ]],
                ]],
            ];
        }
        return $sections;
    }

    /**
     * 自定义首页版块本身已经是 Builder 文档，直接展开为可编辑区块。
     *
     * @return array<int,array<string,mixed>>
     */
    private static function legacyCustomSections(string $type): array
    {
        $number = (int) substr($type, 7);
        if ($number < 1) {
            return [];
        }

        $custom = json_decode((string) config('home_custom_' . $number, ''), true);
        $blocks = is_array($custom) && is_array($custom['blocks'] ?? null) ? $custom['blocks'] : [];
        if ($blocks === []) {
            return [];
        }

        $title = trim((string) ($custom['title'] ?? ''));
        $sections = self::normalizeSections($blocks);
        foreach ($sections as $sectionIndex => &$section) {
            $prefix = 'home_custom_' . $number . '_' . $sectionIndex . '_';
            $section['id'] = $prefix . (string) ($section['id'] ?? 'section');
            if ($sectionIndex === 0 && $title !== '') {
                $section['name'] = $title;
            }
            foreach ($section['columns'] as $columnIndex => &$column) {
                $column['id'] = $prefix . 'c_' . $columnIndex;
                foreach ($column['elements'] as $elementIndex => &$element) {
                    $element['id'] = $prefix . 'e_' . $columnIndex . '_' . $elementIndex;
                }
                unset($element);
            }
            unset($column);
        }
        unset($section);

        return $sections;
    }

    /**
     * 首次打开免费版首页排版时，把旧首页的真实内容带入独立草稿。
     *
     * @return array<string,mixed>
     */
    private static function legacyBlockData(string $type, bool $enabled, array $legacyBlock = []): array
    {
        $data = [
            'block_type' => $type,
            'enabled' => $enabled,
            'label' => self::legacyLabel($type),
        ];

        foreach (['bg_color', 'bg_image', 'bg_opacity', 'text_light', 'layout', 'limit', 'per_row', 'sort'] as $key) {
            if (array_key_exists($key, $legacyBlock)) {
                $data[$key] = $legacyBlock[$key];
            }
        }

        if (str_starts_with($type, 'channel:')) {
            $channelId = (int) substr($type, 8);
            try {
                $channel = $channelId > 0 ? channelModel()->find($channelId) : null;
            } catch (Throwable) {
                $channel = null;
            }
            if (is_array($channel)) {
                $channelName = trim((string) ($channel['name'] ?? ''));
                if ($channelName !== '') {
                    $data['label'] = $channelName;
                    $data['override_title'] = $channelName;
                }
            }
            return $data;
        }

        if ($type === 'product_carousel') {
            $data['title'] = mb_substr(trim(strip_tags((string) ($legacyBlock['title'] ?? ''))), 0, 200);
            $data['per_row'] = max(1, min(6, (int) ($legacyBlock['per_row'] ?? 4)));
            $autoplay = (int) ($legacyBlock['autoplay'] ?? 0);
            $data['autoplay'] = $autoplay > 0 ? max(2, min(30, $autoplay)) : 0;
            $data['product_ids'] = array_slice(array_values(array_unique(array_filter(
                array_map('intval', is_array($legacyBlock['product_ids'] ?? null) ? $legacyBlock['product_ids'] : []),
                static fn (int $id): bool => $id > 0
            ))), 0, 100);
            return $data;
        }

        if ($type === 'partners') {
            $data['override_title'] = configLang('home_links_title', 'footer_partners');
            return $data;
        }

        if ($type === 'testimonials') {
            $data['override_title'] = configLang('home_testimonials_title', 'home_testimonials_title');
            $data['override_description'] = configLang('home_testimonials_desc', 'home_testimonials_desc');
            $raw = (string) config('home_testimonials_' . siteLang(), '');
            if ($raw === '') {
                $raw = (string) config('home_testimonials', '[]');
            }
            $items = json_decode($raw, true);
            $data['testimonial_items'] = is_array($items) ? array_values($items) : [];
            return $data;
        }

        if ($type === 'advantage') {
            $data['override_title'] = configLang('home_advantage_title') ?: __('home_our_advantage');
            $data['override_description'] = configLang('home_advantage_desc', 'home_advantage_desc');
            $iconDefaults = ['check-circle', 'academic-cap', 'briefcase', 'users'];
            $data['advantage_items'] = [];
            for ($index = 1; $index <= 4; $index++) {
                $data['advantage_items'][] = [
                    'icon' => (string) config('home_adv_' . $index . '_icon', $iconDefaults[$index - 1]),
                    'title' => configLang('home_adv_' . $index . '_title', 'home_adv_' . $index . '_title'),
                    'description' => configLang('home_adv_' . $index . '_desc', 'home_adv_' . $index . '_desc'),
                ];
            }
            return $data;
        }

        if ($type === 'cta') {
            $data['override_title'] = configLang('home_cta_title', 'home_cta_title');
            $data['override_description'] = configLang('home_cta_desc', 'home_cta_desc');
            $data['override_button_text'] = (string) (config('home_cta_button', '') ?: __('detail_consult'));
            $data['override_button_url'] = (string) (config('home_cta_link', '') ?: '/contact.html');
            $data['bg_opacity'] = isset($data['bg_opacity']) ? max(0, min(100, (int) $data['bg_opacity'])) : 55;
            $data['text_light'] = true;
            return $data;
        }

        if ($type === 'stats') {
            $iconDefaults = ['award', 'users', 'briefcase', 'thumb-up'];
            $numberDefaults = ['10+', '1000+', '50+', '100%'];
            $data['override_background'] = (string) (
                config('home_stat_bg', '')
                ?: 'https://images.unsplash.com/photo-1497215842964-222b430dc094?w=1920&q=80'
            );
            $data['counter_enabled'] = (string) config('home_stat_counter_enabled', '1') !== '0';
            $data['counter_start'] = max(0, min(1000000, (int) config('home_stat_counter_start', 0)));
            $data['counter_duration'] = max(0, min(5000, (int) config('home_stat_counter_duration', 0)));
            $mobileColumns = (string) config('home_stat_mobile_columns', '2');
            $data['stats_mobile_columns'] = in_array($mobileColumns, ['1', '2'], true) ? $mobileColumns : '2';
            $tabletColumns = (string) config('home_stat_tablet_columns', '4');
            $data['stats_tablet_columns'] = in_array($tabletColumns, ['2', '4'], true) ? $tabletColumns : '4';
            $data['stats_items'] = [];
            for ($index = 1; $index <= 4; $index++) {
                $data['stats_items'][] = [
                    'icon' => (string) config('home_stat_' . $index . '_icon', $iconDefaults[$index - 1]),
                    'number' => (string) config('home_stat_' . $index . '_num', $numberDefaults[$index - 1]),
                    'label' => configLang('home_stat_' . $index . '_text', 'home_stat_' . $index),
                ];
            }
            return $data;
        }

        if ($type !== 'about') {
            return $data;
        }

        $title = trim((string) (configJsonLang('home_about_title') ?: config('home_about_title', '')));
        if ($title === '') {
            $title = __('home_about_title') . configRawLang('site_name', '');
        }

        $layout = (string) config('home_about_layout', 'text_left');
        $data['override_layout'] = $layout === 'image_left' ? 'image_left' : 'text_left';
        $ratio = (string) config('home_about_ratio', '1_1');
        $data['override_ratio'] = in_array($ratio, ['1_1', '5_7', '7_5', '1_2', '2_1'], true) ? $ratio : '1_1';
        $data['override_breakpoint'] = (string) config('home_about_breakpoint', 'lg') === 'md' ? 'md' : 'lg';
        $data['override_title'] = $title;
        $data['override_content'] = configLang('home_about_content', 'home_about_default');
        $data['override_image'] = (string) config(
            'home_about_image',
            'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80'
        );
        $data['override_tag_title'] = (string) (configJsonLang('home_about_tag_title') ?: config('home_about_tag_title', ''));
        $data['override_tag_description'] = (string) (configJsonLang('home_about_tag_desc') ?: config('home_about_tag_desc', ''));
        $data['override_button_text'] = (string) (config('home_about_button', '') ?: __('home_learn_more'));
        $data['override_button_url'] = (string) config('home_about_link', '');

        return $data;
    }

    public static function legacyLabel(string $type): string
    {
        $labels = [
            'banner'       => 'Banner 轮播图',
            'about'        => '关于我们',
            'stats'        => '数据统计',
            'channels'     => '栏目内容',
            'testimonials' => '客户评价',
            'advantage'    => '我们的优势',
            'cta'          => '行动号召',
            'partners'     => '合作伙伴',
            'product_categories' => '产品分类',
        ];
        if (isset($labels[$type])) {
            return $labels[$type];
        }
        if (str_starts_with($type, 'channel:')) {
            return '栏目内容 #' . substr($type, 8);
        }
        if (str_starts_with($type, 'custom:')) {
            return '自定义版块 #' . substr($type, 7);
        }
        return $type;
    }
}