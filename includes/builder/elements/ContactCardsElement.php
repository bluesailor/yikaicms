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
            ['key' => 'cols', 'type' => 'select', 'label' => __('blox_cols_per_row'), 'default' => 'auto',
                'options' => ['auto' => __('blox_cols_auto'), '2' => __('blox_n_cols', ['n' => 2]), '3' => __('blox_n_cols', ['n' => 3]), '4' => __('blox_n_cols', ['n' => 4])]],
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
            '2' => 'md:grid-cols-2',
            '3' => 'md:grid-cols-3',
            '4' => 'md:grid-cols-2 lg:grid-cols-4',
            default => contactGridCols(count($cards)),
        };
        // 区块间距由 section 管理；固定联系页仍通过默认参数保留历史 mb-12。
        return renderContactCardsHtml($cards, $grid, null, null, false);
    }
}
