<?php
/** 面包屑导航：按当前页面的上级栏目自动生成，独立于「页面标题」元素。 */
declare(strict_types=1);

final class BreadcrumbElement extends AbstractElement
{
    private const SEPARATORS = ['slash' => '/', 'chevron' => '›', 'dot' => '·'];

    public function type(): string { return 'breadcrumb'; }
    public function label(): string { return __('blox_el_breadcrumb'); }
    public function icon(): string { return 'chevrons-right'; }
    public function isDynamic(): bool { return true; }
    public function backgroundRenderStrategy(): string { return 'native'; }

    /** 有页面上下文的编辑器才提供（单页、栏目页、产品页、联系页）。 */
    public function paletteVisible(string $context = 'page'): bool
    {
        return in_array($context, ['page', 'content-list', 'product', 'contact'], true);
    }

    public function controls(): array
    {
        return [
            ['key' => 'show_home', 'type' => 'checkbox', 'label' => __('blox_breadcrumb_show_home'), 'default' => true],
            ['key' => 'home_text', 'type' => 'text', 'label' => __('blox_breadcrumb_home_text'), 'default' => '',
                'placeholder' => __('breadcrumb_home'), 'required' => ['show_home', '=', true]],
            ['key' => 'show_current', 'type' => 'checkbox', 'label' => __('blox_breadcrumb_show_current'), 'default' => true],
            ['key' => 'separator', 'type' => 'select', 'label' => __('blox_breadcrumb_separator'), 'default' => 'slash', 'tab' => 'style',
                'options' => ['slash' => '/', 'chevron' => '›', 'dot' => '·']],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'default' => 'left', 'tab' => 'style',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')],
                'option_icons' => ['left' => 'align-left', 'center' => 'align-center', 'right' => 'align-right']],
            ['key' => 'size', 'type' => 'select', 'label' => __('blox_font_size'), 'default' => 'sm', 'tab' => 'style',
                'options' => ['xs' => __('blox_spacing_sm'), 'sm' => __('blox_spacing_md'), 'md' => __('blox_spacing_lg')]],
            ['key' => 'color', 'type' => 'color', 'label' => __('blox_text_color'), 'default' => '', 'tab' => 'style'],
            ...$this->backgroundControls(),
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $page = PageTitleElement::currentPage();
        if ($page === [] && !BlockRenderer::$showHidden) {
            return '';
        }
        $align = in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? (string) $data['align'] : 'left';
        $size = ['xs' => 'text-xs', 'sm' => 'text-sm', 'md' => 'text-base'][(string) ($data['size'] ?? 'sm')] ?? 'text-sm';
        $style = self::backgroundDeclarations($data);
        $color = self::cssColor($data['color'] ?? null);
        if ($color !== null) {
            $style .= ';color:' . $color;
        }
        $nav = self::renderTrail($page, [
            'show_home' => BloxValueSanitizer::truthy($data['show_home'] ?? true),
            'home_text' => trim((string) ($data['home_text'] ?? '')),
            'show_current' => BloxValueSanitizer::truthy($data['show_current'] ?? true),
            'separator' => (string) ($data['separator'] ?? 'slash'),
            'class' => $size . ' ' . ['left' => 'justify-start', 'center' => 'justify-center', 'right' => 'justify-end'][$align],
        ]);
        return '<div class="blox-breadcrumb" style="' . self::escape($style) . '"' . $this->animationAttrs($data) . '>' . $nav . '</div>';
    }

    /**
     * 面包屑导航 HTML，「页面标题」元素也用它，保证两处层级一致。
     * 没有页面上下文时（模板编辑画布）输出示意层级。
     *
     * @param array<string,mixed> $page
     * @param array{show_home?:bool,home_text?:string,show_current?:bool,separator?:string,class?:string} $options
     */
    public static function renderTrail(array $page, array $options = []): string
    {
        $items = [];
        if ($options['show_home'] ?? true) {
            $home = function_exists('langUrl') ? langUrl('/', (string) ($page['lang'] ?? '')) : '/';
            $items[] = ['name' => ($options['home_text'] ?? '') !== '' ? (string) $options['home_text'] : __('breadcrumb_home'), 'url' => $home];
        }
        if ($page === []) {
            $items[] = ['name' => __('blox_breadcrumb_sample_current'), 'url' => null];
        } else {
            foreach (self::parents($page) as $parent) {
                $items[] = ['name' => (string) $parent['name'], 'url' => function_exists('channelUrl') ? channelUrl($parent) : ''];
            }
            if ($options['show_current'] ?? true) {
                $items[] = ['name' => (string) ($page['name'] ?? ''), 'url' => null];
            }
        }
        if ($items === []) {
            return '';
        }
        $separator = self::SEPARATORS[(string) ($options['separator'] ?? 'slash')] ?? '/';
        $html = '<nav aria-label="' . self::escape(__('breadcrumb_nav')) . '"><ol class="flex flex-wrap items-center gap-2 ' . self::escape((string) ($options['class'] ?? 'text-sm')) . '">';
        foreach ($items as $index => $item) {
            if ($index > 0) {
                $html .= '<li aria-hidden="true">' . self::escape($separator) . '</li>';
            }
            $html .= $item['url'] === null
                ? '<li aria-current="page">' . self::escape($item['name']) . '</li>'
                : '<li><a class="underline hover:no-underline" href="' . self::escape(self::safeHref($item['url']) ?: '#') . '">' . self::escape($item['name']) . '</a></li>';
        }
        return $html . '</ol></nav>';
    }

    /** @param array<string,mixed> $page @return list<array<string,mixed>> */
    private static function parents(array $page): array
    {
        $parents = [];
        $seen = [(int) ($page['id'] ?? 0) => true];
        $parentId = (int) ($page['parent_id'] ?? 0);
        while ($parentId > 0 && !isset($seen[$parentId]) && count($parents) < 20) {
            $seen[$parentId] = true;
            $parent = channelModel()->find($parentId);
            if (!$parent) {
                break;
            }
            array_unshift($parents, $parent);
            $parentId = (int) ($parent['parent_id'] ?? 0);
        }
        return $parents;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
