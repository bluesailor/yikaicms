<?php
/** Dynamic article catalog for Blox-managed news and content channel pages. */

declare(strict_types=1);

final class ContentCatalogElement extends AbstractElement
{
    /** @var array<string,mixed>|null */
    private static ?array $runtimeContext = null;

    public function type(): string { return 'content-catalog'; }
    public function label(): string { return __('blox_content_catalog'); }
    public function icon(): string { return 'news'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'content-list'; }
    public function supportsBoxStyles(): bool { return false; }

    /** @param array<string,mixed>|null $context */
    public static function setRuntimeContext(?array $context): void
    {
        self::$runtimeContext = $context;
    }

    public function controls(): array
    {
        return [
            [
                'key' => 'layout', 'type' => 'select', 'label' => __('blox_content_catalog_layout'),
                'default' => 'list', 'options' => [
                    'list' => __('blox_content_catalog_layout_list'),
                    'grid' => __('blox_content_catalog_layout_grid'),
                ],
            ],
            [
                'key' => 'columns', 'type' => 'select', 'label' => __('blox_dynamic_columns'),
                'default' => '3', 'options' => ['2' => '2', '3' => '3', '4' => '4'],
                'tab' => 'style', 'required' => ['layout', '=', 'grid'],
            ],
            ['key' => 'show_search', 'type' => 'checkbox', 'label' => __('blox_content_catalog_show_search'), 'default' => true],
            ['key' => 'show_categories', 'type' => 'checkbox', 'label' => __('blox_content_catalog_show_categories'), 'default' => true],
            ['key' => 'show_cover', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_image'), 'default' => true],
            ['key' => 'show_summary', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_summary'), 'default' => true],
            ['key' => 'show_channel', 'type' => 'checkbox', 'label' => __('blox_content_catalog_show_channel'), 'default' => true],
            ['key' => 'show_author', 'type' => 'checkbox', 'label' => __('blox_content_catalog_show_author'), 'default' => false],
            ['key' => 'show_date', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_date'), 'default' => true],
            ['key' => 'show_views', 'type' => 'checkbox', 'label' => __('blox_content_catalog_show_views'), 'default' => true],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $layout = (string) ($data['layout'] ?? 'list') === 'grid' ? 'grid' : 'list';
        if (self::$runtimeContext === null) {
            $channelId = class_exists('BlockRenderer') ? BlockRenderer::$editChannelId : 0;
            return (new ListDynamicElement())->render([
                'query_source' => $channelId > 0 ? 'channel:' . $channelId : 'type:article',
                'limit' => 10,
                'columns' => $layout === 'grid' ? (string) self::columns($data) : '1',
                'show_image' => self::enabled($data, 'show_cover', true),
                'show_title' => true,
                'show_summary' => self::enabled($data, 'show_summary', true),
                'show_date' => self::enabled($data, 'show_date', true),
                'item_preset' => $layout === 'grid' ? 'card' : 'media',
                'image_ratio' => 'wide',
                'empty_mode' => 'message',
                'empty' => __('no_content'),
            ]);
        }

        extract(self::$runtimeContext, EXTR_SKIP);
        $contentCatalogLayout = $layout;
        $contentCatalogColumns = self::columns($data);
        $contentCatalogShowSearch = self::enabled($data, 'show_search', true);
        $contentCatalogShowCategories = self::enabled($data, 'show_categories', true);
        $listOpts = [];
        foreach ([
            'cover' => 'show_cover', 'summary' => 'show_summary', 'channel' => 'show_channel',
            'author' => 'show_author', 'date' => 'show_date', 'views' => 'show_views',
        ] as $option => $field) {
            if (self::enabled($data, $field, $field !== 'show_author')) {
                $listOpts[] = $option;
            }
        }

        ob_start();
        require ROOT_PATH . '/views/list/content-catalog.php';
        return (string) ob_get_clean();
    }

    private static function columns(array $data): int
    {
        $columns = (int) ($data['columns'] ?? 3);
        return in_array($columns, [2, 3, 4], true) ? $columns : 3;
    }

    private static function enabled(array $data, string $key, bool $default): bool
    {
        return array_key_exists($key, $data) ? !empty($data[$key]) : $default;
    }
}
