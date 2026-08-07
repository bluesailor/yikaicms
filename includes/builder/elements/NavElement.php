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
            $tpl = '<li><a href="{yk:field name=url /}" class="hover:text-primary">{yk:field name=name /}</a></li>';
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
