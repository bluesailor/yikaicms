<?php
/** 地图 / 二维码元素：按语言与配置自动选高德 / 百度 / Google，未配置则回退静态图或二维码。 */

declare(strict_types=1);

final class ContactMapElement extends AbstractElement
{
    public function type(): string { return 'contact_map'; }
    public function label(): string { return __('blox_el_contact_map'); }
    public function icon(): string { return 'map-pin'; }
    public function paletteVisible(string $context = 'page'): bool { return false; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array { return []; }

    public function render(array $data, string $children = ''): string
    {
        require_once ROOT_PATH . '/includes/contact_parts.php';
        return renderContactMapHtml();
    }
}
