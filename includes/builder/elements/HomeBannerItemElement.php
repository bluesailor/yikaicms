<?php

declare(strict_types=1);

final class HomeBannerItemElement extends AbstractElement
{
    public function type(): string { return 'home-banner-item'; }
    public function label(): string { return __('blox_home_banner_item'); }
    public function icon(): string { return 'photo'; }
    public function category(): string { return 'dynamic'; }

    public function controls(): array
    {
        return [
            ['key' => 'title', 'type' => 'text', 'label' => __('blox_home_banner_title'), 'default' => ''],
            ['key' => 'subtitle', 'type' => 'textarea', 'label' => __('blox_home_banner_subtitle'), 'default' => ''],
            ['key' => 'image', 'type' => 'image', 'label' => __('blox_home_banner_image'), 'default' => ''],
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
        foreach (['btn1_url', 'btn2_url', 'link_url'] as $key) {
            $item[$key] = self::safeUrl((string) ($data[$key] ?? ''), true);
        }
        $item['link_target'] = ($data['link_target'] ?? '_self') === '_blank' ? '_blank' : '_self';
        return $item;
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
