<?php
/**
 * 导航元素 —— {yk:nav parent=} 的可拖拽版。
 * data：menu_group（0=栏目投影）、parent（父栏目 id/slug，空=顶级）、
 * nav_only、wrap_class（外层 ul class）。
 * 无内层模板时用默认 <li><a>，需要专属样式可在 template[] 放子元素（同 list-dynamic）。
 */

declare(strict_types=1);

final class NavElement extends AbstractElement
{
    public function type(): string { return 'nav'; }
    public function label(): string { return __('blox_el_nav'); }
    public function icon(): string { return 'menu-2'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'menu_group', 'type' => 'select', 'label' => __('blox_menu_source'), 'default' => 0,
                'options' => [0 => __('blox_menu_source_default')] + NavMegaElement::menuGroupOptions()],
            ['key' => 'parent', 'type' => 'text', 'label' => __('blox_nav_parent'), 'default' => '', 'placeholder' => __('blox_empty_top_level')],
            ['key' => 'nav_only', 'type' => 'checkbox', 'label' => __('blox_nav_only'), 'default' => true],
            ['key' => 'dropdown', 'type' => 'checkbox', 'label' => __('blox_nav_dropdown'), 'default' => false],
            ['key' => 'desktop_only', 'type' => 'checkbox', 'label' => __('blox_nav_desktop_only'), 'default' => false],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $usesDefaultRoot = trim((string) ($data['parent'] ?? '')) === ''
            && (string) ($data['nav_only'] ?? '1') !== '0'
            && empty($data['template'])
            && function_exists('getDefaultNavigation');
        if ((int) ($data['menu_group'] ?? 0) > 0 || $usesDefaultRoot) {
            return $this->renderMenuGroup($data);
        }
        $markup = $this->buildMarkup($data);
        return class_exists('TagEngine') ? \TagEngine::render($markup) : $markup;
    }

    private function renderMenuGroup(array $data): string
    {
        $nodes = NavMegaElement::navTree($data);
        if ($nodes === []) {
            return '';
        }
        $items = '';
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $items .= $this->renderMenuNode($node, !empty($data['dropdown']));
        }
        $wrapClass = htmlspecialchars($this->wrapClass($data), ENT_QUOTES);
        return '<ul class="' . $wrapClass . '">' . $items . '</ul>';
    }

    /** @param array<string,mixed> $node */
    private function renderMenuNode(array $node, bool $dropdown): string
    {
        $name = htmlspecialchars((string) ($node['name'] ?? ''), ENT_QUOTES);
        $url = htmlspecialchars(NavMegaElement::nodeHref($node), ENT_QUOTES);
        $children = is_array($node['children'] ?? null)
            ? array_values(array_filter($node['children'], 'is_array'))
            : [];
        if (!$dropdown || $children === []) {
            return '<li><a href="' . $url . '"' . NavMegaElement::targetAttr($node)
                . ' class="hover:text-primary">' . $name . '</a></li>';
        }

        $nested = '';
        foreach ($children as $child) {
            $nested .= '<li><a href="' . htmlspecialchars(NavMegaElement::nodeHref($child), ENT_QUOTES) . '"'
                . NavMegaElement::targetAttr($child)
                . ' class="block px-4 py-2 text-sm text-gray-600 hover:bg-gray-50 hover:text-primary">'
                . htmlspecialchars((string) ($child['name'] ?? ''), ENT_QUOTES) . '</a></li>';
        }
        return '<li class="relative group/nav"><a href="' . $url . '"' . NavMegaElement::targetAttr($node)
            . ' class="inline-flex items-center gap-1 hover:text-primary">' . $name . $this->dropdownCaret() . '</a>'
            . '<ul class="absolute left-0 top-full z-30 hidden w-max min-w-[10rem] rounded-xl border border-gray-100 bg-white py-2 shadow-lg group-hover/nav:block">'
            . $nested . '</ul></li>';
    }

    private function dropdownCaret(): string
    {
        return '<svg data-yk-nav-caret class="h-3 w-3 shrink-0 opacity-60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>';
    }

    public function buildMarkup(array $data): string
    {
        $tpl = '';
        foreach ($data['template'] ?? [] as $child) {
            $el = BuilderRegistry::get((string) ($child['type'] ?? ''));
            if ($el !== null) {
                $tpl .= $el->render(is_array($child['data'] ?? null) ? $child['data'] : []);
            }
        }
        if ($tpl === '') {
            $tpl = !empty($data['dropdown'])
                // 桌面多级下拉：{yk:subnav} 循环子栏目，wrap 包裹在无子级时整体省略（叶子项悬停不出空面板）；
                // 下拉箭头按 has_children 条件渲染。CSS hover 展开（group/nav 命名组），移动端配 nav-drawer。
                ? '<li class="relative group/nav">'
                    . '<a href="{yk:field name=url /}" class="inline-flex items-center gap-1 hover:text-primary">{yk:field name=name /}'
                    . '{yk:if field=has_children op=eq value=1}' . $this->dropdownCaret() . '{/yk:if}'
                    . '</a>'
                    . '{yk:subnav wrap=ul class="absolute left-0 top-full z-30 hidden w-max min-w-[10rem] rounded-xl border border-gray-100 bg-white py-2 shadow-lg group-hover/nav:block"}'
                    . '<li><a href="{yk:field name=url /}" class="block px-4 py-2 text-sm text-gray-600 hover:bg-gray-50 hover:text-primary">{yk:field name=name /}</a></li>'
                    . '{/yk:subnav}</li>'
                : '<li><a href="{yk:field name=url /}" class="hover:text-primary">{yk:field name=name /}</a></li>';
        }

        $attrs = '';
        if (isset($data['parent']) && $data['parent'] !== '') {
            $attrs .= ' parent=' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $data['parent']);
        }
        if (isset($data['nav_only']) && (string) $data['nav_only'] === '0') {
            $attrs .= ' nav_only=0';
        }

        $wrapClass = htmlspecialchars($this->wrapClass($data));
        return '<ul class="' . $wrapClass . '">{yk:nav' . $attrs . '}' . $tpl . '{/yk:nav}</ul>';
    }

    /** @param array<string,mixed> $data */
    private function wrapClass(array $data): string
    {
        $class = trim((string) ($data['wrap_class'] ?? 'flex flex-wrap gap-4'));
        if (!empty($data['desktop_only'])) {
            $class = 'hidden xl:flex ' . preg_replace('/(^|\s)flex(?=\s|$)/', '', $class);
        }
        return trim((string) preg_replace('/\s+/', ' ', $class));
    }
}
