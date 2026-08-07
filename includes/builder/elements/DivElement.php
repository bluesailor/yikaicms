<?php
/**
 * Div element: a generic content wrapper inside a builder column.
 *
 * Block flow is the default. Flex can be enabled when child alignment is needed.
 * Child nodes are stored in data.children and rendered recursively by BlockRenderer.
 */

declare(strict_types=1);

final class DivElement extends AbstractElement
{
    private const GAP_MAP = ['none' => 'gap-0', 'sm' => 'gap-2', 'md' => 'gap-4', 'lg' => 'gap-8', 'xl' => 'gap-12'];
    private const PAD_MAP = ['none' => '', 'sm' => 'p-3', 'md' => 'p-6', 'lg' => 'p-10', 'xl' => 'p-16'];
    private const RADIUS_MAP = ['none' => '', 'md' => 'rounded-lg', 'xl' => 'rounded-2xl'];
    private const ITEMS_MAP = ['stretch' => '', 'start' => 'items-start', 'center' => 'items-center', 'end' => 'items-end', 'baseline' => 'items-baseline'];
    private const JUSTIFY_MAP = ['start' => '', 'center' => 'justify-center', 'end' => 'justify-end', 'between' => 'justify-between', 'around' => 'justify-around', 'evenly' => 'justify-evenly'];

    public function type(): string { return 'div'; }
    public function label(): string { return 'Div'; }
    public function icon(): string { return 'square'; }
    public function category(): string { return 'layout'; }
    public function isContainer(): bool { return true; }
    public function allowedChildren(array $data = []): array { return ['*']; }

    public function controls(): array
    {
        return [
            ['key' => 'display', 'type' => 'select', 'label' => '显示方式', 'default' => 'block', 'tab' => 'style',
                'options' => ['block' => '块级', 'flex' => 'Flex']],
            ['key' => 'direction', 'type' => 'select', 'label' => '排列方向', 'default' => 'column', 'tab' => 'style',
                'options' => ['column' => '纵向（上下堆叠）', 'row' => '横向（并排，可换行）']],
            ['key' => 'wrap', 'type' => 'select', 'label' => __('blox_flex_wrap'), 'default' => 'auto', 'tab' => 'style',
                'options' => ['auto' => __('blox_flex_wrap_auto'), 'wrap' => __('blox_flex_wrap_on'), 'nowrap' => __('blox_flex_wrap_off')]],
            ['key' => 'gap', 'type' => 'select', 'label' => '子元素间距', 'default' => 'none', 'tab' => 'style',
                'options' => ['none' => '无', 'sm' => '小', 'md' => '中', 'lg' => '大', 'xl' => __('blox_spacing_xl')]],
            ['key' => 'align', 'type' => 'select', 'label' => '交叉轴对齐', 'default' => 'stretch', 'tab' => 'style',
                'options' => ['stretch' => '拉伸', 'start' => '起点', 'center' => '居中', 'end' => '终点', 'baseline' => __('blox_flex_align_baseline')]],
            ['key' => 'justify', 'type' => 'select', 'label' => '主轴分布', 'default' => 'start', 'tab' => 'style',
                'options' => ['start' => '起点', 'center' => '居中', 'end' => '终点', 'between' => '两端', 'around' => __('blox_flex_around'), 'evenly' => __('blox_flex_evenly')]],
            ['key' => 'bg_color', 'type' => 'color', 'label' => '背景颜色', 'default' => '', 'tab' => 'style'],
            ['key' => 'padding', 'type' => 'select', 'label' => '内边距', 'default' => 'none', 'tab' => 'style',
                'options' => ['none' => '无', 'sm' => '小', 'md' => '中', 'lg' => '大', 'xl' => __('blox_spacing_xl')]],
            ['key' => 'radius', 'type' => 'select', 'label' => '圆角', 'default' => 'none', 'tab' => 'style',
                'options' => ['none' => '无', 'md' => '中', 'xl' => '大']],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $display = ($data['display'] ?? 'block') === 'flex' ? 'flex' : 'block';
        $cls = 'yk-div';
        if ($display === 'flex') {
            $isRow = ($data['direction'] ?? 'column') === 'row';
            $cls .= ' flex ' . ($isRow ? 'flex-row' : 'flex-col');
            $wrap = $data['wrap'] ?? 'auto';
            if ($wrap === 'wrap' || ($wrap === 'auto' && $isRow)) {
                $cls .= ' flex-wrap';
            } elseif ($wrap === 'nowrap') {
                $cls .= ' flex-nowrap';
            }
            $cls .= ' ' . (self::GAP_MAP[$data['gap'] ?? 'none'] ?? self::GAP_MAP['none']);
            foreach ([
                self::ITEMS_MAP[$data['align'] ?? 'stretch'] ?? '',
                self::JUSTIFY_MAP[$data['justify'] ?? 'start'] ?? '',
            ] as $layoutClass) {
                if ($layoutClass !== '') {
                    $cls .= ' ' . $layoutClass;
                }
            }
        }
        foreach ([
            self::PAD_MAP[$data['padding'] ?? 'none'] ?? '',
            self::RADIUS_MAP[$data['radius'] ?? 'none'] ?? '',
        ] as $boxClass) {
            if ($boxClass !== '') {
                $cls .= ' ' . $boxClass;
            }
        }

        $style = '';
        if (!empty($data['bg_color'])) {
            $style = ' style="background-color:' . htmlspecialchars((string) $data['bg_color'], ENT_QUOTES) . ';"';
        }

        return '<div class="' . $cls . '"' . $style . '>' . $children . '</div>';
    }
}
