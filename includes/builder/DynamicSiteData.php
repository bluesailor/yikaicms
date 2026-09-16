<?php
/** 可视化动态绑定可读取的站点字段白名单。 */

declare(strict_types=1);

final class DynamicSiteData
{
    private const TAGS = [
        'site_name' => 'site_name', 'site_description' => 'site_description',
        'phone' => 'contact_phone', 'email' => 'contact_email', 'address' => 'contact_address',
        'year' => '', 'copyright' => 'footer_copyright_text', 'icp' => 'site_icp', 'police' => 'site_police',
        'author' => '',
    ];

    /**
     * @return array<string,string>
     * 唯一调用方是 Blox 编辑器（admin/blox_editor/partials/media-editing-methods.php），
     * 该目录不随公开仓分发、被 psalm.xml 排除扫描，故静态分析视为无人调用。对外 API，勿删。
     * @psalm-suppress PossiblyUnusedMethod
     */
    public static function tagOptions(bool $links = false): array
    {
        if ($links) {
            return ['{phone_link}' => __('blox_contact_show_phone'), '{email_link}' => __('blox_contact_show_email'), '{site_url}' => __('blox_dynamic_site_home_url')];
        }
        $labels = ['site_name' => 'setting_site_name', 'site_description' => 'setting_site_description',
            'phone' => 'setting_contact_phone', 'email' => 'setting_contact_email', 'address' => 'setting_contact_address',
            'year' => 'blox_dynamic_current_year', 'copyright' => 'setting_footer_copyright_text',
            'icp' => 'blox_dynamic_icp', 'police' => 'blox_dynamic_police', 'author' => 'blox_dynamic_author'];
        $out = [];
        foreach ($labels as $tag => $label) $out['{' . $tag . '}'] = __($label);
        return $out;
    }

    public static function interpolate(string $text, bool $links = false): string
    {
        // One pass only: settings are data, never nested expressions or executable tags.
        return preg_replace_callback('/(?<!\{)\{([a-z_]+)\}(?!\})/', static function (array $match) use ($links): string {
            $tag = $match[1];
            if ($links) {
                if ($tag === 'phone_link') return SiteContactElement::phoneHref(self::tagValue('phone'));
                if ($tag === 'email_link') {
                    $email = self::tagValue('email');
                    return filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $email : '';
                }
                if ($tag === 'site_url') return function_exists('langPrefix') ? langPrefix() . '/' : '/';
                return $match[0];
            }
            return array_key_exists($tag, self::TAGS) ? self::tagValue($tag) : $match[0];
        }, $text) ?? $text;
    }

    private static function tagValue(string $tag): string
    {
        if ($tag === 'year') return date('Y');
        if ($tag === 'author') {
            // Use the document's public author, never the signed-in administrator.
            $author = ArticleTemplateDocument::currentContent()['author'] ?? '';
            return is_scalar($author) ? trim((string) $author) : '';
        }
        if (in_array($tag, ['icp', 'police'], true) && function_exists('siteLang') && siteLang() !== 'zh-CN') return '';
        $key = self::TAGS[$tag];
        $value = in_array($tag, ['icp', 'police'], true)
            ? config($key, '')
            : (function_exists('configRawLang') ? configRawLang($key, '') : config($key, ''));
        $value = is_scalar($value) ? (string) $value : '';
        if ($tag === 'copyright') {
            if (trim($value) === '') $value = '© {year} {site_name} ' . __('footer_copyright');
            return SiteCopyrightElement::formatText($value, self::tagValue('site_name'), (int) date('Y'));
        }
        return $value;
    }

    public static function interpolateHtml(string $html): string
    {
        if (!preg_match('/\{[a-z_]+\}/', $html)) return $html;
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="yk-dynamic-root">' . $html . '</div></body></html>', LIBXML_NONET);
            $xpath = new DOMXPath($doc);
            // Only text nodes; attributes, URLs, code samples and scripts are not interpolated.
            foreach ($xpath->query('//*[@id="yk-dynamic-root"]//text()[not(ancestor::script or ancestor::style or ancestor::code or ancestor::pre)]') as $node) {
                if (!$node instanceof DOMText) continue;
                $node->nodeValue = self::interpolate($node->nodeValue ?? '');
            }
            $root = $doc->getElementById('yk-dynamic-root');
            if ($root === null) return $html;
            $out = '';
            foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

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
