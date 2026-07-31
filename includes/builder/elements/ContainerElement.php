<?php
/**
 * 容器元素：列内的一层嵌套布局。
 *
 * 子元素存 data.children（元素对象数组），由 BlockRenderer 递归渲染后经 $children
 * 传入（AbstractElement::render 第二参就是为此预留的）。设计取中间路线：只嵌套一层
 * （容器里不再放容器，编辑器侧约束；渲染器另有深度上限兜底），旧数据与两个编辑器
 * 的现有行为零影响——没有 container 的页面渲染路径不变。
 */

declare(strict_types=1);

final class ContainerElement extends AbstractElement
{
    /** 类名全部字面量写死：Tailwind 独立编译靠扫描源码提取 */
    private const GAP_MAP = ['none' => 'gap-0', 'sm' => 'gap-2', 'md' => 'gap-4', 'lg' => 'gap-8'];
    private const PAD_MAP = ['none' => '', 'sm' => 'p-3', 'md' => 'p-6', 'lg' => 'p-10'];
    private const RADIUS_MAP = ['none' => '', 'md' => 'rounded-lg', 'xl' => 'rounded-2xl'];
    private const ITEMS_MAP = ['stretch' => '', 'start' => 'items-start', 'center' => 'items-center', 'end' => 'items-end'];
    private const JUSTIFY_MAP = ['start' => '', 'center' => 'justify-center', 'end' => 'justify-end', 'between' => 'justify-between'];

    public function type(): string { return 'container'; }
    public function label(): string { return '容器'; }
    public function icon(): string { return 'box-margin'; }
    public function category(): string { return 'layout'; }
    public function isContainer(): bool { return true; }

    public function controls(): array
    {
        // 容器没有内容型设置——它的「内容」就是子元素（结构树里管理），
        // 所以全部控件标 tab:style（blox 设置面板的样式页签）。
        // option_icons：编辑器把该 select 显示为图标按钮组（键与 options 对应，
        // 值为 Tabler 图标名）；不认识此键的编辑器仍按普通下拉渲染，向后兼容
        return [
            ['key' => 'direction', 'type' => 'select', 'label' => '排列方向', 'default' => 'column', 'tab' => 'style',
                'options' => ['column' => '纵向（上下堆叠）', 'row' => '横向（并排，可换行）'],
                'option_icons' => ['column' => 'layout-list', 'row' => 'layout-columns']],
            ['key' => 'gap', 'type' => 'select', 'label' => '子元素间距', 'default' => 'md', 'tab' => 'style',
                'options' => ['none' => '无', 'sm' => '小', 'md' => '中', 'lg' => '大']],
            ['key' => 'align', 'type' => 'select', 'label' => '交叉轴对齐', 'default' => 'stretch', 'tab' => 'style',
                'options' => ['stretch' => '拉伸', 'start' => '起点', 'center' => '居中', 'end' => '终点'],
                'option_icons' => ['stretch' => 'arrows-vertical', 'start' => 'layout-align-top', 'center' => 'layout-align-middle', 'end' => 'layout-align-bottom']],
            ['key' => 'justify', 'type' => 'select', 'label' => '主轴分布', 'default' => 'start', 'tab' => 'style',
                'options' => ['start' => '起点', 'center' => '居中', 'end' => '终点', 'between' => '两端'],
                'option_icons' => ['start' => 'align-left', 'center' => 'align-center', 'end' => 'align-right', 'between' => 'align-justified']],
            ['key' => 'bg_color', 'type' => 'color', 'label' => '背景颜色', 'default' => '', 'tab' => 'style'],
            ['key' => 'padding', 'type' => 'select', 'label' => '内边距', 'default' => 'none', 'tab' => 'style',
                'options' => ['none' => '无', 'sm' => '小', 'md' => '中', 'lg' => '大']],
            ['key' => 'radius', 'type' => 'select', 'label' => '圆角', 'default' => 'none', 'tab' => 'style',
                'options' => ['none' => '无', 'md' => '中', 'xl' => '大']],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        // yk-container 是编辑态定位钩子（画布空容器占位用），前台无样式含义——与 yk-col-card 同例
        $cls = 'yk-container flex ' . ((($data['direction'] ?? 'column') === 'row') ? 'flex-row flex-wrap' : 'flex-col');
        $cls .= ' ' . (self::GAP_MAP[$data['gap'] ?? 'md'] ?? self::GAP_MAP['md']);
        foreach ([
            self::ITEMS_MAP[$data['align'] ?? 'stretch'] ?? '',
            self::JUSTIFY_MAP[$data['justify'] ?? 'start'] ?? '',
            self::PAD_MAP[$data['padding'] ?? 'none'] ?? '',
            self::RADIUS_MAP[$data['radius'] ?? 'none'] ?? '',
        ] as $c) {
            if ($c !== '') {
                $cls .= ' ' . $c;
            }
        }
        $style = '';
        if (!empty($data['bg_color'])) {
            $style = ' style="background-color:' . htmlspecialchars((string) $data['bg_color'], ENT_QUOTES) . ';"';
        }
        return '<div class="' . $cls . '"' . $style . '>' . $children . '</div>';
    }
}
