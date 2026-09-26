<?php
declare(strict_types=1);

/**
 * 受控错位与叠放（V2.0.1 C）。
 *
 * 任务书明确要求："宁可只用已有负 margin 和固定结构预设，不引入半成品 transform"。
 * 所以这里没有自由拖拽定位、没有 transform、没有绝对定位——只有三组命名好的
 * 负 margin 组合，外加一个三档白名单层级。
 *
 * 为什么走 CSS 类而不是行内样式：预设要分断点（桌面偏移大、平板减半、手机归零），
 * 行内样式写不了媒体查询。选择器用两个类名，特异性压过全局类的单类选择器——
 * 与 V2.0.0"元素本地值胜过全局类"的既有优先级一致。
 */
final class BloxOverlap
{
    /** 预设：上浮卡片 / 图片压边 / 标题叠图。'' = 不启用。 */
    public const PRESETS = ['lift', 'bleed', 'title_over'];

    /** 层级白名单。不接受任意 z-index 数值。 */
    public const LAYERS = ['front', 'back'];

    public const STYLESHEET = '/assets/css/blox-overlap.css';

    private const PRESET_CLASS = 'yk-overlap';
    private const LAYER_CLASS = 'yk-stack';

    /**
     * 每个预设自带的层级。作者没单独指定时用它——
     * 上浮卡片不浮到前景就看不出"浮"，等于白设。
     */
    private const PRESET_LAYER = [
        'lift' => 'front',
        'bleed' => '',
        'title_over' => 'front',
    ];

    /** @return list<string> 本特性用到的全部元素键 */
    public static function dataKeys(): array
    {
        return ['overlap_preset', 'stack_layer'];
    }

    /**
     * 归一化。只清洗已存在的键，不给旧文档补默认值。
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function normalizeData(array $data): array
    {
        if (array_key_exists('overlap_preset', $data)) {
            $preset = is_string($data['overlap_preset']) ? $data['overlap_preset'] : '';
            $data['overlap_preset'] = in_array($preset, self::PRESETS, true) ? $preset : '';
        }
        if (array_key_exists('stack_layer', $data)) {
            $layer = is_string($data['stack_layer']) ? $data['stack_layer'] : '';
            $data['stack_layer'] = in_array($layer, self::LAYERS, true) ? $layer : '';
        }
        return $data;
    }

    public static function preset(array $data): string
    {
        $preset = (string) ($data['overlap_preset'] ?? '');
        return in_array($preset, self::PRESETS, true) ? $preset : '';
    }

    /** 生效层级：作者显式选的优先，否则用预设自带的。 */
    public static function layer(array $data): string
    {
        $layer = (string) ($data['stack_layer'] ?? '');
        if (in_array($layer, self::LAYERS, true)) {
            return $layer;
        }
        return self::PRESET_LAYER[self::preset($data)] ?? '';
    }

    public static function isEnabled(array $data): bool
    {
        return self::preset($data) !== '' || self::layer($data) !== '';
    }

    /**
     * 要追加到元素根标签的类名（含前导空格）。未启用返回空串。
     */
    public static function classNames(array $data): string
    {
        $classes = '';
        $preset = self::preset($data);
        if ($preset !== '') {
            $classes .= ' ' . self::PRESET_CLASS . ' ' . self::PRESET_CLASS . '--' . str_replace('_', '-', $preset);
        }
        $layer = self::layer($data);
        if ($layer !== '') {
            $classes .= ' ' . self::LAYER_CLASS . ' ' . self::LAYER_CLASS . '--' . $layer;
        }
        if ($classes !== '' && class_exists('BloxAssetCollector')) {
            BloxAssetCollector::addStyle(self::STYLESHEET);
        }
        return $classes;
    }

    /**
     * 装饰背景层要不要连键盘也进不去。
     *
     * CSS 的 pointer-events:none 只挡鼠标，键盘 Tab 照样能落进去——一个看不见、
     * 点不着却能聚焦的东西对读屏用户是纯粹的干扰。inert 把整棵子树移出交互与
     * 无障碍树，正好对上任务书"必要的装饰层不可获得焦点"。
     */
    public static function isInert(array $data): bool
    {
        return self::layer($data) === 'back';
    }

    /**
     * 编辑器控件。挂在容器 / 图片 / 标题上（对应三个预设的典型用法）。
     *
     * @return list<array<string,mixed>>
     */
    public static function controls(): array
    {
        return [
            ['key' => 'overlap_preset', 'type' => 'select', 'label' => __('blox_overlap_preset'),
                'default' => '', 'tab' => 'style',
                'options' => ['' => __('blox_overlap_none'), 'lift' => __('blox_overlap_lift'),
                    'bleed' => __('blox_overlap_bleed'), 'title_over' => __('blox_overlap_title')],
                'option_icons' => ['lift' => 'arrow-up', 'bleed' => 'arrow-bar-left', 'title_over' => 'layers-subtract'],
                'hint' => __('blox_overlap_hint')],
            ['key' => 'stack_layer', 'type' => 'select', 'label' => __('blox_stack_layer'),
                'default' => '', 'tab' => 'style', 'advanced' => true,
                'options' => ['' => __('blox_stack_layer_normal'), 'front' => __('blox_stack_layer_front'),
                    'back' => __('blox_stack_layer_back')],
                'hint' => __('blox_stack_layer_hint')],
        ];
    }

    /**
     * 属性契约（验收门槛 1）。数值与 blox-overlap.css 一一对应，改 CSS 要同步改这里。
     *
     * @return list<array<string,mixed>>
     */
    public static function propertyContract(): array
    {
        return [
            ['key' => 'overlap_preset', 'type' => 'enum', 'default' => '',
                'options' => array_merge([''], self::PRESETS),
                'css' => [
                    'lift' => ['d' => 'margin-top:-64px', 't' => 'margin-top:-40px', 'm' => 'margin-top:0'],
                    'bleed' => ['d' => 'margin-left:-48px', 't' => 'margin-left:-24px', 'm' => 'margin-left:0'],
                    'title_over' => ['d' => 'margin-top:-48px', 't' => 'margin-top:-32px', 'm' => 'margin-top:0'],
                ],
                'note' => 'mobile always resets to 0'],
            ['key' => 'stack_layer', 'type' => 'enum', 'default' => '',
                'options' => array_merge([''], self::LAYERS),
                'css' => ['front' => 'position:relative;z-index:20', 'back' => 'position:relative;z-index:-1;pointer-events:none'],
                'note' => 'back also renders the inert attribute; defaults come from the preset'],
        ];
    }
}
