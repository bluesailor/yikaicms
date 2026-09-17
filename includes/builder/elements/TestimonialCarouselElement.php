<?php
/** 客户评价轮播：头像、姓名、职位、评价与星级；多条自动轮播，下方圆点导航。数据全部在文档里。 */

declare(strict_types=1);

final class TestimonialCarouselElement extends AbstractElement
{
    public const MAX_ITEMS = 12;
    private const TEXT_LIMITS = ['name' => 40, 'role' => 60, 'content' => 500];

    public function type(): string { return 'testimonial-carousel'; }
    public function label(): string { return __('blox_el_testimonial_carousel'); }
    public function icon(): string { return 'message-star'; }
    public function category(): string { return 'advanced'; }
    public function treeLabelField(): ?string { return null; }
    public function scripts(): array { return ['/assets/js/blox-carousel.js']; }
    public function styles(): array { return ['/assets/css/blox-carousel.css']; }

    public function controls(): array
    {
        return [
            [
                'key' => 'items', 'type' => 'items_repeater', 'label' => __('blox_testimonial_items'),
                'max' => self::MAX_ITEMS, 'title_field' => 'name', 'thumb_field' => 'avatar',
                'item_label' => __('blox_testimonial_item'),
                'fields' => [
                    ['key' => 'avatar', 'type' => 'image', 'label' => __('blox_testimonial_avatar')],
                    ['key' => 'name', 'type' => 'text', 'label' => __('blox_testimonial_name')],
                    ['key' => 'role', 'type' => 'text', 'label' => __('blox_testimonial_role')],
                    ['key' => 'content', 'type' => 'textarea', 'label' => __('blox_testimonial_content')],
                    ['key' => 'rating', 'type' => 'select', 'label' => __('blox_testimonial_rating'), 'options' => [
                        ['value' => '', 'label' => __('blox_testimonial_rating_none')],
                        ['value' => '5', 'label' => '★★★★★'],
                        ['value' => '4', 'label' => '★★★★'],
                        ['value' => '3', 'label' => '★★★'],
                    ]],
                ],
                'default' => self::seedItems(),
            ],
            ['key' => 'per_view', 'type' => 'select', 'label' => __('blox_carousel_per_view'), 'default' => '3',
                'options' => ['1' => '1', '2' => '2', '3' => '3']],
            ['key' => 'autoplay', 'type' => 'checkbox', 'label' => __('blox_carousel_autoplay'), 'default' => true],
            ['key' => 'interval', 'type' => 'select', 'label' => __('blox_carousel_interval'), 'default' => '5',
                'options' => ['3' => '3s', '5' => '5s', '8' => '8s'], 'required' => ['autoplay', '=', true]],
            ['key' => 'show_dots', 'type' => 'checkbox', 'label' => __('blox_carousel_dots'), 'default' => true],
            ['key' => 'show_arrows', 'type' => 'checkbox', 'label' => __('blox_carousel_arrows'), 'default' => false],
            ['key' => 'card_style', 'type' => 'select', 'label' => __('blox_testimonial_card_style'), 'tab' => 'style', 'default' => 'cards',
                'options' => [
                    'cards' => __('blox_testimonial_style_cards'),
                    'outline' => __('blox_testimonial_style_outline'),
                    'plain' => __('blox_testimonial_style_plain'),
                ]],
            ['key' => 'align', 'type' => 'select', 'label' => __('blox_align'), 'tab' => 'style', 'default' => 'left',
                'options' => ['left' => __('blox_align_left'), 'center' => __('blox_align_center')]],
        ];
    }

    /** @return list<array{avatar:string,name:string,role:string,content:string,rating:string}> */
    public static function seedItems(): array
    {
        $items = [];
        foreach ([1, 2, 3, 4] as $n) {
            $items[] = [
                'avatar' => '/assets/images/blox-templates/avatar-' . $n . '.svg',
                'name' => __('blox_testimonial_seed_name_' . $n),
                'role' => __('blox_testimonial_seed_role_' . $n),
                'content' => __('blox_testimonial_seed_content_' . $n),
                'rating' => '5',
            ];
        }
        return $items;
    }

    /** @return list<array{avatar:string,name:string,role:string,content:string,rating:int}> */
    public static function normalizeItems(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $items = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_ITEMS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = ['avatar' => UrlPolicy::image($item['avatar'] ?? '')];
            foreach (self::TEXT_LIMITS as $key => $limit) {
                $normalized[$key] = self::clip(is_scalar($item[$key] ?? null) ? (string) $item[$key] : '', $limit);
            }
            $rating = is_numeric($item['rating'] ?? null) ? (int) $item['rating'] : 0;
            $normalized['rating'] = max(0, min(5, $rating));
            if ($normalized['content'] === '' && $normalized['name'] === '') {
                continue;
            }
            $items[] = $normalized;
        }
        return $items;
    }

    public function render(array $data, string $children = ''): string
    {
        $items = self::normalizeItems(array_key_exists('items', $data) ? $data['items'] : self::seedItems());
        if ($items === []) {
            return '';
        }
        $perViewValue = (string) ($data['per_view'] ?? '3');
        $perView = in_array($perViewValue, ['1', '2', '3'], true) ? (int) $perViewValue : 3;
        $autoplay = self::enabled($data, 'autoplay', true) && count($items) > $perView;
        $intervalValue = (string) ($data['interval'] ?? '5');
        $interval = in_array($intervalValue, ['3', '5', '8'], true) ? (int) $intervalValue : 5;
        $style = in_array($data['card_style'] ?? '', ['cards', 'outline', 'plain'], true) ? (string) $data['card_style'] : 'cards';
        $center = ($data['align'] ?? 'left') === 'center';
        $label = __('blox_el_testimonial_carousel');

        $html = '<div class="yk-carousel yk-testimonials yk-testimonials--' . $style . ($center ? ' yk-testimonials--center' : '') . '"'
            . ' data-yk-carousel style="--yk-carousel-per-view:' . $perView . '"'
            . ($autoplay ? ' data-yk-carousel-autoplay="' . ($interval * 1000) . '"' : '')
            . ' data-yk-carousel-dot-label="' . self::h(__('blox_carousel_dot_label')) . '">';
        $html .= '<div class="yk-carousel-track" data-yk-carousel-track tabindex="0" role="region" aria-roledescription="carousel" aria-label="' . self::h($label) . '">';
        $total = count($items);
        foreach ($items as $index => $item) {
            $html .= '<div class="yk-carousel-slide" role="group" aria-roledescription="slide" aria-label="' . ($index + 1) . ' / ' . $total . '">'
                . self::card($item) . '</div>';
        }
        $html .= '</div>';
        if (self::enabled($data, 'show_arrows', false)) {
            $html .= '<button type="button" class="yk-carousel-arrow yk-carousel-arrow--prev" data-yk-carousel-prev aria-label="' . self::h(__('blox_carousel_prev')) . '">'
                . '<i class="' . BloxIcon::classes('chevron-left') . '" aria-hidden="true"></i></button>'
                . '<button type="button" class="yk-carousel-arrow yk-carousel-arrow--next" data-yk-carousel-next aria-label="' . self::h(__('blox_carousel_next')) . '">'
                . '<i class="' . BloxIcon::classes('chevron-right') . '" aria-hidden="true"></i></button>';
        }
        if (self::enabled($data, 'show_dots', true)) {
            $html .= '<div class="yk-carousel-dots" data-yk-carousel-dots></div>';
        }
        return $html . '</div>';
    }

    /** @param array{avatar:string,name:string,role:string,content:string,rating:int} $item */
    private static function card(array $item): string
    {
        $html = '<figure class="yk-testimonial">';
        if ($item['rating'] > 0) {
            $html .= '<div class="yk-testimonial-rating" role="img" aria-label="' . self::h(__('blox_testimonial_rating_label', ['n' => $item['rating']])) . '">'
                . str_repeat('<span aria-hidden="true">★</span>', $item['rating']) . '</div>';
        }
        $html .= '<blockquote class="yk-testimonial-text">' . nl2br(self::h($item['content'])) . '</blockquote>';
        $html .= '<figcaption class="yk-testimonial-author">';
        if ($item['avatar'] !== '') {
            $html .= '<img class="yk-testimonial-avatar" src="' . self::h($item['avatar']) . '" alt="' . self::h($item['name']) . '" loading="lazy" decoding="async" width="48" height="48">';
        } else {
            $initial = $item['name'] !== '' ? mb_substr($item['name'], 0, 1) : '·';
            $html .= '<span class="yk-testimonial-avatar yk-testimonial-initial" aria-hidden="true">' . self::h($initial) . '</span>';
        }
        $html .= '<span class="yk-testimonial-meta"><span class="yk-testimonial-name">' . self::h($item['name']) . '</span>';
        if ($item['role'] !== '') {
            $html .= '<span class="yk-testimonial-role">' . self::h($item['role']) . '</span>';
        }
        return $html . '</span></figcaption></figure>';
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
