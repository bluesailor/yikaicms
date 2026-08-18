<?php
/** Product catalog element for Blox-managed product landing pages. */

declare(strict_types=1);

final class ProductCatalogElement extends AbstractElement
{
    /** @var array<string,mixed>|null */
    private static ?array $runtimeContext = null;

    public function type(): string { return 'product-catalog'; }
    public function label(): string { return __('blox_product_catalog'); }
    public function icon(): string { return 'shopping-bag'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'product'; }
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
                'key' => 'layout', 'type' => 'select', 'label' => __('blox_product_catalog_layout'),
                'default' => 'inherit', 'options' => [
                    'inherit' => __('blox_product_catalog_layout_inherit'),
                    'sidebar' => __('blox_product_catalog_layout_sidebar'),
                    'grid' => __('blox_product_catalog_layout_grid'),
                ],
            ],
            [
                'key' => 'columns', 'type' => 'select', 'label' => __('blox_dynamic_columns'),
                'default' => '4', 'options' => ['2' => '2', '3' => '3', '4' => '4'], 'tab' => 'style',
            ],
            ['key' => 'show_search', 'type' => 'checkbox', 'label' => __('blox_product_catalog_show_search'), 'default' => true],
            ['key' => 'show_categories', 'type' => 'checkbox', 'label' => __('blox_product_catalog_show_categories'), 'default' => true],
            ['key' => 'show_sort', 'type' => 'checkbox', 'label' => __('blox_product_catalog_show_sort'), 'default' => true],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        if (self::$runtimeContext === null) {
            return (new ListDynamicElement())->render([
                'query_source' => 'type:product',
                'limit' => ListDynamicElement::EDITOR_PREVIEW_LIMIT,
                'columns' => (string) self::columns($data),
                'show_image' => true,
                'show_title' => true,
                'show_summary' => true,
                'show_meta' => true,
                'meta_field' => 'model',
                'item_preset' => 'card',
                'image_ratio' => 'landscape',
                'empty_mode' => 'message',
                'empty' => __('no_content'),
            ]);
        }

        extract(self::$runtimeContext, EXTR_SKIP);
        $requestedLayout = (string) ($data['layout'] ?? 'inherit');
        if (!in_array($requestedLayout, ['inherit', 'sidebar', 'grid'], true)) {
            $requestedLayout = 'inherit';
        }
        if ($requestedLayout === 'inherit') {
            $requestedLayout = (string) config('product_layout', 'sidebar') === 'top' ? 'grid' : 'sidebar';
        }

        $productCatalogLayout = $requestedLayout;
        $productCatalogColumns = self::columns($data);
        $productCatalogShowSearch = !array_key_exists('show_search', $data) || !empty($data['show_search']);
        $productCatalogShowCategories = $requestedLayout === 'sidebar'
            && (!array_key_exists('show_categories', $data) || !empty($data['show_categories']));
        $productCatalogShowSort = !array_key_exists('show_sort', $data) || !empty($data['show_sort']);

        ob_start();
        ?>
        <div data-product-catalog>
            <?php require ROOT_PATH . '/views/list/sidebar.php'; ?>
        </div>
        <script>
        (function () {
            document.querySelectorAll('[data-product-catalog] .category-toggle').forEach(function (btn) {
                if (btn.dataset.catalogReady === '1') return;
                btn.dataset.catalogReady = '1';
                btn.addEventListener('click', function (event) {
                    event.preventDefault();
                    var item = this.closest('.category-item');
                    var childList = item ? item.querySelector('.category-children') : null;
                    var icon = this.querySelector('svg');
                    if (!childList) return;
                    var expanded = this.dataset.expanded === 'true';
                    childList.classList.toggle('hidden', expanded);
                    if (icon) icon.classList.toggle('rotate-180', !expanded);
                    this.dataset.expanded = expanded ? 'false' : 'true';
                });
            });
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    private static function columns(array $data): int
    {
        $columns = (int) ($data['columns'] ?? 4);
        return in_array($columns, [2, 3, 4], true) ? $columns : 4;
    }
}
