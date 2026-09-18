<?php
declare(strict_types=1);

final class BloxImageFraming
{
    private const RATIOS = ['square' => '1 / 1', 'landscape' => '4 / 3', 'wide' => '16 / 9', 'portrait' => '3 / 4'];

    /** @return list<array<string,mixed>> */
    public static function controls(bool $forCard = false): array
    {
        $terms = $forCard ? [['image', 'not_empty'], ['card_layout', '!=', 'text']] : [];
        $framedTerms = array_merge($terms, [['image_ratio', '!=', 'auto']]);
        $options = $forCard ? ['default' => __('blox_image_ratio_follow_layout')] : [];
        return [
            ['key' => 'image_ratio', 'type' => 'select', 'label' => __('blox_image_ratio'), 'default' => $forCard ? 'default' : 'auto', 'tab' => 'style',
                'option_preview' => 'image-ratio', 'visible_when' => ['terms' => $terms],
                'options' => $options + ['auto' => __('blox_image_ratio_auto'), 'square' => '1:1', 'landscape' => '4:3', 'wide' => '16:9', 'portrait' => '3:4']],
            ['key' => 'image_fit', 'type' => 'select', 'label' => __('blox_image_fit'), 'default' => 'cover', 'tab' => 'style',
                'option_preview' => 'image-fit', 'visible_when' => ['terms' => $framedTerms],
                'options' => ['cover' => __('blox_image_fit_cover'), 'contain' => __('blox_image_fit_contain')]],
            ['key' => 'image_position', 'type' => 'select', 'label' => __('blox_image_position'), 'default' => 'center', 'tab' => 'style',
                'option_preview' => 'image-position', 'visible_when' => ['terms' => $framedTerms],
                'options' => [
                    'top-left' => __('blox_image_top_left'), 'top' => __('blox_image_top'), 'top-right' => __('blox_image_top_right'),
                    'left' => __('blox_image_left'), 'center' => __('blox_image_center'), 'right' => __('blox_image_right'),
                    'bottom-left' => __('blox_image_bottom_left'), 'bottom' => __('blox_image_bottom'), 'bottom-right' => __('blox_image_bottom_right'),
                ],
                'option_icons' => [
                    'top-left' => 'arrow-up-left', 'top' => 'arrow-up', 'top-right' => 'arrow-up-right',
                    'left' => 'arrow-left', 'center' => 'focus-centered', 'right' => 'arrow-right',
                    'bottom-left' => 'arrow-down-left', 'bottom' => 'arrow-down', 'bottom-right' => 'arrow-down-right',
                ]],
        ];
    }

    public static function ratio(array $data, bool $forCard = false): string
    {
        $ratio = $data['image_ratio'] ?? null;
        $default = $forCard ? 'default' : 'auto';
        return is_string($ratio) && (isset(self::RATIOS[$ratio]) || $ratio === 'auto' || $ratio === $default) ? $ratio : $default;
    }

    public static function ratioCss(string $ratio): string
    {
        return self::RATIOS[$ratio] ?? '';
    }

    public static function objectStyle(array $data): string
    {
        $fit = ($data['image_fit'] ?? '') === 'contain' ? 'contain' : 'cover';
        $position = match ($data['image_position'] ?? null) {
            'top-left' => 'left top', 'top' => 'center top', 'top-right' => 'right top',
            'left' => 'left center', 'right' => 'right center',
            'bottom-left' => 'left bottom', 'bottom' => 'center bottom', 'bottom-right' => 'right bottom',
            default => 'center center',
        };
        return 'object-fit:' . $fit . ';object-position:' . $position . ';';
    }

    public static function standaloneStyle(array $data): string
    {
        $ratio = self::ratioCss(self::ratio($data));
        return $ratio === '' ? '' : ' style="width:100%;height:auto;aspect-ratio:' . $ratio . ';' . self::objectStyle($data) . '"';
    }
}
