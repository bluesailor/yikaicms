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

    /**
     * 命名区域：结构树把它渲染成根节点下可选中、可展开的子节点。
     *
     * 区域是 schema 级声明（不写入文档 data），因此旧文档不产生 dirty、不触发迁移，
     * 也不需要把区域伪造成 children（BloxDocumentValidator 仍按非容器校验本元素）。
     * `keys` 决定选中该区域时设置面板只显示哪些控件。
     *
     * @return list<array{key:string,label:string,icon:string,keys:list<string>}>
     */
    public function regions(): array
    {
        return [
            [
                'key' => 'toolbar',
                'label' => __('blox_catalog_region_toolbar'),
                'icon' => 'adjustments-horizontal',
                'keys' => ['show_search', 'show_sort', 'show_count'],
            ],
            [
                'key' => 'categories',
                'label' => __('blox_catalog_region_categories'),
                'icon' => 'category',
                'keys' => ['show_categories', 'nav_position'],
            ],
            [
                'key' => 'list',
                'label' => __('blox_catalog_region_list'),
                'icon' => 'layout-grid',
                'keys' => [
                    'layout_mode', 'layout', 'columns', 'card_style',
                    'show_image', 'show_title', 'show_summary', 'show_button',
                    'image_ratio', 'card_gap', 'content_align', 'sidebar_width',
                    'empty_text', 'button_text',
                ],
            ],
            [
                'key' => 'pagination',
                'label' => __('blox_catalog_region_pagination'),
                'icon' => 'arrows-left-right',
                'keys' => ['show_pagination'],
            ],
        ];
    }

    public function controls(): array
    {
        return [
            // ── 排版骨架（根） ─────────────────────────────────────────────
            [
                'key' => 'layout_mode', 'type' => 'select', 'label' => __('blox_product_catalog_layout_mode'),
                'default' => '', 'options' => [
                    '' => __('blox_product_catalog_mode_inherit'),
                    'sidebar_grid' => __('blox_product_catalog_mode_sidebar_grid'),
                    'top_grid' => __('blox_product_catalog_mode_top_grid'),
                    'toolbar_grid' => __('blox_product_catalog_mode_toolbar_grid'),
                    'media_list' => __('blox_product_catalog_mode_media_list'),
                    'featured' => __('blox_product_catalog_mode_featured'),
                ],
            ],
            // 旧字段：仅在“跟随旧设置”时可见，保证老文档的 sidebar/grid 语义仍可编辑
            [
                'key' => 'layout', 'type' => 'select', 'label' => __('blox_product_catalog_layout'),
                'default' => 'inherit', 'options' => [
                    'inherit' => __('blox_product_catalog_layout_inherit'),
                    'sidebar' => __('blox_product_catalog_layout_sidebar'),
                    'grid' => __('blox_product_catalog_layout_grid'),
                ],
                'visible_when' => ['terms' => [['layout_mode', 'empty']]],
            ],
            // ── 分类导航 ─────────────────────────────────────────────────
            [
                'key' => 'nav_position', 'type' => 'select', 'label' => __('blox_product_catalog_nav_position'),
                'default' => '', 'options' => [
                    '' => __('blox_product_catalog_nav_follow'),
                    'sidebar' => __('blox_product_catalog_nav_sidebar'),
                    'top' => __('blox_product_catalog_nav_top'),
                    'toolbar' => __('blox_product_catalog_nav_toolbar'),
                    'hidden' => __('blox_product_catalog_nav_hidden'),
                ],
            ],
            ['key' => 'show_categories', 'type' => 'checkbox', 'label' => __('blox_product_catalog_show_categories'), 'default' => true],
            // ── 工具栏 ───────────────────────────────────────────────────
            ['key' => 'show_search', 'type' => 'checkbox', 'label' => __('blox_product_catalog_show_search'), 'default' => true],
            ['key' => 'show_sort', 'type' => 'checkbox', 'label' => __('blox_product_catalog_show_sort'), 'default' => true],
            ['key' => 'show_count', 'type' => 'checkbox', 'label' => __('blox_product_catalog_show_count'), 'default' => true],
            // ── 产品列表 ─────────────────────────────────────────────────
            [
                'key' => 'card_style', 'type' => 'select', 'label' => __('blox_product_catalog_card_style'),
                'default' => 'card', 'options' => [
                    'card' => __('blox_product_catalog_card_card'),
                    'media' => __('blox_product_catalog_card_media'),
                    'featured' => __('blox_product_catalog_card_featured'),
                ],
                'visible_when' => ['terms' => [['layout_mode', 'not_in', ['media_list', 'featured']]]],
            ],
            ['key' => 'show_image', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_image'), 'default' => true],
            ['key' => 'show_title', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_title'), 'default' => true],
            ['key' => 'show_summary', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_summary'), 'default' => true],
            ['key' => 'show_button', 'type' => 'checkbox', 'label' => __('blox_product_catalog_show_button'), 'default' => true],
            ['key' => 'empty_text', 'type' => 'text', 'label' => __('blox_product_catalog_empty_text'), 'default' => ''],
            ['key' => 'button_text', 'type' => 'text', 'label' => __('blox_product_catalog_button_text'), 'default' => ''],
            // ── 分页 ─────────────────────────────────────────────────────
            ['key' => 'show_pagination', 'type' => 'checkbox', 'label' => __('blox_product_catalog_show_pagination'), 'default' => true],
            // ── 样式页签 ─────────────────────────────────────────────────
            [
                'key' => 'columns', 'type' => 'select', 'label' => __('blox_dynamic_columns'),
                'default' => '4', 'options' => ['2' => '2', '3' => '3', '4' => '4'], 'tab' => 'style',
            ],
            [
                'key' => 'image_ratio', 'type' => 'select', 'label' => __('blox_product_catalog_image_ratio'),
                'default' => 'landscape', 'tab' => 'style', 'options' => [
                    'landscape' => __('blox_product_catalog_ratio_landscape'),
                    'square' => __('blox_product_catalog_ratio_square'),
                    'portrait' => __('blox_product_catalog_ratio_portrait'),
                ],
            ],
            [
                'key' => 'card_gap', 'type' => 'select', 'label' => __('blox_product_catalog_card_gap'),
                'default' => 'md', 'tab' => 'style', 'options' => [
                    'sm' => __('blox_product_catalog_gap_sm'),
                    'md' => __('blox_product_catalog_gap_md'),
                    'lg' => __('blox_product_catalog_gap_lg'),
                ],
            ],
            [
                'key' => 'content_align', 'type' => 'select', 'label' => __('blox_product_catalog_content_align'),
                'default' => 'left', 'tab' => 'style', 'options' => [
                    'left' => __('blox_product_catalog_align_left'),
                    'center' => __('blox_product_catalog_align_center'),
                ],
            ],
            [
                'key' => 'sidebar_width', 'type' => 'select', 'label' => __('blox_product_catalog_sidebar_width'),
                'default' => 'md', 'tab' => 'style', 'options' => [
                    'sm' => __('blox_product_catalog_width_sm'),
                    'md' => __('blox_product_catalog_width_md'),
                    'lg' => __('blox_product_catalog_width_lg'),
                ],
                'visible_when' => ['terms' => [['nav_position', 'in', ['sidebar']]]],
            ],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        if (self::$runtimeContext === null) {
            return $this->renderPalettePreview($data);
        }

        $mode = ProductCatalogLayout::resolveMode($data);
        if ($mode === null) {
            return $this->renderLegacy($data);
        }

        $context = self::$runtimeContext;
        $catalog = ProductCatalogLayout::settings($data, $mode);
        $scripts = $this->categoryToggleScript();
        ob_start();
        require ROOT_PATH . '/views/list/product-catalog/render.php';
        return (string) ob_get_clean();
    }

    /** 旧文档（无新模式字段）路径：与历史 sidebar.php 输出逐项一致。 */
    private function renderLegacy(array $data): string
    {
        extract(self::$runtimeContext ?? [], EXTR_SKIP);
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
        <div <?php echo ProductCatalogRequest::rootAttributes($catalogQuery ?? ProductCatalogRequest::normalize($_GET)); ?>>
            <?php require ROOT_PATH . '/views/list/sidebar.php'; ?>
        </div>
        <?php echo $this->categoryToggleScript(); ?>
        <?php
        return (string) ob_get_clean();
    }

    /** 元素库拖拽预览：无运行上下文时保持动态产品查询（不落假数据）。 */
    private function renderPalettePreview(array $data): string
    {
        $preset = match (ProductCatalogLayout::settings($data, 'sidebar_grid')['card']) {
            'media' => 'media',
            'featured' => 'featured',
            default => 'card',
        };
        return (new ListDynamicElement())->render([
            'query_source' => 'type:product',
            'limit' => ListDynamicElement::EDITOR_PREVIEW_LIMIT,
            'columns' => (string) self::columns($data),
            'show_image' => true,
            'show_title' => true,
            'show_summary' => true,
            'show_meta' => true,
            'meta_field' => 'model',
            'item_preset' => $preset,
            'image_ratio' => 'landscape',
            'empty_mode' => 'message',
            'empty' => __('no_content'),
        ]);
    }

    /** 分类树展开/收起的本地脚本；只渲染一次（前端与预览共用）。 */
    private function categoryToggleScript(): string
    {
        return <<<'HTML'
        <script>
        (function () {
            document.querySelectorAll('[data-product-catalog] .category-toggle').forEach(function (btn) {
                if (btn.dataset.catalogReady === '1') return;
                btn.dataset.catalogReady = '1';
                btn.addEventListener('click', function (event) {
                    event.yikaiCatalogToggleHandled = true;
                    event.preventDefault();
                    var item = this.closest('.category-item');
                    var childList = item ? item.querySelector('.category-children') : null;
                    var icon = this.querySelector('svg');
                    if (!childList) return;
                    var expanded = this.dataset.expanded === 'true';
                    childList.classList.toggle('hidden', expanded);
                    if (icon) icon.classList.toggle('rotate-180', !expanded);
                    this.dataset.expanded = expanded ? 'false' : 'true';
                    this.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                });
            });
        })();
        </script>
        HTML;
    }

    private static function columns(array $data): int
    {
        $columns = (int) ($data['columns'] ?? 4);
        return in_array($columns, [2, 3, 4], true) ? $columns : 4;
    }
}
