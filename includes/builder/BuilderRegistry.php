<?php
/**
 * YikaiCMS 页面构建器 —— 元素注册表。
 *
 * 内置元素在 bootstrap 里注册；插件可监听 builder_register_element action 追加。
 * 渲染器与后台 palette 都从这里取元素，实现可扩展。
 */

declare(strict_types=1);

final class BuilderRegistry
{
    /** @var array<string, AbstractElement> */
    private static array $elements = [];
    private static bool $booted = false;

    public static function register(AbstractElement $element): void
    {
        self::$elements[$element->type()] = $element;
    }

    public static function get(string $type): ?AbstractElement
    {
        self::boot();
        return self::$elements[$type] ?? null;
    }

    /** @return array<string, AbstractElement> */
    public static function all(): array
    {
        self::boot();
        return self::$elements;
    }

    /** 注册内置元素（幂等），随后广播 builder_register_element 让插件扩展 */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        foreach ([
            // 静态元素
            new HeadingElement(),
            new TextElement(),
            new ImageElement(),
            new ButtonElement(),
            new IconElement(),
            new CodeElement(),
            new DividerElement(),
            new SpacerElement(),
            // 动态元素（接 {yk:} 引擎 + 自定义模型）
            new ListDynamicElement(),
            new BannerElement(),
            new NavElement(),
        ] as $el) {
            self::register($el);
        }

        if (function_exists('do_action')) {
            do_action('builder_register_element');
        }
    }
}
