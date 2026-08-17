<?php
/** 社交媒体入口，实时绑定 social_links 设置并过滤不安全 URL。 */

declare(strict_types=1);

final class SocialLinksElement extends AbstractElement
{
    private const ICONS = [
        'line' => 'brand-line', 'x' => 'brand-x', 'instagram' => 'brand-instagram',
        'facebook' => 'brand-facebook', 'youtube' => 'brand-youtube', 'tiktok' => 'brand-tiktok',
        'linkedin' => 'brand-linkedin', 'threads' => 'brand-threads', 'pinterest' => 'brand-pinterest',
        'wechat' => 'brand-wechat', 'wechat_global' => 'brand-wechat', 'weibo' => 'brand-weibo',
        'douyin' => 'brand-tiktok', 'kuaishou' => 'video', 'xiaohongshu' => 'book',
        'bilibili' => 'brand-bilibili', 'zhihu' => 'brand-zhihu', 'whatsapp' => 'brand-whatsapp',
        'discord' => 'brand-discord', 'note' => 'note',
    ];

    public function type(): string { return 'social-links'; }
    public function label(): string { return __('blox_el_social_links'); }
    public function icon(): string { return 'brand-instagram'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'style', 'type' => 'select', 'label' => __('blox_social_style'), 'default' => 'outline',
                'options' => ['outline' => __('blox_social_outline'), 'solid' => __('blox_social_solid')]],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'default' => 'left',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')]],
            ['key' => 'tone', 'type' => 'select', 'label' => __('blox_site_tone'), 'default' => 'dark',
                'options' => ['dark' => __('blox_site_tone_dark'), 'light' => __('blox_site_tone_light')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $raw = function_exists('config') ? (string) config('social_links', '[]') : '[]';
        $links = self::decodeLinks($raw);
        if ($links === []) {
            return '';
        }
        $light = ($data['tone'] ?? 'dark') === 'light';
        $solid = ($data['style'] ?? 'outline') === 'solid';
        $buttonClass = $solid
            ? ($light ? 'bg-white text-gray-900 hover:bg-white/80' : 'bg-gray-900 text-white hover:bg-gray-700')
            : ($light ? 'border border-white/30 text-white hover:bg-white/10' : 'border border-gray-300 text-gray-700 hover:border-gray-500 hover:text-gray-900');
        $items = '';
        foreach ($links as $link) {
            $platform = $link['platform'];
            $icon = self::ICONS[$platform];
            $label = self::platformLabel($platform);
            $items .= '<a href="' . htmlspecialchars($link['url'], ENT_QUOTES) . '" target="_blank" rel="nofollow noopener"'
                . ' class="inline-flex h-9 w-9 items-center justify-center rounded-full transition ' . $buttonClass . '"'
                . ' aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '" title="' . htmlspecialchars($label, ENT_QUOTES) . '">'
                . '<i class="ti ti-' . $icon . ' text-lg" aria-hidden="true"></i></a>';
        }
        $align = match ($data['align'] ?? 'left') {
            'center' => 'justify-center',
            'right' => 'justify-end',
            default => 'justify-start',
        };
        return '<div class="flex flex-wrap gap-2 ' . $align . '">' . $items . '</div>';
    }

    /** @return list<array{platform:string,url:string}> */
    public static function decodeLinks(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $links = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $platform = strtolower(trim((string) ($item['platform'] ?? '')));
            $url = self::safeLinkUrl((string) ($item['url'] ?? ''));
            if (!isset(self::ICONS[$platform]) || $url === '') {
                continue;
            }
            $links[] = ['platform' => $platform, 'url' => $url];
        }
        return $links;
    }

    private static function safeLinkUrl(string $url): string
    {
        if (function_exists('safeUrl')) {
            return safeUrl($url);
        }
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (($url[0] === '/' && !str_starts_with($url, '//')) || $url[0] === '#' || $url[0] === '?') {
            return $url;
        }
        return preg_match('#^(https?|mailto|tel):#i', $url) === 1 ? $url : '';
    }

    private static function platformLabel(string $platform): string
    {
        return match ($platform) {
            'x' => 'X', 'wechat', 'wechat_global' => 'WeChat', 'weibo' => 'Weibo',
            'douyin' => 'Douyin', 'kuaishou' => 'Kuaishou', 'xiaohongshu' => 'Xiaohongshu',
            'bilibili' => 'Bilibili', 'zhihu' => 'Zhihu', 'note' => 'Note',
            default => ucfirst($platform),
        };
    }
}
