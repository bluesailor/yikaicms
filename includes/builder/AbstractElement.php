<?php
/**
 * YikaiCMS 页面构建器 —— 元素抽象基类。
 *
 * 一个元素 = 一个类，声明元数据 + render()。取代原 renderBlocksToHtml 里写死的 switch，
 * 使元素可扩展、插件可注册（见 BuilderRegistry）。设计见 yikaicms-docs/design-page-builder.md。
 *
 * 迁移期红线：内置元素的 render() 输出必须与旧 renderBlocksToHtml 逐字节一致（黄金对拍锁定）。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/i18n/TextDirection.php';

require_once __DIR__ . '/BloxResponsiveValue.php';

abstract class AbstractElement
{
    /** 元素类型标识（对应 blocks_data 里 element.type） */
    abstract public function type(): string;

    /** 渲染前台 HTML。$data = element['data']；$children = 子元素渲染结果（容器类用） */
    abstract public function render(array $data, string $children = ''): string;

    /**
     * 带渲染上下文的扩展入口。普通元素保持调用 render()；需要掌管子模板的动态元素可覆盖。
     *
     * @param array<string,mixed> $context
     */
    public function renderWithContext(array $data, string $children = '', array $context = []): string
    {
        return $this->render($data, $children);
    }

    /**
     * 设置项 schema（后台构建器据此自动生成设置表单）。返回控件定义数组，每项：
     *   ['key'=>字段名, 'type'=>控件类型, 'label'=>标签, 'default'=>默认值, ...]
     * 控件类型：text/textarea/number/select/checkbox/color（通用，自动生成表单）；
     *           richtext/image/icon（富控件，由构建器专用编辑器接管，见 hasCustomUI）。
     * select 需 'options'=>['值'=>'显示', ...]；number 可 'min'/'max'；text 可 'placeholder'。
     * 无控件的元素返回 []。
     */
    public function controls(): array
    {
        return [];
    }

    /** Trusted render target within this element's HTML; null means the outer root. */
    public function compiledCssTargetTag(): ?string
    {
        return null;
    }

    /**
     * 子项在父级 flex 容器中的布局控件（0a 布局引擎）。
     *
     * order/flex-grow/flex-shrink/flex-basis 走声明式 CSS 引擎（留空即不输出，存量渲染不变）；
     * align_self 是枚举，由元素 render 用字面类映射输出。0a 先开放给布局节点
     * （container/div），递归容器落地后再推广到其余元素。
     *
     * @return list<array<string, mixed>>
     */
    protected function flexItemControls(): array
    {
        return [
            ['key' => 'align_self', 'type' => 'select', 'label' => __('blox_align_self'), 'default' => 'auto', 'tab' => 'style',
                'options' => ['auto' => __('blox_align_self_auto'), 'start' => __('blox_align_start'), 'center' => __('blox_align_center'), 'end' => __('blox_align_end'), 'stretch' => __('blox_align_stretch'), 'baseline' => __('blox_flex_align_baseline')],
                'option_icons' => ['auto' => 'ban', 'start' => 'layout-align-top', 'center' => 'layout-align-middle', 'end' => 'layout-align-bottom', 'stretch' => 'arrows-vertical', 'baseline' => 'align-box-bottom-center']],
            ['key' => 'order_n', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_flex_order'),
                'default' => '', 'tab' => 'style', 'responsive' => true, 'min' => -10, 'max' => 10, 'step' => 1,
                'css' => [['property' => 'order']]],
            ['key' => 'flex_grow', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_flex_grow'),
                'default' => '', 'tab' => 'style', 'min' => 0, 'max' => 10, 'step' => 1,
                'css' => [['property' => 'flex-grow']]],
            ['key' => 'flex_shrink', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_flex_shrink'),
                'default' => '', 'tab' => 'style', 'min' => 0, 'max' => 10, 'step' => 1,
                'css' => [['property' => 'flex-shrink']]],
            ['key' => 'flex_basis_px', 'type' => BloxCssCompiler::CONTROL_TYPE, 'label' => __('blox_flex_basis'),
                'default' => '', 'tab' => 'style', 'responsive' => true, 'min' => 0, 'max' => 1200, 'step' => 1, 'unit' => 'px',
                'css' => [['property' => 'flex-basis']]],
            // 0b Grid：作为网格子项时的跨列（父级非 grid 时这些类不生效，无害）。
            ['key' => 'grid_span', 'type' => 'select', 'label' => __('blox_grid_span'), 'default' => '', 'tab' => 'style', 'responsive' => true,
                'options' => ['' => __('blox_grid_span_auto'), '2' => '2', '3' => '3', '4' => '4', '6' => '6', 'full' => __('blox_grid_span_full')]],
        ];
    }

    /** align_self 枚举 → 字面类（Tailwind 扫描要求字面量；auto 无类，存量输出不变）。 */
    protected static function alignSelfClass(array $data): string
    {
        return ['start' => 'self-start', 'center' => 'self-center', 'end' => 'self-end',
            'stretch' => 'self-stretch', 'baseline' => 'self-baseline'][$data['align_self'] ?? 'auto'] ?? '';
    }

    // ── 0b Grid：容器列模板与子项跨列（类名字面量供 Tailwind 扫描） ──────────

    // 键用显式 int（数字字符串键会被 PHP 静默转 int，Psalm 按 int 键报字符串偏移非法）
    private const GRID_COLS_MOBILE_MAP = [
        1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3',
        4 => 'grid-cols-4', 6 => 'grid-cols-6', 12 => 'grid-cols-12',
    ];
    private const GRID_COLS_TABLET_MAP = [
        1 => 'md:grid-cols-1', 2 => 'md:grid-cols-2', 3 => 'md:grid-cols-3',
        4 => 'md:grid-cols-4', 6 => 'md:grid-cols-6', 12 => 'md:grid-cols-12',
    ];
    private const GRID_COLS_DESKTOP_MAP = [
        1 => 'lg:grid-cols-1', 2 => 'lg:grid-cols-2', 3 => 'lg:grid-cols-3',
        4 => 'lg:grid-cols-4', 6 => 'lg:grid-cols-6', 12 => 'lg:grid-cols-12',
    ];
    private const GRID_COLS_WIDE_MAP = [
        1 => 'wide:grid-cols-1', 2 => 'wide:grid-cols-2', 3 => 'wide:grid-cols-3',
        4 => 'wide:grid-cols-4', 6 => 'wide:grid-cols-6', 12 => 'wide:grid-cols-12',
    ];
    private const GRID_SPAN_MOBILE_MAP = [
        2 => 'col-span-2', 3 => 'col-span-3', 4 => 'col-span-4',
        6 => 'col-span-6', 'full' => 'col-span-full',
    ];
    private const GRID_SPAN_TABLET_MAP = [
        2 => 'md:col-span-2', 3 => 'md:col-span-3', 4 => 'md:col-span-4',
        6 => 'md:col-span-6', 'full' => 'md:col-span-full',
    ];
    private const GRID_SPAN_DESKTOP_MAP = [
        2 => 'lg:col-span-2', 3 => 'lg:col-span-3', 4 => 'lg:col-span-4',
        6 => 'lg:col-span-6', 'full' => 'lg:col-span-full',
    ];
    private const GRID_SPAN_WIDE_MAP = [
        2 => 'wide:col-span-2', 3 => 'wide:col-span-3', 4 => 'wide:col-span-4',
        6 => 'wide:col-span-6', 'full' => 'wide:col-span-full',
    ];

    /**
     * Grid 容器控件组。$modeKey = 触发 grid 的控件键（container 用 layout、div 用 display）。
     * @return list<array<string, mixed>>
     */
    protected function gridLayoutControls(string $modeKey): array
    {
        return [
            ['key' => 'grid_cols', 'type' => 'select', 'label' => __('blox_grid_cols'), 'default' => '3', 'tab' => 'style', 'responsive' => true,
                'required' => [$modeKey, '=', 'grid'],
                'options' => ['1' => '1', '2' => '2', '3' => '3', '4' => '4', '6' => '6', '12' => '12']],
            ['key' => 'grid_flow', 'type' => 'select', 'label' => __('blox_grid_flow'), 'default' => 'row', 'tab' => 'style',
                'required' => [$modeKey, '=', 'grid'],
                'options' => ['row' => __('blox_grid_flow_row'), 'dense' => __('blox_grid_flow_dense')]],
        ];
    }

    /**
     * grid_cols {d,t,m,w} → 列模板类。手机缺省单列（既定约束的延续），显式 m 值可覆盖；
     * t 缺省继承 d（t=d 时只出 md: 级联向上）；w 仅差异且宽屏档开启时输出。
     */
    protected static function gridColumnClasses(mixed $value): string
    {
        $raw = is_array($value) ? $value : ['d' => $value];
        $pick = static function (mixed $candidate, int $fallback): int {
            $candidate = is_numeric($candidate) ? (int) $candidate : 0;
            return isset(self::GRID_COLS_MOBILE_MAP[$candidate]) ? $candidate : $fallback;
        };
        $d = $pick($raw['d'] ?? null, 3);
        $t = $pick($raw['t'] ?? null, $d);
        $m = $pick($raw['m'] ?? null, 1);
        $w = $pick($raw['w'] ?? null, $d);
        $classes = self::GRID_COLS_MOBILE_MAP[$m] . ' ' . self::GRID_COLS_TABLET_MAP[$t];
        if ($t !== $d) {
            $classes .= ' ' . self::GRID_COLS_DESKTOP_MAP[$d];
        }
        if ($w !== $d && BloxResponsiveValue::wideEnabled()) {
            $classes .= ' ' . self::GRID_COLS_WIDE_MAP[$w];
        }
        return $classes;
    }

    /**
     * grid_span {d,t,m,w} → 子项跨列类。空值=自动（无类）；标量按 md: 起生效
     * （手机默认单列，跨列无意义），显式 m 值才输出基类。
     */
    protected static function gridItemSpanClasses(array $data): string
    {
        $value = $data['grid_span'] ?? '';
        $raw = is_array($value) ? $value : ['d' => $value];
        // '' = 自动（无类）；数字键为 int，'full' 保持字符串键
        $pick = static function (mixed $candidate, int|string $fallback): int|string {
            if (is_numeric($candidate)) {
                $candidate = (int) $candidate;
            }
            return ($candidate === 'full' || is_int($candidate)) && isset(self::GRID_SPAN_MOBILE_MAP[$candidate])
                ? $candidate
                : $fallback;
        };
        $d = $pick($raw['d'] ?? null, '');
        $t = $pick($raw['t'] ?? null, $d);
        $m = $pick($raw['m'] ?? null, '');
        $w = $pick($raw['w'] ?? null, $d);
        $classes = [];
        if ($m !== '') {
            $classes[] = self::GRID_SPAN_MOBILE_MAP[$m];
        }
        if ($t !== '') {
            $classes[] = self::GRID_SPAN_TABLET_MAP[$t];
        }
        if ($d !== '' && $d !== $t) {
            $classes[] = self::GRID_SPAN_DESKTOP_MAP[$d];
        }
        if ($w !== '' && $w !== $d && BloxResponsiveValue::wideEnabled()) {
            $classes[] = self::GRID_SPAN_WIDE_MAP[$w];
        }
        return implode(' ', $classes);
    }

    /**
     * 常用内容元素共享的入场动画设置。
     *
     * @return list<array<string, mixed>>
     */
    protected function animationControls(): array
    {
        return [
            [
                'key' => 'animation', 'type' => 'select', 'label' => __('blox_anim'), 'default' => '', 'tab' => 'style', 'group' => 'animation',
                'option_preview' => 'entrance',
                'help' => __('motion_policy_help'),
                'options' => [
                    '' => __('blox_anim_none'),
                    'fade' => __('blox_anim_fade'),
                    'fade-up' => __('blox_anim_fade_up'),
                    'fade-down' => __('blox_anim_fade_down'),
                    'fade-left' => __('blox_anim_fade_left'),
                    'fade-right' => __('blox_anim_fade_right'),
                    'zoom-in' => __('blox_anim_zoom'),
                ],
                'option_icons' => [
                    '' => 'ban',
                    'fade' => 'contrast',
                    'fade-up' => 'arrow-up',
                    'fade-down' => 'arrow-down',
                    'fade-left' => 'arrow-right',
                    'fade-right' => 'arrow-left',
                    'zoom-in' => 'zoom-in',
                ],
            ],
            [
                'key' => 'animation_trigger', 'type' => 'select', 'label' => __('blox_anim_trigger'),
                'default' => 'viewport', 'tab' => 'style', 'group' => 'animation',
                'options' => ['viewport' => __('blox_anim_trigger_viewport'), 'load' => __('blox_anim_trigger_load')],
                'required' => ['animation', '!=', ''],
            ],
            [
                'key' => 'animation_speed', 'type' => 'select', 'label' => __('blox_anim_speed'), 'default' => 'normal', 'tab' => 'style', 'group' => 'animation',
                'options' => ['normal' => __('blox_anim_normal'), 'fast' => __('blox_anim_fast'), 'slow' => __('blox_anim_slow')],
            ],
            [
                'key' => 'animation_delay', 'type' => 'select', 'label' => __('blox_anim_delay'), 'default' => 'none', 'tab' => 'style', 'group' => 'animation',
                'options' => ['none' => __('blox_anim_delay_none'), 'short' => __('blox_anim_delay_short'), 'medium' => __('blox_anim_delay_medium'), 'long' => __('blox_anim_delay_long')],
            ],
            ['key' => 'animation_device', 'type' => 'select', 'label' => __('motion_device'), 'default' => 'all', 'tab' => 'style', 'group' => 'animation',
                'options' => ['all' => __('motion_device_all'), 'desktop' => __('motion_device_desktop'), 'tablet' => __('motion_device_tablet'), 'mobile' => __('motion_device_mobile')]],
        ];
    }

    /**
     * 容器和 Div 共用分组入口，循环生成的卡片也直接复用。
     * @return list<array<string,mixed>>
     */
    protected function staggerControls(): array
    {
        return [
            ['key' => 'animation_stagger', 'type' => 'checkbox', 'label' => __('motion_stagger'), 'default' => false, 'tab' => 'style', 'group' => 'animation',
                'help' => __('motion_stagger_help')],
            ['key' => 'animation_speed', 'type' => 'select', 'label' => __('blox_anim_speed'), 'default' => 'normal', 'tab' => 'style', 'group' => 'animation',
                'required' => ['animation_stagger', '=', true],
                'options' => ['normal' => __('blox_anim_normal'), 'fast' => __('blox_anim_fast'), 'slow' => __('blox_anim_slow')]],
            ['key' => 'animation_device', 'type' => 'select', 'label' => __('motion_device'), 'default' => 'all', 'tab' => 'style', 'group' => 'animation',
                'required' => ['animation_stagger', '=', true],
                'options' => ['all' => __('motion_device_all'), 'desktop' => __('motion_device_desktop'), 'tablet' => __('motion_device_tablet'), 'mobile' => __('motion_device_mobile')]],
        ];
    }

    /** 关闭时逐字节保留旧输出；仅输出白名单属性。 */
    protected function staggerAttrs(array $data): string
    {
        if (!in_array($data['animation_stagger'] ?? false, [true, 1, '1'], true)) {
            return '';
        }
        BloxAssetCollector::addScript('/assets/js/scroll-anim.js');
        $attrs = ' data-stagger';
        if (in_array($data['animation_speed'] ?? '', ['fast', 'slow'], true)) {
            $attrs .= ' data-animate-speed="' . $data['animation_speed'] . '"';
        }
        if (in_array($data['animation_device'] ?? '', ['desktop', 'tablet', 'mobile'], true)) {
            $attrs .= ' data-animate-device="' . $data['animation_device'] . '"';
        }
        return $attrs;
    }

    /** 折叠高度选项（px）；空串 = 该设备不折叠 */
    private const COLLAPSE_HEIGHTS = ['' => 0, '120' => 120, '200' => 200, '300' => 300, '400' => 400, '500' => 500, '640' => 640, '800' => 800];
    private const COLLAPSE_BUTTON_STYLES = [
        'link' => 'inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:underline',
        'outline' => 'inline-flex items-center gap-1.5 rounded-full border border-current px-5 py-2 text-sm font-semibold text-primary hover:bg-primary/5',
        'solid' => 'inline-flex items-center gap-1.5 rounded-full bg-primary px-5 py-2 text-sm font-semibold text-white hover:opacity-90',
    ];
    private const COLLAPSE_ALIGN = ['center' => 'justify-center', 'left' => 'justify-start', 'right' => 'justify-end'];
    private static int $collapseSeq = 0;
    private static bool $collapseNoscriptSent = false;

    /**
     * 长内容折叠（容器 / Div 共用，2.0.3）：按设备设折叠高度，透明或颜色渐隐，展开/收起按钮样式与位置、动画。
     * @return list<array<string, mixed>>
     */
    protected function collapseControls(): array
    {
        $on = ['collapse', '=', true];
        $heights = ['' => __('blox_collapse_height_off')];
        foreach (array_keys(self::COLLAPSE_HEIGHTS) as $px) {
            if ($px !== '') {
                $heights[(string) $px] = $px . 'px';
            }
        }
        return [
            ['key' => 'collapse', 'type' => 'checkbox', 'label' => __('blox_collapse_enable'), 'default' => false, 'tab' => 'style', 'group' => 'collapse'],
            ['key' => 'collapse_height', 'type' => 'select', 'label' => __('blox_collapse_height'), 'default' => '300', 'tab' => 'style', 'group' => 'collapse',
                'responsive' => true, 'required' => $on, 'options' => $heights],
            ['key' => 'collapse_fade', 'type' => 'select', 'label' => __('blox_collapse_fade'), 'default' => 'mask', 'tab' => 'style', 'group' => 'collapse', 'required' => $on,
                'options' => ['mask' => __('blox_collapse_fade_mask'), 'color' => __('blox_collapse_fade_color'), 'none' => __('blox_collapse_fade_none')]],
            ['key' => 'collapse_fade_color', 'type' => 'color', 'label' => __('blox_collapse_fade_to'), 'default' => '#ffffff', 'tab' => 'style', 'group' => 'collapse',
                'required' => ['collapse_fade', '=', 'color']],
            ['key' => 'collapse_more_text', 'type' => 'text', 'label' => __('blox_collapse_more'), 'default' => __('blox_collapse_more_default'), 'tab' => 'style', 'group' => 'collapse', 'required' => $on],
            ['key' => 'collapse_less_text', 'type' => 'text', 'label' => __('blox_collapse_less'), 'default' => __('blox_collapse_less_default'), 'tab' => 'style', 'group' => 'collapse', 'required' => $on],
            ['key' => 'collapse_button_style', 'type' => 'select', 'label' => __('blox_collapse_button_style'), 'default' => 'link', 'tab' => 'style', 'group' => 'collapse', 'required' => $on,
                'options' => ['link' => __('blox_collapse_style_link'), 'outline' => __('blox_collapse_style_outline'), 'solid' => __('blox_collapse_style_solid')]],
            ['key' => 'collapse_button_align', 'type' => 'select', 'label' => __('blox_collapse_button_align'), 'default' => 'center', 'tab' => 'style', 'group' => 'collapse', 'required' => $on,
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')]],
            ['key' => 'collapse_icon', 'type' => 'checkbox', 'label' => __('blox_collapse_icon'), 'default' => true, 'tab' => 'style', 'group' => 'collapse', 'required' => $on],
            ['key' => 'collapse_animate', 'type' => 'checkbox', 'label' => __('blox_collapse_animate'), 'default' => true, 'tab' => 'style', 'group' => 'collapse', 'required' => $on],
        ];
    }

    /**
     * 折叠开启且至少一个设备有高度时，给出渲染所需的几段：根元素追加的类 / 属性 / 样式变量、内层 id、按钮 HTML。
     * 结构：根（背景、内边距、圆角、在父级中的布局）> 内层 .yk-collapse-body（子项布局 + 裁切 + 渐隐）+ 按钮。
     * 内容始终完整输出（搜索引擎可见）；未开启时返回 null，元素输出与旧版逐字节一致。
     *
     * @return null|array{class:string,attrs:string,style:string,body_id:string,toggle:string}
     */
    protected static function collapseParts(array $data): ?array
    {
        if (!in_array($data['collapse'] ?? false, [true, 1, '1'], true)) {
            return null;
        }
        $heights = BloxResponsiveValue::normalize($data['collapse_height'] ?? '300', self::COLLAPSE_HEIGHTS, '300');
        $vars = [];
        $any = false;
        foreach (['m', 't', 'd', 'w'] as $bp) {
            $px = self::COLLAPSE_HEIGHTS[(string) $heights[$bp]] ?? 0;
            $any = $any || $px > 0;
            $vars[] = '--ykc-h-' . $bp . ':' . ($px > 0 ? $px . 'px' : 'none');
        }
        if (!$any) {
            return null;
        }
        $fade = $data['collapse_fade'] ?? 'mask';
        $fade = in_array($fade, ['mask', 'color', 'none'], true) ? $fade : 'mask';
        if ($fade === 'color') {
            $vars[] = '--ykc-fade:' . (self::cssColor($data['collapse_fade_color'] ?? null) ?? '#ffffff');
        }
        $id = 'ykc-' . substr(md5((string) json_encode($data)), 0, 8) . '-' . (++self::$collapseSeq);
        $clip = static fn (mixed $v, string $fallback): string => mb_substr(trim(is_scalar($v) ? (string) $v : ''), 0, 40) ?: $fallback;
        $more = $clip($data['collapse_more_text'] ?? '', __('blox_collapse_more_default'));
        $less = $clip($data['collapse_less_text'] ?? '', __('blox_collapse_less_default'));
        $style = self::COLLAPSE_BUTTON_STYLES[(string) ($data['collapse_button_style'] ?? 'link')] ?? self::COLLAPSE_BUTTON_STYLES['link'];
        $align = self::COLLAPSE_ALIGN[(string) ($data['collapse_button_align'] ?? 'center')] ?? 'justify-center';
        $icon = !in_array($data['collapse_icon'] ?? true, [false, 0, '0'], true)
            ? '<i class="ti ti-chevron-down transition-transform" data-yk-collapse-icon aria-hidden="true"></i>'
            : '';
        $h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // 按钮先隐藏：内容本来就不超过折叠高度时，脚本不会让它出现
        $toggle = '<div class="yk-collapse-toggle mt-4 flex ' . $align . '" data-yk-collapse-toggle hidden>'
            . '<button type="button" class="' . $style . '" aria-expanded="false" aria-controls="' . $id . '"'
            . ' data-yk-collapse-more="' . $h($more) . '" data-yk-collapse-less="' . $h($less) . '">'
            . '<span data-yk-collapse-label>' . $h($more) . '</span>' . $icon . '</button></div>';
        if (!self::$collapseNoscriptSent) {
            // 不跑脚本的访客（或脚本加载失败）：不裁切，完整显示
            self::$collapseNoscriptSent = true;
            $toggle .= '<noscript><style>.yk-collapse>.yk-collapse-body{max-height:none!important;-webkit-mask-image:none!important;mask-image:none!important}.yk-collapse>.yk-collapse-body::after{display:none!important}</style></noscript>';
        }
        $animate = !in_array($data['collapse_animate'] ?? true, [false, 0, '0'], true);
        BloxAssetCollector::addScript('/assets/js/yikay-collapse.js');
        BloxAssetCollector::addStyle('/assets/css/yikay-collapse.css');
        return [
            'class' => 'yk-collapse',
            'attrs' => ' data-yk-collapse data-yk-collapse-state="collapsed" data-yk-collapse-fade="' . $fade . '"' . ($animate ? ' data-yk-collapse-animate' : ''),
            'style' => implode(';', $vars),
            'body_id' => $id,
            'toggle' => $toggle,
        ];
    }

    /** 根元素样式：背景声明后接折叠高度变量；未折叠时原样返回（历史输出不变）。 */
    protected static function collapseStyle(string $declarations, ?array $collapse): string
    {
        if ($collapse === null || $collapse['style'] === '') {
            return $declarations;
        }
        $declarations = trim($declarations);
        return ($declarations !== '' ? rtrim($declarations, ';') . ';' : '') . $collapse['style'];
    }

    /** 将动画设置转成安全的 data 属性；无动画时不改变历史 HTML。 */
    protected function animationAttrs(array $data): string
    {
        $animation = is_string($data['animation'] ?? null) ? $data['animation'] : '';
        if (!in_array($animation, ['fade', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'zoom-in'], true)) {
            return '';
        }

        BloxAssetCollector::addScript('/assets/js/scroll-anim.js');
        $attrs = ' data-animate="' . $animation . '"';
        if (in_array($data['animation_device'] ?? '', ['desktop', 'tablet', 'mobile'], true)) $attrs .= ' data-animate-device="' . $data['animation_device'] . '"';
        $trigger = ($data['animation_trigger'] ?? 'viewport') === 'load' ? 'load' : 'viewport';
        $attrs .= ' data-animate-trigger="' . $trigger . '"';
        $speed = is_string($data['animation_speed'] ?? null) ? $data['animation_speed'] : 'normal';
        if (in_array($speed, ['fast', 'slow'], true)) {
            $attrs .= ' data-animate-speed="' . $speed . '"';
        }
        $delay = is_string($data['animation_delay'] ?? null) ? $data['animation_delay'] : 'none';
        if (in_array($delay, ['short', 'medium', 'long'], true)) {
            $attrs .= ' data-animate-delay="' . $delay . '"';
        }
        return $attrs;
    }

    /**
     * CSS 长度值白名单校验（间距精确输入用）。
     *
     * 只放行：数字+单位（px/rem/em/%/vw/vh，最多 4 位整数 2 位小数）、0，
     * margin 另允许负值与 auto。这是安全边界——值会拼入 style 属性，
     * 黑名单挡不住 calc()/expression()/注释等注入，必须白名单。
     * 不合法返回 null（调用方静默忽略，不输出）。
     */
    public static function cssLength(string $value, bool $allowNegative, bool $allowAuto): ?string
    {
        $value = trim($value);
        if ($value === '0') {
            return '0';
        }
        if ($allowAuto && $value === 'auto') {
            return 'auto';
        }
        $sign = $allowNegative ? '-?' : '';
        return preg_match('/^' . $sign . '\d{1,4}(\.\d{1,2})?(px|rem|em|%|vw|vh)$/', $value) ? $value : null;
    }

    /**
     * 可安全写入 style 声明的颜色值。
     *
     * 除颜色控件现有的 hex 外，保留合法 rgb/hsl 与站点颜色变量兼容；锚定白名单
     * 明确拒绝分号、注释和额外声明。返回 null 表示不输出该样式。
     */
    public static function cssColor(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1) {
            return strtolower($value);
        }
        if (in_array(strtolower($value), ['transparent', 'currentcolor'], true)) {
            return strtolower($value);
        }
        if (preg_match('/^(?:rgb|rgba|hsl|hsla)\([0-9.,%+\-\s]+\)$/i', $value) === 1) {
            return $value;
        }
        return preg_match('/^var\(--(?:yk-color|color)-[a-z0-9_-]+\)$/i', $value) === 1 ? $value : null;
    }

    /**
     * 可安全写入 href 的链接地址（与全局 safeUrl() 同一套语义，引擎自包含副本）。
     *
     * htmlspecialchars 只能防属性逃逸，防不了 javascript: 伪协议——它转义后
     * 仍是可点击执行的存储型 XSS。允许：站内相对路径（排除协议相对 //）、
     * 锚点、查询串、http(s)、mailto/tel。不合法返回空串，调用方据此不渲染链接。
     */
    public static function safeHref(mixed $value): string
    {
        // v1.18.6 起委托 UrlPolicy（builder/bootstrap.php 已加载）；
        // 循环占位符豁免语义保留（{yk:field name=x /} 整串精确匹配）
        return UrlPolicy::href($value, true, true);
    }

    /** 可选的链接关系（受控 rel）；noopener / noreferrer 由新窗口规则自动加，不让作者选。 */
    public const LINK_REL_OPTIONS = ['nofollow', 'sponsored', 'ugc'];

    /**
     * 链接的共用附加控件（V2.0.0：Heading / Button / Image 一致）：rel、title、aria-label。
     *
     * @param array<string,mixed> $extra 追加到每个控件上的键（visible_when、section 等）
     * @return list<array<string,mixed>>
     */
    protected function linkAttributeControls(array $extra = []): array
    {
        $options = ['' => __('blox_link_rel_none')];
        foreach (self::LINK_REL_OPTIONS as $rel) {
            $options[$rel] = $rel;
        }
        return [
            ['key' => 'link_rel', 'type' => 'select', 'label' => __('blox_link_rel'), 'default' => '', 'options' => $options] + $extra,
            ['key' => 'link_title', 'type' => 'text', 'label' => __('blox_link_title'), 'default' => ''] + $extra,
            ['key' => 'link_aria_label', 'type' => 'text', 'label' => __('blox_link_aria_label'), 'default' => ''] + $extra,
        ];
    }

    /**
     * 派生元素（商品/文章字段）删掉委托元素的部分控件后，把显示规则引用了已删除 key 的控件一并去掉，
     * 例如标题的链接 rel/title/aria-label 在没有 url 控件的「商品标题」上没有意义。
     *
     * @param list<array<string,mixed>> $controls
     * @return list<array<string,mixed>>
     */
    protected static function withoutOrphanedRules(array $controls): array
    {
        $keys = array_map(static fn(array $control): string => (string) ($control['key'] ?? ''), $controls);
        return array_values(array_filter($controls, static function (array $control) use ($keys): bool {
            foreach (($control['visible_when']['terms'] ?? []) as $term) {
                if (is_array($term) && isset($term[0]) && !in_array((string) $term[0], $keys, true)) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * 链接 <a> 上 href 之后的属性串（三类元素共用）。$href 必须已过 safeHref。
     * 新窗口一律带 noopener，外链（http(s):// 或 //）再加 noreferrer；rel 只接受白名单值；
     * title / aria-label 转义并限长，留空不输出。
     *
     * @param array<string,mixed> $data
     */
    public static function linkAttributes(string $href, mixed $newTab, array $data): string
    {
        $attributes = '';
        $rel = [];
        $choice = $data['link_rel'] ?? '';
        if (is_string($choice) && in_array($choice, self::LINK_REL_OPTIONS, true)) {
            $rel[] = $choice;
        }
        if (BloxValueSanitizer::truthy($newTab)) {
            $attributes .= ' target="_blank"';
            $rel[] = 'noopener';
            if (preg_match('#^(?:https?:)?//#i', $href) === 1) {
                $rel[] = 'noreferrer';
            }
        }
        if ($rel !== []) {
            $attributes .= ' rel="' . implode(' ', $rel) . '"';
        }
        foreach (['link_title' => 'title', 'link_aria_label' => 'aria-label'] as $key => $attribute) {
            $value = $data[$key] ?? '';
            $value = is_string($value) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '') : '';
            if ($value !== '') {
                $attributes .= ' ' . $attribute . '="' . htmlspecialchars(mb_substr($value, 0, 200), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }
        }
        return $attributes;
    }

    /**
     * 可用于 CSS background-image 的图片地址。
     *
     * 仅允许站内绝对路径与 http(s)；协议相对、data/javascript 及控制字符一律拒绝。
     */
    public static function cssImageUrl(mixed $value): ?string
    {
        $url = UrlPolicy::image($value);
        return $url === '' ? null : $url;
    }

    /** 把已校验 URL 编码成不会逃出 url() 的 CSS 字符串。 */
    public static function cssUrlLiteral(string $url): string
    {
        return 'url(' . json_encode(
            $url,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        ) . ')';
    }

    /** 遮罩档位 → linear-gradient 的 alpha（第 4 轮：CSS 多背景层，遮罩不占 DOM） */
    private const BG_OVERLAY_ALPHA = ['40' => '.4', '60' => '.6', '80' => '.8'];

    /**
     * 共享背景值解析（通用背景契约 2026-09-02）：清洗并生成可安全拼入 style 属性的
     * 背景 CSS 声明串。标量值——{d,t,m} 响应式数组明确拒绝：内联样式派生不出
     * md:/lg: 变体，响应式只属于类映射通路（respClasses）。
     * 第 4 轮起支持背景图与遮罩：遮罩以 linear-gradient 作为多背景层叠在图上
     *（不新增 DOM），只在设了背景图时生效；图默认 cover/center。
     * 无有效值返回 ''，调用方据此不输出 style 属性。
     */
    public static function backgroundDeclarations(array $data): string
    {
        $decl = '';
        $color = self::cssColor($data['bg_color'] ?? null);
        if ($color !== null) {
            $decl .= 'background-color:' . $color . ';';
        }
        $image = self::cssImageUrl($data['bg_image'] ?? null);
        if ($image !== null && $image !== '') {
            $alpha = self::backgroundOverlayAlpha($data['bg_overlay'] ?? null);
            $layer = self::cssUrlLiteral($image);
            if ($alpha !== null) {
                $rgba = 'rgba(0,0,0,' . $alpha . ')';
                $layer = 'linear-gradient(' . $rgba . ',' . $rgba . '),' . $layer;
            }
            $decl .= 'background-image:' . $layer . ';background-size:cover;background-position:center;';
        }
        return $decl;
    }

    /**
     * 背景视频直链校验（第 5 轮）：合法地址 + 视频扩展名，二者缺一返回 ''。
     * 平台链接（YouTube/Vimeo 等）不作背景——iframe 背景涉及自动播放与第三方策略，明确暂缓。
     * 校验口径与 VideoElement 的直链分支一致。
     */
    public static function backgroundVideoUrl(array $data): string
    {
        $raw = $data['bg_video'] ?? '';
        if (!is_string($raw)) {
            return '';
        }
        $url = trim($raw);
        if ($url === '') {
            return '';
        }
        $safe = self::safeHref($url);
        $path = strtolower((string) parse_url($safe, PHP_URL_PATH));
        if ($safe === '' || preg_match('/\.(mp4|webm|ogg|ogv|mov|m4v)$/', $path) !== 1) {
            return '';
        }
        return $safe;
    }

    /**
     * 遮罩档位 → alpha；图片 gradient 与视频 DOM 层遮罩共用同一映射。
     * 注意 PHP 会把数字字符串数组键强转 int（'40' => 键 40），故先正则白名单再显式转型。
     */
    public static function backgroundOverlayAlpha(mixed $key): ?string
    {
        if (!is_string($key) || preg_match('/^(?:40|60|80)$/', $key) !== 1) {
            return null;
        }
        return self::BG_OVERLAY_ALPHA[(int) $key];
    }

    /** 是否在共享背景组里提供背景视频（第 5 轮：默认关，首批仅 Container 开启） */
    protected function backgroundVideoEnabled(): bool
    {
        return false;
    }

    /** 是否提供设计系统的圆角 / 阴影 token 选项（2.0.4：容器与布局块） */
    public function supportsDesignScale(): bool
    {
        return false;
    }

    /**
     * 圆角 / 阴影 token 选择（选项是站点自己的 token）。选了圆角 token 时它覆盖上面的圆角档位。
     *
     * @return list<array<string,mixed>>
     */
    protected function designScaleControls(): array
    {
        if (!$this->supportsDesignScale()) {
            return [];
        }
        return [
            ['key' => 'radius_token', 'type' => 'select', 'label' => __('blox_radius_token'), 'default' => '', 'tab' => 'style',
                'options' => ['' => __('blox_scale_token_none')] + BloxDesignSystem::scaleOptions('radius'),
                'help' => __('blox_radius_token_help')],
            ['key' => 'shadow_token', 'type' => 'select', 'label' => __('blox_shadow_token'), 'default' => '', 'tab' => 'style',
                'options' => ['' => __('blox_scale_token_none')] + BloxDesignSystem::scaleOptions('shadow')],
        ];
    }

    /** 圆角 / 阴影 token → 根标签内联声明（var 带回退）；未设置返回 ''。 */
    public static function designScaleDeclarations(array $data): string
    {
        $css = '';
        $radius = BloxDesignScale::cssVar('radius', $data['radius_token'] ?? null, '0');
        if ($radius !== null) {
            $css .= 'border-radius:' . $radius . ';';
        }
        $shadow = BloxDesignScale::cssVar('shadow', $data['shadow_token'] ?? null, 'none');
        if ($shadow !== null) {
            $css .= 'box-shadow:' . $shadow . ';';
        }
        return $css;
    }

    /** 是否提供极光动态背景（2.0.4：容器与布局块） */
    public function supportsAurora(): bool
    {
        return false;
    }

    /** 盒模型间距：数据键 => [css 属性, 是否外边距, 响应式类缩写]。总值在前、四边在后（四边覆盖总值）。 */
    private const BOX_FIELDS = [
        'style_margin'        => ['margin', true, 'm'],
        'style_margin_top'    => ['margin-top', true, 'mt'],
        // 左右按起始/结束输出（TextDirection）：LTR 与原来一致，阿拉伯语等 RTL 页面自动换边
        'style_margin_right'  => ['margin-inline-end', true, 'me'],
        'style_margin_bottom' => ['margin-bottom', true, 'mb'],
        'style_margin_left'   => ['margin-inline-start', true, 'ms'],
        'style_padding'        => ['padding', false, 'p'],
        'style_padding_top'    => ['padding-top', false, 'pt'],
        'style_padding_right'  => ['padding-inline-end', false, 'pe'],
        'style_padding_bottom' => ['padding-bottom', false, 'pb'],
        'style_padding_left'   => ['padding-inline-start', false, 'ps'],
    ];
    private const BOX_SIZES = ['none' => '0', 'xs' => '0.25rem', 'sm' => '0.5rem', 'md' => '1rem', 'lg' => '2rem', 'xl' => '4rem', 'auto' => 'auto'];
    /** 各档的屏幕范围（与 BloxResponsiveValue::TIERS 一致）：手机 <768、平板 768–1023、桌面 ≥1024、宽屏 ≥1440（排在桌面之后覆盖它） */
    private const BOX_MEDIA = [
        'm' => '@media not all and (min-width:768px)',
        't' => '@media (min-width:768px) and (max-width:1023.98px)',
        'd' => '@media (min-width:1024px)',
        'w' => '@media (min-width:1440px)',
    ];

    /** 单个取值（档位或精确值）→ css 值；不合法返回 null。auto 档仅外边距可用。 */
    private static function boxValue(mixed $value, bool $isMargin): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if (isset(self::BOX_SIZES[$value])) {
            return !$isMargin && $value === 'auto' ? null : self::BOX_SIZES[$value];
        }
        // 精确输入：白名单校验（margin 允负值/auto，padding 非负）
        return self::cssLength($value, $isMargin, $isMargin);
    }

    /**
     * 通用盒模型间距（2.0.4 起四档响应式）。
     * - 取值为字符串：所有屏幕相同，输出内联声明（与改版前逐字一致，存量页面与缓存不变）。
     * - 取值为 {d,t,m,w}：平板继承桌面、手机继承平板、宽屏继承桌面；各档相同仍走内联；
     *   不同则每档输出一个内联变量 + 只在该档屏幕范围生效的类（规则见 responsiveBoxStylesheet，带 !important）。
     *   同一类（外边距或内边距）里只要有响应式取值，该类的字符串取值也改走类，保证「四边覆盖总值」的顺序不被内联打乱。
     *
     * @return array{style:string,classes:list<string>}
     */
    public static function boxSpacing(array $data): array
    {
        $responsiveKind = ['margin' => false, 'padding' => false];
        foreach (self::BOX_FIELDS as $key => [, $isMargin]) {
            if (is_array($data[$key] ?? null)) {
                $tiers = self::boxTiers($data[$key], $isMargin);
                if ($tiers !== null && count(array_unique(array_map('strval', $tiers))) > 1) {
                    $responsiveKind[$isMargin ? 'margin' : 'padding'] = true;
                }
            }
        }
        $style = '';
        $classes = [];
        foreach (self::BOX_FIELDS as $key => [$property, $isMargin, $abbr]) {
            $raw = $data[$key] ?? null;
            if (!$responsiveKind[$isMargin ? 'margin' : 'padding']) {
                $value = is_array($raw) ? (self::boxTiers($raw, $isMargin)['d'] ?? null) : self::boxValue($raw, $isMargin);
                if ($value !== null) {
                    $style .= $property . ':' . $value . '!important;';
                }
                continue;
            }
            $tiers = self::boxTiers(is_string($raw) ? ['d' => $raw] : $raw, $isMargin);
            if ($tiers === null) {
                continue;
            }
            foreach (['m', 't', 'd', 'w'] as $device) {
                $value = $tiers[$device];
                if ($value === null || ($device === 'w' && $value === $tiers['d'])) {
                    continue;
                }
                $style .= '--yk-sp-' . $abbr . '-' . $device . ':' . $value . ';';
                $classes[] = 'yk-sp-' . $abbr . '-' . $device;
            }
        }
        return ['style' => $style, 'classes' => $classes];
    }

    /**
     * 四档取值（已换成 css 值；null = 该档不设）。全空或不是数组返回 null。
     * @return array{m:?string,t:?string,d:?string,w:?string}|null
     */
    private static function boxTiers(mixed $raw, bool $isMargin): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $d = self::boxValue($raw['d'] ?? null, $isMargin);
        $t = self::boxValue($raw['t'] ?? null, $isMargin) ?? $d;
        $m = self::boxValue($raw['m'] ?? null, $isMargin) ?? $t;
        $w = BloxResponsiveValue::wideEnabled() ? (self::boxValue($raw['w'] ?? null, $isMargin) ?? $d) : $d;
        if ($d === null && $t === null && $m === null && $w === null) {
            return null;
        }
        return ['m' => $m, 't' => $t, 'd' => $d, 'w' => $w];
    }

    /**
     * 响应式间距的固定规则（编进 app.css，与此逐字一致，由单测校验）。每档一组媒体查询，组内总值在前、四边在后。
     * @api Build-time stylesheet contract, not a per-request renderer.
     */
    public static function responsiveBoxStylesheet(): string
    {
        $css = '';
        foreach (self::BOX_MEDIA as $device => $media) {
            $rules = '';
            foreach (self::BOX_FIELDS as [$property, , $abbr]) {
                $class = 'yk-sp-' . $abbr . '-' . $device;
                $rules .= '.' . $class . '{' . $property . ':var(--' . $class . ')!important}';
            }
            $css .= $media . '{' . $rules . '}';
        }
        return $css;
    }

    /** 由 controls() 推导默认 data（后台新增元素用） */
    public function defaults(): array
    {
        $d = [];
        foreach ($this->controls() as $c) {
            if (isset($c['key'])) {
                $d[$c['key']] = $c['default'] ?? '';
            }
        }
        if ($this->isContainer()) {
            $d['children'] = $this->defaultChildren();
        }
        return $d;
    }

    /** 后台显示名 */
    public function label(): string
    {
        return $this->type();
    }

    /** 分类（basic / media / dynamic / layout…），供后台 palette 分组 */
    public function category(): string
    {
        return 'basic';
    }

    /** Tabler 图标名（后台 palette 用），无则空 */
    public function icon(): string
    {
        return '';
    }

    /** 是否动态元素（渲染时拉实时数据，view-time 渲染 + 缓存策略据此区分；P1-E 用） */
    public function isDynamic(): bool
    {
        return false;
    }

    /** 是否容器元素（data.children 存子元素，渲染器据此递归、编辑器据此显示嵌套树） */
    public function isContainer(): bool
    {
        return false;
    }

    /** 容器是否自行渲染 data.children；默认仍由 BlockRenderer 递归。 */
    public function rendersOwnChildren(): bool
    {
        return false;
    }

    /** 是否显示在指定编辑器场景的根级元素库；内部子元素可返回 false。 */
    public function paletteVisible(string $context = 'page'): bool
    {
        return true;
    }

    /**
     * 容器可直接接收的元素类型。`*` 表示任意可作为通用子元素的非容器元素。
     *
     * @return list<string>
     */
    public function allowedChildren(array $data = []): array
    {
        return [];
    }

    /**
     * 由父元素数据决定的子元素规则，供浏览器端复现 allowedChildren()。
     *
     * @return list<array{field:string,operator:string,value:mixed,allowedChildren:list<string>}>
     */
    public function childRules(): array
    {
        return [];
    }

    /** @return list<array<string,mixed>> */
    public function defaultChildren(): array
    {
        return [];
    }

    /** `allowedChildren = ['*']` 时是否允许作为通用叶子节点。 */
    public function canBeGenericChild(): bool
    {
        return true;
    }

    /** 是否在编辑器样式面板显示通用 margin / padding 盒模型。 */
    /**
     * 元素级交互状态（BloxStateStyles）作用到哪里：返回作用域类之后的目标选择器（如 ' a'），null 表示不支持。
     * 支持的元素同时在 controls() 里放 BloxStateStyles::controls()。
     */
    public function stateStyleTarget(): ?string
    {
        return null;
    }

    public function supportsBoxStyles(): bool
    {
        return true;
    }

    /**
     * 背景渲染策略（通用背景契约 2026-09-02 第 1 轮）：
     * - 'none'：不支持通用背景（默认）；
     * - 'native'：元素在自己的 render() 里决定背景写到哪个标签，值必须来自
     *   backgroundDeclarations()，不得自行拼接清洗；
     * - 'root'：保留值——渲染完成后由 BlockRenderer 注入首标签。注入分支随
     *   第一个使用该策略的元素一起落地，避免无消费者的死路径。
     * 字符串而非 enum：composer 承诺 php >= 8.0。
     *
     * @psalm-suppress PossiblyUnusedMethod 本轮调用方在 tests/（psalm.xml 未纳入）：
     *   BloxBackgroundStyleTest 的契约一致性测试；渲染侧消费者随 'root' 首个元素引入。
     */
    public function backgroundRenderStrategy(): string
    {
        return 'none';
    }

    /**
     * 通用背景控件组（策略非 'none' 的元素在 controls() 里展开；首版仅背景色）。
     * 键名 bg_color 与存量文档一致。必须经 controls() 声明、不得做面板旁路注入——
     * BloxDocumentPipeline 以 controls() 为合法键登记处，BloxUnknownKeys 的目标
     * 策略是丢弃未声明键，旁路键未来会被当成未知键剥掉。
     *
     * @return list<array<string, mixed>>
     */
    protected function backgroundControls(): array
    {
        $imageControl = ['key' => 'bg_image', 'type' => 'image', 'label' => __('blox_bg_image'), 'default' => '', 'tab' => 'style', 'group' => 'background'];
        if ($this->backgroundVideoEnabled()) {
            $imageControl['help'] = __('blox_bg_video_poster_help');
        }
        return [
            ['key' => 'bg_color', 'type' => 'color', 'label' => __('blox_bg_color'), 'default' => '', 'tab' => 'style', 'group' => 'background'],
            $imageControl,
            // 遮罩叠在背景图上提升文字可读性；未设图时无意义，隐藏（渲染端同样只在有图时生效）
            ['key' => 'bg_overlay', 'type' => 'select', 'label' => __('blox_bg_overlay'), 'default' => '', 'tab' => 'style', 'group' => 'background',
                'options' => ['' => __('blox_bg_overlay_none'), '40' => __('blox_bg_overlay_light'), '60' => __('blox_bg_overlay_medium'), '80' => __('blox_bg_overlay_heavy')],
                // 显示规则只引用本元素真实存在的键（SchemaContract 强制）：视频键仅在开启视频时纳入
                'visible_when' => ['relation' => 'or', 'terms' => $this->backgroundVideoEnabled()
                    ? [['bg_image', 'not_empty'], ['bg_video', 'not_empty']]
                    : [['bg_image', 'not_empty']]]],
            ...($this->backgroundVideoEnabled() ? [
                ['key' => 'bg_video', 'type' => 'video_url', 'label' => __('blox_bg_video'), 'default' => '', 'tab' => 'style', 'group' => 'background',
                    'help' => __('blox_bg_video_help')],
                ['key' => 'bg_video_mobile_mode', 'type' => 'select', 'label' => __('blox_bg_video_mobile_mode'), 'default' => 'poster', 'tab' => 'style', 'group' => 'background',
                    'options' => ['poster' => __('blox_bg_video_mobile_poster'), 'video' => __('blox_bg_video_mobile_play')],
                    'visible_when' => ['terms' => [['bg_video', 'not_empty']]],
                    'help' => __('blox_bg_video_mobile_help')],
            ] : []),
            ...($this->supportsAurora() ? BloxAurora::controls() : []),
        ];
    }

    /** @return list<string> 元素前台运行所需的本地脚本路径。 */
    public function scripts(): array
    {
        return [];
    }

    /** @return list<string> 元素前台运行所需的本地样式路径。 */
    public function styles(): array
    {
        return [];
    }

    /** @param array<string,mixed> $data @return list<string> */
    public function scriptsFor(array $data): array
    {
        return $this->scripts();
    }

    /** @param array<string,mixed> $data @return list<string> */
    public function stylesFor(array $data): array
    {
        unset($data);
        return $this->styles();
    }

    /**
     * 元素直接读取的站点设置及其可移植性（2.0.3）：
     * - 'portable'：随整站模板导出（导出白名单里必须有这个精确键），为空时元素没有内容可显示；
     * - 'optional'：同样随包导出，但元素有内置兜底（为空也能正常显示），导出检查不提示；
     * - 'site'：属于装模板的那个站点（如备案号），不随包导出，导入后需在目标站填写。
     * 导出检查据此提示「用到了但为空 / 需在目标站填写」；契约测试保证元素里
     * 读取的每个设置键都已在这里声明（BloxElementSettingDependencyTest）。
     * 样式、脚本与图标依赖不在这里：沿用 stylesFor()/scriptsFor() 与 BloxIcon。
     *
     * @return array<string,'portable'|'optional'|'site'>
     */
    public function settingDependencies(): array
    {
        return [];
    }

    /** 结构树优先读取的数据字段；null 时使用编辑器的通用文本字段。 */
    public function treeLabelField(): ?string
    {
        return null;
    }

    /**
     * 复合元素的命名区域（结构树把每个区域渲染为根节点下可选中、可展开的子节点）。
     *
     * 区域是 schema 级声明：不写入文档 data，因此旧文档不产生 dirty、不需要迁移，
     * 也不能被 BloxDocumentValidator 当作 children 校验。`keys` 决定选中区域时
     * 设置面板只显示哪些控件；未列入任何区域的控件在根节点下始终可见。
     *
     * @return list<array{key:string,label:string,icon:string,keys:list<string>}>
     */
    public function regions(): array
    {
        return [];
    }

    /** 已废弃元素仍可渲染已有数据，但不应再允许新增。 */
    public function deprecated(): bool
    {
        return false;
    }

    /** 返回动态内容网格的 1–8 列响应式类，类名保持字面量以供 Tailwind 扫描。 */
    public static function gridClasses(int $columns, int $default = 4, bool $singleOnMobile = false): string
    {
        $columns = max(1, min(8, $columns > 0 ? $columns : $default));
        if ($singleOnMobile) {
            return [
                1 => 'grid-cols-1',
                2 => 'grid-cols-1 md:grid-cols-2',
                3 => 'grid-cols-1 md:grid-cols-3',
                4 => 'grid-cols-1 md:grid-cols-4',
                5 => 'grid-cols-1 md:grid-cols-3 lg:grid-cols-5',
                6 => 'grid-cols-1 md:grid-cols-3 lg:grid-cols-6',
                7 => 'grid-cols-1 md:grid-cols-4 lg:grid-cols-7',
                8 => 'grid-cols-1 md:grid-cols-4 lg:grid-cols-8',
            ][$columns];
        }
        return [
            1 => 'grid-cols-1',
            2 => 'grid-cols-1 sm:grid-cols-2',
            3 => 'grid-cols-2 md:grid-cols-3',
            4 => 'grid-cols-2 md:grid-cols-3 lg:grid-cols-4',
            5 => 'grid-cols-2 md:grid-cols-3 lg:grid-cols-5',
            6 => 'grid-cols-2 md:grid-cols-4 lg:grid-cols-6',
            7 => 'grid-cols-2 md:grid-cols-4 lg:grid-cols-7',
            8 => 'grid-cols-2 md:grid-cols-4 lg:grid-cols-8',
        ][$columns];
    }

    /**
     * 响应式三档解析（P2）：设置值可为标量（全断点统一）或 {d,t,m}（桌面/平板/手机分档）。
     * mobile-first 输出：基类 ← m，md: ← t，lg: ← d（与预览设备档 手机390/平板768/桌面 对应）。
     * 全档一致或标量时输出单个基类，与三档机制之前的输出逐字节一致（黄金对拍不破）。
     *
     * $map 每项 = [基类, md:类, lg:类]，三个断点的类名必须全量字面量写死——
     * Tailwind 独立编译靠扫描 PHP 源码提取 class，动态拼接的类名扫不到。
     */
    public static function respClasses(mixed $value, array $map, string $fallback): string
    {
        $responsive = BloxResponsiveValue::normalize($value, $map, $fallback);
        if (is_array($value)) {
            $d = $responsive['d'];
            $t = $responsive['t'];
            $m = $responsive['m'];
            $w = $responsive['w'];
            if ($m === $t && $t === $d && $d === $w) {
                return $map[$m][0];
            }
            $cls = $map[$m][0];
            if ($t !== $m) {
                $cls .= ' ' . $map[$t][1];
            }
            if ($d !== $t) {
                $cls .= ' ' . $map[$d][2];
            }
            if ($w !== $d && isset($map[$w][3])) {
                $cls .= ' ' . $map[$w][3];
            }
            return $cls;
        }
        return $map[$responsive['d']][0];
    }

    /** respClasses 的实例快捷方式，元素 render() 内用 */
    protected function resp(mixed $value, array $map, string $fallback): string
    {
        return self::respClasses($value, $map, $fallback);
    }
}
