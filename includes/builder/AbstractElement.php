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
                    'fade' => 'opacity',
                    'fade-up' => 'arrow-up',
                    'fade-down' => 'arrow-down',
                    'fade-left' => 'arrow-right',
                    'fade-right' => 'arrow-left',
                    'zoom-in' => 'zoom-in',
                ],
            ],
            [
                'key' => 'animation_speed', 'type' => 'select', 'label' => __('blox_anim_speed'), 'default' => 'normal', 'tab' => 'style', 'group' => 'animation',
                'options' => ['normal' => __('blox_anim_normal'), 'fast' => __('blox_anim_fast'), 'slow' => __('blox_anim_slow')],
            ],
            [
                'key' => 'animation_delay', 'type' => 'select', 'label' => __('blox_anim_delay'), 'default' => 'none', 'tab' => 'style', 'group' => 'animation',
                'options' => ['none' => __('blox_anim_delay_none'), 'short' => __('blox_anim_delay_short'), 'medium' => __('blox_anim_delay_medium'), 'long' => __('blox_anim_delay_long')],
            ],
        ];
    }

    /** 将动画设置转成安全的 data 属性；无动画时不改变历史 HTML。 */
    protected function animationAttrs(array $data): string
    {
        $animation = is_string($data['animation'] ?? null) ? $data['animation'] : '';
        if (!in_array($animation, ['fade', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'zoom-in'], true)) {
            return '';
        }

        $attrs = ' data-animate="' . $animation . '"';
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

    /**
     * 通用盒模型间距。接受固定档位或经 cssLength() 白名单校验的精确值，
     * 返回可安全拼入 style 属性的声明。
     * 总值先输出、四边覆盖后输出；同为 !important 时后者精确覆盖元素自带间距。
     */
    public static function boxStyle(array $data): string
    {
        $sizes = [
            'none' => '0',
            'xs'   => '0.25rem',
            'sm'   => '0.5rem',
            'md'   => '1rem',
            'lg'   => '2rem',
            'xl'   => '4rem',
            'auto' => 'auto',
        ];
        $fields = [
            'style_margin'        => ['margin', true],
            'style_margin_top'    => ['margin-top', true],
            'style_margin_right'  => ['margin-right', true],
            'style_margin_bottom' => ['margin-bottom', true],
            'style_margin_left'   => ['margin-left', true],
            'style_padding'        => ['padding', false],
            'style_padding_top'    => ['padding-top', false],
            'style_padding_right'  => ['padding-right', false],
            'style_padding_bottom' => ['padding-bottom', false],
            'style_padding_left'   => ['padding-left', false],
        ];

        $style = '';
        foreach ($fields as $key => [$property, $isMargin]) {
            $value = $data[$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            if (isset($sizes[$value])) {
                // 固定档位（auto 档仅 margin 可用）
                if (!$isMargin && $value === 'auto') {
                    continue;
                }
                $style .= $property . ':' . $sizes[$value] . '!important;';
                continue;
            }
            // 精确输入：白名单校验（margin 允负值/auto，padding 非负），不合法静默忽略
            $exact = self::cssLength($value, $isMargin, $isMargin);
            if ($exact !== null) {
                $style .= $property . ':' . $exact . '!important;';
            }
        }
        return $style;
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
        return [
            ['key' => 'bg_color', 'type' => 'color', 'label' => __('blox_bg_color'), 'default' => '', 'tab' => 'style', 'group' => 'background'],
            ['key' => 'bg_image', 'type' => 'image', 'label' => __('blox_bg_image'), 'default' => '', 'tab' => 'style', 'group' => 'background'],
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
            ] : []),
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

    /** 结构树优先读取的数据字段；null 时使用编辑器的通用文本字段。 */
    public function treeLabelField(): ?string
    {
        return null;
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
            if ($m === $t && $t === $d) {
                return $map[$m][0];
            }
            $cls = $map[$m][0];
            if ($t !== $m) {
                $cls .= ' ' . $map[$t][1];
            }
            if ($d !== $t) {
                $cls .= ' ' . $map[$d][2];
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
