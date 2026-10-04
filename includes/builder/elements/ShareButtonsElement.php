<?php
/**
 * 分享按钮（2.0.4，WordPress 迁移：原站产品页有分享）：Facebook、X、LinkedIn、WhatsApp、邮件、复制链接。
 * 链接在服务端按当前地址生成（无脚本也能用）；前台脚本改用页面的规范网址（canonical）并接管「复制链接」。
 * 只是外链按钮，不加载任何第三方脚本，也不收集数据。
 */

declare(strict_types=1);

final class ShareButtonsElement extends AbstractElement
{
    /** 网络 => [图标, 分享地址模板（{url} {title}）] */
    private const NETWORKS = [
        'facebook' => ['brand-facebook', 'https://www.facebook.com/sharer/sharer.php?u={url}'],
        'x' => ['brand-x', 'https://twitter.com/intent/tweet?url={url}&text={title}'],
        'linkedin' => ['brand-linkedin', 'https://www.linkedin.com/sharing/share-offsite/?url={url}'],
        'whatsapp' => ['brand-whatsapp', 'https://wa.me/?text={title}%20{url}'],
        'email' => ['mail', 'mailto:?subject={title}&body={url}'],
        'copy' => ['link', ''],
    ];
    private const BRAND_NAMES = ['facebook' => 'Facebook', 'x' => 'X', 'linkedin' => 'LinkedIn', 'whatsapp' => 'WhatsApp'];

    public function type(): string { return 'share-buttons'; }
    public function label(): string { return __('blox_el_share_buttons'); }
    public function icon(): string { return 'share'; }
    public function category(): string { return 'dynamic'; }
    public function scripts(): array { return ['/assets/js/blox-share.js']; }

    public function controls(): array
    {
        $controls = [
            ['key' => 'label', 'type' => 'text', 'label' => __('blox_share_label'), 'default' => __('blox_share_label_default')],
        ];
        foreach (array_keys(self::NETWORKS) as $network) {
            $controls[] = ['key' => 'share_' . $network, 'type' => 'checkbox', 'label' => self::networkLabel($network), 'default' => true];
        }
        return [
            ...$controls,
            ['key' => 'style', 'type' => 'select', 'label' => __('blox_social_style'), 'default' => 'outline', 'tab' => 'style',
                'options' => ['outline' => __('blox_social_outline'), 'solid' => __('blox_social_solid')]],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'default' => 'left', 'tab' => 'style',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $url = self::currentUrl();
        $title = '';   // 页面标题由前台脚本填（document.title），服务端链接只带网址
        $solid = ($data['style'] ?? 'outline') === 'solid';
        $button = $solid ? 'bg-gray-900 text-white hover:bg-primary' : 'border border-gray-300 text-gray-700 hover:border-primary hover:text-primary';
        $items = '';
        foreach (self::NETWORKS as $network => [$icon, $template]) {
            if (!self::enabled($data, 'share_' . $network)) {
                continue;
            }
            $name = self::networkLabel($network);
            $class = 'inline-flex h-9 w-9 items-center justify-center rounded-full transition ' . $button;
            $glyph = '<i class="' . BloxIcon::classes($icon) . ' text-lg" aria-hidden="true"></i>';
            if ($network === 'copy') {
                $items .= '<button type="button" class="' . $class . '" data-yk-share-copy data-copied="' . self::h(__('blox_share_copied')) . '"'
                    . ' aria-label="' . self::h($name) . '" title="' . self::h($name) . '">' . $glyph . '</button>';
                continue;
            }
            $href = strtr($template, ['{url}' => rawurlencode($url), '{title}' => rawurlencode($title)]);
            $items .= '<a href="' . self::h($href) . '" data-yk-share-network="' . $network . '"'
                . ($network === 'email' ? '' : ' target="_blank" rel="nofollow noopener"')
                . ' class="' . $class . '" aria-label="' . self::h($name) . '" title="' . self::h($name) . '">' . $glyph . '</a>';
        }
        if ($items === '') {
            return '';
        }
        $justify = ['center' => 'justify-center', 'right' => 'justify-end'][(string) ($data['align'] ?? 'left')] ?? 'justify-start';
        $label = trim((string) ($data['label'] ?? ''));
        return '<div class="yk-share flex flex-wrap items-center gap-2 ' . $justify . '" data-yk-share role="group" aria-label="' . self::h($label !== '' ? $label : __('blox_el_share_buttons')) . '">'
            . ($label !== '' ? '<span class="mr-1 text-sm font-medium text-gray-600">' . self::h($label) . '</span>' : '')
            . $items . '</div>';
    }

    private static function networkLabel(string $network): string
    {
        return self::BRAND_NAMES[$network] ?? __('blox_share_' . $network);
    }

    /** 服务端能拿到的当前地址（前台脚本会换成 canonical）。 */
    private static function currentUrl(): string
    {
        $path = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
        $base = defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '';
        return $base . ($path !== '' ? $path : '/');
    }

    private static function enabled(array $data, string $key): bool
    {
        return array_key_exists($key, $data) ? !in_array($data[$key], [false, 0, '0', '', null], true) : true;
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
