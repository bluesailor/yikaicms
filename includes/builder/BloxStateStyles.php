<?php
/**
 * 元素级交互状态样式（2.0.4，借鉴 WordPress 7.1）：按钮、导航链接可以直接设置悬停 / 键盘聚焦 / 按下
 * （导航另有「当前页」）时的颜色，不必先建全局类。
 *
 * - 控件：样式页签「状态」组里的颜色控件，键为 state_<状态>_<属性>；值经文档管线按 color 控件清洗。
 * - 渲染：按取值生成一个内容寻址的作用域类（yk-st-xxxxxxxxxx），规则写进页面收集的内联样式；
 *   目标是元素里的链接（按钮的 <a>、导航的各个 <a>），状态伪类与全局类同一套（BloxGlobalClasses::STATES）。
 *   声明带 !important：按钮颜色是内联样式，状态规则只在该状态下生效，所以不会压住平时的样子。
 */

declare(strict_types=1);

final class BloxStateStyles
{
    /** 可设置的属性：控件键后缀 => css 属性 */
    public const PROPS = ['text_color' => 'color', 'bg_color' => 'background-color', 'border_color' => 'border-color'];
    /** 导航专用：当前页的链接（NavCurrent 输出 aria-current） */
    public const CURRENT = '[aria-current]';

    /**
     * 「状态」组的颜色控件。
     * @param list<string> $props PROPS 的键
     * @param bool $withCurrent 是否加「当前页」一档（导航）
     * @return list<array<string,mixed>>
     */
    public static function controls(array $props, bool $withCurrent = false): array
    {
        $states = array_keys(BloxGlobalClasses::STATES);
        if ($withCurrent) array_unshift($states, 'current');
        $controls = [];
        foreach ($states as $state) {
            foreach ($props as $prop) {
                if (!isset(self::PROPS[$prop])) continue;
                $controls[] = [
                    'key' => 'state_' . $state . '_' . $prop, 'type' => 'color', 'default' => '', 'tab' => 'style', 'group' => 'states',
                    'label' => __('blox_state_' . $state) . ' · ' . __('blox_state_prop_' . $prop),
                ];
            }
        }
        return $controls;
    }

    /**
     * 有状态取值时给元素首标签加作用域类并收集规则；没有取值原样返回。
     * @param string $target 作用域类之后的目标选择器（如 ' a'）
     */
    public static function apply(string $html, array $data, string $target): string
    {
        $css = self::rules($data, '%scope%', $target);
        if ($html === '' || $css === '') return $html;
        $scope = 'yk-st-' . substr(hash('sha256', $target . '|' . $css), 0, 10);
        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag()) return $html;
        $existing = $processor->getAttribute('class');
        $processor->setAttribute('class', trim((is_string($existing) ? $existing : '') . ' ' . $scope));
        // 双写作用域类：特异性高于主题的按钮变体与 hover:text-primary 这类工具类
        BloxAssetCollector::addInlineCss(str_replace('%scope%', '.' . $scope . '.' . $scope, $css));
        return $processor->getUpdatedHtml();
    }

    /** 生成规则（scope 为占位符，便于测试与内容寻址）。顺序：当前页 → 悬停 → 聚焦 → 按下，后者在冲突时胜出。 */
    public static function rules(array $data, string $scope, string $target): string
    {
        $pseudos = ['current' => self::CURRENT] + BloxGlobalClasses::STATES;
        $css = '';
        foreach ($pseudos as $state => $pseudo) {
            $body = [];
            foreach (self::PROPS as $prop => $property) {
                $color = AbstractElement::cssColor($data['state_' . $state . '_' . $prop] ?? null);
                if ($color !== null) $body[] = $property . ':' . $color . '!important';
            }
            if ($body !== []) $css .= $scope . $target . $pseudo . '{' . implode(';', $body) . '}';
        }
        return $css;
    }
}
