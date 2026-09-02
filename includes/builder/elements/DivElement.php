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
        'column' => ['flex-col', 'md:flex-col', 'lg:flex-col'],
        'row' => ['flex-row', 'md:flex-row', 'lg:flex-row'],
    ];
    private const AUTO_WRAP_MAP = [
        'column' => ['', 'md:flex-nowrap', 'lg:flex-nowrap'],
        'row' => ['flex-wrap', 'md:flex-wrap', 'lg:flex-wrap'],
    ];
    private const GAP_MAP = [
        'none' => ['gap-0', 'md:gap-0', 'lg:gap-0'],
        'sm' => ['gap-2', 'md:gap-2', 'lg:gap-2'],
        'md' => ['gap-4', 'md:gap-4', 'lg:gap-4'],
        'lg' => ['gap-8', 'md:gap-8', 'lg:gap-8'],
        'xl' => ['gap-12', 'md:gap-12', 'lg:gap-12'],
    ];
    private const PAD_MAP = [
        'none' => ['', 'md:p-0', 'lg:p-0'],
        'sm' => ['p-3', 'md:p-3', 'lg:p-3'],
        'md' => ['p-6', 'md:p-6', 'lg:p-6'],
        'lg' => ['p-10', 'md:p-10', 'lg:p-10'],
        'xl' => ['p-16', 'md:p-16', 'lg:p-16'],
    ];
    private const RADIUS_MAP = ['none' => '', 'md' => 'rounded-lg', 'xl' => 'rounded-2xl'];
    private const ITEMS_MAP = ['stretch' => '', 'start' => 'items-start', 'center' => 'items-center', 'end' => 'items-end', 'baseline' => 'items-baseline'];
    private const JUSTIFY_MAP = ['start' => '', 'center' => 'justify-center', 'end' => 'justify-end', 'between' => 'justify-between', 'around' => 'justify-around', 'evenly' => 'justify-evenly'];

    public function type(): string { return 'div'; }
    public function label(): string { return 'Div'; }
    public function icon(): string { return 'square'; }
    public function category(): string { return 'layout'; }
    public function isContainer(): bool { return true; }
    public function allowedChildren(array $data = []): array { return ['*']; }
    /** 通用背景：native——背景写在自己的根 div 上，存量输出逐字节不变 */
    public function backgroundRenderStrategy(): string { return 'native'; }

    public function controls(): array
    {
        return [
            ['key' => 'display', 'type' => 'select', 'label' => __('blox_display_mode'), 'default' => 'block', 'tab' => 'style',
                'options' => ['block' => __('blox_block_level'), 'flex' => 'Flex']],
            ['key' => 'direction', 'type' => 'select', 'label' => __('blox_direction'), 'default' => 'column', 'tab' => 'style', 'responsive' => true,
                'options' => ['column' => __('blox_dir_column_stack'), 'row' => __('blox_dir_row_wrap')]],
            ['key' => 'wrap', 'type' => 'select', 'label' => __('blox_flex_wrap'), 'default' => 'auto', 'tab' => 'style',
                'options' => ['auto' => __('blox_flex_wrap_auto'), 'wrap' => __('blox_flex_wrap_on'), 'nowrap' => __('blox_flex_wrap_off')]],
            ['key' => 'gap', 'type' => 'select', 'label' => __('blox_child_gap'), 'default' => 'none', 'tab' => 'style', 'responsive' => true,
                'options' => ['none' => __('blox_spacing_none'), 'sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg'), 'xl' => __('blox_spacing_xl')]],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_cross_align'), 'default' => 'stretch', 'tab' => 'style',
                'options' => ['stretch' => __('blox_align_stretch'), 'start' => __('blox_align_start'), 'center' => __('blox_align_center'), 'end' => __('blox_align_end'), 'baseline' => __('blox_flex_align_baseline')]],
            ['key' => 'justify', 'type' => 'select', 'label' => __('blox_main_distribute'), 'default' => 'start', 'tab' => 'style',
                'options' => ['start' => __('blox_align_start'), 'center' => __('blox_align_center'), 'end' => __('blox_align_end'), 'between' => __('blox_align_between'), 'around' => __('blox_flex_around'), 'evenly' => __('blox_flex_evenly')]],
            ...$this->backgroundControls(),
            ['key' => 'padding', 'type' => 'select', 'label' => __('blox_padding'), 'default' => 'none', 'tab' => 'style', 'responsive' => true,
                'options' => ['none' => __('blox_spacing_none'), 'sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg'), 'xl' => __('blox_spacing_xl')]],
            ['key' => 'radius', 'type' => 'select', 'label' => __('blox_radius'), 'default' => 'none', 'tab' => 'style',
                'options' => ['none' => __('blox_spacing_none'), 'md' => __('blox_spacing_md'), 'xl' => __('blox_spacing_lg')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $display = ($data['display'] ?? 'block') === 'flex' ? 'flex' : 'block';
        $cls = 'yk-div';
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
                self::ITEMS_MAP[$data['align'] ?? 'stretch'] ?? '',
                self::JUSTIFY_MAP[$data['justify'] ?? 'start'] ?? '',
            ] as $layoutClass) {
                if ($layoutClass !== '') {
                    $cls .= ' ' . $layoutClass;
                }
            }
        }
        foreach ([
            $this->resp($data['padding'] ?? 'none', self::PAD_MAP, 'none'),
            self::RADIUS_MAP[$data['radius'] ?? 'none'] ?? '',
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
