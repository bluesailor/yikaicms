<?php
declare(strict_types=1);

final class BloxImageFraming
{
    private const RATIOS = ['square' => '1 / 1', 'landscape' => '4 / 3', 'wide' => '16 / 9', 'portrait' => '3 / 4'];

    /** @return list<array<string,mixed>> */
    public static function controls(bool $forCard = false): array
    {
        $terms = $forCard ? [['image', 'not_empty'], ['card_layout', '!=', 'text']] : [];
        $framedTerms = array_merge($terms, [['image_ratio', '!=', 'auto']]);
        $options = $forCard ? ['default' => __('blox_image_ratio_follow_layout')] : [];
        return [
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
        $ratio = $data['image_ratio'] ?? null;
        $default = $forCard ? 'default' : 'auto';
        return is_string($ratio) && (isset(self::RATIOS[$ratio]) || $ratio === 'auto' || $ratio === $default) ? $ratio : $default;
    }

    public static function ratioCss(string $ratio): string
    {
        return self::RATIOS[$ratio] ?? '';
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

    public static function standaloneStyle(array $data): string
    {
        $ratio = self::ratioCss(self::ratio($data));
        return $ratio === '' ? '' : ' style="width:100%;height:auto;aspect-ratio:' . $ratio . ';' . self::objectStyle($data) . '"';
    }
}
