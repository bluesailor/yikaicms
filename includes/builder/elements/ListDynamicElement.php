<?php
/**
 * 动态列表元素 —— 页面构建器的"循环"块，把 {yk:list} 变成可拖拽配置。
 *
 * data：
 *   source_type  内容类型：article/case/product 或 自定义模型 key（= {yk:list type=}）
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
    public function type(): string { return 'list-dynamic'; }
    public function label(): string { return '动态列表'; }
    public function icon(): string { return 'list-details'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }

    public function render(array $data, string $children = ''): string
    {
        $markup = $this->buildMarkup($data);
        // 运行时 TagEngine 已加载 → 解析出实时列表；未加载则原样返回（由内容渲染管线兜底）
        return class_exists('TagEngine') ? \TagEngine::render($markup) : $markup;
    }

    /** 拼出 {yk:list ...}<循环模板>{/yk:list}（纯字符串，便于单测；render 再交 TagEngine 解析）。
     *  循环模板：优先用 template[] 子元素（高级）；否则用字段开关拼内置卡片（后台简单表单用）。 */
    public function buildMarkup(array $data): string
    {
        $tpl = $this->loopTemplate($data);

        $attrs = 'type=' . self::attr($data['source_type'] ?? 'article');
        foreach (['cat', 'limit', 'offset', 'order', 'keyword'] as $k) {
            if (isset($data[$k]) && $data[$k] !== '') {
                $attrs .= ' ' . $k . '=' . self::attr((string) $data[$k]);
            }
        }
        foreach (['recommend', 'hot', 'top'] as $flag) {
            if (!empty($data[$flag])) {
                $attrs .= ' ' . $flag . '=1';
            }
        }
        if (isset($data['empty']) && $data['empty'] !== '') {
            $attrs .= ' empty=' . self::attr((string) $data['empty']);
        }

        $list = '{yk:list ' . $attrs . '}' . $tpl . '{/yk:list}';

        // 内置卡片模式：整个列表包一层响应式网格
        if (empty($data['template'])) {
            $cols = max(1, min(4, (int) ($data['columns'] ?? 3)));
            $grid = $cols > 1 ? 'grid grid-cols-1 md:grid-cols-' . $cols . ' gap-6' : 'space-y-6';
            return '<div class="' . $grid . '">' . $list . '</div>';
        }
        return $list;
    }

    /** 循环模板：template[] 子元素优先，否则按字段开关拼内置卡片 */
    private function loopTemplate(array $data): string
    {
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

        // 内置卡片：字段开关（默认显示 封面/标题/摘要，不显示日期），整卡可点进详情。
        // (bool)(... ?? 默认)：未设走默认；显式 false/'0'/'' 关，true/'1' 开（兼容 Alpine 布尔与旧字符串）
        $showImage = (bool) ($data['show_image'] ?? true);
        $showTitle = (bool) ($data['show_title'] ?? true);
        $showSummary = (bool) ($data['show_summary'] ?? true);
        $showDate = (bool) ($data['show_date'] ?? false);
        $sumLen = max(20, min(300, (int) ($data['summary_len'] ?? 80)));

        $inner = '';
        if ($showImage) {
            $inner .= '<div class="aspect-video overflow-hidden bg-gray-100"><img src="{yk:field name=cover /}" alt="" loading="lazy" class="w-full h-full object-cover"></div>';
        }
        $body = '';
        if ($showTitle) {
            $body .= '<h3 class="text-lg font-semibold mb-2 group-hover:text-primary transition">{yk:field name=title /}</h3>';
        }
        if ($showDate) {
            $body .= '<div class="text-xs text-gray-400 mb-2">{yk:field name=date dateformat="Y-m-d" /}</div>';
        }
        if ($showSummary) {
            $body .= '<p class="text-sm text-gray-500">{yk:field name=summary len=' . $sumLen . ' /}</p>';
        }
        if ($body !== '') {
            $inner .= '<div class="p-4">' . $body . '</div>';
        }
        // 整卡链接到详情
        return '<a href="{yk:field name=url /}" class="group block bg-white rounded-lg border border-gray-100 shadow-sm hover:shadow-md transition overflow-hidden no-underline">' . $inner . '</a>';
    }

    /** 属性值：含空格用引号包，否则裸值（TagEngine parseAttrs 两者都认） */
    private static function attr(string $v): string
    {
        return strpbrk($v, " \t") !== false ? '"' . str_replace('"', '', $v) . '"' : $v;
    }
}
