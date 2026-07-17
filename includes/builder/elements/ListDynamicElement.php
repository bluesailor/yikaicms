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

    /** 拼出 {yk:list ...}<子元素模板>{/yk:list}（纯字符串，便于单测；render 再交 TagEngine 解析） */
    public function buildMarkup(array $data): string
    {
        // 内层子元素渲染成循环模板（子元素设置里的 {yk:field} 原样带出，循环时逐条解析）
        $tpl = '';
        foreach ($data['template'] ?? [] as $child) {
            $el = BuilderRegistry::get((string) ($child['type'] ?? ''));
            if ($el !== null) {
                $tpl .= $el->render(is_array($child['data'] ?? null) ? $child['data'] : []);
            }
        }

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

        return '{yk:list ' . $attrs . '}' . $tpl . '{/yk:list}';
    }

    /** 属性值：含空格用引号包，否则裸值（TagEngine parseAttrs 两者都认） */
    private static function attr(string $v): string
    {
        return strpbrk($v, " \t") !== false ? '"' . str_replace('"', '', $v) . '"' : $v;
    }
}
