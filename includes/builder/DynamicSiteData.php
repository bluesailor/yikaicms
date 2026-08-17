<?php
/** 可视化动态绑定可读取的站点字段白名单。 */

declare(strict_types=1);

final class DynamicSiteData
{
    /** @var array<string,list<string>> */
    private const FIELDS = [
        'text' => ['site_name', 'site_description', 'contact_phone', 'contact_email', 'contact_address', 'copyright'],
        'image' => ['site_logo'],
        'url' => ['site_url'],
    ];

    /** @return array<string,string> */
    public static function fieldOptions(string $slot): array
    {
        $labels = [
            'site_name' => __('setting_site_name'),
            'site_description' => __('setting_site_description'),
            'site_logo' => __('setting_site_logo'),
            'site_url' => __('blox_dynamic_site_home_url'),
            'contact_phone' => __('setting_contact_phone'),
            'contact_email' => __('setting_contact_email'),
            'contact_address' => __('setting_contact_address'),
            'copyright' => __('setting_footer_copyright_text'),
        ];
        $out = ['none' => __('blox_dynamic_field_none')];
        foreach (self::FIELDS[$slot] ?? [] as $field) {
            $out[$field] = $labels[$field];
        }
        return $out;
    }

    public static function value(string $field, string $slot, string $fallback = ''): string
    {
        if (!in_array($field, self::FIELDS[$slot] ?? [], true)) {
            return $fallback;
        }
        $value = TagEngine::configValue($field, $fallback);
        return trim($value) !== '' ? $value : $fallback;
    }

    /** @param array<string,mixed> $data */
    public static function usesBinding(array $data): bool
    {
        foreach (['site_field', 'site_image_field', 'site_text_field', 'site_url_field'] as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($value !== '' && $value !== 'none') {
                return true;
            }
        }
        return false;
    }
}
