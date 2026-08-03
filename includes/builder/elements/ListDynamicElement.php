<?php
/**
 * 动态列表元素 —— 页面构建器的"循环"块，把 {yk:list} 变成可拖拽配置。
 *
 * data：
 *   query_source  可视化来源（type:article / channel:ID）；旧 source_type 继续兼容
 *   cat          栏目/分类 slug 或 id（= {yk:list cat=}），可空
 *   limit/offset/order/recommend/hot/top  透传 {yk:list} 同名参数
 *   empty        无数据文案
 *   template[]   内层子元素定义 [{type,data},...]，每条数据渲染一次；
 *                子元素字段值里写 {yk:field name=xxx /} 即绑定当前条目字段
 *
 * 渲染：拼 {yk:list ...}<子元素模板>{/yk:list} 交 TagEngine 解析（复用其循环上下文栈）。
 * 是动态元素 → view-time 渲染时拉实时数据（见 P1-E）。
 */

declare(strict_types=1);

final class ListDynamicElement extends AbstractElement
{
    public const EDITOR_PREVIEW_LIMIT = 12;
    public function type(): string { return 'list-dynamic'; }
    public function label(): string { return __('blox_dynamic_list_label'); }
    public function icon(): string { return 'list-details'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function isContainer(): bool { return true; }
    public function rendersOwnChildren(): bool { return true; }

    public function controls(): array
    {
        $columns = [];
        for ($i = 1; $i <= 8; $i++) {
            $columns[(string) $i] = (string) $i;
        }

        return [
            [
                'key' => 'query_source', 'type' => 'select', 'label' => __('blox_dynamic_source'),
                'default' => 'type:article', 'options' => self::sourceOptions(),
                'help' => __('blox_dynamic_source_help'),
            ],
            [
                'key' => 'cat', 'type' => 'text', 'label' => __('blox_dynamic_filter'), 'default' => '',
                'placeholder' => __('blox_dynamic_filter_placeholder'),
                'help' => __('blox_dynamic_filter_help'),
            ],
            [
                'key' => 'limit', 'type' => 'number', 'label' => __('blox_dynamic_limit'),
                'default' => 6, 'min' => 1, 'max' => 50, 'help' => __('blox_dynamic_limit_help'),
            ],
            ['key' => 'offset', 'type' => 'number', 'label' => __('blox_dynamic_offset'), 'default' => 0, 'min' => 0, 'max' => 5000],
            ['key' => 'keyword', 'type' => 'text', 'label' => __('blox_dynamic_keyword'), 'default' => ''],
            [
                'key' => 'order', 'type' => 'select', 'label' => __('blox_dynamic_order'), 'default' => 'default',
                'options' => [
                    'default' => __('blox_dynamic_order_default'),
                    'newest' => __('blox_dynamic_order_newest'),
                    'updated' => __('blox_dynamic_order_updated'),
                    'views' => __('blox_dynamic_order_views'),
                    'price_asc' => __('blox_dynamic_order_price_asc'),
                    'price_desc' => __('blox_dynamic_order_price_desc'),
                ],
                'help' => __('blox_dynamic_order_help'),
            ],
            ['key' => 'recommend', 'type' => 'checkbox', 'label' => __('blox_dynamic_recommend'), 'default' => false],
            ['key' => 'hot', 'type' => 'checkbox', 'label' => __('blox_dynamic_hot'), 'default' => false],
            ['key' => 'top', 'type' => 'checkbox', 'label' => __('blox_dynamic_top'), 'default' => false],
            ['key' => 'show_image', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_image'), 'default' => true],
            [
                'key' => 'image_field', 'type' => 'select', 'label' => __('blox_dynamic_image_field'),
                'default' => 'cover', 'options' => DynamicListItemSchema::fieldOptions('image'),
                'required' => ['show_image', '=', true],
            ],
            ['key' => 'show_title', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_title'), 'default' => true],
            [
                'key' => 'title_field', 'type' => 'select', 'label' => __('blox_dynamic_title_field'),
                'default' => 'title', 'options' => DynamicListItemSchema::fieldOptions('title'),
                'source_options' => [
                    'content' => DynamicListItemSchema::fieldOptions('title', 'content'),
                    'product' => DynamicListItemSchema::fieldOptions('title', 'product'),
                ],
                'required' => ['show_title', '=', true],
            ],
            ['key' => 'show_summary', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_summary'), 'default' => true],
            [
                'key' => 'summary_field', 'type' => 'select', 'label' => __('blox_dynamic_summary_field'),
                'default' => 'summary', 'options' => DynamicListItemSchema::fieldOptions('summary'),
                'required' => ['show_summary', '=', true],
            ],
            ['key' => 'show_date', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_date'), 'default' => false],
            [
                'key' => 'date_field', 'type' => 'select', 'label' => __('blox_dynamic_date_field'),
                'default' => 'date', 'options' => DynamicListItemSchema::fieldOptions('date'),
                'source_options' => [
                    'content' => DynamicListItemSchema::fieldOptions('date', 'content'),
                    'product' => DynamicListItemSchema::fieldOptions('date', 'product'),
                ],
                'required' => ['show_date', '=', true],
            ],
            [
                'key' => 'show_meta', 'type' => 'checkbox', 'label' => __('blox_dynamic_show_meta'),
                'default' => false, 'source_kind' => 'product',
            ],
            [
                'key' => 'meta_field', 'type' => 'select', 'label' => __('blox_dynamic_meta_field'),
                'default' => 'model', 'options' => DynamicListItemSchema::fieldOptions('meta', 'product'),
                'required' => ['show_meta', '=', true], 'source_kind' => 'product',
                'help' => __('blox_dynamic_meta_help'),
            ],
            [
                'key' => 'link_field', 'type' => 'select', 'label' => __('blox_dynamic_link_field'),
                'default' => 'url', 'options' => DynamicListItemSchema::fieldOptions('link'),
            ],
            [
                'key' => 'summary_len', 'type' => 'number', 'label' => __('blox_dynamic_summary_length'),
                'default' => 80, 'min' => 20, 'max' => 300, 'required' => ['show_summary', '=', true],
            ],
            [
                'key' => 'empty_mode', 'type' => 'select', 'label' => __('blox_dynamic_empty_mode'),
                'default' => 'message', 'options' => [
                    'message' => __('blox_dynamic_empty_message'),
                    'hidden' => __('blox_dynamic_empty_hidden'),
                ],
            ],
            [
                'key' => 'empty', 'type' => 'text', 'label' => __('blox_dynamic_empty'),
                'default' => __('blox_dynamic_empty_default'), 'required' => ['empty_mode', '=', 'message'],
            ],
            [
                'key' => 'item_preset', 'type' => 'select', 'label' => __('blox_dynamic_item_preset'),
                'default' => 'card', 'options' => DynamicListItemSchema::presetOptions(), 'tab' => 'style',
                'help' => __('blox_dynamic_item_preset_help'),
            ],
            [
                'key' => 'columns', 'type' => 'select', 'label' => __('blox_dynamic_columns'),
                'default' => '3', 'options' => $columns, 'tab' => 'style',
            ],
            [
                'key' => 'image_ratio', 'type' => 'select', 'label' => __('blox_dynamic_image_ratio'),
                'default' => 'wide', 'tab' => 'style', 'required' => ['show_image', '=', true],
                'options' => [
                    'wide' => __('blox_dynamic_ratio_wide'),
                    'landscape' => __('blox_dynamic_ratio_landscape'),
                    'square' => __('blox_dynamic_ratio_square'),
                ],
            ],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        return $this->renderWithContext($data, $children);
    }

    /** @param array<string,mixed> $context */
    public function renderWithContext(array $data, string $children = '', array $context = []): string
    {
        $previewLimit = class_exists('BlockRenderer') && BlockRenderer::$editChannelId > 0
            ? self::EDITOR_PREVIEW_LIMIT
            : null;
        $markup = $this->buildMarkup($data, $previewLimit, $context);
        // 运行时 TagEngine 已加载 → 解析出实时列表；未加载则原样返回（由内容渲染管线兜底）
        return class_exists('TagEngine') ? \TagEngine::render($markup) : $markup;
    }
    /** 拼出 {yk:list ...}<循环模板>{/yk:list}（纯字符串，便于单测；render 再交 TagEngine 解析）。
     *  循环模板：优先用 template[] 子元素（高级）；否则用字段开关拼内置卡片（后台简单表单用）。 */
    public function buildMarkup(array $data, ?int $limitCap = null, array $context = []): string
    {
        [$sourceType, $category] = self::resolveSource($data);
        $tpl = $this->loopTemplate($data, $context);

        $attrs = 'type=' . self::attr($sourceType);
        if ($category !== '') {
            $attrs .= ' cat=' . self::attr($category);
        }

        $limit = max(1, min(50, (int) ($data['limit'] ?? 6)));
        if ($limitCap !== null) {
            $limit = min($limit, max(1, $limitCap));
        }
        $attrs .= ' limit=' . $limit;
        $offset = max(0, min(5000, (int) ($data['offset'] ?? 0)));
        if ($offset > 0) {
            $attrs .= ' offset=' . $offset;
        }
        $keyword = trim((string) ($data['keyword'] ?? ''));
        if ($keyword !== '') {
            $attrs .= ' keyword=' . self::attr($keyword);
        }

        $order = (string) ($data['order'] ?? 'default');
        if ($sourceType === 'product' && in_array($order, ['default', 'newest', 'updated', 'views', 'price_asc', 'price_desc'], true)) {
            $attrs .= ' order=' . $order;
        }
        foreach (['recommend', 'hot', 'top'] as $flag) {
            if (!empty($data[$flag])) {
                $attrs .= ' ' . $flag . '=1';
            }
        }
        $emptyMode = (string) ($data['empty_mode'] ?? 'message');
        if ($emptyMode !== 'hidden' && isset($data['empty']) && trim((string) $data['empty']) !== '') {
            $attrs .= ' empty=' . self::attr((string) $data['empty']);
        }

        $list = '{yk:list ' . $attrs . '}' . $tpl . '{/yk:list}';
        if (!empty($data['template']) && empty($data['children'])) {
            return $list;
        }

        $columns = max(1, min(8, (int) ($data['columns'] ?? 3)));
        $layout = $columns === 1
            ? 'space-y-6'
            : 'grid ' . AbstractElement::gridClasses($columns, 3, true) . ' gap-6';
        return '<div class="' . $layout . '">' . $list . '</div>';
    }

    /** 循环模板：template[] 子元素优先，否则按字段开关拼内置卡片 */
    private function loopTemplate(array $data, array $context = []): string
    {
        if (!empty($data['children']) && is_array($data['children'])) {
            return DynamicLoopTemplateRenderer::render($data['children'], $data, $context);
        }

        if (!empty($data['template']) && is_array($data['template'])) {
            $tpl = '';
            foreach ($data['template'] as $child) {
                $el = BuilderRegistry::get((string) ($child['type'] ?? ''));
                if ($el !== null) {
                    $tpl .= $el->render(is_array($child['data'] ?? null) ? $child['data'] : []);
                }
            }
            return $tpl;
        }

        return DynamicListItemSchema::render($data);
    }

    /** @return array<string,string> */
    private static function sourceOptions(): array
    {
        $options = [
            'type:article' => __('blox_dynamic_source_article'),
            'type:case' => __('blox_dynamic_source_case'),
            'type:product' => __('blox_dynamic_source_product'),
        ];

        try {
            foreach (channelModel()->getFlatList(0, 0, siteLang()) as $channel) {
                $id = (int) ($channel['id'] ?? 0);
                $type = self::safeSourceType((string) ($channel['type'] ?? ''));
                if ($id < 1 || empty($channel['status']) || in_array($type, ['page', 'link', 'form', 'product'], true)) {
                    continue;
                }
                $name = trim((string) ($channel['_prefix'] ?? '') . (string) ($channel['name'] ?? ''));
                $options['channel:' . $id] = __('blox_dynamic_source_channel') . ' · ' . ($name !== '' ? $name : '#' . $id);
            }
        } catch (Throwable) {
            // 安装早期或无数据库测试环境只显示内置来源。
        }

        return $options;
    }

    /** @param array<string,mixed> $data @return array{string,string} */
    private static function resolveSource(array $data): array
    {
        $category = trim((string) ($data['cat'] ?? ''));
        $querySource = trim((string) ($data['query_source'] ?? ''));

        if (str_starts_with($querySource, 'channel:')) {
            $channelId = (int) substr($querySource, 8);
            try {
                $channel = $channelId > 0 ? channelModel()->find($channelId) : null;
            } catch (Throwable) {
                $channel = null;
            }
            if ($channel && !empty($channel['status'])) {
                $sourceType = self::safeSourceType((string) ($channel['type'] ?? 'article'));
                return [$sourceType, (string) $channelId];
            }
        }

        if (str_starts_with($querySource, 'type:')) {
            return [self::safeSourceType(substr($querySource, 5)), $category];
        }

        return [self::safeSourceType((string) ($data['source_type'] ?? 'article')), $category];
    }

    private static function safeSourceType(string $type): string
    {
        $type = strtolower(trim($type));
        return preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $type) ? $type : 'article';
    }

    /** 属性值：含空格用引号包，否则裸值（TagEngine parseAttrs 两者都认） */
    private static function attr(string $v): string
    {
        $v = mb_substr(trim(str_replace(['"', '{', '}', "\r", "\n"], '', $v)), 0, 200);
        return preg_match('/\s/u', $v) ? '"' . $v . '"' : $v;
    }
}
