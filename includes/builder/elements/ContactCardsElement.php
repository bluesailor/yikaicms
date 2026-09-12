<?php
/** 联系信息卡片元素：渲染「联系我们设置」里维护的卡片（电话/邮箱/地址…）。 */

declare(strict_types=1);

final class ContactCardsElement extends AbstractElement
{
    public function type(): string { return 'contact_cards'; }
    public function label(): string { return __('blox_el_contact_cards'); }
    public function icon(): string { return 'address-book'; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'contact'; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'card_layout', 'type' => 'select', 'label' => __('blox_contact_layout'), 'default' => 'classic', 'tab' => 'style',
                'option_preview' => 'contact-layout',
                'options' => ['classic' => __('blox_contact_layout_classic'), 'side' => __('blox_contact_layout_side'), 'list' => __('blox_contact_layout_list')]],
            ['key' => 'cols', 'type' => 'select', 'label' => __('blox_cols_per_row'), 'default' => 'auto', 'tab' => 'style',
                'options' => ['auto' => __('blox_contact_cols_auto'), '1' => __('blox_n_cols', ['n' => 1]), '2' => __('blox_n_cols', ['n' => 2]), '3' => __('blox_n_cols', ['n' => 3]), '4' => __('blox_n_cols', ['n' => 4])]],
            ['key' => 'card_align', 'type' => 'select', 'label' => __('blox_align'), 'default' => 'auto', 'tab' => 'style',
                'options' => ['auto' => __('blox_button_hover_default'), 'left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')],
                'option_icons' => ['auto' => 'adjustments', 'left' => 'align-left', 'center' => 'align-center', 'right' => 'align-right']],
            ['key' => 'show_icons', 'type' => 'checkbox', 'label' => __('blox_contact_show_icons'), 'default' => true, 'tab' => 'style'],
            ['key' => 'icon_surface', 'type' => 'select', 'label' => __('blox_icon_box_surface'), 'default' => 'circle', 'tab' => 'style',
                'option_preview' => 'icon-surface', 'required' => ['show_icons', '=', true],
                'options' => ['circle' => __('blox_icon_box_circle'), 'square' => __('blox_icon_box_square'), 'none' => __('blox_icon_box_none')]],
            ['key' => 'card_gap', 'type' => 'select', 'label' => __('blox_item_gap'), 'default' => 'md', 'tab' => 'style',
                'options' => ['sm' => __('blox_spacing_sm'), 'md' => __('blox_spacing_md'), 'lg' => __('blox_spacing_lg')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        require_once ROOT_PATH . '/includes/contact_parts.php';
        $cards = contactCardsData();
        if (empty($cards)) {
            return '';   // 未配置卡片时不占位，避免前台留白
        }
        $cols = (string) ($data['cols'] ?? 'auto');
        $grid = match ($cols) {
            '1' => 'md:grid-cols-1',
            '2' => 'md:grid-cols-2',
            '3' => 'md:grid-cols-3',
            '4' => 'md:grid-cols-2 lg:grid-cols-4',
            default => ($data['card_layout'] ?? '') === 'list' ? 'md:grid-cols-1' : contactGridCols(count($cards)),
        };
        // 区块间距由 section 管理；固定联系页仍通过默认参数保留历史 mb-12。
        return renderContactCardsHtml($cards, $grid, null, null, false, $data);
    }
}
