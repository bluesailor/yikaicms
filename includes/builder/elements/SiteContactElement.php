<?php
/** 电话、邮箱、地址和工作时间，实时读取联系我们设置。 */

declare(strict_types=1);

final class SiteContactElement extends AbstractElement
{
    public function type(): string { return 'site-contact'; }
    public function label(): string { return __('blox_el_site_contact'); }
    public function icon(): string { return 'address-book'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array
    {
        return [
            ['key' => 'show_phone', 'type' => 'checkbox', 'label' => __('blox_contact_show_phone'), 'default' => true],
            ['key' => 'show_email', 'type' => 'checkbox', 'label' => __('blox_contact_show_email'), 'default' => true],
            ['key' => 'show_address', 'type' => 'checkbox', 'label' => __('blox_contact_show_address'), 'default' => false],
            ['key' => 'show_hours', 'type' => 'checkbox', 'label' => __('blox_contact_show_hours'), 'default' => false],
            ['key' => 'show_icons', 'type' => 'checkbox', 'label' => __('blox_contact_show_icons'), 'default' => true],
            ['key' => 'layout', 'type' => 'select', 'label' => __('blox_contact_layout'), 'default' => 'inline',
                'options' => ['inline' => __('blox_contact_inline'), 'stacked' => __('blox_contact_stacked')]],
            ['key' => 'tone', 'type' => 'select', 'label' => __('blox_site_tone'), 'default' => 'dark',
                'options' => ['dark' => __('blox_site_tone_dark'), 'light' => __('blox_site_tone_light')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $fields = [
            'phone' => ['setting' => 'contact_phone', 'icon' => 'phone', 'show' => 'show_phone'],
            'email' => ['setting' => 'contact_email', 'icon' => 'mail', 'show' => 'show_email'],
            'address' => ['setting' => 'contact_address', 'icon' => 'map-pin', 'show' => 'show_address'],
            'hours' => ['setting' => 'contact_hours', 'icon' => 'clock', 'show' => 'show_hours'],
        ];
        $items = [];
        $showIcons = self::enabled($data, 'show_icons', true);
        foreach ($fields as $key => $field) {
            if (!self::enabled($data, $field['show'], in_array($key, ['phone', 'email'], true))) {
                continue;
            }
            $value = function_exists('configRawLang') ? trim(configRawLang($field['setting'], '')) : '';
            if ($value === '') {
                continue;
            }
            $icon = $showIcons ? '<i class="ti ti-' . $field['icon'] . ' shrink-0 text-base" aria-hidden="true"></i>' : '';
            $content = htmlspecialchars($value, ENT_QUOTES);
            if ($key === 'phone') {
                $href = self::phoneHref($value);
                $content = $href === '' ? $content : '<a href="' . htmlspecialchars($href, ENT_QUOTES) . '" class="hover:underline">' . $content . '</a>';
            } elseif ($key === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $content = '<a href="mailto:' . htmlspecialchars($value, ENT_QUOTES) . '" class="hover:underline">' . $content . '</a>';
            }
            $items[] = '<span class="inline-flex min-w-0 items-start gap-2">' . $icon . '<span class="min-w-0 break-words">' . $content . '</span></span>';
        }
        if ($items === []) {
            return '';
        }
        $layout = ($data['layout'] ?? 'inline') === 'stacked'
            ? 'flex-col items-start gap-3'
            : 'flex-row flex-wrap items-center gap-x-5 gap-y-2';
        $tone = ($data['tone'] ?? 'dark') === 'light' ? 'text-white/80' : 'text-gray-600';
        return '<div class="flex text-sm ' . $layout . ' ' . $tone . '">' . implode('', $items) . '</div>';
    }

    public static function phoneHref(string $phone): string
    {
        $number = preg_replace('/[^0-9+]/', '', trim($phone)) ?? '';
        return $number === '' ? '' : 'tel:' . $number;
    }

    private static function enabled(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }
        return !in_array($data[$key], [false, 0, '0', '', null], true);
    }
}
