<?php
/**
 * 极光动态背景（2.0.4，借鉴 Avada 7.16）：容器 / 布局块的背景选项。
 *
 * 不新增 DOM：根标签挂 yk-aurora 类与几条 CSS 变量，光斑画在 ::before 上（在元素自己的
 * 背景色 / 背景图之上、子元素之下），只用 transform 动画交给 GPU。规则固定写在 app.css，
 * 与 stylesheet() 逐字一致（单测校验）。访客开了「减少动态」或全站动效不是标准时静止。
 */

declare(strict_types=1);

final class BloxAurora
{
    public const SPEEDS = ['slow' => '30s', 'medium' => '18s', 'fast' => '10s'];
    public const SIZES = ['sm' => '35%', 'md' => '50%', 'lg' => '70%'];
    public const BLURS = ['none' => '0px', 'sm' => '24px', 'md' => '48px', 'lg' => '80px'];
    /** 未填的颜色取站点色 token（主色、辅助色）与两种柔和点缀 */
    private const DEFAULT_COLORS = [
        'var(--yk-color-primary,#2563eb)',
        'var(--yk-color-secondary,#1d4ed8)',
        '#22d3ee',
        '#a78bfa',
    ];

    /** @return list<array<string,mixed>> */
    public static function controls(): array
    {
        $when = ['terms' => [['bg_aurora', '=', true]]];
        $controls = [
            ['key' => 'bg_aurora', 'type' => 'checkbox', 'label' => __('blox_aurora'), 'default' => false, 'tab' => 'style', 'group' => 'background',
                'help' => __('blox_aurora_help')],
        ];
        for ($i = 1; $i <= 4; $i++) {
            $controls[] = ['key' => 'bg_aurora_color_' . $i, 'type' => 'color', 'label' => __('blox_aurora_color', ['n' => $i]), 'default' => '',
                'tab' => 'style', 'group' => 'background', 'visible_when' => $when];
        }
        return [
            ...$controls,
            ['key' => 'bg_aurora_speed', 'type' => 'select', 'label' => __('blox_aurora_speed'), 'default' => 'medium', 'tab' => 'style', 'group' => 'background',
                'options' => ['slow' => __('blox_aurora_slow'), 'medium' => __('blox_aurora_medium'), 'fast' => __('blox_aurora_fast')], 'visible_when' => $when],
            ['key' => 'bg_aurora_size', 'type' => 'select', 'label' => __('blox_aurora_size'), 'default' => 'md', 'tab' => 'style', 'group' => 'background',
                'options' => ['sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg')], 'visible_when' => $when],
            ['key' => 'bg_aurora_blur', 'type' => 'select', 'label' => __('blox_aurora_blur'), 'default' => 'md', 'tab' => 'style', 'group' => 'background',
                'options' => ['none' => __('blox_spacing_none'), 'sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg')], 'visible_when' => $when],
            ['key' => 'bg_aurora_opacity', 'type' => 'range', 'label' => __('blox_aurora_opacity'), 'default' => 60, 'min' => 10, 'max' => 100, 'step' => 5,
                'tab' => 'style', 'group' => 'background', 'visible_when' => $when],
        ];
    }

    /**
     * 区块列的极光设置（2.0.5）：列不是元素，没有控件声明来过滤，存盘前在这里收窄成白名单键与合法值。
     * 未开启时整组不存。
     * @return array<string,mixed>
     */
    public static function normalizeStored(array $data): array
    {
        if (!self::enabled($data)) {
            return [];
        }
        $clean = ['bg_aurora' => true];
        for ($i = 1; $i <= 4; $i++) {
            $color = AbstractElement::cssColor($data['bg_aurora_color_' . $i] ?? null);
            if ($color !== null) $clean['bg_aurora_color_' . $i] = $color;
        }
        foreach (['bg_aurora_speed' => self::SPEEDS, 'bg_aurora_size' => self::SIZES, 'bg_aurora_blur' => self::BLURS] as $key => $choices) {
            if (isset($choices[(string) ($data[$key] ?? '')])) $clean[$key] = (string) $data[$key];
        }
        if (is_numeric($data['bg_aurora_opacity'] ?? null)) {
            $clean['bg_aurora_opacity'] = max(10, min(100, (int) $data['bg_aurora_opacity']));
        }
        return $clean;
    }

    public static function enabled(array $data): bool
    {
        return !in_array($data['bg_aurora'] ?? false, [false, 0, '0', '', null], true);
    }

    /** 根标签上的 CSS 变量（只来自白名单档位与校验过的颜色）。未开启返回 ''。 */
    public static function declarations(array $data): string
    {
        if (!self::enabled($data)) {
            return '';
        }
        $css = '';
        for ($i = 1; $i <= 4; $i++) {
            $color = AbstractElement::cssColor($data['bg_aurora_color_' . $i] ?? null) ?? self::DEFAULT_COLORS[$i - 1];
            $css .= '--yk-aurora-' . $i . ':' . $color . ';';
        }
        $css .= '--yk-aurora-speed:' . (self::SPEEDS[(string) ($data['bg_aurora_speed'] ?? '')] ?? self::SPEEDS['medium']) . ';';
        $css .= '--yk-aurora-size:' . (self::SIZES[(string) ($data['bg_aurora_size'] ?? '')] ?? self::SIZES['md']) . ';';
        $css .= '--yk-aurora-blur:' . (self::BLURS[(string) ($data['bg_aurora_blur'] ?? '')] ?? self::BLURS['md']) . ';';
        $opacity = is_numeric($data['bg_aurora_opacity'] ?? null) ? (int) $data['bg_aurora_opacity'] : 60;
        $css .= '--yk-aurora-opacity:' . (max(10, min(100, $opacity)) / 100) . ';';
        return $css;
    }

    /** 把类与变量挂到元素根标签。 */
    public static function apply(string $html, array $data): string
    {
        $vars = self::declarations($data);
        if ($html === '' || $vars === '') {
            return $html;
        }
        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag()) {
            return $html;
        }
        $style = $processor->getAttribute('style');
        $style = is_string($style) ? trim($style) : '';
        if ($style !== '' && !str_ends_with($style, ';')) {
            $style .= ';';
        }
        $processor->setAttribute('style', $style . $vars);
        $class = $processor->getAttribute('class');
        $processor->setAttribute('class', trim((is_string($class) ? $class : '') . ' yk-aurora'));
        return $processor->getUpdatedHtml();
    }

    /**
     * 固定规则（编进 app.css）。isolation 让 z-index:-1 的光斑留在元素自己的背景之上、子元素之下；
     * overflow:clip 裁掉放大与模糊溢出的部分（不建滚动容器）。
     * @api Build-time stylesheet contract, not a per-request renderer.
     */
    public static function stylesheet(): string
    {
        $spot = static fn (int $n, string $at): string => 'radial-gradient(circle at ' . $at . ',var(--yk-aurora-' . $n . ') 0,transparent var(--yk-aurora-size))';
        return '.yk-aurora{position:relative;isolation:isolate;overflow:clip}'
            . '.yk-aurora::before{content:"";position:absolute;inset:-20%;z-index:-1;pointer-events:none;'
            . 'background:' . $spot(1, '20% 30%') . ',' . $spot(2, '80% 20%') . ',' . $spot(3, '70% 80%') . ',' . $spot(4, '25% 75%') . ';'
            . 'filter:blur(var(--yk-aurora-blur));opacity:var(--yk-aurora-opacity);will-change:transform;'
            . 'animation:yk-aurora var(--yk-aurora-speed) ease-in-out infinite alternate}'
            . '@keyframes yk-aurora{0%{transform:translate3d(-8%,-6%,0) rotate(0deg) scale(1)}'
            . '50%{transform:translate3d(6%,4%,0) rotate(6deg) scale(1.08)}'
            . '100%{transform:translate3d(-3%,8%,0) rotate(-5deg) scale(1.04)}}'
            . '@media (prefers-reduced-motion:reduce){.yk-aurora::before{animation:none}}';
    }
}
