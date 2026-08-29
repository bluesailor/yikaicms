<?php
/** 页面标题区背景与版式来源解析。 */

declare(strict_types=1);

final class PageHeroStyleResolver
{
    public const MODE_SELF = 'self';
    public const MODE_PARENT = 'parent';
    public const MODE_GLOBAL = 'global';

    private const MAX_ANCESTORS = 32;

    /** @return array{background_color:string,overlay_opacity:int,height:string,alignment:string,text_tone:string} */
    public static function defaultOptions(bool $compactContact = false): array
    {
        return [
            'background_color' => '',
            'overlay_opacity' => 60,
            'height' => $compactContact ? 'compact' : 'standard',
            'alignment' => $compactContact ? 'left' : 'center',
            'text_tone' => 'auto',
        ];
    }

    /**
     * @param array<string,mixed>|string|null $raw
     * @return array{background_color:string,overlay_opacity:int,height:string,alignment:string,text_tone:string}
     */
    public static function normalizeOptions(array|string|null $raw, bool $compactContact = false): array
    {
        $options = self::defaultOptions($compactContact);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return $options;
        }

        $color = trim((string) ($raw['background_color'] ?? ''));
        if ($color === ''
            || preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1
            || preg_match('/^var\(--yk-color-[a-z0-9-]{1,48}\)$/', $color) === 1
        ) {
            $options['background_color'] = strtolower($color);
        }
        $options['overlay_opacity'] = max(0, min(90, (int) ($raw['overlay_opacity'] ?? $options['overlay_opacity'])));
        $options['height'] = in_array(($raw['height'] ?? null), ['compact', 'standard', 'large'], true)
            ? (string) $raw['height']
            : $options['height'];
        $options['alignment'] = in_array(($raw['alignment'] ?? null), ['left', 'center'], true)
            ? (string) $raw['alignment']
            : $options['alignment'];
        $options['text_tone'] = in_array(($raw['text_tone'] ?? null), ['auto', 'light', 'dark'], true)
            ? (string) $raw['text_tone']
            : $options['text_tone'];

        return $options;
    }

    /** @param array<string,mixed>|string|null $raw */
    public static function encodeOptions(array|string|null $raw, bool $compactContact = false): string
    {
        $normalized = self::normalizeOptions($raw, $compactContact);
        if ($normalized === self::defaultOptions($compactContact)) {
            return '';
        }
        return (string) json_encode($normalized, JSON_UNESCAPED_SLASHES);
    }

    public static function normalizeMode(?string $mode): string
    {
        return in_array($mode, [self::MODE_SELF, self::MODE_PARENT, self::MODE_GLOBAL], true)
            ? $mode
            : self::MODE_SELF;
    }

    /**
     * @param array<string,mixed> $channel
     * @param null|callable(int):(?array<string,mixed>) $channelLoader
     * @param array<string,mixed>|string|null $globalOptions
     * @return array{mode:string,background:string,source:string,source_channel_id:int,source_channel_name:string,inheritance_path:list<string>,can_inherit:bool,options:array{background_color:string,overlay_opacity:int,height:string,alignment:string,text_tone:string}}
     */
    public static function resolve(
        array $channel,
        bool $compactContact = false,
        ?callable $channelLoader = null,
        ?string $globalBackground = null,
        array|string|null $globalOptions = null
    ): array {
        $mode = self::normalizeMode((string) ($channel['hero_style_source'] ?? self::MODE_SELF));
        $globalBackground ??= (string) config('page_hero_default_bg', '');
        $globalOptions ??= (string) config('page_hero_style_options', '');
        $normalizedGlobalOptions = self::normalizeOptions($globalOptions);
        $canInherit = (int) ($channel['parent_id'] ?? 0) > 0;

        if ($mode === self::MODE_GLOBAL) {
            return self::globalResult($mode, $globalBackground, $canInherit, $normalizedGlobalOptions);
        }

        if ($mode === self::MODE_PARENT) {
            $channelLoader ??= static fn(int $id): ?array => channelModel()->find($id);
            $parentId = (int) ($channel['parent_id'] ?? 0);
            $channelLang = (string) ($channel['lang'] ?? '');
            $visited = [];
            $inheritancePath = [];
            $currentId = (int) ($channel['id'] ?? 0);
            if ($currentId > 0) {
                $visited[$currentId] = true;
            }

            for ($depth = 0; $parentId > 0 && $depth < self::MAX_ANCESTORS; $depth++) {
                if (isset($visited[$parentId])) {
                    break;
                }
                $visited[$parentId] = true;
                $parent = $channelLoader($parentId);
                if (!is_array($parent)) {
                    break;
                }
                $parentLang = (string) ($parent['lang'] ?? '');
                if ($channelLang !== '' && $parentLang !== '' && $channelLang !== $parentLang) {
                    break;
                }
                $parentName = trim((string) ($parent['name'] ?? ''));
                if ($parentName !== '') {
                    $inheritancePath[] = $parentName;
                }

                $parentMode = self::normalizeMode((string) ($parent['hero_style_source'] ?? self::MODE_SELF));
                if ($parentMode === self::MODE_GLOBAL) {
                    return self::globalResult($mode, $globalBackground, $canInherit, $normalizedGlobalOptions, $inheritancePath);
                }

                if ($parentMode === self::MODE_SELF) {
                    $parentBackground = trim((string) ($parent['hero_bg'] ?? ''));
                    if ($parentBackground === '') {
                        $parentBackground = trim((string) ($parent['image'] ?? ''));
                    }
                    $parentOptionsRaw = trim((string) ($parent['hero_style_options'] ?? ''));
                    if ($parentBackground !== '' || $parentOptionsRaw !== '') {
                        return [
                            'mode' => $mode,
                            'background' => $parentBackground !== '' ? $parentBackground : $globalBackground,
                            'source' => 'parent',
                            'source_channel_id' => (int) ($parent['id'] ?? 0),
                            'source_channel_name' => (string) ($parent['name'] ?? ''),
                            'inheritance_path' => $inheritancePath,
                            'can_inherit' => $canInherit,
                            'options' => self::normalizeOptions($parentOptionsRaw),
                        ];
                    }
                }
                $parentId = (int) ($parent['parent_id'] ?? 0);
            }

            return self::globalResult($mode, $globalBackground, $canInherit, $normalizedGlobalOptions, $inheritancePath);
        }

        $localOptions = self::normalizeOptions($channel['hero_style_options'] ?? '', $compactContact);
        $heroBackground = trim((string) ($channel['hero_bg'] ?? ''));
        if ($heroBackground !== '') {
            return self::localResult($mode, $heroBackground, 'custom', $canInherit, $localOptions);
        }
        if (!$compactContact) {
            $cover = trim((string) ($channel['image'] ?? ''));
            if ($cover !== '') {
                return self::localResult($mode, $cover, 'cover', $canInherit, $localOptions);
            }
            if ($globalBackground !== '') {
                return self::localResult($mode, $globalBackground, 'global', $canInherit, $localOptions);
            }
            return self::localResult($mode, '', 'builtin', $canInherit, $localOptions);
        }

        return self::localResult($mode, '', 'builtin', $canInherit, $localOptions);
    }

    /**
     * @param array{background_color:string,overlay_opacity:int,height:string,alignment:string,text_tone:string} $options
     * @return array{mode:string,background:string,source:string,source_channel_id:int,source_channel_name:string,inheritance_path:list<string>,can_inherit:bool,options:array{background_color:string,overlay_opacity:int,height:string,alignment:string,text_tone:string}}
     */
    private static function localResult(string $mode, string $background, string $source, bool $canInherit, array $options): array
    {
        return [
            'mode' => $mode,
            'background' => $background,
            'source' => $source,
            'source_channel_id' => 0,
            'source_channel_name' => '',
            'inheritance_path' => [],
            'can_inherit' => $canInherit,
            'options' => $options,
        ];
    }

    /**
     * @param array{background_color:string,overlay_opacity:int,height:string,alignment:string,text_tone:string} $options
     * @param list<string> $inheritancePath
     * @return array{mode:string,background:string,source:string,source_channel_id:int,source_channel_name:string,inheritance_path:list<string>,can_inherit:bool,options:array{background_color:string,overlay_opacity:int,height:string,alignment:string,text_tone:string}}
     */
    private static function globalResult(
        string $mode,
        string $background,
        bool $canInherit,
        array $options,
        array $inheritancePath = []
    ): array
    {
        $result = self::localResult($mode, $background, $background !== '' ? 'global' : 'builtin', $canInherit, $options);
        $result['inheritance_path'] = $inheritancePath;
        return $result;
    }
}
