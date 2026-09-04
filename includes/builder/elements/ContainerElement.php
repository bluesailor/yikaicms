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

    public function type(): string { return 'container'; }
    public function label(): string { return __('blox_tree_container'); }
    public function icon(): string { return 'box-margin'; }
    public function category(): string { return 'layout'; }
    public function isContainer(): bool { return true; }
    public function allowedChildren(array $data = []): array { return ['*']; }
    /** 通用背景：native——背景写在自己的根 div 上，存量输出逐字节不变 */
    public function backgroundRenderStrategy(): string { return 'native'; }
    /** 背景视频首批仅容器（区块级视频背景是真实场景；正文元素无此需求） */
    protected function backgroundVideoEnabled(): bool { return true; }

    /** @param array<string,mixed> $data @return list<string> */
    public function scriptsFor(array $data): array
    {
        return self::backgroundVideoUrl($data) !== ''
            ? ['/assets/js/blox-video-policy.js', '/assets/js/blox-background-video.js']
            : [];
    }

    public function controls(): array
    {
        // 容器没有内容型设置——它的「内容」就是子元素（结构树里管理），
        // 所以全部控件标 tab:style（blox 设置面板的样式页签）。
        // option_icons：编辑器把该 select 显示为图标按钮组（键与 options 对应，
        // 值为 Tabler 图标名）；不认识此键的编辑器仍按普通下拉渲染，向后兼容
        return [
            ['key' => 'direction', 'type' => 'select', 'label' => __('blox_direction'), 'default' => 'column', 'tab' => 'style', 'responsive' => true,
                'options' => ['column' => __('blox_dir_column_stack'), 'row' => __('blox_dir_row_wrap')],
                'option_icons' => ['column' => 'layout-list', 'row' => 'layout-columns']],
            ['key' => 'wrap', 'type' => 'select', 'label' => __('blox_flex_wrap'), 'default' => 'auto', 'tab' => 'style',
                'options' => ['auto' => __('blox_flex_wrap_auto'), 'wrap' => __('blox_flex_wrap_on'), 'nowrap' => __('blox_flex_wrap_off')]],
            ['key' => 'gap', 'type' => 'select', 'label' => __('blox_child_gap'), 'default' => 'md', 'tab' => 'style', 'responsive' => true,
                'options' => ['none' => __('blox_spacing_none'), 'sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg'), 'xl' => __('blox_spacing_xl')]],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_cross_align'), 'default' => 'stretch', 'tab' => 'style',
                'options' => ['stretch' => __('blox_align_stretch'), 'start' => __('blox_align_start'), 'center' => __('blox_align_center'), 'end' => __('blox_align_end'), 'baseline' => __('blox_flex_align_baseline')],
                'option_icons' => ['stretch' => 'arrows-vertical', 'start' => 'layout-align-top', 'center' => 'layout-align-middle', 'end' => 'layout-align-bottom', 'baseline' => 'align-box-bottom-center']],
            ['key' => 'justify', 'type' => 'select', 'label' => __('blox_main_distribute'), 'default' => 'start', 'tab' => 'style',
                'options' => ['start' => __('blox_align_start'), 'center' => __('blox_align_center'), 'end' => __('blox_align_end'), 'between' => __('blox_align_between'), 'around' => __('blox_flex_around'), 'evenly' => __('blox_flex_evenly')],
                'option_icons' => ['start' => 'align-left', 'center' => 'align-center', 'end' => 'align-right', 'between' => 'align-justified', 'around' => 'spacing-horizontal', 'evenly' => 'space']],
            ...$this->backgroundControls(),
            ['key' => 'padding', 'type' => 'select', 'label' => __('blox_padding'), 'default' => 'none', 'tab' => 'style', 'responsive' => true,
                'options' => ['none' => __('blox_spacing_none'), 'sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg'), 'xl' => __('blox_spacing_xl')]],
            ['key' => 'radius', 'type' => 'select', 'label' => __('blox_radius'), 'default' => 'none', 'tab' => 'style',
                'options' => ['none' => __('blox_spacing_none'), 'md' => __('blox_spacing_md'), 'xl' => __('blox_spacing_lg')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        // yk-container 是编辑态定位钩子（画布空容器占位用），前台无样式含义——与 yk-col-card 同例
        // $layout 单独成串：视频分支要把 flex 布局整体移交内容层（radius 留根），
        // 无视频路径的拼接顺序与历史逐字节一致（radius 仍最后追加）。
        $direction = $data['direction'] ?? 'column';
        $layout = 'flex ' . $this->resp($direction, self::DIRECTION_MAP, 'column');
        $wrap = $data['wrap'] ?? 'auto';
        if ($wrap === 'auto') {
            $wrapClass = $this->resp($direction, self::AUTO_WRAP_MAP, 'column');
            if ($wrapClass !== '') {
                $layout .= ' ' . $wrapClass;
            }
        } elseif ($wrap === 'wrap') {
            $layout .= ' flex-wrap';
        } elseif ($wrap === 'nowrap') {
            $layout .= ' flex-nowrap';
        }
        $gapClass = $this->resp($data['gap'] ?? 'md', self::GAP_MAP, 'md');
        if ($gapClass !== '') {
            $layout .= ' ' . $gapClass;
        }
        foreach ([
            self::ITEMS_MAP[$data['align'] ?? 'stretch'] ?? '',
            self::JUSTIFY_MAP[$data['justify'] ?? 'start'] ?? '',
            $this->resp($data['padding'] ?? 'none', self::PAD_MAP, 'none'),
        ] as $c) {
            if ($c !== '') {
                $layout .= ' ' . $c;
            }
        }
        $radiusCls = self::RADIUS_MAP[$data['radius'] ?? 'none'] ?? '';

        $video = self::backgroundVideoUrl($data);
        if ($video !== '') {
            $mobileVideoMode = ($data['bg_video_mobile_mode'] ?? 'poster') === 'video' ? 'video' : 'poster';
            $poster = self::cssImageUrl($data['bg_image'] ?? null);
            $posterAttr = $poster !== null
                ? ' poster="' . htmlspecialchars($poster, ENT_QUOTES) . '"'
                : '';
            // 三层结构（第 5 轮）：media/overlay 绝对定位不占流、pointer-events:none；
            // 遮罩在视频场景是 DOM 层，不再叠进背景图 gradient（避免双重压暗）；
            // 色/图仍作根元素底层，视频加载失败时兜底可读。
            $base = $data;
            unset($base['bg_overlay']);
            $style = '';
            $background = self::backgroundDeclarations($base);
            if ($background !== '') {
                $style = ' style="' . htmlspecialchars($background, ENT_QUOTES) . '"';
            }
            $alpha = self::backgroundOverlayAlpha($data['bg_overlay'] ?? null);
            $overlay = $alpha !== null
                ? '<div class="blox-bg-overlay" style="background:rgba(0,0,0,' . $alpha . ')"></div>'
                : '';
            return '<div class="yk-container blox-has-bg' . ($radiusCls !== '' ? ' ' . $radiusCls : '') . '"' . $style . '>'
                . '<div class="blox-bg-media" aria-hidden="true"><video muted loop playsinline preload="none" data-blox-background-video data-blox-mobile-video="'
                . $mobileVideoMode . '" data-blox-video-src="'
                . htmlspecialchars($video, ENT_QUOTES) . '"' . $posterAttr . '></video></div>'
                . $overlay
                . '<div class="blox-content ' . $layout . '">' . $children . '</div>'
                . '</div>';
        }

        $cls = 'yk-container ' . $layout . ($radiusCls !== '' ? ' ' . $radiusCls : '');
        $style = '';
        $background = self::backgroundDeclarations($data);
        if ($background !== '') {
            $style = ' style="' . htmlspecialchars($background, ENT_QUOTES) . '"';
        }
        return '<div class="' . $cls . '"' . $style . '>' . $children . '</div>';
    }
}
