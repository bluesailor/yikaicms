<?php

declare(strict_types=1);

/** Versioned global theme-style settings with legacy color fallbacks. */
final class ThemeSettings
{
    public const KEY = 'theme_style_settings';

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'version' => 1,
            'general' => [
                'site_layout' => 'full',
                'content_max_width' => 1200,
                'site_background' => '#F8FAFC',
                'content_background' => '#FFFFFF',
                'color_mode' => 'light',
            ],
            'typography' => [
                'html_font_size' => 16,
                'body_font' => 'system',
                'heading_font' => 'system',
            ],
            'spacing' => [
                'section_padding_y' => 64,
                'content_gutter' => 16,
            ],
            'button' => [
                'radius' => 4,
                'background' => '#2563EB',
                'text' => '#FFFFFF',
                'hover_background' => '#1D4ED8',
            ],
            'responsive' => [
                'tablet' => 1024,
                'mobile' => 768,
            ],
            'custom_css' => '',
        ];
    }

    /** @return array<string,mixed> */
    public static function read(string $themeSlug = ''): array
    {
        $raw = (string) config(self::KEY, '');
        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $themeSlug = $themeSlug !== '' ? $themeSlug : (function_exists('currentTheme') ? currentTheme() : 'default');
        $profiles = is_array($decoded['themes'] ?? null) ? $decoded['themes'] : [];
        $settings = is_array($profiles[$themeSlug] ?? null) ? $profiles[$themeSlug] : $decoded;
        $hasButtonBackground = is_array($settings['button'] ?? null) && array_key_exists('background', $settings['button']);
        $hasButtonHoverBackground = is_array($settings['button'] ?? null) && array_key_exists('hover_background', $settings['button']);
        $defaults = self::defaults();
        $settings = self::normalize(self::merge($defaults, $settings));
        if (!$hasButtonBackground) $settings['button']['background'] = self::color('', (string) config('primary_color', '#2563EB'));
        if (!$hasButtonHoverBackground) $settings['button']['hover_background'] = self::color('', (string) config('secondary_color', '#1D4ED8'));
        return $settings;
    }

    public static function hasProfile(string $themeSlug = ''): bool
    {
        $raw = (string) config(self::KEY, '');
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return false;
        $themeSlug = $themeSlug !== '' ? $themeSlug : (function_exists('currentTheme') ? currentTheme() : 'default');
        if (is_array($decoded['themes'] ?? null)) return is_array($decoded['themes'][$themeSlug] ?? null);
        return array_diff_key($decoded, ['version' => true, 'schema_version' => true]) !== [];
    }

    /** @param array<string,mixed> $settings */
    public static function encodeProfile(string $themeSlug, array $settings, string $raw = ''): string
    {
        $decoded = json_decode($raw, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $themes = is_array($decoded['themes'] ?? null) ? $decoded['themes'] : [];
        $themes[$themeSlug] = $settings;
        ksort($themes);
        return (string) json_encode(['schema_version' => 1, 'themes' => $themes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $input */
    public static function normalize(array $input): array
    {
        $d = self::defaults();
        $g = is_array($input['general'] ?? null) ? $input['general'] : [];
        $t = is_array($input['typography'] ?? null) ? $input['typography'] : [];
        $s = is_array($input['spacing'] ?? null) ? $input['spacing'] : [];
        $b = is_array($input['button'] ?? null) ? $input['button'] : [];
        $r = is_array($input['responsive'] ?? null) ? $input['responsive'] : [];
        $hex = static fn(mixed $v, string $fallback): string => self::color($v, $fallback);
        return [
            'version' => 1,
            'general' => [
                'site_layout' => in_array((string) ($g['site_layout'] ?? ''), ['full', 'boxed'], true) ? (string) $g['site_layout'] : $d['general']['site_layout'],
                'content_max_width' => max(760, min(1920, (int) ($g['content_max_width'] ?? 1200))),
                'site_background' => $hex($g['site_background'] ?? '', $d['general']['site_background']),
                'content_background' => $hex($g['content_background'] ?? '', $d['general']['content_background']),
                'color_mode' => in_array((string) ($g['color_mode'] ?? ''), ['light', 'dark', 'auto'], true) ? (string) $g['color_mode'] : 'light',
            ],
            'typography' => [
                'html_font_size' => max(14, min(20, (int) ($t['html_font_size'] ?? 16))),
                'body_font' => self::font($t['body_font'] ?? 'system'),
                'heading_font' => self::font($t['heading_font'] ?? 'system'),
            ],
            'spacing' => [
                'section_padding_y' => max(0, min(240, (int) ($s['section_padding_y'] ?? 64))),
                'content_gutter' => max(0, min(80, (int) ($s['content_gutter'] ?? 16))),
            ],
            'button' => [
                'radius' => max(0, min(32, (int) ($b['radius'] ?? 4))),
                'background' => $hex($b['background'] ?? '', (string) $d['button']['background']),
                'text' => $hex($b['text'] ?? '', (string) $d['button']['text']),
                'hover_background' => $hex($b['hover_background'] ?? '', (string) $d['button']['hover_background']),
            ],
            'responsive' => [
                'tablet' => max(800, min(1400, (int) ($r['tablet'] ?? 1024))),
                'mobile' => max(480, min(900, (int) ($r['mobile'] ?? 768))),
            ],
            'custom_css' => mb_substr((string) ($input['custom_css'] ?? ''), 0, 20000),
        ];
    }

    public static function css(): string
    {
        if (!self::hasProfile()) return '';
        $s = self::read();
        $g = $s['general']; $t = $s['typography']; $sp = $s['spacing']; $b = $s['button']; $r = $s['responsive'];
        $css = ':root{--yk-content-max-width:' . $g['content_max_width'] . 'px;--yk-content-gutter:' . $sp['content_gutter'] . 'px;--yk-html-font-size:' . $t['html_font_size'] . 'px;--yk-section-padding-y:' . $sp['section_padding_y'] . 'px;--yk-button-radius:' . $b['radius'] . 'px;--yk-button-bg:' . $b['background'] . ';--yk-button-text:' . $b['text'] . ';--yk-button-hover-bg:' . $b['hover_background'] . ';--yk-site-bg:' . $g['site_background'] . ';--yk-content-bg:' . $g['content_background'] . ';}';
        $css .= 'html{font-size:var(--yk-html-font-size);}.yk-site-body{background-color:var(--yk-site-bg);}';
        $css .= '.yk-site-body .container{max-width:var(--yk-content-max-width);}.yk-site-body main{background-color:var(--yk-content-bg);}' . ($g['site_layout'] === 'boxed' ? '.yk-site-body main{max-width:var(--yk-content-max-width);margin-left:auto;margin-right:auto;}' : '');
        $css .= 'button:not([class*="rounded"]),a.button,.btn,.yk-button{border-radius:var(--yk-button-radius);}' . ($t['body_font'] !== 'system' ? 'body{font-family:' . $t['body_font'] . ';}' : '') . ($t['heading_font'] !== 'system' ? 'h1,h2,h3,h4,h5,h6{font-family:' . $t['heading_font'] . ';}' : '');
        if ($g['color_mode'] === 'dark' || $g['color_mode'] === 'auto') {
            $dark = $g['color_mode'] === 'dark' ? '' : '@media (prefers-color-scheme:dark){';
            $css .= $dark . ':root{--yk-site-bg:#111827;--yk-content-bg:#1F2937;}.yk-site-body{color:#E5E7EB;}' . ($dark === '' ? '' : '}');
        }
        $css .= '@media (max-width:' . $r['tablet'] . 'px){.container{max-width:100%;}}@media (max-width:' . $r['mobile'] . 'px){:root{--yk-section-padding-y:' . max(32, (int) $sp['section_padding_y'] / 2) . 'px;}}';
        if (trim((string) $s['custom_css']) !== '') $css .= str_ireplace('</style', '<\\/style', (string) $s['custom_css']);
        return $css;
    }

    private static function color(mixed $value, string $fallback): string
    {
        $value = strtoupper(trim((string) $value));
        return preg_match('/^#[0-9A-F]{6}$/D', $value) === 1 ? $value : $fallback;
    }

    private static function font(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === 'system') return 'system';
        return preg_match('/^[a-zA-Z0-9 ,\'"_-]{1,160}$/', $value) === 1 ? $value : 'system';
    }

    /** @param array<string,mixed> $base @param array<string,mixed> $overrides */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && is_array($base[$key] ?? null)) $base[$key] = self::merge($base[$key], $value);
            elseif (array_key_exists($key, $base)) $base[$key] = $value;
        }
        return $base;
    }
}
