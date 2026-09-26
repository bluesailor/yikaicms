<?php
declare(strict_types=1);

/**
 * 图片形状与非对称圆角（V2.0.1 B）。
 *
 * 为什么只用 border-radius、完全不碰 clip-path / mask：
 * 遮罩类方案会连带影响 <a> 的命中区域、焦点环形状与灯箱触发范围，而 border-radius
 * 只改绘制，alt / srcset / object-fit / 链接一律不受影响——任务书那条
 * "不得让点击区、灯箱、alt、响应式失效"就是这么保证的。
 * 代价是做不了真正的自由异形，属"先做固定形状，不做自由绘制"允许的范围。
 *
 * 形状走 CSS 类（两个类名的选择器，特异性压过 Tailwind 的 .rounded-lg，不用 !important）；
 * 四角独立值随值而变，只能走行内样式，行内样式本就压过类。
 */
final class BloxImageShape
{
    /** 形状枚举。'' = 原样（沿用元素默认圆角）。 */
    public const SHAPES = ['arch', 'side', 'organic'];

    /** 单侧大圆角放在哪个角。 */
    public const SIDES = ['tl', 'tr', 'br', 'bl'];

    public const CORNERS = ['tl', 'tr', 'br', 'bl'];

    /**
     * 四角半径的模式。
     *
     * 为什么需要这个开关：number 控件把空值折成 0（BloxValueSanitizer 的既有口径），
     * 于是"没设过"和"设成 0"在存储里长得一模一样。没有模式位的话，作者一旦碰过
     * 圆角输入框，图片的默认 rounded-lg 就会被一串 0 悄悄吃掉。
     * 有了它：'' = 沿用默认圆角，'all' = 统一四角（任务书要的快捷入口），
     * 'custom' = 四角分别设置（全填 0 就是明确要直角）。
     */
    public const RADIUS_MODES = ['all', 'custom'];

    public const RADIUS_MAX = 400;

    public const STYLESHEET = '/assets/css/blox-image-shape.css';

    private const BASE_CLASS = 'yk-img-shape';

    /**
     * @psalm-api 契约表与单测据此核对存储键，不在渲染路径上。
     * @return list<string> 本特性用到的全部元素键
     */
    public static function dataKeys(): array
    {
        $keys = ['image_shape', 'image_shape_side', 'image_radius_mode', 'image_radius_all'];
        foreach (self::CORNERS as $corner) {
            $keys[] = 'image_radius_' . $corner;
        }
        return $keys;
    }

    /**
     * 归一化：导入与保存共用。只清洗已存在的键，不给旧文档凭空补默认值。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function normalizeData(array $data): array
    {
        if (array_key_exists('image_shape', $data)) {
            $shape = is_string($data['image_shape']) ? $data['image_shape'] : '';
            $data['image_shape'] = in_array($shape, self::SHAPES, true) ? $shape : '';
        }
        if (array_key_exists('image_shape_side', $data)) {
            $side = is_string($data['image_shape_side']) ? $data['image_shape_side'] : '';
            $data['image_shape_side'] = in_array($side, self::SIDES, true) ? $side : 'tl';
        }
        if (array_key_exists('image_radius_mode', $data)) {
            $mode = is_string($data['image_radius_mode']) ? $data['image_radius_mode'] : '';
            $data['image_radius_mode'] = in_array($mode, self::RADIUS_MODES, true) ? $mode : '';
        }
        foreach (array_merge(['all'], self::CORNERS) as $corner) {
            $key = 'image_radius_' . $corner;
            if (array_key_exists($key, $data)) {
                $data[$key] = max(0, min(self::RADIUS_MAX, (int) $data[$key]));
            }
        }
        return $data;
    }

    /** 作者选了固定形状吗？ */
    public static function hasShape(array $data): bool
    {
        return in_array((string) ($data['image_shape'] ?? ''), self::SHAPES, true);
    }

    /** 四角半径模式：'' / all / custom。 */
    public static function radiusMode(array $data): string
    {
        $mode = (string) ($data['image_radius_mode'] ?? '');
        return in_array($mode, self::RADIUS_MODES, true) ? $mode : '';
    }

    private static function corner(array $data, string $corner): int
    {
        return max(0, min(self::RADIUS_MAX, (int) ($data['image_radius_' . $corner] ?? 0)));
    }

    /**
     * 形状类名（含前导空格，可直接拼进 class 串）。无形状返回空串。
     *
     * 优先级：固定形状 > 四角独立值。两者同时存在时形状胜出，
     * 免得作者在两套控件之间来回猜哪个生效。
     */
    public static function classNames(array $data): string
    {
        if (!self::hasShape($data)) {
            return '';
        }
        $shape = (string) $data['image_shape'];
        $modifier = $shape;
        if ($shape === 'side') {
            $side = (string) ($data['image_shape_side'] ?? 'tl');
            $modifier .= '-' . (in_array($side, self::SIDES, true) ? $side : 'tl');
        }
        self::requireStylesheet();
        return ' ' . self::BASE_CLASS . ' ' . self::BASE_CLASS . '--' . $modifier;
    }

    /**
     * 四角独立半径的行内 CSS（形状未选时才生效）。返回形如 'border-radius:...;' 或空串。
     */
    public static function inlineCss(array $data): string
    {
        // 固定形状优先：两套控件同时有值时形状胜出，免得作者猜哪个生效
        if (self::hasShape($data)) {
            return '';
        }
        $mode = self::radiusMode($data);
        if ($mode === '') {
            return '';
        }
        if ($mode === 'all') {
            return 'border-radius:' . self::corner($data, 'all') . 'px!important;';
        }
        $values = [];
        foreach (self::CORNERS as $corner) {
            $values[] = self::corner($data, $corner) . 'px';
        }
        return 'border-radius:' . implode(' ', $values) . '!important;';
    }

    private static function requireStylesheet(): void
    {
        if (class_exists('BloxAssetCollector')) {
            BloxAssetCollector::addStyle(self::STYLESHEET);
        }
    }

    /**
     * 编辑器控件（声明式，由通用控件渲染器出面板）。
     *
     * @param bool $forCard 卡片类元素复用同一组控件时，跟随卡片自己的可见性条件
     * @return list<array<string,mixed>>
     */
    public static function controls(bool $forCard = false): array
    {
        $terms = $forCard ? [['image', 'not_empty'], ['card_layout', '!=', 'text']] : [];
        // 四角独立值只在"没选固定形状"时出现——两套控件同时摆着，作者会猜不到哪个生效
        $radiusTerms = array_merge($terms, [['image_shape', '=', '']]);

        $controls = [
            ['key' => 'image_shape', 'type' => 'select', 'label' => __('blox_image_shape'), 'default' => '', 'tab' => 'style',
                'visible_when' => ['terms' => $terms],
                'options' => ['' => __('blox_image_shape_none'), 'arch' => __('blox_image_shape_arch'),
                    'side' => __('blox_image_shape_side'), 'organic' => __('blox_image_shape_organic')],
                'option_icons' => ['arch' => 'building-arch', 'side' => 'border-corner-rounded', 'organic' => 'blob']],
            ['key' => 'image_shape_side', 'type' => 'select', 'label' => __('blox_image_shape_side_corner'), 'default' => 'tl', 'tab' => 'style',
                'visible_when' => ['terms' => array_merge($terms, [['image_shape', '=', 'side']])],
                'options' => ['tl' => __('blox_corner_tl'), 'tr' => __('blox_corner_tr'),
                    'br' => __('blox_corner_br'), 'bl' => __('blox_corner_bl')],
                'option_icons' => ['tl' => 'arrow-up-left', 'tr' => 'arrow-up-right',
                    'br' => 'arrow-down-right', 'bl' => 'arrow-down-left']],
        ];
        $controls[] = ['key' => 'image_radius_mode', 'type' => 'select', 'label' => __('blox_image_corner_radius'),
            'default' => '', 'tab' => 'style', 'visible_when' => ['terms' => $radiusTerms],
            'options' => ['' => __('blox_image_corner_radius_default'), 'all' => __('blox_image_corner_radius_all'),
                'custom' => __('blox_image_corner_radius_custom')]];

        // 统一四角用独立键：同一个 key 挂两个控件条目会在编辑器里撞 key
        $controls[] = ['key' => 'image_radius_all', 'type' => 'number', 'label' => __('blox_image_corner_radius'),
            'default' => 0, 'tab' => 'style', 'min' => 0, 'max' => self::RADIUS_MAX, 'step' => 2,
            'visible_when' => ['terms' => array_merge($radiusTerms, [['image_radius_mode', '=', 'all']])]];

        $cornerLabels = ['tl' => 'blox_corner_tl', 'tr' => 'blox_corner_tr', 'br' => 'blox_corner_br', 'bl' => 'blox_corner_bl'];
        foreach (self::CORNERS as $corner) {
            $controls[] = ['key' => 'image_radius_' . $corner, 'type' => 'number',
                'label' => __('blox_image_corner_radius') . ' · ' . __($cornerLabels[$corner]),
                'default' => 0, 'tab' => 'style',
                'min' => 0, 'max' => self::RADIUS_MAX, 'step' => 2,
                'visible_when' => ['terms' => array_merge($radiusTerms, [['image_radius_mode', '=', 'custom']])]];
        }
        return $controls;
    }

    /**
     * 属性契约（验收门槛 1）。
     *
     * @psalm-api 交付文档与单测消费，不在渲染路径上。
     * @return list<array<string,mixed>>
     */
    public static function propertyContract(): array
    {
        $fields = [
            ['key' => 'image_shape', 'type' => 'enum', 'default' => '',
                'options' => array_merge([''], self::SHAPES), 'css' => 'border-radius (class)',
                'note' => 'wins over per-corner radii'],
            ['key' => 'image_shape_side', 'type' => 'enum', 'default' => 'tl',
                'options' => self::SIDES, 'css' => 'border-radius (class)',
                'note' => 'only when image_shape=side'],
        ];
        $fields[] = ['key' => 'image_radius_mode', 'type' => 'enum', 'default' => '',
            'options' => array_merge([''], self::RADIUS_MODES), 'css' => 'border-radius (inline)',
            'note' => "'' keeps the element default radius; ignored when image_shape is set"];
        $fields[] = ['key' => 'image_radius_all', 'type' => 'px', 'default' => 0,
            'min' => 0, 'max' => self::RADIUS_MAX, 'css' => 'border-radius (inline)',
            'note' => 'applies only when image_radius_mode=all'];
        foreach (self::CORNERS as $corner) {
            $fields[] = ['key' => 'image_radius_' . $corner, 'type' => 'px', 'default' => 0,
                'min' => 0, 'max' => self::RADIUS_MAX, 'css' => 'border-radius (inline)',
                'note' => 'applies only when image_radius_mode=custom'];
        }
        return $fields;
    }
}
