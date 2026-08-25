<?php
/** 服务流程步骤：编号、图标、标题和说明均可单独编辑。 */

declare(strict_types=1);

final class ProcessStepElement extends AbstractElement
{
    public function type(): string { return 'process-step'; }
    public function label(): string { return __('blox_el_process_step'); }
    public function icon(): string { return 'route-alt-left'; }
    public function category(): string { return 'advanced'; }
    public function paletteVisible(string $context = 'page'): bool { return false; }
    public function canBeGenericChild(): bool { return false; }
    public function treeLabelField(): ?string { return 'title'; }

    public function controls(): array
    {
        return [
            ['key' => 'number', 'type' => 'text', 'label' => __('blox_process_number'), 'default' => '01'],
            ['key' => 'icon', 'type' => 'icon', 'label' => __('blox_ctl_icon'), 'default' => 'route'],
            ['key' => 'title', 'type' => 'text', 'label' => __('blox_field_title_short'), 'default' => __('blox_process_seed_1_title')],
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_ctl_desc'), 'default' => __('blox_process_seed_1_text'), 'rows' => 3],
            ['key' => 'accent_color', 'type' => 'color', 'label' => __('blox_icon_color'), 'default' => '', 'tab' => 'style'],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $number = htmlspecialchars(trim((string) ($data['number'] ?? '')), ENT_QUOTES);
        $title = htmlspecialchars(trim((string) ($data['title'] ?? '')), ENT_QUOTES);
        $text = htmlspecialchars(trim((string) ($data['text'] ?? '')), ENT_QUOTES);
        $icon = (string) ($data['icon'] ?? 'route');
        $accent = self::cssColor($data['accent_color'] ?? null);
        $accentStyle = $accent !== null ? ' style="color:' . htmlspecialchars($accent, ENT_QUOTES) . ';"' : '';

        $html = '<article class="yk-process-step h-full border-t border-gray-300 pt-5">';
        $html .= '<div class="mb-5 flex items-center justify-between gap-4 text-primary"' . $accentStyle . '>';
        $html .= '<span class="text-xs font-bold tabular-nums">' . $number . '</span>';
        if (!BloxIcon::isNone($icon)) {
            $html .= '<i class="' . BloxIcon::classes($icon, 'route') . ' text-2xl" aria-hidden="true"></i>';
        }
        $html .= '</div>';
        if ($title !== '') {
            $html .= '<h3 class="mb-2 text-lg font-semibold text-gray-900">' . $title . '</h3>';
        }
        if ($text !== '') {
            $html .= '<p class="text-sm leading-relaxed text-gray-600">' . $text . '</p>';
        }
        return $html . '</article>';
    }

    public function stylesFor(array $data): array
    {
        $stylesheet = BloxIcon::stylesheet($data['icon'] ?? null);
        return $stylesheet === null ? [] : [$stylesheet];
    }
}
