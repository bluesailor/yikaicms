<?php

declare(strict_types=1);

final class HomeBannerItemElement extends AbstractElement
{
    private const CONTENT_MOTIONS = ['inherit', 'none', 'fade-up', 'slide-left', 'slide-right', 'zoom-in'];
    private const BACKGROUND_MOTIONS = ['inherit', 'none', 'zoom-in', 'zoom-out'];

    public function type(): string { return 'home-banner-item'; }
    public function label(): string { return __('blox_home_banner_item'); }
    public function icon(): string { return 'photo'; }
    public function category(): string { return 'dynamic'; }
    public function paletteVisible(string $context = 'page'): bool { return false; }
    public function canBeGenericChild(): bool { return false; }
    public function supportsBoxStyles(): bool { return false; }
    public function treeLabelField(): ?string { return 'title'; }

    public function controls(): array
    {
        return [
            ['key' => 'title', 'type' => 'text', 'label' => __('blox_home_banner_title'), 'default' => ''],
            ['key' => 'subtitle', 'type' => 'textarea', 'label' => __('blox_home_banner_subtitle'), 'default' => ''],
            [
                'key' => 'content_motion',
                'type' => 'select',
                'label' => __('blox_home_banner_content_motion'),
                'default' => 'inherit',
                'options' => [
                    'inherit' => __('blox_banner_motion_inherit'),
                    'none' => __('blox_banner_motion_none'),
                    'fade-up' => __('blox_banner_motion_fade_up'),
                    'slide-left' => __('blox_banner_motion_slide_left'),
                    'slide-right' => __('blox_banner_motion_slide_right'),
                    'zoom-in' => __('blox_banner_motion_zoom_in'),
                ],
                'option_icons' => [
                    'inherit' => 'settings',
                    'none' => 'ban',
                    'fade-up' => 'arrow-up',
                    'slide-left' => 'arrow-left',
                    'slide-right' => 'arrow-right',
                    'zoom-in' => 'zoom-in',
                ],
                'help' => __('blox_home_banner_content_motion_help'),
            ],
            ['key' => 'image', 'type' => 'image', 'label' => __('blox_home_banner_image'), 'default' => ''],
            ['key' => 'image_mobile', 'type' => 'image', 'label' => __('bn_mobile_image'), 'default' => ''],
            [
                'key' => 'background_motion',
                'type' => 'select',
                'label' => __('blox_banner_background_motion'),
                'default' => 'inherit',
                'options' => [
                    'inherit' => __('blox_banner_motion_inherit'),
                    'none' => __('blox_banner_motion_none'),
                    'zoom-in' => __('blox_banner_background_zoom_in'),
                    'zoom-out' => __('blox_banner_background_zoom_out'),
                ],
                'option_icons' => [
                    'inherit' => 'settings',
                    'none' => 'ban',
                    'zoom-in' => 'zoom-in',
                    'zoom-out' => 'zoom-out',
                ],
            ],
            ['key' => 'btn1_text', 'type' => 'text', 'label' => __('blox_home_banner_primary_text'), 'default' => ''],
            ['key' => 'btn1_url', 'type' => 'text', 'label' => __('blox_home_banner_primary_url'), 'default' => ''],
            ['key' => 'btn2_text', 'type' => 'text', 'label' => __('blox_home_banner_secondary_text'), 'default' => ''],
            ['key' => 'btn2_url', 'type' => 'text', 'label' => __('blox_home_banner_secondary_url'), 'default' => ''],
            ['key' => 'link_url', 'type' => 'text', 'label' => __('blox_home_banner_image_url'), 'default' => ''],
            [
                'key' => 'link_target',
                'type' => 'select',
                'label' => __('blox_home_banner_target'),
                'default' => '_self',
                'options' => [
                    '_self' => __('blox_home_banner_target_self'),
                    '_blank' => __('blox_home_banner_target_blank'),
                ],
            ],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $item = self::normalize($data);
        $image = $item['image'] !== ''
            ? '<img src="' . e($item['image']) . '" alt="' . e($item['title']) . '" class="w-full aspect-video object-cover">'
            : '<div class="w-full aspect-video bg-gray-100"></div>';
        return '<article class="overflow-hidden border border-gray-200 rounded-lg">' . $image
            . '<div class="p-4"><h3 class="font-semibold">' . e($item['title']) . '</h3>'
            . '<p class="text-sm text-gray-500 mt-1">' . e($item['subtitle']) . '</p></div></article>';
    }

    /** @param array<string, mixed> $data @return array<string, string> */
    public static function normalize(array $data): array
    {
        $item = [];
        foreach (['title' => 200, 'subtitle' => 500, 'btn1_text' => 100, 'btn2_text' => 100] as $key => $limit) {
            $item[$key] = mb_substr(trim(strip_tags((string) ($data[$key] ?? ''))), 0, $limit);
        }
        $item['image'] = self::safeUrl((string) ($data['image'] ?? ''), false);
        $item['image_mobile'] = self::safeUrl((string) ($data['image_mobile'] ?? ''), false);
        foreach (['btn1_url', 'btn2_url', 'link_url'] as $key) {
            $item[$key] = self::safeUrl((string) ($data[$key] ?? ''), true);
        }
        $item['link_target'] = ($data['link_target'] ?? '_self') === '_blank' ? '_blank' : '_self';
        $item['content_motion'] = self::contentMotion($data);
        $item['background_motion'] = self::backgroundMotion($data);
        return $item;
    }

    /** @param array<string, mixed> $data */
    public static function contentMotion(array $data): string
    {
        $motion = (string) ($data['content_motion'] ?? 'inherit');
        return in_array($motion, self::CONTENT_MOTIONS, true) ? $motion : 'inherit';
    }

    /** @param array<string, mixed> $data */
    public static function contentMotionAttribute(array $data): string
    {
        $motion = self::contentMotion($data);
        return $motion === 'inherit'
            ? ''
            : ' data-blox-slide-content-motion="' . htmlspecialchars($motion, ENT_QUOTES) . '"';
    }

    /** @param array<string, mixed> $data */
    public static function backgroundMotion(array $data): string
    {
        $motion = (string) ($data['background_motion'] ?? 'inherit');
        return in_array($motion, self::BACKGROUND_MOTIONS, true) ? $motion : 'inherit';
    }

    /** @param array<string, mixed> $data */
    public static function backgroundMotionAttribute(array $data): string
    {
        $motion = self::backgroundMotion($data);
        return $motion === 'inherit'
            ? ''
            : ' data-blox-slide-background-motion="' . htmlspecialchars($motion, ENT_QUOTES) . '"';
    }

    /** @param array<string, mixed> $data */
    public static function motionAttributes(array $data): string
    {
        return self::contentMotionAttribute($data) . self::backgroundMotionAttribute($data);
    }

    /** @param array<string, mixed> $data */
    public static function responsiveImageHtml(array $data, string $class = 'w-full h-full object-cover'): string
    {
        $item = self::normalize($data);
        if ($item['image'] === '') {
            return '';
        }

        $class = htmlspecialchars($class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $alt = htmlspecialchars($item['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<picture class="block w-full h-full" data-blox-banner-bg>';
        if ($item['image_mobile'] !== '') {
            $html .= '<source media="(max-width: 767px)" srcset="'
                . htmlspecialchars($item['image_mobile'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
        }
        $html .= '<img src="' . htmlspecialchars($item['image'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" alt="' . $alt . '" class="' . $class . '"></picture>';

        return $html;
    }

    /** @param array<string, mixed> $banner @return array<string, string> */
    public static function fromLegacy(array $banner): array
    {
        return self::normalize($banner);
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, array<string, string>>
     */
    public static function normalizeChildren(array $children, string $parentPath = ''): array
    {
        $items = [];
        foreach ($children as $index => $child) {
            if (!is_array($child) || ($child['type'] ?? '') !== 'home-banner-item') {
                continue;
            }
            $item = self::normalize(is_array($child['data'] ?? null) ? $child['data'] : []);
            if ($parentPath !== '') {
                $item['_blox_path'] = $parentPath . '.' . (int) $index;
            }
            $items[] = $item;
        }
        return $items;
    }

    private static function safeUrl(string $value, bool $allowActionSchemes): string
    {
        $value = trim(strip_tags($value));
        if ($value === '' || mb_strlen($value) > 1000) {
            return '';
        }
        if (str_starts_with($value, '/') || str_starts_with($value, '#')
            || preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }
        if ($allowActionSchemes && preg_match('#^(?:mailto|tel):#i', $value) === 1) {
            return $value;
        }
        return '';
    }
}
