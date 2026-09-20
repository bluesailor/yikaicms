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
    private const DIRECTION_MAP = [
        'column' => ['flex-col', 'md:flex-col', 'lg:flex-col', 'wide:flex-col'],
        'row' => ['flex-row', 'md:flex-row', 'lg:flex-row', 'wide:flex-row'],
    ];
    private const AUTO_WRAP_MAP = [
        'column' => ['', 'md:flex-nowrap', 'lg:flex-nowrap', 'wide:flex-nowrap'],
        'row' => ['flex-wrap', 'md:flex-wrap', 'lg:flex-wrap', 'wide:flex-wrap'],
    ];
    private const GAP_MAP = [
        'none' => ['gap-0', 'md:gap-0', 'lg:gap-0', 'wide:gap-0'],
        'sm' => ['gap-2', 'md:gap-2', 'lg:gap-2', 'wide:gap-2'],
        'md' => ['gap-4', 'md:gap-4', 'lg:gap-4', 'wide:gap-4'],
        'lg' => ['gap-8', 'md:gap-8', 'lg:gap-8', 'wide:gap-8'],
        'xl' => ['gap-12', 'md:gap-12', 'lg:gap-12', 'wide:gap-12'],
    ];
    private const PAD_MAP = [
        'none' => ['', 'md:p-0', 'lg:p-0', 'wide:p-0'],
        'sm' => ['p-3', 'md:p-3', 'lg:p-3', 'wide:p-3'],
        'md' => ['p-6', 'md:p-6', 'lg:p-6', 'wide:p-6'],
        'lg' => ['p-10', 'md:p-10', 'lg:p-10', 'wide:p-10'],
        'xl' => ['p-16', 'md:p-16', 'lg:p-16', 'wide:p-16'],
    ];
    private const RADIUS_MAP = ['none' => '', 'md' => 'rounded-lg', 'xl' => 'rounded-2xl'];
    // 0a：与容器元素同款的响应式对齐四元组（标量旧值只输出基类，存量渲染不变）。
    private const ITEMS_MAP = [
        'stretch' => ['', 'md:items-stretch', 'lg:items-stretch', 'wide:items-stretch'],
        'start' => ['items-start', 'md:items-start', 'lg:items-start', 'wide:items-start'],
        'center' => ['items-center', 'md:items-center', 'lg:items-center', 'wide:items-center'],
        'end' => ['items-end', 'md:items-end', 'lg:items-end', 'wide:items-end'],
        'baseline' => ['items-baseline', 'md:items-baseline', 'lg:items-baseline', 'wide:items-baseline'],
    ];
    private const JUSTIFY_MAP = [
        'start' => ['', 'md:justify-start', 'lg:justify-start', 'wide:justify-start'],
        'center' => ['justify-center', 'md:justify-center', 'lg:justify-center', 'wide:justify-center'],
        'end' => ['justify-end', 'md:justify-end', 'lg:justify-end', 'wide:justify-end'],
        'between' => ['justify-between', 'md:justify-between', 'lg:justify-between', 'wide:justify-between'],
        'around' => ['justify-around', 'md:justify-around', 'lg:justify-around', 'wide:justify-around'],
        'evenly' => ['justify-evenly', 'md:justify-evenly', 'lg:justify-evenly', 'wide:justify-evenly'],
    ];
    private const CONTENT_MAP = [
        '' => ['', 'md:content-normal', 'lg:content-normal', 'wide:content-normal'],
        'start' => ['content-start', 'md:content-start', 'lg:content-start', 'wide:content-start'],
        'center' => ['content-center', 'md:content-center', 'lg:content-center', 'wide:content-center'],
        'end' => ['content-end', 'md:content-end', 'lg:content-end', 'wide:content-end'],
        'between' => ['content-between', 'md:content-between', 'lg:content-between', 'wide:content-between'],
        'around' => ['content-around', 'md:content-around', 'lg:content-around', 'wide:content-around'],
        'evenly' => ['content-evenly', 'md:content-evenly', 'lg:content-evenly', 'wide:content-evenly'],
    ];

    public function type(): string { return 'div'; }
    public function label(): string { return 'Div'; }
    public function icon(): string { return 'square'; }
    public function category(): string { return 'layout'; }
    public function isContainer(): bool { return true; }
    // 0b：布局节点开放互嵌（显式列出容器类型；'*' 通配仍只放行非容器叶子）。
    // 深度上限由 BloxDocumentValidator::MAX_ELEMENT_DEPTH 统一约束。
    public function allowedChildren(array $data = []): array { return ['container', 'div', '*']; }
    /** 通用背景：native——背景写在自己的根 div 上，存量输出逐字节不变 */
    public function backgroundRenderStrategy(): string { return 'native'; }

    public function controls(): array
    {
        return [
            ['key' => 'display', 'type' => 'select', 'label' => __('blox_display_mode'), 'default' => 'block', 'tab' => 'style',
                'options' => ['block' => __('blox_block_level'), 'flex' => 'Flex', 'grid' => 'Grid', 'overlay' => __('blox_layout_overlay')]],
            ['key' => 'direction', 'type' => 'select', 'label' => __('blox_direction'), 'default' => 'column', 'tab' => 'style', 'responsive' => true,
                'required' => ['display', '=', 'flex'],
                'options' => ['column' => __('blox_dir_column_stack'), 'row' => __('blox_dir_row_wrap')]],
            ['key' => 'wrap', 'type' => 'select', 'label' => __('blox_flex_wrap'), 'default' => 'auto', 'tab' => 'style',
                'required' => ['display', '=', 'flex'],
                'options' => ['auto' => __('blox_flex_wrap_auto'), 'wrap' => __('blox_flex_wrap_on'), 'nowrap' => __('blox_flex_wrap_off')]],
            // 0b Grid：display=grid 时的列模板与排列填充。
            ...$this->gridLayoutControls('display'),
            ['key' => 'gap', 'type' => 'select', 'label' => __('blox_child_gap'), 'default' => 'none', 'tab' => 'style', 'responsive' => true,
                'options' => ['none' => __('blox_spacing_none'), 'sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg'), 'xl' => __('blox_spacing_xl')]],
            // 0a 布局引擎：受控任意值间距，留空沿用上面的档位（与容器元素同款）。
            ['key' => 'gap_px', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_css_gap'),
                'default' => '', 'tab' => 'style', 'responsive' => true, 'min' => 0, 'max' => 160, 'step' => 1, 'unit' => 'px',
                'css' => [['property' => 'gap']]],
            ['key' => 'row_gap_px', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_css_row_gap'),
                'default' => '', 'tab' => 'style', 'responsive' => true, 'min' => 0, 'max' => 160, 'step' => 1, 'unit' => 'px',
                'css' => [['property' => 'row-gap']]],
            ['key' => 'column_gap_px', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_css_column_gap'),
                'default' => '', 'tab' => 'style', 'responsive' => true, 'min' => 0, 'max' => 160, 'step' => 1, 'unit' => 'px',
                'css' => [['property' => 'column-gap']]],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_cross_align'), 'default' => 'stretch', 'tab' => 'style', 'responsive' => true,
                'options' => ['stretch' => __('blox_align_stretch'), 'start' => __('blox_align_start'), 'center' => __('blox_align_center'), 'end' => __('blox_align_end'), 'baseline' => __('blox_flex_align_baseline')]],
            ['key' => 'justify', 'type' => 'select', 'label' => __('blox_main_distribute'), 'default' => 'start', 'tab' => 'style', 'responsive' => true,
                'options' => ['start' => __('blox_align_start'), 'center' => __('blox_align_center'), 'end' => __('blox_align_end'), 'between' => __('blox_align_between'), 'around' => __('blox_flex_around'), 'evenly' => __('blox_flex_evenly')]],
            ['key' => 'align_content', 'type' => 'select', 'label' => __('blox_align_content'), 'default' => '', 'tab' => 'style', 'responsive' => true,
                'required' => ['wrap', '!=', 'nowrap'],
                'options' => ['' => __('blox_align_content_normal'), 'start' => __('blox_align_start'), 'center' => __('blox_align_center'), 'end' => __('blox_align_end'), 'between' => __('blox_align_between'), 'around' => __('blox_flex_around'), 'evenly' => __('blox_flex_evenly')]],
            ...$this->backgroundControls(),
            ['key' => 'padding', 'type' => 'select', 'label' => __('blox_padding'), 'default' => 'none', 'tab' => 'style', 'responsive' => true,
                'options' => ['none' => __('blox_spacing_none'), 'sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg'), 'xl' => __('blox_spacing_xl')]],
            ['key' => 'radius', 'type' => 'select', 'label' => __('blox_radius'), 'default' => 'none', 'tab' => 'style',
                'options' => ['none' => __('blox_spacing_none'), 'md' => __('blox_spacing_md'), 'xl' => __('blox_spacing_lg')]],
            // 0a：Div 自身作为父级 flex 子项的布局。
            ...$this->flexItemControls(),
        ];
    }

    public function stylesFor(array $data): array
    {
        return ($data['display'] ?? '') === 'overlay' ? ['/assets/css/blox-overlay.css'] : [];
    }

    public function render(array $data, string $children = ''): string
    {
        $display = ($data['display'] ?? 'block') === 'flex' ? 'flex' : 'block';
        $cls = 'yk-div';
        if (($data['display'] ?? '') === 'overlay') $cls .= ' yk-div-overlay';
        if (($data['display'] ?? '') === 'grid') {
            // 0b Grid：列模板 + 排列填充；间距与三轴对齐类与 flex 分支同一套语义。
            $cls .= ' grid ' . self::gridColumnClasses($data['grid_cols'] ?? null);
            if (($data['grid_flow'] ?? 'row') === 'dense') {
                $cls .= ' grid-flow-dense';
            }
            $gridGap = $this->resp($data['gap'] ?? 'none', self::GAP_MAP, 'none');
            if ($gridGap !== '') {
                $cls .= ' ' . $gridGap;
            }
            foreach ([
                $this->resp($data['align'] ?? 'stretch', self::ITEMS_MAP, 'stretch'),
                $this->resp($data['justify'] ?? 'start', self::JUSTIFY_MAP, 'start'),
                $this->resp($data['align_content'] ?? '', self::CONTENT_MAP, ''),
            ] as $gridLayoutClass) {
                if ($gridLayoutClass !== '') {
                    $cls .= ' ' . $gridLayoutClass;
                }
            }
        }
        if ($display === 'flex') {
            $direction = $data['direction'] ?? 'column';
            $cls .= ' flex ' . $this->resp($direction, self::DIRECTION_MAP, 'column');
            $wrap = $data['wrap'] ?? 'auto';
            if ($wrap === 'auto') {
                $wrapClass = $this->resp($direction, self::AUTO_WRAP_MAP, 'column');
                if ($wrapClass !== '') {
                    $cls .= ' ' . $wrapClass;
                }
            } elseif ($wrap === 'wrap') {
                $cls .= ' flex-wrap';
            } elseif ($wrap === 'nowrap') {
                $cls .= ' flex-nowrap';
            }
            $gapClass = $this->resp($data['gap'] ?? 'none', self::GAP_MAP, 'none');
            if ($gapClass !== '') {
                $cls .= ' ' . $gapClass;
            }
            foreach ([
                $this->resp($data['align'] ?? 'stretch', self::ITEMS_MAP, 'stretch'),
                $this->resp($data['justify'] ?? 'start', self::JUSTIFY_MAP, 'start'),
                $this->resp($data['align_content'] ?? '', self::CONTENT_MAP, ''),
            ] as $layoutClass) {
                if ($layoutClass !== '') {
                    $cls .= ' ' . $layoutClass;
                }
            }
        }
        foreach ([
            $this->resp($data['padding'] ?? 'none', self::PAD_MAP, 'none'),
            self::RADIUS_MAP[$data['radius'] ?? 'none'] ?? '',
            self::alignSelfClass($data),
            self::gridItemSpanClasses($data),
        ] as $boxClass) {
            if ($boxClass !== '') {
                $cls .= ' ' . $boxClass;
            }
        }

        $style = '';
        $background = self::backgroundDeclarations($data);
        if ($background !== '') {
            $style = ' style="' . htmlspecialchars($background, ENT_QUOTES) . '"';
        }

        return '<div class="' . $cls . '"' . $style . '>' . $children . '</div>';
    }
}
