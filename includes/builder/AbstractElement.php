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
                'key' => 'animation', 'type' => 'select', 'label' => '入场动画', 'default' => '', 'tab' => 'style',
                'options' => [
                    '' => '无动画',
                    'fade' => '淡入',
                    'fade-up' => '向上淡入',
                    'fade-down' => '向下淡入',
                    'fade-left' => '从左进入',
                    'fade-right' => '从右进入',
                    'zoom-in' => '缩放进入',
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
                'key' => 'animation_speed', 'type' => 'select', 'label' => '动画速度', 'default' => 'normal', 'tab' => 'style',
                'options' => ['normal' => '标准', 'fast' => '快速', 'slow' => '舒缓'],
            ],
            [
                'key' => 'animation_delay', 'type' => 'select', 'label' => '延迟出现', 'default' => 'none', 'tab' => 'style',
                'options' => ['none' => '无延迟', 'short' => '0.15 秒', 'medium' => '0.3 秒', 'long' => '0.6 秒'],
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
        if (is_array($value)) {
            $d = isset($map[$value['d'] ?? '']) ? $value['d'] : $fallback;
            $t = isset($map[$value['t'] ?? '']) ? $value['t'] : $d;
            $m = isset($map[$value['m'] ?? '']) ? $value['m'] : $t;
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
        $key = is_string($value) && isset($map[$value]) ? $value : $fallback;
        return $map[$key][0];
    }

    /** respClasses 的实例快捷方式，元素 render() 内用 */
    protected function resp(mixed $value, array $map, string $fallback): string
    {
        return self::respClasses($value, $map, $fallback);
    }
}
