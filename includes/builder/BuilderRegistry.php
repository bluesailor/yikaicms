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
     *
     * 调用方：admin/blox_editor.php（付费文件，CI 的 Psalm 任务里不存在）、
     * admin/page_edit_advance.php（调用点埋在 $extraJs 拼接串里，Psalm 认不出）、
     * 以及 tests/（psalm.xml 未纳入分析）——三者都躲开了未使用检查。
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function meta(string $context = 'page'): array
    {
        self::boot();
        $out = [];
        foreach (self::$elements as $type => $el) {
            $childRules = $el->childRules();
            $defaults = $el->defaults();
            $out[$type] = [
                'type'      => $type,
                'label'     => $el->label(),
                'category'  => $el->category(),
                'icon'      => $el->icon(),
                'controls'  => $el->controls(),
                'defaults'  => $defaults,
                'dynamic'   => $el->isDynamic(),
                'container' => $el->isContainer(),
                'paletteVisible' => $el->paletteVisible($context),
                'allowedChildren' => $childRules === [] ? $el->allowedChildren($defaults) : [],
                'childRules' => $childRules,
                'genericChild' => $el->canBeGenericChild(),
                'supportsBoxStyles' => $el->supportsBoxStyles(),
                'scripts' => $el->scripts(),
                'styles' => $el->styles(),
                'treeLabelField' => $el->treeLabelField(),
                'deprecated' => $el->deprecated(),
                'missing' => false,
                'plugin' => BloxPluginRegistry::ownerOf($type),
            ];
        }
        foreach (BloxPluginRegistry::missingElementMeta(array_keys(self::$elements)) as $type => $meta) {
            $out[$type] = $meta;
        }
        return $out;
    }

    /** @param array<string,mixed> $parentData */
    /** @psalm-suppress PossiblyUnusedMethod Public child-policy contract for plugins and editor adapters. */
    public static function allowsChild(AbstractElement $parent, AbstractElement $child, array $parentData = []): bool
    {
        $allowed = $parent->allowedChildren($parentData);
        if (in_array($child->type(), $allowed, true)) {
            return true;
        }

        return in_array('*', $allowed, true)
            && !$child->isContainer()
            && $child->canBeGenericChild();
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
            new OrgChartElement(),
            new StatItemElement(),
            new ProcessStepElement(),
            new LogoElement(),
            new SiteCopyrightElement(),
            new SiteContactElement(),
            new SocialLinksElement(),
            new SiteSearchElement(),
            new LanguageSwitcherElement(),
            new NavDrawerElement(),
            new NavMegaElement(),
            // 布局容器（一层嵌套；子元素在 data.children）
            new ContainerElement(),
            new DivElement(),
            new StatsGroupElement(),
            new ProcessStepsElement(),
            // 动态元素（接 {yk:} 引擎 + 自定义模型）
            new ListDynamicElement(),
            new ContentCatalogElement(),
            new ProductCatalogElement(),
            new BannerElement(),
            new NavElement(),
            new HomeBannerItemElement(),
            new HomeBlockElement(),
            // 联系页组成部分（读「联系我们设置」/表单设计器的数据，可拖到任意位置）
            new ContactCardsElement(),
            new ContactFormElement(),
            new ContactMapElement(),
        ] as $el) {
            self::register($el);
        }

        if (function_exists('do_action')) {
            do_action('builder_register_element');
        }
    }
}
