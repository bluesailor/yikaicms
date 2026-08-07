<?php
/**
 * 导航元素 —— {yk:nav parent=} 的可拖拽版。
 * data：parent（父栏目 id/slug，空=顶级）、nav_only、wrap_class（外层 ul class）。
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
            ['key' => 'parent', 'type' => 'text', 'label' => __('blox_nav_parent'), 'default' => '', 'placeholder' => __('blox_empty_top_level')],
            ['key' => 'nav_only', 'type' => 'checkbox', 'label' => __('blox_nav_only'), 'default' => true],
            ['key' => 'dropdown', 'type' => 'checkbox', 'label' => __('blox_nav_dropdown'), 'default' => false],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $markup = $this->buildMarkup($data);
        return class_exists('TagEngine') ? \TagEngine::render($markup) : $markup;
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
                    . '{yk:if field=has_children op=eq value=1}<svg class="h-3 w-3 opacity-60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>{/yk:if}'
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

        $wrapClass = htmlspecialchars((string) ($data['wrap_class'] ?? 'flex flex-wrap gap-4'));
        return '<ul class="' . $wrapClass . '">{yk:nav' . $attrs . '}' . $tpl . '{/yk:nav}</ul>';
    }
}
