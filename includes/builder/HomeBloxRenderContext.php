<?php
/**
 * 首页动态渲染上下文。
 *
 * 前台首页和 Blox 画布预览使用同一个动态区块入口，只有数据来源和编辑标记
 * 由上下文决定。这样不会让画布直接 include index.php，也不会复制主题区块分发逻辑。
 */

declare(strict_types=1);

final class HomeBloxRenderContext
{
    /** @var array<string, array<string, mixed>> */
    private array $legacyBlockByType;

    /** @var array<string, string> */
    private array $blockTemplates;

    /** @var array<int, array<string, mixed>> */
    private array $homeChannelsMap;

    /** @var array<int, array<string, mixed>> */
    private array $banners;

    /** @var array<string, mixed>|null */
    private ?array $aboutChannel;

    /** @var array<int, array<string, mixed>> */
    private array $testimonials;

    private bool $editMode;

    /**
     * @param array<string, array<string, mixed>> $legacyBlockByType
     * @param array<string, string> $blockTemplates
     * @param array<int, array<string, mixed>> $homeChannelsMap
     * @param array<int, array<string, mixed>> $banners
     * @param array<string, mixed>|null $aboutChannel
     * @param array<int, array<string, mixed>> $testimonials
     */
    private function __construct(
        array $legacyBlockByType,
        array $blockTemplates,
        array $homeChannelsMap,
        array $banners,
        ?array $aboutChannel,
        array $testimonials,
        bool $editMode
    ) {
        $this->legacyBlockByType = $legacyBlockByType;
        $this->blockTemplates = $blockTemplates;
        $this->homeChannelsMap = $homeChannelsMap;
        $this->banners = $banners;
        $this->aboutChannel = $aboutChannel;
        $this->testimonials = $testimonials;
        $this->editMode = $editMode;
    }

    /**
     * 首页入口已经准备好数据时使用，避免前台重复查询。
     *
     * @param array<int, array<string, mixed>> $blocksConfig
     * @param array<string, string> $blockTemplates
     * @param array<int, array<string, mixed>> $homeChannelsMap
     * @param array<int, array<string, mixed>> $banners
     * @param array<string, mixed>|null $aboutChannel
     * @param array<int, array<string, mixed>> $testimonials
     */
    public static function fromHomePageData(
        array $blocksConfig,
        array $blockTemplates,
        array $homeChannelsMap,
        array $banners,
        ?array $aboutChannel,
        array $testimonials,
        bool $editMode
    ): self {
        return new self(
            self::indexBlocks($blocksConfig),
            $blockTemplates,
            $homeChannelsMap,
            $banners,
            $aboutChannel,
            $testimonials,
            $editMode
        );
    }

    /**
     * 后台画布预览使用当前站点数据构造最小动态上下文。
     */
    public static function fromCurrentSite(bool $editMode = false): self
    {
        $homeChannels = channelModel()->getHomeChannels();
        $productCategories = productCategoryModel()->getTopLevel(6);
        $blocksConfig = self::configuredBlocks();
        $channelBlockCfg = [];

        foreach ($blocksConfig as $block) {
            $type = (string) ($block['type'] ?? '');
            if (str_starts_with($type, 'channel:')) {
                $channelBlockCfg[(int) substr($type, 8)] = $block;
            }
        }

        $homeChannelsMap = [];
        foreach ($homeChannels as $homeChannel) {
            $channel = $homeChannel;
            $cfg = $channelBlockCfg[(int) ($channel['id'] ?? 0)] ?? [];
            $limit = (int) ($cfg['limit'] ?? 8);
            $limit = $limit > 0 ? $limit : 8;
            $sort = ($cfg['sort'] ?? 'recommend') === 'latest' ? 'latest' : 'recommend';
            $channel['per_row'] = (int) ($cfg['per_row'] ?? 4);

            if (($channel['type'] ?? '') === 'product') {
                $contents = $sort === 'recommend'
                    ? getProducts(0, $limit, 0, ['is_recommend' => true])
                    : getProducts(0, $limit, 0);
                if ($sort === 'recommend' && empty($contents)) {
                    $contents = getProducts(0, $limit, 0);
                }
                $channel['contents'] = $contents;
                $channel['is_product'] = true;
                $channel['categories'] = $productCategories;
            } else {
                $contents = $sort === 'recommend'
                    ? getContents((int) $channel['id'], $limit, 0, ['include_children' => true, 'is_recommend' => true])
                    : getContents((int) $channel['id'], $limit, 0, ['include_children' => true]);
                if ($sort === 'recommend' && empty($contents)) {
                    $contents = getContents((int) $channel['id'], $limit, 0, ['include_children' => true]);
                }
                $channel['contents'] = $contents;
                $channel['is_product'] = false;
            }

            $homeChannelsMap[(int) ($channel['id'] ?? 0)] = $channel;
        }

        return new self(
            self::indexBlocks($blocksConfig),
            self::defaultBlockTemplates(),
            $homeChannelsMap,
            getBanners('home', 5),
            getChannelBySlug('about', true),
            json_decode(configJsonLang('home_testimonials') ?: '[]', true) ?: [],
            $editMode
        );
    }

    /**
     * HomeBloxRenderer 的统一回调入口。
     *
     * @param array<string, mixed> $element
     */
    public function renderLegacyBlock(array $element): string
    {
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        if (empty($data['enabled'])) {
            return '';
        }

        $type = trim((string) ($data['block_type'] ?? ''));
        if ($type === '') {
            return '';
        }

        $legacyBlock = $this->legacyBlockByType[$type] ?? ['type' => $type];
        $merged = array_merge($legacyBlock, $data);
        if ((int) ($data['limit'] ?? 0) === 0 && isset($legacyBlock['limit'])) {
            $merged['limit'] = $legacyBlock['limit'];
        }
        if ((int) ($data['per_row'] ?? 0) === 0 && isset($legacyBlock['per_row'])) {
            $merged['per_row'] = $legacyBlock['per_row'];
        }
        if (($data['sort'] ?? 'inherit') === 'inherit' && isset($legacyBlock['sort'])) {
            $merged['sort'] = $legacyBlock['sort'];
        }

        $block = HomeBloxBlockSchema::normalize($merged);
        $block['type'] = $type;
        $block['enabled'] = true;

        $blockTemplates = $this->blockTemplates;
        $homeChannelsMap = $this->homeChannelsMap;
        $banners = $this->banners;
        $bannerItemsMode = (string) ($data['items_mode'] ?? 'inherit');
        if ($type === 'banner' && $bannerItemsMode === 'custom') {
            $banners = HomeBannerItemElement::normalizeChildren(
                is_array($data['children'] ?? null) ? $data['children'] : [],
                $this->editMode ? trim((string) ($data['_blox_path'] ?? '')) : ''
            );
        }
        if ($type === 'banner' && (int) $block['limit'] > 0) {
            $banners = array_slice($banners, 0, (int) $block['limit']);
        }
        $aboutChannel = $this->aboutChannel;
        $testimonials = $this->testimonials;
        if ($type === 'testimonials' && array_key_exists('testimonial_items', $data)) {
            $testimonials = is_array($block['testimonial_items'] ?? null) ? $block['testimonial_items'] : [];
        }
        $ykHomeEdit = $this->editMode;
        $currentChannel = null;
        $emptyStateConfigured = array_key_exists('empty_state', $data);
        $sourceIsEmpty = $type === 'banner' && $banners === [];

        if (str_starts_with($type, 'channel:')) {
            $channelId = (int) substr($type, 8);
            $currentChannel = $this->channelForBlock($channelId, $block, $data);
            if ($currentChannel !== null) {
                $currentChannel['name'] = HomeBloxBlockSchema::overrideText(
                    $block,
                    'title',
                    (string) ($currentChannel['name'] ?? '')
                );
                $currentChannel['description'] = HomeBloxBlockSchema::overrideText(
                    $block,
                    'description',
                    (string) ($currentChannel['description'] ?? '')
                );
                $currentChannel['home_button_text'] = HomeBloxBlockSchema::overrideText(
                    $block,
                    'button_text',
                    __('home_view_all')
                );
                $currentChannel['home_button_url'] = trim((string) ($block['override_button_url'] ?? ''));
            }
            $sourceIsEmpty = $currentChannel === null || empty($currentChannel['contents']);
        }

        if ($type === 'product_categories' && trim((string) ($block['override_title'] ?? '')) !== '') {
            $block['title'] = (string) $block['override_title'];
        }

        if ($emptyStateConfigured && $sourceIsEmpty) {
            return $this->withEditMarker($this->emptyStateHtml($block), $type);
        }

        $renderVars = [
            'block' => $block,
            'blockTemplates' => $blockTemplates,
            'homeChannelsMap' => $homeChannelsMap,
            'banners' => $banners,
            'aboutChannel' => $aboutChannel,
            'testimonials' => $testimonials,
            'ykHomeEdit' => $ykHomeEdit,
            'currentChannel' => $currentChannel,
        ];

        $runtimeKey = 'yikai_config_runtime_overrides';
        $hadRuntimeOverrides = array_key_exists($runtimeKey, $GLOBALS);
        $previousRuntimeOverrides = $GLOBALS[$runtimeKey] ?? null;
        $GLOBALS[$runtimeKey] = array_merge(
            is_array($previousRuntimeOverrides) ? $previousRuntimeOverrides : [],
            HomeBloxBlockSchema::runtimeConfigOverrides($block)
        );

        extract($renderVars, EXTR_OVERWRITE);
        $bufferLevel = ob_get_level();
        ob_start();
        try {
            if (str_starts_with($type, 'channel:')) {
                if ($currentChannel) {
                    require $blockTemplates['channel'] ?? theme_path('blocks/channel.php');
                }
            } elseif (str_starts_with($type, 'custom:')) {
                $customData = json_decode((string) config('home_custom_' . substr($type, 7), ''), true);
                if (!empty($customData['blocks']) && function_exists('renderBlocksToHtml')) {
                    echo renderBlocksToHtml(json_encode($customData['blocks'], JSON_UNESCAPED_UNICODE));
                }
            } elseif (isset($blockTemplates[$type]) && file_exists($blockTemplates[$type])) {
                require $blockTemplates[$type];
            } else {
                echo (string) apply_filters('home_block_render', '', $type, $block);
            }
            $html = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            if ($hadRuntimeOverrides) {
                $GLOBALS[$runtimeKey] = $previousRuntimeOverrides;
            } else {
                unset($GLOBALS[$runtimeKey]);
            }
        }
        if ($emptyStateConfigured && trim($html) === '') {
            $html = $this->emptyStateHtml($block);
        }
        return $this->withEditMarker($html, $type);
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $elementData
     * @return array<string, mixed>|null
     */
    private function channelForBlock(int $channelId, array $block, array $elementData): ?array
    {
        if ($channelId < 1) {
            return null;
        }

        $channel = $this->homeChannelsMap[$channelId] ?? channelModel()->find($channelId);
        if (!$channel || empty($channel['status'])) {
            return null;
        }

        if (!HomeBloxBlockSchema::hasQueryOverride($elementData)
            && isset($this->homeChannelsMap[$channelId])) {
            return $channel;
        }

        $limit = (int) ($block['limit'] ?? 0);
        if ($limit < 1) {
            $limit = 8;
        }
        $sort = (string) ($block['sort'] ?? 'inherit');
        if (!in_array($sort, ['recommend', 'latest'], true)) {
            $sort = 'recommend';
        }
        $perRow = (int) ($block['per_row'] ?? 0);
        if ($perRow < 1) {
            $perRow = (int) ($channel['per_row'] ?? 4);
        }
        $channel['per_row'] = max(1, min(HomeBloxBlockSchema::MAX_COLUMNS, $perRow));

        if (($channel['type'] ?? '') === 'product') {
            $contents = $sort === 'recommend'
                ? getProducts(0, $limit, 0, ['is_recommend' => true])
                : getProducts(0, $limit, 0);
            if ($sort === 'recommend' && $contents === []) {
                $contents = getProducts(0, $limit, 0);
            }
            $channel['contents'] = $contents;
            $channel['is_product'] = true;
            $channel['categories'] = productCategoryModel()->getTopLevel(6);
        } else {
            $contents = $sort === 'recommend'
                ? getContents($channelId, $limit, 0, ['include_children' => true, 'is_recommend' => true])
                : getContents($channelId, $limit, 0, ['include_children' => true]);
            if ($sort === 'recommend' && $contents === []) {
                $contents = getContents($channelId, $limit, 0, ['include_children' => true]);
            }
            $channel['contents'] = $contents;
            $channel['is_product'] = false;
        }

        return $channel;
    }

    private function withEditMarker(string $html, string $type): string
    {
        if (!$this->editMode || $html === '') {
            return $html;
        }
        if (str_starts_with($type, 'custom:')) {
            return (string) preg_replace('/<section\b/', '<section data-yk-home="' . e($type) . '"', $html);
        }
        return (string) preg_replace('/<(\w+)/', '<$1 data-yk-home="' . e($type) . '"', $html, 1);
    }

    /** @param array<string, mixed> $block */
    private function emptyStateHtml(array $block): string
    {
        $showMessage = ($block['empty_state'] ?? 'hide') === 'message';
        if (!$showMessage && !$this->editMode) {
            return '';
        }

        $text = $showMessage
            ? trim((string) ($block['empty_text'] ?? ''))
            : __('blox_home_empty_hidden_preview');
        if ($text === '') {
            $text = __('blox_home_empty_default');
        }

        return '<section class="py-10"><div class="container mx-auto px-4">'
            . '<div class="border border-dashed border-gray-300 bg-gray-50 text-gray-500 text-sm text-center py-8 px-4 rounded-lg">'
            . e($text) . '</div></div></section>';
    }
    /** @return array<int, array<string, mixed>> */
    private static function configuredBlocks(): array
    {
        $blocks = json_decode((string) config('home_blocks_config', ''), true);
        if (is_array($blocks) && $blocks !== []) {
            return array_values(array_filter($blocks, static fn (mixed $block): bool => is_array($block)));
        }

        return [
            ['type' => 'banner', 'enabled' => true],
            ['type' => 'about', 'enabled' => true],
            ['type' => 'stats', 'enabled' => true],
            ['type' => 'channels', 'enabled' => true],
            ['type' => 'testimonials', 'enabled' => true],
            ['type' => 'advantage', 'enabled' => true],
            ['type' => 'cta', 'enabled' => true],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<string, array<string, mixed>>
     */
    private static function indexBlocks(array $blocks): array
    {
        $indexed = [];
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            if ($type !== '') {
                $indexed[$type] = $block;
            }
        }
        return $indexed;
    }

    /** @return array<string, string> */
    private static function defaultBlockTemplates(): array
    {
        return [
            'banner' => theme_path('blocks/banner.php'),
            'about' => theme_path('blocks/about.php'),
            'stats' => theme_path('blocks/stats.php'),
            'testimonials' => theme_path('blocks/testimonials.php'),
            'advantage' => theme_path('blocks/advantage.php'),
            'cta' => theme_path('blocks/cta.php'),
            'partners' => theme_path('blocks/partners.php'),
            'product_categories' => theme_path('blocks/product_categories.php'),
            'channel' => theme_path('blocks/channel.php'),
        ];
    }
}