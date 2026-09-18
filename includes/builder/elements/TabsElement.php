<?php
declare(strict_types=1);

final class TabsElement extends AbstractElement
{
    public function type(): string { return 'tabs'; }
    public function label(): string { return __('blox_el_tabs'); }
    public function icon(): string { return 'layout-navbar'; }

    public function controls(): array
    {
        return [
            ['key' => 'items', 'type' => 'faq_repeater', 'label' => __('blox_tabs_items'), 'max' => 12,
                'add_label' => __('blox_tabs_add'), 'title_label' => __('blox_tabs_title'),
                'body_label' => __('blox_tabs_content'), 'delete_label' => __('blox_tabs_delete'),
                'new_title' => __('blox_tabs_new'), 'new_body' => __('blox_tabs_seed_content'),
                'limit_label' => __('blox_tabs_limit', ['n' => 12]),
                'default' => [
                    ['question' => __('blox_tabs_seed_overview'), 'answer' => __('blox_tabs_seed_content')],
                    ['question' => __('blox_tabs_seed_details'), 'answer' => __('blox_tabs_seed_content')],
                    ['question' => __('blox_tabs_seed_service'), 'answer' => __('blox_tabs_seed_content')],
                ]],
            ['key' => 'active_tab', 'type' => 'number', 'label' => __('blox_tabs_active'), 'default' => 1, 'min' => 1, 'max' => 12],
            ['key' => 'tabs_style', 'type' => 'select', 'label' => __('blox_tabs_style'), 'tab' => 'style', 'default' => 'underline',
                'options' => ['underline' => __('blox_tabs_underline'), 'segmented' => __('blox_tabs_segmented'), 'bordered' => __('blox_tabs_bordered')],
                'option_icons' => ['underline' => 'border-bottom', 'segmented' => 'layout-columns', 'bordered' => 'border-all']],
        ];
    }

    public function scripts(): array { return ['/assets/js/blox-tabs.js']; }
    public function styles(): array { return ['/assets/css/blox-tabs.css']; }

    public function render(array $data, string $children = ''): string
    {
        $items = array_slice((new AccordionElement())->items($data), 0, 12);
        if (!$items) return '';
        $style = in_array($data['tabs_style'] ?? '', ['segmented', 'bordered'], true) ? $data['tabs_style'] : 'underline';
        $active = max(0, min(count($items) - 1, (int) ($data['active_tab'] ?? 1) - 1));
        // Per-render IDs stay unique for repeated elements and cached fragments.
        $id = 'yk-tabs-' . bin2hex(random_bytes(8));
        $html = '<div class="yk-tabs" data-blox-tabs data-tabs-style="' . $style . '" data-active-tab="' . $active . '">';
        $html .= '<div class="yk-tabs-nav" role="tablist" aria-label="' . e($this->label()) . '">';
        foreach ($items as $i => [$title]) {
            $html .= '<button type="button" role="tab" class="yk-tabs-tab" id="' . $id . '-tab-' . $i . '"'
                . ' aria-controls="' . $id . '-panel-' . $i . '" aria-selected="' . ($active === $i ? 'true' : 'false') . '"'
                . ' tabindex="' . ($active === $i ? '0' : '-1') . '">' . e($title) . '</button>';
        }
        $html .= '</div>';
        foreach ($items as $i => [, $content]) {
            $rich = ($items[$i][2] ?? '') === 'html';
            $html .= '<div class="yk-tabs-panel yk-description" role="tabpanel" id="' . $id . '-panel-' . $i . '"'
                . ' aria-labelledby="' . $id . '-tab-' . $i . '" tabindex="0">'
                . ($rich ? $content : nl2br(e($content))) . '</div>';
        }
        return $html . '</div>';
    }
}
