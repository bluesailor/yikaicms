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

    /**
     * 元素元数据（label/category/icon/controls/defaults/dynamic），供后台构建器 JS 生成
     * palette 与设置表单。加了元素类即自动出现在后台，无需手写 UI（简单控件）。
     */
    public static function meta(): array
    {
        self::boot();
        $out = [];
        foreach (self::$elements as $type => $el) {
            $out[$type] = [
                'type'      => $type,
                'label'     => $el->label(),
                'category'  => $el->category(),
                'icon'      => $el->icon(),
                'controls'  => $el->controls(),
                'defaults'  => $el->defaults(),
                'dynamic'   => $el->isDynamic(),
                'container' => $el->isContainer(),
            ];
        }
        return $out;
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
            // 扩充静态元素（schema 驱动，后台自动 UI）
            new AlertElement(),
            new CtaElement(),
            new QuoteElement(),
            new CardElement(),
            new VideoElement(),
            new IconBoxElement(),
            new AccordionElement(),
            // 布局容器（一层嵌套；子元素在 data.children）
            new ContainerElement(),
            new DivElement(),
            // 动态元素（接 {yk:} 引擎 + 自定义模型）
            new ListDynamicElement(),
            new BannerElement(),
            new NavElement(),
            new HomeBannerItemElement(),
            new HomeBlockElement(),
        ] as $el) {
            self::register($el);
        }

        if (function_exists('do_action')) {
            do_action('builder_register_element');
        }
    }
}
