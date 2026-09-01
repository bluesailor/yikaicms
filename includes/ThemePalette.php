<?php

declare(strict_types=1);

/** Theme-owned color presets plus per-site overrides. */
final class ThemePalette
{
    /** @return array{colors:array{primary:string,secondary:string},preview:list<string>,palettes:list<array<string,string>>} */
    public static function definition(string $themesRoot, string $slug): array
    {
        $fallback = [
            'colors' => ['primary' => '#2563EB', 'secondary' => '#1D4ED8'],
            'preview' => ['#2563EB', '#1D4ED8', '#F8FAFC', '#172033'],
            'palettes' => [],
        ];
        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/D', $slug) !== 1) {
            return self::withDefaultPalette($fallback);
        }

        $themeDir = rtrim($themesRoot, '/\\') . DIRECTORY_SEPARATOR . $slug;
        $manifest = self::readJson($themeDir . DIRECTORY_SEPARATOR . 'theme.json');
        $tokenFile = basename((string) ($manifest['design_tokens'] ?? ''));
        if ($tokenFile === '' || $tokenFile !== (string) ($manifest['design_tokens'] ?? '')) {
            return self::withDefaultPalette($fallback);
        }

        $tokens = self::readJson($themeDir . DIRECTORY_SEPARATOR . $tokenFile);
        $primary = self::color($tokens['colors']['primary'] ?? null) ?? $fallback['colors']['primary'];
        $secondary = self::color($tokens['colors']['secondary'] ?? null) ?? $fallback['colors']['secondary'];
        $preview = [];
        foreach ((array) ($tokens['preview'] ?? []) as $value) {
            $color = self::color($value);
            if ($color !== null && !in_array($color, $preview, true)) {
                $preview[] = $color;
            }
        }
        if ($preview === []) {
            $preview = [$primary, $secondary];
        }

        $palettes = [];
        foreach ((array) ($tokens['palettes'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $itemPrimary = self::color($item['primary'] ?? null);
            $itemSecondary = self::color($item['secondary'] ?? null);
            if ($itemPrimary === null || $itemSecondary === null) continue;
            $palettes[] = [
                'name' => self::label($item['name'] ?? ''),
                'name_en' => self::label($item['name_en'] ?? ($item['name'] ?? '')),
                'name_ja' => self::label($item['name_ja'] ?? ($item['name'] ?? '')),
                'primary' => $itemPrimary,
                'secondary' => $itemSecondary,
            ];
        }

        return self::withDefaultPalette([
            'colors' => ['primary' => $primary, 'secondary' => $secondary],
            'preview' => array_slice($preview, 0, 5),
            'palettes' => $palettes,
        ]);
    }

    /** @return array<string,array{primary:string,secondary:string}> */
    public static function profiles(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) return [];
        $profiles = [];
        foreach ($decoded as $slug => $colors) {
            if (!is_string($slug)
                || preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/D', $slug) !== 1
                || !is_array($colors)) {
                continue;
            }
            $primary = self::color($colors['primary'] ?? null);
            $secondary = self::color($colors['secondary'] ?? null);
            if ($primary !== null && $secondary !== null) {
                $profiles[$slug] = ['primary' => $primary, 'secondary' => $secondary];
            }
        }
        return $profiles;
    }

    /** @param array<string,array{primary:string,secondary:string}> $profiles */
    public static function encodeProfiles(array $profiles): string
    {
        ksort($profiles);
        return (string) json_encode($profiles, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function color(mixed $value): ?string
    {
        $color = strtoupper(trim((string) $value));
        return preg_match('/^#[0-9A-F]{6}$/D', $color) === 1 ? $color : null;
    }

    private static function label(mixed $value): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, 40);
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path): array
    {
        if (!is_file($path)) return [];
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array{colors:array{primary:string,secondary:string},preview:list<string>,palettes:list<array<string,string>>} $definition
     * @return array{colors:array{primary:string,secondary:string},preview:list<string>,palettes:list<array<string,string>>}
     */
    private static function withDefaultPalette(array $definition): array
    {
        if ($definition['palettes'] === []) {
            $definition['palettes'][] = [
                'name' => '',
                'name_en' => 'Theme default',
                'name_ja' => '',
                'primary' => $definition['colors']['primary'],
                'secondary' => $definition['colors']['secondary'],
            ];
        }
        return $definition;
    }
}
