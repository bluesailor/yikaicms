<?php
/** 图标元素。对齐旧 case 'icon'（含 feather→Tabler 别名兼容）。 */

declare(strict_types=1);

final class IconElement extends AbstractElement
{
    private const FEATHER_ALIAS = [
        'zap' => 'bolt', 'globe' => 'world', 'info' => 'info-circle', 'life-buoy' => 'lifebuoy',
        'mic' => 'microphone', 'monitor' => 'device-desktop', 'pen-tool' => 'pencil',
        'smile' => 'mood-smile', 'tv' => 'device-tv', 'thumbs-up' => 'thumb-up',
        'check-circle' => 'circle-check', 'box' => 'box', 'settings' => 'settings',
    ];
    private const SIZE_MAP = ['sm' => '24px', 'md' => '32px', 'lg' => '48px', 'xl' => '64px'];

    public function type(): string { return 'icon'; }
    public function label(): string { return '图标'; }
    public function icon(): string { return 'star'; }

    public function render(array $data, string $children = ''): string
    {
        $iconName = $data['icon'] ?? 'star';
        $iconName = self::FEATHER_ALIAS[$iconName] ?? $iconName;
        $iconName = preg_replace('/[^a-z0-9-]/', '', (string) $iconName) ?: 'star';
        $iconSize = self::SIZE_MAP[$data['size'] ?? 'md'] ?? '32px';
        $iconColor = htmlspecialchars($data['color'] ?? '');
        $style = 'font-size:' . $iconSize . ';line-height:1;' . ($iconColor ? 'color:' . $iconColor . ';' : '');
        $iconText = htmlspecialchars($data['text'] ?? '');
        $html = '<div class="text-center my-2">';
        $html .= '<i class="ti ti-' . $iconName . ' inline-block" style="' . $style . '"></i>';
        if ($iconText) {
            $html .= '<div class="mt-1 text-sm">' . $iconText . '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
