<?php
/**
 * 图片轮播（2.1.0）：多张图片横向轮播，每张可带说明文字与链接；导航用圆点、缩略图或不显示。
 * 与客户评价轮播共用 yikay-carousel.js / .css（原生横向滚动 + scroll-snap，无 JS 时仍可滑动）。
 * 替代文字没填时用媒体库里给这张图填的（mediaAltFor）。
 */

declare(strict_types=1);

final class ImageCarouselElement extends AbstractElement
{
    public const MAX_ITEMS = 20;
    private const RATIOS = ['auto' => '', 'landscape' => '4 / 3', 'wide' => '16 / 9', 'banner' => '21 / 9', 'square' => '1 / 1', 'portrait' => '3 / 4'];
    private const NAVS = ['dots', 'thumbs', 'none'];

    public function type(): string { return 'image-carousel'; }
    public function label(): string { return __('blox_el_image_carousel'); }
    public function icon(): string { return 'carousel-horizontal'; }
    public function category(): string { return 'media'; }
    public function treeLabelField(): ?string { return null; }

    public function scripts(): array { return ['/assets/js/yikay-carousel.js']; }
    public function styles(): array { return ['/assets/css/yikay-carousel.css']; }

    public function controls(): array
    {
        return [
            [
                'key' => 'items', 'type' => 'items_repeater', 'label' => __('blox_image_carousel_items'),
                'max' => self::MAX_ITEMS, 'title_field' => 'caption', 'thumb_field' => 'image',
                'item_label' => __('blox_image_carousel_item'),
                'fields' => [
                    ['key' => 'image', 'type' => 'image', 'label' => __('blox_image_carousel_image')],
                    ['key' => 'alt', 'type' => 'text', 'label' => __('blox_ctl_desc')],
                    ['key' => 'caption', 'type' => 'text', 'label' => __('blox_image_carousel_caption')],
                    ['key' => 'link', 'type' => 'url', 'label' => __('blox_image_carousel_link')],
                ],
                'default' => self::seedItems(),
            ],
            ['key' => 'ratio', 'type' => 'select', 'label' => __('blox_image_carousel_ratio'), 'default' => 'wide',
                'options' => [
                    'auto' => __('blox_image_carousel_ratio_auto'), 'landscape' => '4:3', 'wide' => '16:9',
                    'banner' => '21:9', 'square' => '1:1', 'portrait' => '3:4',
                ]],
            ['key' => 'per_view', 'type' => 'select', 'label' => __('blox_carousel_per_view'), 'default' => '1',
                'options' => ['1' => '1', '2' => '2', '3' => '3']],
            ['key' => 'nav', 'type' => 'select', 'label' => __('blox_image_carousel_nav'), 'default' => 'dots',
                'options' => [
                    'dots' => __('blox_image_carousel_nav_dots'), 'thumbs' => __('blox_image_carousel_nav_thumbs'),
                    'none' => __('blox_image_carousel_nav_none'),
                ]],
            ['key' => 'show_arrows', 'type' => 'checkbox', 'label' => __('blox_carousel_arrows'), 'default' => true],
            ['key' => 'autoplay', 'type' => 'checkbox', 'label' => __('blox_carousel_autoplay'), 'default' => false],
            ['key' => 'interval', 'type' => 'select', 'label' => __('blox_carousel_interval'), 'default' => '5',
                'options' => ['3' => '3s', '5' => '5s', '8' => '8s'],
                'visible_when' => ['terms' => [['autoplay', '=', true]]]],
            ['key' => 'rounded', 'type' => 'checkbox', 'label' => __('blox_image_carousel_rounded'), 'default' => true, 'tab' => 'style'],
        ];
    }

    /** @return list<array{image:string,alt:string,caption:string,link:string}> */
    public static function seedItems(): array
    {
        $items = [];
        foreach (['banner-1', 'banner-2', 'banner-3'] as $name) {
            $items[] = ['image' => '/assets/images/demo/' . $name . '.svg', 'alt' => '', 'caption' => '', 'link' => ''];
        }
        return $items;
    }

    /** @return list<array{image:string,alt:string,caption:string,link:string}> */
    public static function normalizeItems(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        $items = [];
        foreach (array_slice(is_array($raw) ? array_values($raw) : [], 0, self::MAX_ITEMS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $image = UrlPolicy::image($item['image'] ?? '');
            if ($image === '') {
                continue;
            }
            $items[] = [
                'image' => $image,
                'alt' => self::clip((string) ($item['alt'] ?? ''), 200),
                'caption' => self::clip((string) ($item['caption'] ?? ''), 200),
                'link' => is_string($item['link'] ?? null) ? UrlPolicy::href($item['link']) : '',
            ];
        }
        return $items;
    }

    public function render(array $data, string $children = ''): string
    {
        $items = self::normalizeItems(array_key_exists('items', $data) ? $data['items'] : self::seedItems());
        if ($items === []) {
            return '';
        }
        $perView = (string) ($data['per_view'] ?? '1');
        $perView = in_array($perView, ['1', '2', '3'], true) ? (int) $perView : 1;
        $nav = (string) ($data['nav'] ?? 'dots');
        $nav = in_array($nav, self::NAVS, true) ? $nav : 'dots';
        $ratio = self::RATIOS[(string) ($data['ratio'] ?? 'wide')] ?? self::RATIOS['wide'];
        $interval = (string) ($data['interval'] ?? '5');
        $interval = in_array($interval, ['3', '5', '8'], true) ? (int) $interval : 5;
        $autoplay = self::enabled($data, 'autoplay', false) && count($items) > $perView;
        $rounded = self::enabled($data, 'rounded', true) ? ' yk-image-carousel--rounded' : '';
        $label = __('blox_el_image_carousel');
        $sizes = $perView === 1 ? '100vw' : '(max-width: 767px) 100vw, ' . (int) round(100 / $perView) . 'vw';

        $html = '<div class="yk-carousel yk-image-carousel' . $rounded . '" data-yk-carousel style="--yk-carousel-per-view:' . $perView
            . ($ratio !== '' ? ';--yk-image-carousel-ratio:' . $ratio : '') . '"'
            . ($autoplay ? ' data-yk-carousel-autoplay="' . ($interval * 1000) . '"' : '')
            . ' data-yk-carousel-dot-label="' . self::h(__('blox_carousel_dot_label')) . '">';
        $html .= '<div class="yk-carousel-track" data-yk-carousel-track tabindex="0" role="region" aria-roledescription="carousel" aria-label="' . self::h($label) . '">';
        $total = count($items);
        foreach ($items as $index => $item) {
            $alt = $item['alt'] !== '' ? $item['alt'] : (function_exists('mediaAltFor') ? mediaAltFor($item['image']) : '');
            $attrs = function_exists('responsiveImageAttributes')
                ? responsiveImageAttributes($item['image'], $perView === 1 ? 'large' : 'medium', $sizes)
                : 'src="' . self::h($item['image']) . '"';
            $img = '<img class="yk-image-carousel-img" ' . $attrs . ' alt="' . self::h($alt) . '"'
                . ($index === 0 ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async">';
            $figure = '<figure class="yk-image-carousel-figure">'
                . ($item['link'] !== '' ? '<a href="' . self::h($item['link']) . '" class="block">' . $img . '</a>' : $img)
                . ($item['caption'] !== '' ? '<figcaption class="yk-image-carousel-caption">' . self::h($item['caption']) . '</figcaption>' : '')
                . '</figure>';
            $html .= '<div class="yk-carousel-slide" role="group" aria-roledescription="slide" aria-label="' . ($index + 1) . ' / ' . $total . '">' . $figure . '</div>';
        }
        $html .= '</div>';
        if (self::enabled($data, 'show_arrows', true) && $total > $perView) {
            $html .= '<button type="button" class="yk-carousel-arrow yk-carousel-arrow--prev" data-yk-carousel-prev aria-label="' . self::h(__('blox_carousel_prev')) . '">'
                . '<i class="' . BloxIcon::classes('chevron-left') . '" aria-hidden="true"></i></button>'
                . '<button type="button" class="yk-carousel-arrow yk-carousel-arrow--next" data-yk-carousel-next aria-label="' . self::h(__('blox_carousel_next')) . '">'
                . '<i class="' . BloxIcon::classes('chevron-right') . '" aria-hidden="true"></i></button>';
        }
        if ($nav === 'dots') {
            $html .= '<div class="yk-carousel-dots" data-yk-carousel-dots></div>';
        } elseif ($nav === 'thumbs' && $total > 1) {
            // 缩略图导航：每张图一个按钮，点了滚到那张；当前那张由脚本标 aria-current
            $html .= '<div class="yk-carousel-thumbs" data-yk-carousel-thumbs>';
            foreach ($items as $index => $item) {
                $thumb = function_exists('thumbnail') ? thumbnail($item['image'], 'thumb') : $item['image'];
                $html .= '<button type="button" class="yk-carousel-thumb" data-yk-carousel-thumb="' . $index . '"'
                    . ' aria-label="' . self::h(str_replace('%d', (string) ($index + 1), __('blox_carousel_dot_label'))) . '">'
                    . '<img src="' . self::h($thumb) . '" alt="" loading="lazy" decoding="async"></button>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    private static function clip(string $value, int $limit): string
    {
        $value = trim((string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/u', ' ', $value));
        return mb_substr($value, 0, $limit);
    }

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function enabled(array $data, string $key, bool $default): bool
    {
        return array_key_exists($key, $data) ? !in_array($data[$key], [false, 0, '0', '', null], true) : $default;
    }
}
