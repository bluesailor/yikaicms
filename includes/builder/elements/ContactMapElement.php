<?php
/** 地图 / 二维码元素：按语言与配置自动选高德 / 百度 / Google，未配置则回退静态图或二维码。 */

declare(strict_types=1);

final class ContactMapElement extends AbstractElement
{
    public function type(): string { return 'contact_map'; }
    public function label(): string { return '地图 / 二维码'; }
    public function icon(): string { return 'map-pin'; }

    public function controls(): array { return []; }

    public function render(array $data, string $children = ''): string
    {
        require_once ROOT_PATH . '/includes/contact_parts.php';
        return renderContactMapHtml();
    }
}
