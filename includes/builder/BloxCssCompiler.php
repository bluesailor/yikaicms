<?php
/** Declarative, instance-scoped CSS compiler for trusted element control schemas (E05). */

declare(strict_types=1);

final class BloxCssCompiler
{
    public const CONTROL_TYPE = 'css_length';
    private const MAX_DECLARATIONS = 24;
    private const TABLET_MIN = 768;
    private const DESKTOP_MIN = 1024;
    private const WIDE_MIN = 1440;

    /**
     * 可由控件声明的属性白名单与值类型。普通文档只提供值，属性、选择器与解析方式只来自可信 schema。
     * length: px 整数或一位小数；number: 无单位一位小数；int: 无单位整数；enum: 固定枚举。
     */
    private const PROPERTIES = [
        'font-size' => ['length', 8, 160],
        'line-height' => ['number', 0.8, 3.0],
        'font-weight' => ['enum', ['400', '500', '600', '700', '800']],
        'letter-spacing' => ['length', -5, 20],
        'padding-inline' => ['length', 0, 96],
        'padding-block' => ['length', 0, 96],
        'border-radius' => ['length', 0, 999],
        'gap' => ['length', 0, 160],
        // 0a 布局引擎：flex 容器与子项的受控任意值（枚举类布局值仍走既有类映射，输出不变）。
        'row-gap' => ['length', 0, 160],
        'column-gap' => ['length', 0, 160],
        'flex-basis' => ['length', 0, 1200],
        'flex-grow' => ['number', 0, 10],
        'flex-shrink' => ['number', 0, 10],
        'order' => ['int', -10, 10],
    ];

    /**
     * 编译一个元素实例的声明式 CSS。
     * 全断点相同 → 根标签内联声明；断点不同 → 内联自定义属性 + 固定标记类（规则见 responsiveStylesheet）。
     * 空值继承更宽断点；仅窄屏设置用限定范围的规则，保留宽屏原有样式。
     *
     * @param list<array<string,mixed>> $controls 元素 controls()（可信）
     * @param array<string,mixed> $data 文档值（不可信）
     * @return array{style:string,classes:list<string>}
     */
    public static function compile(array $controls, array $data): array
    {
        $declarations = [];
        foreach ($controls as $control) {
            if (!is_array($control) || !is_array($control['css'] ?? null)) {
                continue;
            }
            $key = (string) ($control['key'] ?? '');
            foreach ($control['css'] as $rule) {
                [$property] = self::assertRule($rule, $key);
                $resolved = self::resolve($property, $data[$key] ?? null);
                if ($resolved === null) {
                    continue;
                }
                // 同一属性后声明覆盖先声明，但保持首次出现的位置，输出顺序稳定。
                $declarations[$property] = $resolved;
            }
        }
        if (count($declarations) > self::MAX_DECLARATIONS) {
            throw new InvalidArgumentException('Too many declarative CSS rules');
        }

        $style = '';
        $classes = [];
        foreach ($declarations as $property => $value) {
            if ($value['d'] === null) {
                foreach (['t', 'm', 'w'] as $device) {
                    if ($value[$device] !== null) {
                        $style .= '--yk-r-' . $property . '-' . $device . ':' . $value[$device] . ';';
                        $classes[] = 'yk-r-' . $property . '-' . $device . '-only';
                    }
                }
                continue;
            }
            if ($value['d'] === $value['t'] && $value['t'] === $value['m'] && $value['d'] === $value['w']) {
                $style .= $property . ':' . $value['d'] . ';';
                continue;
            }
            foreach (['m', 't', 'd'] as $device) {
                $style .= '--yk-r-' . $property . '-' . $device . ':' . $value[$device] . ';';
            }
            // 宽屏变量只在与桌面不同时输出；样式表回退到桌面变量，旧文档与已缓存页面输出不变
            if ($value['w'] !== $value['d']) {
                $style .= '--yk-r-' . $property . '-w:' . $value['w'] . ';';
            }
            $classes[] = 'yk-r-' . $property;
        }
        return ['style' => $style, 'classes' => $classes];
    }

    /**
     * 与白名单一一对应的固定响应式规则（随 app.css 编译进静态样式表，不按页面生成）。
     * @api Build-time stylesheet contract, not a per-request renderer.
     */
    public static function responsiveStylesheet(): string
    {
        $base = $tablet = $desktop = $wide = $tabletOnly = $mobileOnly = $wideOnly = '';
        foreach (array_keys(self::PROPERTIES) as $property) {
            $selector = '.yk-r-' . $property;
            // Explicit instance controls outrank site defaults such as h2.yk-type-h2 without !important.
            $base .= $selector . $selector . '{' . $property . ':var(--yk-r-' . $property . '-m)}';
            $tablet .= $selector . $selector . '{' . $property . ':var(--yk-r-' . $property . '-t)}';
            $desktop .= $selector . $selector . '{' . $property . ':var(--yk-r-' . $property . '-d)}';
            $wide .= $selector . $selector . '{' . $property . ':var(--yk-r-' . $property . '-w,var(--yk-r-' . $property . '-d))}';
            $tabletOnly .= $selector . '-t-only' . $selector . '-t-only{' . $property . ':var(--yk-r-' . $property . '-t)}';
            $mobileOnly .= $selector . '-m-only' . $selector . '-m-only{' . $property . ':var(--yk-r-' . $property . '-m)}';
            $wideOnly .= $selector . '-w-only' . $selector . '-w-only{' . $property . ':var(--yk-r-' . $property . '-w)}';
        }
        return $base
            . '@media (min-width:' . self::TABLET_MIN . 'px){' . $tablet . '}'
            . '@media (min-width:' . self::DESKTOP_MIN . 'px){' . $desktop . '}'
            . '@media not all and (min-width:' . self::DESKTOP_MIN . 'px){' . $tabletOnly . '}'
            . '@media not all and (min-width:' . self::TABLET_MIN . 'px){' . $mobileOnly . '}'
            . '@media (min-width:' . self::WIDE_MIN . 'px){' . $wide . $wideOnly . '}';
    }

    /** 保存侧清洗：空串/缺失表示未设置，0 保留；越界或非法值清为未设置而不是回落默认数字。 */
    public static function sanitizeValue(array $control, mixed $value): mixed
    {
        $rules = is_array($control['css'] ?? null) ? $control['css'] : [];
        $property = is_array($rules[0] ?? null) ? (string) ($rules[0]['property'] ?? '') : '';
        if (!isset(self::PROPERTIES[$property])) {
            return '';
        }
        if (is_array($value)) {
            $out = [];
            foreach (['d', 't', 'm', 'w'] as $device) {
                $out[$device] = self::scalar($property, $value[$device] ?? null) ?? '';
            }
            if ($out['w'] === '') {
                unset($out['w']);
            }
            return $out['d'] === '' && $out['t'] === '' && $out['m'] === '' && !isset($out['w']) ? '' : $out;
        }
        return self::scalar($property, $value) ?? '';
    }

    /** @return array{0:string} */
    private static function assertRule(mixed $rule, string $key): array
    {
        if (!is_array($rule)) {
            throw new InvalidArgumentException('Invalid declarative CSS rule for control ' . $key);
        }
        $property = (string) ($rule['property'] ?? '');
        if (!isset(self::PROPERTIES[$property])) {
            throw new InvalidArgumentException('Declarative CSS property is not allowed for control ' . $key);
        }
        if ((string) ($rule['selector'] ?? '') !== '') {
            // 首版只作用于元素根：不接受子/兄弟/祖先选择器，杜绝越出实例作用域。
            throw new InvalidArgumentException('Declarative CSS selector is not allowed for control ' . $key);
        }
        return [$property];
    }

    /** @return array{d:?string,t:?string,m:?string,w:?string}|null */
    private static function resolve(string $property, mixed $raw): ?array
    {
        $values = is_array($raw) ? $raw : ['d' => $raw];
        $desktop = self::format($property, $values['d'] ?? null);
        $tablet = self::format($property, $values['t'] ?? null) ?? $desktop;
        $mobile = self::format($property, $values['m'] ?? null) ?? $tablet;
        // 宽屏继承桌面（更宽断点）；只设宽屏时仅在 ≥1440 生效；全站关闭宽屏档时忽略 w
        $wide = BloxResponsiveValue::wideEnabled() ? (self::format($property, $values['w'] ?? null) ?? $desktop) : $desktop;
        return $mobile === null && $wide === null ? null : ['d' => $desktop, 't' => $tablet, 'm' => $mobile, 'w' => $wide];
    }

    private static function format(string $property, mixed $raw): ?string
    {
        $value = self::scalar($property, $raw);
        if ($value === null) {
            return null;
        }
        return self::PROPERTIES[$property][0] === 'length' ? $value . 'px' : (string) $value;
    }

    private static function scalar(string $property, mixed $raw): int|float|string|null
    {
        [$type, $a, $b] = self::PROPERTIES[$property] + [null, null, null];
        if ($type === 'enum') {
            $value = is_scalar($raw) ? (string) $raw : '';
            return in_array($value, $a, true) ? $value : null;
        }
        if (is_string($raw)) {
            $raw = trim($raw);
            if (preg_match('/^-?\d{1,4}(?:\.\d)?$/D', $raw) !== 1) {
                return null;
            }
            $raw = str_contains($raw, '.') ? (float) $raw : (int) $raw;
        }
        if (!is_int($raw) && !is_float($raw)) {
            return null;
        }
        if (is_float($raw) && !is_finite($raw)) {
            return null;
        }
        $number = round((float) $raw, 1);
        if ($number < $a || $number > $b) {
            return null;
        }
        if ($type === 'int') {
            // order 等只接受整数的属性：小数不是合法 CSS 值，拒收而不是四舍五入。
            return floor($number) === $number ? (int) $number : null;
        }
        return floor($number) === $number && $type === 'length' ? (int) $number : $number;
    }
}
