<?php
declare(strict_types=1);

final class BloxImageFraming
{
    private const RATIOS = ['square' => '1 / 1', 'landscape' => '4 / 3', 'wide' => '16 / 9', 'portrait' => '3 / 4'];

    /**
     * 构图预设（E05 切片 B）：常见场景的一组构图基线。
     *
     * 预设只提供**基线**，作者显式选过的值优先——否则"套个预设"就会把已经调好的比例
     * 或填充方式冲掉。判定"没设置过"用的是控件默认值（ratio 的 auto/default、fit 的空），
     * 与切片 A 的焦点同一口径：默认值即未设置。
     *
     * @return array<string,string>
     */
    public static function presetDefaults(mixed $preset): array
    {
        return match ($preset) {
            // 头像：正方裁切 + 圆形，脸部构图再靠焦点微调
            'avatar' => ['image_ratio' => 'square', 'image_fit' => 'cover'],
            // 白底产品：整件装得下比填满重要，所以是 contain
            'product' => ['image_ratio' => 'square', 'image_fit' => 'contain'],
            'case' => ['image_ratio' => 'wide', 'image_fit' => 'cover'],
            'portrait' => ['image_ratio' => 'portrait', 'image_fit' => 'cover'],
            'overlay' => ['image_ratio' => 'wide', 'image_fit' => 'cover'],
            default => [],
        };
    }

    /** 预设的附加外观：圆形与白底不是比例/填充能表达的，单独给出。 */
    public static function presetFrameStyle(mixed $preset): string
    {
        return match ($preset) {
            'avatar' => 'border-radius:9999px;overflow:hidden;',
            'product' => 'background-color:#fff;',
            default => '',
        };
    }

    /**
     * 把预设基线并进数据：作者已选的值一律保留。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function withPreset(array $data): array
    {
        $unset = ['', 'auto', 'default', null];
        foreach (self::presetDefaults($data['image_preset'] ?? '') as $key => $value) {
            if (in_array($data[$key] ?? null, $unset, true)) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    /** @return list<array<string,mixed>> */
    public static function controls(bool $forCard = false): array
    {
        $terms = $forCard ? [['image', 'not_empty'], ['card_layout', '!=', 'text']] : [];
        $framedTerms = array_merge($terms, [['image_ratio', '!=', 'auto']]);
        $options = $forCard ? ['default' => __('blox_image_ratio_follow_layout')] : [];
        return [
            ['key' => 'image_preset', 'type' => 'select', 'label' => __('blox_image_preset'), 'default' => '', 'tab' => 'style',
                'visible_when' => ['terms' => $terms],
                'options' => ['' => __('blox_image_preset_none'), 'avatar' => __('blox_image_preset_avatar'),
                    'product' => __('blox_image_preset_product'), 'case' => __('blox_image_preset_case'),
                    'portrait' => __('blox_image_preset_portrait'), 'overlay' => __('blox_image_preset_overlay')],
                'option_icons' => ['avatar' => 'user-circle', 'product' => 'package', 'case' => 'photo',
                    'portrait' => 'user', 'overlay' => 'text-caption']],
            ['key' => 'image_ratio', 'type' => 'select', 'label' => __('blox_image_ratio'), 'default' => $forCard ? 'default' : 'auto', 'tab' => 'style',
                'option_preview' => 'image-ratio', 'visible_when' => ['terms' => $terms],
                'options' => $options + ['auto' => __('blox_image_ratio_auto'), 'square' => '1:1', 'landscape' => '4:3', 'wide' => '16:9', 'portrait' => '3:4']],
            ['key' => 'image_fit', 'type' => 'select', 'label' => __('blox_image_fit'), 'default' => 'cover', 'tab' => 'style',
                'option_preview' => 'image-fit', 'visible_when' => ['terms' => $framedTerms],
                'options' => ['cover' => __('blox_image_fit_cover'), 'contain' => __('blox_image_fit_contain')]],
            ['key' => 'image_position', 'type' => 'select', 'label' => __('blox_image_position'), 'default' => 'center', 'tab' => 'style',
                'option_preview' => 'image-position', 'visible_when' => ['terms' => $framedTerms],
                'options' => [
                    'top-left' => __('blox_image_top_left'), 'top' => __('blox_image_top'), 'top-right' => __('blox_image_top_right'),
                    'left' => __('blox_image_left'), 'center' => __('blox_image_center'), 'right' => __('blox_image_right'),
                    'bottom-left' => __('blox_image_bottom_left'), 'bottom' => __('blox_image_bottom'), 'bottom-right' => __('blox_image_bottom_right'),
                ],
                'option_icons' => [
                    'top-left' => 'arrow-up-left', 'top' => 'arrow-up', 'top-right' => 'arrow-up-right',
                    'left' => 'arrow-left', 'center' => 'focus-centered', 'right' => 'arrow-right',
                    'bottom-left' => 'arrow-down-left', 'bottom' => 'arrow-down', 'bottom-right' => 'arrow-down-right',
                ]],
            // 连续焦点（E05 切片 A）：九宫格只能九选一，人脸/宠物脸常常正好落在格子之间，
            // 裁切后被切掉。留空＝沿用上面的九宫格，填了就以百分比精确定位。
            ['key' => 'focus_x', 'type' => 'number', 'label' => __('blox_image_focus_x'), 'default' => '', 'tab' => 'style',
                'min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%', 'visible_when' => ['terms' => $framedTerms]],
            ['key' => 'focus_y', 'type' => 'number', 'label' => __('blox_image_focus_y'), 'default' => '', 'tab' => 'style',
                'min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%', 'visible_when' => ['terms' => $framedTerms]],
            // 手机单独的焦点：竖屏裁切比例与桌面差别最大，正是脸被切掉的场景。
            // 留空＝继承桌面焦点（"未设置"与"设为 0"是两回事，所以默认是空串不是 0）。
            ['key' => 'focus_x_m', 'type' => 'number', 'label' => __('blox_image_focus_x_m'), 'default' => '', 'tab' => 'style',
                'min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%', 'visible_when' => ['terms' => $framedTerms]],
            ['key' => 'focus_y_m', 'type' => 'number', 'label' => __('blox_image_focus_y_m'), 'default' => '', 'tab' => 'style',
                'min' => 0, 'max' => 100, 'step' => 1, 'unit' => '%', 'visible_when' => ['terms' => $framedTerms]],
        ];
    }

    public static function ratio(array $data, bool $forCard = false): string
    {
        // 预设基线在这里并入：作者显式选过的比例不受影响（见 withPreset）
        $data = self::withPreset($data);
        $ratio = $data['image_ratio'] ?? null;
        $default = $forCard ? 'default' : 'auto';
        if ($ratio === 'custom') {
            // 自定义比例（2.3.1 产品图）：宽高不合法时退回默认，保存时另有服务端校验拒收
            return self::customRatioCss($data) !== null ? 'custom' : $default;
        }
        return is_string($ratio) && (isset(self::RATIOS[$ratio]) || $ratio === 'auto' || $ratio === $default) ? $ratio : $default;
    }

    public static function ratioCss(string $ratio, array $data = []): string
    {
        if ($ratio === 'custom') {
            return self::customRatioCss($data) ?? '';
        }
        return self::RATIOS[$ratio] ?? '';
    }

    // ── 产品图片比例（2.3.1）────────────────────────────────────────────
    // 首页产品区块、产品目录卡片、详情页主图/相册各自一份设置，键名与通用图片相同
    // （image_ratio / image_fit），多了「跟随排版」「自定义」两档。默认值＝跟随排版，
    // 即渲染与升级前逐字节一致，直到客户自己选了比例或填充方式。

    /** 自定义宽高的取值范围与最极端的比例（1:10 至 10:1）。 */
    public const CUSTOM_MIN = 1;
    public const CUSTOM_MAX = 1000;
    public const CUSTOM_MAX_RATIO = 10;

    /** 自定义比例的 CSS 值；任一边不合法返回 null。 */
    public static function customRatioCss(array $data): ?string
    {
        $w = self::customSide($data['image_ratio_w'] ?? null);
        $h = self::customSide($data['image_ratio_h'] ?? null);
        if ($w === null || $h === null) {
            return null;
        }
        $r = $w / $h;
        if ($r > self::CUSTOM_MAX_RATIO || $r < 1 / self::CUSTOM_MAX_RATIO) {
            return null;
        }
        return self::number($w) . ' / ' . self::number($h);
    }

    private static function customSide(mixed $value): ?float
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ($value === null || $value === '' || is_bool($value) || is_array($value) || !is_numeric($value)) {
            return null;
        }
        $n = (float) $value;
        // 最多两位小数：1.91:1 这类常见值能填，更长的小数多半是误输入
        if ($n < self::CUSTOM_MIN || $n > self::CUSTOM_MAX || abs(round($n, 2) - $n) > 1e-9) {
            return null;
        }
        return $n;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * 产品图片的比例与填充控件。$terms 是整组的显示条件（如首页区块只对产品栏目来源显示）。
     *
     * @param list<array<int,mixed>> $terms
     * @return list<array<string,mixed>>
     */
    public static function productControls(array $terms = [], string $helpKey = '', bool $fitNeedsFrame = false): array
    {
        $customTerms = array_merge($terms, [['image_ratio', '=', 'custom']]);
        // 跟随排版本身没有框（详情主图默认按原图显示）时，只有选了比例填充方式才有意义
        $fitTerms = array_merge($terms, [['image_ratio', 'not_in', $fitNeedsFrame ? ['auto', 'default'] : ['auto']]]);
        $ratio = ['key' => 'image_ratio', 'type' => 'select', 'label' => __('blox_image_ratio'), 'default' => 'default', 'tab' => 'style',
            'option_preview' => 'image-ratio',
            'options' => [
                'default' => __('blox_pimg_follow_layout'),
                'square' => '1:1', 'landscape' => '4:3', 'portrait' => '3:4', 'wide' => '16:9',
                'auto' => __('blox_pimg_original'),
                'custom' => __('blox_pimg_custom'),
            ]];
        if ($helpKey !== '') {
            $ratio['help'] = __($helpKey);
        }
        $side = static fn (string $key, string $label): array => ['key' => $key, 'type' => 'number', 'label' => __($label),
            'default' => '', 'tab' => 'style', 'min' => self::CUSTOM_MIN, 'max' => self::CUSTOM_MAX, 'step' => 1,
            'visible_when' => ['terms' => $customTerms]];
        $controls = [
            $ratio,
            $side('image_ratio_w', 'blox_pimg_ratio_width'),
            $side('image_ratio_h', 'blox_pimg_ratio_height'),
            ['key' => 'image_fit', 'type' => 'select', 'label' => __('blox_image_fit'), 'default' => '', 'tab' => 'style',
                'option_preview' => 'image-fit', 'visible_when' => ['terms' => $fitTerms],
                'options' => [
                    '' => __('blox_pimg_follow_layout'),
                    'contain' => __('blox_pimg_fit_contain'),
                    'cover' => __('blox_pimg_fit_cover'),
                ]],
        ];
        if ($terms !== []) {
            $controls[0]['visible_when'] = ['terms' => $terms];
        }
        return $controls;
    }

    /**
     * 产品图片的显示框。跟随排版（比例与填充都没选）时原样返回排版自己的类名，
     * 输出与升级前一致；选了的部分改用行内样式。原图（灯箱、放大）不受影响，只改显示框。
     *
     * @param string $followRatioClass 排版默认的比例类（如 aspect-[4/3]）
     * @param string $followFitClass   排版默认的填充类（object-cover / object-contain）
     * @return array{frameClass:string,frameStyle:string,imgClass:string,imgStyle:string,custom:bool}
     */
    public static function productFrame(array $data, string $followRatioClass, string $followFitClass = 'object-cover'): array
    {
        $ratio = is_string($data['image_ratio'] ?? null) ? $data['image_ratio'] : 'default';
        $css = match (true) {
            $ratio === 'custom' => self::customRatioCss($data) ?? '',
            isset(self::RATIOS[$ratio]) => self::RATIOS[$ratio],
            default => '',
        };
        $original = $ratio === 'auto';
        $followRatio = !$original && $css === '';
        $fit = in_array($data['image_fit'] ?? '', ['contain', 'cover'], true) ? (string) $data['image_fit'] : '';

        if ($followRatio && $fit === '') {
            return ['frameClass' => $followRatioClass, 'frameStyle' => '', 'imgClass' => 'h-full ' . $followFitClass, 'imgStyle' => '', 'custom' => false];
        }
        $frameClass = $followRatio ? $followRatioClass : '';
        $frameStyle = $css !== '' ? 'aspect-ratio:' . $css . ';' : '';
        if ($original) {
            // 原比例：不设框，图片按自身宽高显示，填充方式无意义
            return ['frameClass' => '', 'frameStyle' => '', 'imgClass' => 'h-auto', 'imgStyle' => '', 'custom' => true];
        }
        $imgClass = 'h-full' . ($fit === '' ? ' ' . $followFitClass : '');
        $imgStyle = $fit !== '' ? 'object-fit:' . $fit . ';' : '';
        return ['frameClass' => $frameClass, 'frameStyle' => $frameStyle, 'imgClass' => $imgClass, 'imgStyle' => $imgStyle, 'custom' => true];
    }

    /**
     * 服务端校验：选了「自定义」的元素必须带合法宽高（各 1–1000、最多两位小数、比例在 1:10 至 10:1 之间）。
     * 在保存管线归一化之前对原始提交执行——归一化会把越界数字静默夹取，那样错误的输入看起来像生效了。
     *
     * @param array<int,mixed> $sections
     */
    public static function assertSectionsValid(array $sections): void
    {
        $walk = static function (mixed $elements, string $path) use (&$walk): void {
            foreach (is_array($elements) ? array_values($elements) : [] as $index => $element) {
                if (!is_array($element)) {
                    continue;
                }
                $data = is_array($element['data'] ?? null) ? $element['data'] : [];
                $elementPath = $path . ' / ' . __('blox_element_word', ['n' => $index + 1]);
                if (($data['image_ratio'] ?? null) === 'custom' && self::customRatioCss($data) === null) {
                    throw new RuntimeException(__('blox_pimg_custom_invalid', [
                        'path' => $elementPath,
                        'min' => (string) self::CUSTOM_MIN,
                        'max' => (string) self::CUSTOM_MAX,
                        'ratio' => (string) self::CUSTOM_MAX_RATIO,
                    ]));
                }
                $walk($data['children'] ?? [], $elementPath);
            }
        };
        foreach (array_values($sections) as $sectionIndex => $section) {
            if (!is_array($section)) {
                continue;
            }
            $sectionPath = __('blox_section_word', ['n' => $sectionIndex + 1]);
            $columns = $section['columns'] ?? [];
            if (!is_array($columns)) {
                continue;
            }
            $columnNumber = 0;
            foreach ($columns as $column) {
                $columnNumber++;
                $elements = is_array($column) ? ($column['elements'] ?? []) : [];
                $walk($elements, $sectionPath . ' / ' . __('blox_col_word', ['n' => $columnNumber]));
            }
        }
    }

    /**
     * 焦点百分比：留空返回 null（"未设置"），0 是合法取值不能与之混淆。
     * 越界值按未设置处理，不静默夹取——夹取会把明显的输入错误变成"看起来生效了"。
     */
    private static function focusPercent(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_array($value) || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        return ($number < 0 || $number > 100) ? null : $number;
    }

    /**
     * 当前设备该用的焦点。手机档留空即继承桌面档，两轴各自继承——
     * 只调了 y 的常见改法（脸偏上）不该被迫连 x 一起填。
     *
     * @return array{0:?float,1:?float}
     */
    public static function focusFor(array $data, bool $mobile): array
    {
        $x = self::focusPercent($data['focus_x'] ?? null);
        $y = self::focusPercent($data['focus_y'] ?? null);
        if ($mobile) {
            $x = self::focusPercent($data['focus_x_m'] ?? null) ?? $x;
            $y = self::focusPercent($data['focus_y_m'] ?? null) ?? $y;
        }
        return [$x, $y];
    }

    /** 九宫格 → 百分比，供连续焦点只设了一个轴时补另一个轴，也给编辑器做初值。 */
    public static function gridToPercent(mixed $position): array
    {
        return match ($position) {
            'top-left' => [0.0, 0.0], 'top' => [50.0, 0.0], 'top-right' => [100.0, 0.0],
            'left' => [0.0, 50.0], 'right' => [100.0, 50.0],
            'bottom-left' => [0.0, 100.0], 'bottom' => [50.0, 100.0], 'bottom-right' => [100.0, 100.0],
            default => [50.0, 50.0],
        };
    }

    public static function objectStyle(array $data, ?bool $mobile = null): string
    {
        $data = self::withPreset($data);
        $fit = ($data['image_fit'] ?? '') === 'contain' ? 'contain' : 'cover';
        // 设备判定与缓存键、显示条件的 device 同源（HtmlCache::isMobileClient），
        // 三处各写一个正则迟早会把手机焦点冻进桌面缓存桶。
        $isMobile = $mobile ?? (class_exists('HtmlCache') && HtmlCache::isMobileClient());
        [$x, $y] = self::focusFor($data, $isMobile);
        if ($x !== null || $y !== null) {
            // 只设一个轴时，另一个轴沿用九宫格的对应分量，而不是粗暴归中
            [$gridX, $gridY] = self::gridToPercent($data['image_position'] ?? null);
            $position = self::percent($x ?? $gridX) . ' ' . self::percent($y ?? $gridY);
            return 'object-fit:' . $fit . ';object-position:' . $position . ';';
        }
        $position = match ($data['image_position'] ?? null) {
            'top-left' => 'left top', 'top' => 'center top', 'top-right' => 'right top',
            'left' => 'left center', 'right' => 'right center',
            'bottom-left' => 'left bottom', 'bottom' => 'center bottom', 'bottom-right' => 'right bottom',
            default => 'center center',
        };
        return 'object-fit:' . $fit . ';object-position:' . $position . ';';
    }

    /** 输出稳定的百分比：整数不带小数点，避免同一设置在不同请求里产生不同字符串。 */
    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . '%';
    }

    /**
     * @param string $extraCss 追加在最后的声明（如四角独立半径）。
     *                         排在预设之后 → 同属性时它胜出；为空时输出与既有逐字节一致。
     */
    public static function standaloneStyle(array $data, string $extraCss = ''): string
    {
        $preset = self::presetFrameStyle($data['image_preset'] ?? '');
        $ratio = self::ratioCss(self::ratio($data), $data);
        if ($ratio === '') {
            // 没有裁切比例时，预设的外观（圆形／白底）仍要生效
            return ($preset . $extraCss) === '' ? '' : ' style="' . $preset . $extraCss . '"';
        }
        return ' style="width:100%;height:auto;aspect-ratio:' . $ratio . ';' . self::objectStyle($data) . $preset . $extraCss . '"';
    }
}
