<?php
/**
 * 客户评价：头像、姓名、职位、评价与星级。三种布局（2.0.4）：轮播（默认，多条自动轮播、下方圆点导航）、
 * 网格「wall of love」（按列错落排布）、跑马灯（与 Logo 墙同一套无缝滚动）。数据全部在文档里。
 */

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
    public const LAYOUTS = ['carousel', 'grid', 'marquee'];
    private const MARQUEE_SPEEDS = ['slow' => 80, 'medium' => 55, 'fast' => 35];

    public function scripts(): array { return ['/assets/js/blox-carousel.js']; }
    public function styles(): array { return ['/assets/css/blox-carousel.css']; }

    /** 只有轮播布局需要脚本；网格与跑马灯是纯 CSS */
    public function scriptsFor(array $data): array
    {
        return self::layout($data) === 'carousel' ? $this->scripts() : [];
    }

    private static function layout(array $data): string
    {
        $layout = $data['layout'] ?? 'carousel';
        return is_string($layout) && in_array($layout, self::LAYOUTS, true) ? $layout : 'carousel';
    }

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
            ['key' => 'layout', 'type' => 'select', 'label' => __('blox_testimonial_layout'), 'default' => 'carousel',
                'options' => [
                    'carousel' => __('blox_testimonial_layout_carousel'),
                    'grid' => __('blox_testimonial_layout_grid'),
                    'marquee' => __('blox_testimonial_layout_marquee'),
                ],
                'option_icons' => ['carousel' => 'carousel-horizontal', 'grid' => 'layout-masonry', 'marquee' => 'arrows-right']],
            ['key' => 'per_view', 'type' => 'select', 'label' => __('blox_carousel_per_view'), 'default' => '3',
                'options' => ['1' => '1', '2' => '2', '3' => '3'], 'visible_when' => ['terms' => [['layout', 'in', ['', 'carousel']]]]],
            ['key' => 'autoplay', 'type' => 'checkbox', 'label' => __('blox_carousel_autoplay'), 'default' => true,
                'visible_when' => ['terms' => [['layout', 'in', ['', 'carousel']]]]],
            ['key' => 'interval', 'type' => 'select', 'label' => __('blox_carousel_interval'), 'default' => '5',
                'options' => ['3' => '3s', '5' => '5s', '8' => '8s'],
                'visible_when' => ['terms' => [['layout', 'in', ['', 'carousel']], ['autoplay', '=', true]]]],
            ['key' => 'show_dots', 'type' => 'checkbox', 'label' => __('blox_carousel_dots'), 'default' => true,
                'visible_when' => ['terms' => [['layout', 'in', ['', 'carousel']]]]],
            ['key' => 'show_arrows', 'type' => 'checkbox', 'label' => __('blox_carousel_arrows'), 'default' => false,
                'visible_when' => ['terms' => [['layout', 'in', ['', 'carousel']]]]],
            // 网格：桌面最多几列（窄屏按卡片最小宽度自动减列）
            ['key' => 'grid_columns', 'type' => 'select', 'label' => __('blox_testimonial_grid_columns'), 'default' => '3',
                'options' => ['2' => '2', '3' => '3', '4' => '4'], 'visible_when' => ['terms' => [['layout', '=', 'grid']]]],
            ['key' => 'marquee_speed', 'type' => 'select', 'label' => __('blox_testimonial_marquee_speed'), 'default' => 'medium',
                'options' => ['slow' => __('blox_testimonial_speed_slow'), 'medium' => __('blox_testimonial_speed_medium'), 'fast' => __('blox_testimonial_speed_fast')],
                'visible_when' => ['terms' => [['layout', '=', 'marquee']]]],
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
        $layout = self::layout($data);
        $base = 'yk-testimonials yk-testimonials--' . $style . ($center ? ' yk-testimonials--center' : '');
        if ($layout === 'grid') {
            $columnsValue = (string) ($data['grid_columns'] ?? '3');
            $columns = in_array($columnsValue, ['2', '3', '4'], true) ? (int) $columnsValue : 3;
            $html = '<div class="' . $base . ' yk-testimonials--grid" style="--yk-testimonial-columns:' . $columns . '" role="list" aria-label="' . self::h($label) . '">';
            foreach ($items as $item) {
                $html .= '<div class="yk-testimonial-cell" role="listitem">' . self::card($item) . '</div>';
            }
            return $html . '</div>';
        }
        if ($layout === 'marquee') {
            // 两条相同的轨道首尾相接无缝滚动；第二条只是视觉重复，读屏与键盘跳过
            $seconds = self::MARQUEE_SPEEDS[(string) ($data['marquee_speed'] ?? 'medium')] ?? self::MARQUEE_SPEEDS['medium'];
            $cells = '';
            foreach ($items as $item) {
                $cells .= '<div class="yk-testimonial-cell">' . self::card($item) . '</div>';
            }
            return '<div class="' . $base . ' yk-testimonials--marquee" style="--yk-testimonial-marquee:' . max(20, $seconds * count($items) / 4) . 's"'
                . ' role="region" aria-label="' . self::h($label) . '"><div class="yk-testimonial-marquee">'
                . '<div class="yk-testimonial-track">' . $cells . '</div>'
                . '<div class="yk-testimonial-track" aria-hidden="true" inert>' . $cells . '</div></div></div>';
        }

        $html = '<div class="yk-carousel ' . $base . ($perView === 1 ? ' yk-testimonials--single' : '') . '"'
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
            $html .= '<span class="yk-testimonial-avatar yk-testimonial-initial" aria-hidden="true">' . self::h(self::initials($item['name'])) . '</span>';
        }
        $html .= '<span class="yk-testimonial-meta"><span class="yk-testimonial-name">' . self::h($item['name']) . '</span>';
        if ($item['role'] !== '') {
            $html .= '<span class="yk-testimonial-role">' . self::h($item['role']) . '</span>';
        }
        return $html . '</span></figcaption></figure>';
    }

    /** 首字母头像：拉丁字母的多词姓名取首尾两词的首字母（Jane Doe → JD），其余取第一个字 */
    public static function initials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '·';
        }
        $words = preg_split('/\s+/u', $name) ?: [$name];
        if (count($words) >= 2 && preg_match('/^\p{Latin}/u', $words[0]) === 1 && preg_match('/^\p{Latin}/u', (string) end($words)) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr((string) end($words), 0, 1));
        }
        return mb_strtoupper(mb_substr($name, 0, 1));
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
