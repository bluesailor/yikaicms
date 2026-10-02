<?php
/**
 * 栏目 / 分类导航（v2.0.4）：企业站最常见的侧栏——自动认出当前所在栏目，列出它所属栏目的子栏目树；
 * 产品中心列产品分类。子级手风琴、当前项高亮、数量 / 图标 / 分类图，竖排或横排，小屏可折成下拉框。
 *
 * 当前位置按这个顺序取：单页 / 画布的页面上下文（PageTitleElement::currentPage）→ 入口文件写的
 * $GLOBALS['currentChannelId'] / $GLOBALS['currentProductCategoryId'] → 编辑器画布的栏目 id。
 * 取数（buildModel）与输出（renderModel）分开：输出是纯函数，单测不需要数据库。
 */

declare(strict_types=1);

final class CategoryNavElement extends AbstractElement
{
    /** 栏目内容存在 contents 表里的类型：只有这些能数条数 */
    private const COUNTABLE_TYPES = ['list', 'case', 'album'];

    private static int $sequence = 0;

    public function type(): string { return 'category-nav'; }
    public function label(): string { return __('blox_el_category_nav'); }
    public function icon(): string { return 'list-tree'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }

    /** @param array<string,mixed> $data @return list<string> */
    public function scriptsFor(array $data): array
    {
        $accordion = ($data['layout'] ?? 'stacked') !== 'inline' && ($data['expand'] ?? 'accordion') === 'accordion';
        return $accordion || BloxValueSanitizer::truthy($data['mobile_select'] ?? true)
            ? ['/assets/js/blox-category-nav.js']
            : [];
    }

    public function controls(): array
    {
        return [
            ['key' => 'source', 'type' => 'select', 'label' => __('blox_catnav_source'), 'default' => 'auto',
                'options' => self::sourceOptions(), 'help' => __('blox_catnav_source_help')],
            ['key' => 'scope', 'type' => 'select', 'label' => __('blox_catnav_scope'), 'default' => 'section',
                'options' => ['section' => __('blox_catnav_scope_section'), 'current' => __('blox_catnav_scope_current')],
                'required' => ['source', '=', 'auto']],
            ['key' => 'show_title', 'type' => 'checkbox', 'label' => __('blox_catnav_show_title'), 'default' => true],
            ['key' => 'title', 'type' => 'text', 'label' => __('blox_catnav_title'), 'default' => '',
                'placeholder' => __('blox_catnav_title_placeholder'), 'required' => ['show_title', '=', true]],
            ['key' => 'depth', 'type' => 'select', 'label' => __('blox_catnav_depth'), 'default' => '2',
                'options' => ['1' => '1', '2' => '2', '3' => '3']],
            ['key' => 'expand', 'type' => 'select', 'label' => __('blox_catnav_expand'), 'default' => 'accordion',
                'options' => ['accordion' => __('blox_catnav_expand_accordion'), 'all' => __('blox_catnav_expand_all')]],
            ['key' => 'show_count', 'type' => 'checkbox', 'label' => __('blox_catnav_show_count'), 'default' => false],
            ['key' => 'show_icon', 'type' => 'checkbox', 'label' => __('blox_catnav_show_icon'), 'default' => true],
            ['key' => 'show_image', 'type' => 'checkbox', 'label' => __('blox_catnav_show_image'), 'default' => false],
            ['key' => 'layout', 'type' => 'select', 'label' => __('blox_catnav_layout'), 'default' => 'stacked', 'tab' => 'style',
                'options' => ['stacked' => __('blox_catnav_layout_stacked'), 'inline' => __('blox_catnav_layout_inline')]],
            ['key' => 'style', 'type' => 'select', 'label' => __('blox_catnav_style'), 'default' => 'card', 'tab' => 'style',
                'options' => ['card' => __('blox_catnav_style_card'), 'compact' => __('blox_catnav_style_compact')]],
            ['key' => 'mobile_select', 'type' => 'checkbox', 'label' => __('blox_catnav_mobile_select'), 'default' => true, 'tab' => 'style'],
            ...$this->animationControls(),
        ];
    }

    /** 指定栏目的下拉选项：auto + 可有子级的栏目（单页、外链、表单除外） @return array<string,string> */
    public static function sourceOptions(): array
    {
        $options = ['auto' => __('blox_catnav_source_auto')];
        try {
            foreach (channelModel()->getFlatList(0, 0, siteLang()) as $channel) {
                $id = (int) ($channel['id'] ?? 0);
                if ($id < 1 || empty($channel['status']) || in_array((string) ($channel['type'] ?? ''), ['page', 'link', 'form'], true)) {
                    continue;
                }
                $name = trim((string) ($channel['_prefix'] ?? '') . (string) ($channel['name'] ?? ''));
                $options['channel:' . $id] = $name !== '' ? $name : '#' . $id;
            }
        } catch (Throwable) {
            // 安装早期或无数据库的测试环境只有「自动」。
        }
        return $options;
    }

    public function render(array $data, string $children = ''): string
    {
        $model = [];
        try {
            $model = self::buildModel($data);
        } catch (Throwable) {
            $model = [];
        }
        if (($model['items'] ?? []) === []) {
            // 前台没有可列的栏目时整块不出；编辑器里给一句说明，免得元素「消失」
            return BlockRenderer::$showHidden
                ? '<div class="rounded-lg border border-dashed border-gray-300 p-4 text-sm text-gray-500"' . $this->animationAttrs($data) . '>'
                    . self::escape(__('blox_catnav_empty_hint')) . '</div>'
                : '';
        }
        return self::renderModel($model, $data, 'yk-catnav-' . (++self::$sequence), $this->animationAttrs($data));
    }

    // ---------------------------------------------------------------- 取数

    /** @return array{0:int,1:int} 当前栏目 id、当前产品分类 id */
    public static function currentContext(): array
    {
        $page = PageTitleElement::currentPage();
        $channelId = (int) ($page['id'] ?? 0);
        if ($channelId < 1) {
            $channelId = (int) ($GLOBALS['currentChannelId'] ?? 0);
        }
        if ($channelId < 1 && BlockRenderer::$editChannelId > 1) {
            $channelId = BlockRenderer::$editChannelId;
        }
        return [$channelId, (int) ($GLOBALS['currentProductCategoryId'] ?? 0)];
    }

    /**
     * @param array<string,mixed> $data
     * @return array{root:array{name:string,url:string,active:bool}, items:list<array<string,mixed>>}|array{}
     */
    public static function buildModel(array $data): array
    {
        [$channelId, $productCategoryId] = self::currentContext();
        $source = (string) ($data['source'] ?? 'auto');
        $root = null;
        if (preg_match('/^channel:(\d+)$/D', $source, $match) === 1) {
            $root = self::activeChannel((int) $match[1]);
        } elseif ($channelId > 0) {
            $current = self::activeChannel($channelId);
            if ($current !== null) {
                $root = ($data['scope'] ?? 'section') === 'current' ? self::currentScopeRoot($current) : self::topAncestor($current);
            }
        } elseif ($productCategoryId > 0 && function_exists('getChannelBySlug')) {
            // 产品详情页没有栏目上下文，只有所属分类：挂到产品中心下
            $root = getChannelBySlug('product', true) ?: null;
        }
        if ($root === null) {
            return [];
        }
        $depth = max(1, min(3, (int) ($data['depth'] ?? 2)));
        $withCount = BloxValueSanitizer::truthy($data['show_count'] ?? false);
        $items = (string) ($root['type'] ?? '') === 'product'
            ? self::productNodes(productCategoryModel()->getTree(), $productCategoryId, $depth, $withCount)
            : self::channelItems((int) $root['id'], $channelId, $depth, $withCount);
        return [
            'root' => [
                'name' => (string) ($root['name'] ?? ''),
                'url' => self::channelHref($root),
                'active' => (int) $root['id'] === $channelId && $productCategoryId < 1,
            ],
            'items' => $items,
        ];
    }

    /** @return array<string,mixed>|null */
    private static function activeChannel(int $id): ?array
    {
        $channel = channelModel()->find($id);
        return is_array($channel) && (int) ($channel['status'] ?? 0) === 1 ? $channel : null;
    }

    /** @param array<string,mixed> $channel @return array<string,mixed> */
    private static function topAncestor(array $channel): array
    {
        $seen = [(int) $channel['id'] => true];
        while ((int) ($channel['parent_id'] ?? 0) > 0 && count($seen) < 20) {
            $parent = self::activeChannel((int) $channel['parent_id']);
            if ($parent === null || isset($seen[(int) $parent['id']])) {
                break;
            }
            $seen[(int) $parent['id']] = true;
            $channel = $parent;
        }
        return $channel;
    }

    /** 「当前栏目的下级」：没有下级时退到同级（显示兄弟栏目），产品中心照常列分类 @param array<string,mixed> $channel @return array<string,mixed> */
    private static function currentScopeRoot(array $channel): array
    {
        if ((string) ($channel['type'] ?? '') === 'product' || channelModel()->getByParent((int) $channel['id'], true, false, siteLang()) !== []) {
            return $channel;
        }
        $parent = (int) ($channel['parent_id'] ?? 0) > 0 ? self::activeChannel((int) $channel['parent_id']) : null;
        return $parent ?? $channel;
    }

    /** @return list<array<string,mixed>> */
    private static function channelItems(int $parentId, int $currentId, int $depth, bool $withCount): array
    {
        $items = [];
        foreach (channelModel()->getByParent($parentId, true, false, siteLang()) as $channel) {
            $id = (int) $channel['id'];
            $children = $depth > 1 ? self::channelItems($id, $currentId, $depth - 1, $withCount) : [];
            $count = null;
            if ($withCount && in_array((string) ($channel['type'] ?? ''), self::COUNTABLE_TYPES, true)) {
                $count = contentModel()->getCount($id, ['include_children' => true]);
            }
            $items[] = self::node('c' . $id, $channel, self::channelHref($channel), $id === $currentId, $children, $count);
        }
        return $items;
    }

    /** @param list<array<string,mixed>> $categories @return list<array<string,mixed>> */
    private static function productNodes(array $categories, int $currentId, int $depth, bool $withCount): array
    {
        $items = [];
        foreach ($categories as $category) {
            if ((int) ($category['is_nav'] ?? 1) !== 1) {
                continue;
            }
            $id = (int) $category['id'];
            $children = $depth > 1 ? self::productNodes($category['children'] ?? [], $currentId, $depth - 1, $withCount) : [];
            $count = $withCount ? productModel()->getCount($id) : null;
            $items[] = self::node('p' . $id, $category, productCategoryUrl($category), $id === $currentId, $children, $count);
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $children
     * @return array<string,mixed>
     */
    private static function node(string $key, array $row, string $url, bool $active, array $children, ?int $count): array
    {
        $open = $active;
        foreach ($children as $child) {
            if ($child['active'] || $child['open']) {
                $open = true;
                break;
            }
        }
        return [
            'key' => $key,
            'name' => (string) ($row['name'] ?? ''),
            'url' => $url,
            'icon' => (string) ($row['icon'] ?? ''),
            'image' => (string) ($row['image'] ?? ''),
            'count' => $count,
            'active' => $active,
            'open' => $open,
            'children' => $children,
        ];
    }

    /** channelUrl() 对外链类型返回的是已转义的地址，这里还原成原值，输出时统一转义 @param array<string,mixed> $channel */
    private static function channelHref(array $channel): string
    {
        $url = channelUrl($channel);
        return (string) ($channel['type'] ?? '') === 'link' ? html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $url;
    }

    // ---------------------------------------------------------------- 输出

    /**
     * @param array{root:array{name:string,url:string,active:bool}, items:list<array<string,mixed>>} $model
     * @param array<string,mixed> $data
     */
    public static function renderModel(array $model, array $data, string $uid, string $extraAttrs = ''): string
    {
        $inline = ($data['layout'] ?? 'stacked') === 'inline';
        $compact = ($data['style'] ?? 'card') === 'compact';
        $options = [
            'accordion' => !$inline && ($data['expand'] ?? 'accordion') === 'accordion',
            'icon' => BloxValueSanitizer::truthy($data['show_icon'] ?? true),
            'image' => BloxValueSanitizer::truthy($data['show_image'] ?? false),
            'compact' => $compact,
            'uid' => $uid,
        ];
        $mobileSelect = BloxValueSanitizer::truthy($data['mobile_select'] ?? true);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = $model['root']['name'];
        }

        $wrapper = $compact ? 'yk-catnav text-sm' : 'yk-catnav overflow-hidden rounded-lg border border-gray-200 bg-white text-sm';
        $html = '<nav class="' . $wrapper . '" data-yk-category-nav aria-label="' . self::escape($title !== '' ? $title : __('blox_el_category_nav')) . '"' . $extraAttrs . '>';

        if (BloxValueSanitizer::truthy($data['show_title'] ?? true) && $title !== '') {
            $titleClass = $compact ? 'mb-2 font-semibold text-gray-900' : 'border-b border-gray-200 px-4 py-3 font-semibold text-gray-900';
            $current = $model['root']['active'] ? ' aria-current="page"' : '';
            $html .= '<div class="' . $titleClass . '"><a class="hover:text-primary" href="' . self::escape(self::safeHref($model['root']['url']) ?: '#') . '"' . $current . '>'
                . self::escape($title) . '</a></div>';
        }

        if ($mobileSelect) {
            $html .= '<div class="' . ($compact ? 'mb-2' : 'p-3') . ' md:hidden">'
                . '<select class="block w-full rounded border border-gray-300 bg-white px-3 py-2 text-sm" data-yk-category-nav-select aria-label="'
                . self::escape(__('blox_catnav_select_label')) . '">'
                . '<option value="' . self::escape(self::safeHref($model['root']['url']) ?: '#') . '">' . self::escape(__('blox_catnav_select_all')) . '</option>'
                . self::selectOptions($model['items'], 0) . '</select></div>';
        }

        $listHidden = $mobileSelect ? ' hidden md:block' : '';
        if ($inline) {
            $listHidden = $mobileSelect ? ' hidden md:flex' : ' flex';
            $html .= '<ul class="flex-wrap gap-2' . ($compact ? '' : ' p-3') . $listHidden . '">';
            foreach ($model['items'] as $item) {
                $html .= '<li>' . self::inlineLink($item, $options) . '</li>';
            }
            return $html . '</ul></nav>';
        }

        $html .= '<ul class="' . trim(($compact ? 'space-y-0.5' : 'divide-y divide-gray-100') . $listHidden) . '">';
        foreach ($model['items'] as $item) {
            $html .= self::stackedItem($item, $options, 0);
        }
        return $html . '</ul></nav>';
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $options */
    private static function stackedItem(array $item, array $options, int $level): string
    {
        $compact = (bool) $options['compact'];
        $children = $item['children'];
        $hasChildren = $children !== [];
        $panelId = $options['uid'] . '-' . $item['key'];
        $collapsed = $hasChildren && $options['accordion'] && !$item['open'];

        $linkClass = $compact
            ? 'flex min-w-0 flex-1 items-center gap-2 rounded px-2 py-1 transition hover:text-primary'
            : 'flex min-w-0 flex-1 items-center gap-2 py-2.5 pe-2 transition hover:bg-gray-50 hover:text-primary ' . ($level === 0 ? 'ps-4' : 'ps-3');
        $linkClass .= $item['active'] ? ' font-semibold text-primary' . ($compact ? ' bg-gray-100' : ' bg-gray-50') : ' text-gray-700';

        $html = '<li><div class="flex items-center">'
            . '<a class="' . $linkClass . '" href="' . self::escape(self::safeHref($item['url']) ?: '#') . '"' . ($item['active'] ? ' aria-current="page"' : '') . '>'
            . self::itemInner($item, $options) . '</a>';
        if ($hasChildren && $options['accordion']) {
            $html .= '<button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-primary' . ($compact ? '' : ' me-2') . '"'
                . ' data-yk-category-nav-toggle aria-expanded="' . ($collapsed ? 'false' : 'true') . '" aria-controls="' . self::escape($panelId) . '">'
                . '<i class="ti ti-chevron-down text-base transition-transform" aria-hidden="true"></i>'
                . '<span class="sr-only">' . self::escape(__('blox_catnav_toggle', ['name' => (string) $item['name']])) . '</span></button>';
        }
        $html .= '</div>';
        if ($hasChildren) {
            $childClass = $compact ? 'ms-2 space-y-0.5 border-s border-gray-200 ps-2' : 'ms-4 border-s border-gray-100 pb-1';
            $html .= '<ul id="' . self::escape($panelId) . '" class="' . $childClass . '"' . ($collapsed ? ' hidden' : '') . '>';
            foreach ($children as $child) {
                $html .= self::stackedItem($child, $options, $level + 1);
            }
            $html .= '</ul>';
        }
        return $html . '</li>';
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $options */
    private static function inlineLink(array $item, array $options): string
    {
        $class = 'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 transition '
            . (($item['active'] || $item['open']) ? 'border-primary bg-primary text-white' : 'border-gray-200 text-gray-700 hover:border-primary hover:text-primary');
        return '<a class="' . $class . '" href="' . self::escape(self::safeHref($item['url']) ?: '#') . '"' . ($item['active'] ? ' aria-current="page"' : '') . '>'
            . self::itemInner($item, $options) . '</a>';
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $options */
    private static function itemInner(array $item, array $options): string
    {
        $html = '';
        if ($options['image'] && $item['image'] !== '' && ($src = self::safeHref($item['image'])) !== '') {
            $html .= '<img src="' . self::escape($src) . '" alt="" loading="lazy" class="h-6 w-6 shrink-0 rounded object-cover">';
        } elseif ($options['icon'] && $item['icon'] !== '') {
            $html .= NavMegaElement::nodeIconHtml(['icon' => $item['icon']], 'shrink-0 text-base');
        }
        $html .= '<span class="min-w-0 truncate">' . self::escape((string) $item['name']) . '</span>';
        if ($item['count'] !== null) {
            $html .= '<span class="ms-auto shrink-0 text-xs tabular-nums ' . ($item['active'] ? 'text-primary' : 'text-gray-400') . '">' . (int) $item['count'] . '</span>';
        }
        return $html;
    }

    /** @param list<array<string,mixed>> $items */
    private static function selectOptions(array $items, int $level): string
    {
        $html = '';
        foreach ($items as $item) {
            $html .= '<option value="' . self::escape(self::safeHref($item['url']) ?: '#') . '"' . ($item['active'] ? ' selected' : '') . '>'
                . str_repeat('— ', $level) . self::escape((string) $item['name']) . '</option>';
            $html .= self::selectOptions($item['children'], $level + 1);
        }
        return $html;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
