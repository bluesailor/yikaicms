<?php
/** 通用数据统计项：图标、数字和说明文字，可在统计组中单独点选编辑。 */

declare(strict_types=1);

final class StatItemElement extends AbstractElement
{
    public function type(): string { return 'stat-item'; }
    public function label(): string { return __('blox_el_stat_item'); }
    public function icon(): string { return 'number-123'; }
    public function category(): string { return 'advanced'; }
    public function paletteVisible(string $context = 'page'): bool { return false; }
    public function canBeGenericChild(): bool { return false; }
    public function treeLabelField(): ?string { return 'label'; }

    public function controls(): array
    {
        return [
            ['key' => 'icon', 'type' => 'icon', 'label' => __('blox_ctl_icon'), 'default' => 'chart-bar'],
            ['key' => 'number', 'type' => 'text', 'label' => __('blox_ctl_number'), 'default' => '100+'],
            ['key' => 'label', 'type' => 'text', 'label' => __('blox_stat_caption'), 'default' => __('blox_stat_caption_seed')],
            ['key' => 'icon_color', 'type' => 'color', 'label' => __('blox_icon_color'), 'default' => '', 'tab' => 'style'],
            ['key' => 'number_color', 'type' => 'color', 'label' => __('blox_number_color'), 'default' => '', 'tab' => 'style'],
            ['key' => 'label_color', 'type' => 'color', 'label' => __('blox_text_color'), 'default' => '', 'tab' => 'style'],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $icon = $data['icon'] ?? 'chart-bar';
        $number = htmlspecialchars((string) ($data['number'] ?? ''), ENT_QUOTES);
        $label = htmlspecialchars((string) ($data['label'] ?? ''), ENT_QUOTES);
        $iconStyle = self::colorStyle($data['icon_color'] ?? null);
        $numberStyle = self::colorStyle($data['number_color'] ?? null);
        $labelStyle = self::colorStyle($data['label_color'] ?? null);

        $html = '<div class="yk-stat-item text-center px-2 py-3">';
        if (!BloxIcon::isNone($icon)) {
            $html .= '<i class="' . BloxIcon::classes($icon, 'chart-bar') . ' inline-block text-4xl text-primary mb-2 leading-none"' . $iconStyle . '></i>';
        }
        $html .= '<div class="stat-number text-3xl font-bold text-gray-900 mb-1" data-count="' . $number . '"' . $numberStyle . '>' . $number . '</div>';
        $html .= '<div class="text-sm text-gray-500"' . $labelStyle . '>' . $label . '</div>';
        return $html . '</div>';
    }

    public function stylesFor(array $data): array
    {
        $stylesheet = BloxIcon::stylesheet($data['icon'] ?? null);
        return $stylesheet === null ? [] : [$stylesheet];
    }

    private static function colorStyle(mixed $value): string
    {
        $color = self::cssColor($value);
        if ($color === null) {
            return '';
        }
        return ' style="color:' . htmlspecialchars($color, ENT_QUOTES) . ';"';
    }
}
