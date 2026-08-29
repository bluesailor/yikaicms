<?php
/** 页面标题区背景来源解析。 */

declare(strict_types=1);

final class PageHeroStyleResolver
{
    public const MODE_SELF = 'self';
    public const MODE_PARENT = 'parent';
    public const MODE_GLOBAL = 'global';

    private const MAX_ANCESTORS = 32;

    public static function normalizeMode(?string $mode): string
    {
        return in_array($mode, [self::MODE_SELF, self::MODE_PARENT, self::MODE_GLOBAL], true)
            ? $mode
            : self::MODE_SELF;
    }

    /**
     * @param array<string,mixed> $channel
     * @param null|callable(int):(?array<string,mixed>) $channelLoader
     * @return array{mode:string,background:string,source:string,source_channel_id:int,source_channel_name:string,can_inherit:bool}
     */
    public static function resolve(
        array $channel,
        bool $compactContact = false,
        ?callable $channelLoader = null,
        ?string $globalBackground = null
    ): array {
        $mode = self::normalizeMode((string) ($channel['hero_style_source'] ?? self::MODE_SELF));
        $globalBackground ??= (string) config('page_hero_default_bg', '');
        $canInherit = (int) ($channel['parent_id'] ?? 0) > 0;

        if ($mode === self::MODE_GLOBAL) {
            return self::globalResult($mode, $globalBackground, $canInherit);
        }

        if ($mode === self::MODE_PARENT) {
            $channelLoader ??= static fn(int $id): ?array => channelModel()->find($id);
            $parentId = (int) ($channel['parent_id'] ?? 0);
            $channelLang = (string) ($channel['lang'] ?? '');
            $visited = [];
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

                $parentMode = self::normalizeMode((string) ($parent['hero_style_source'] ?? self::MODE_SELF));
                if ($parentMode === self::MODE_GLOBAL) {
                    return self::globalResult($mode, $globalBackground, $canInherit);
                }

                if ($parentMode === self::MODE_SELF) {
                    $parentBackground = trim((string) ($parent['hero_bg'] ?? ''));
                    if ($parentBackground === '') {
                        $parentBackground = trim((string) ($parent['image'] ?? ''));
                    }
                    if ($parentBackground !== '') {
                        return [
                            'mode' => $mode,
                            'background' => $parentBackground,
                            'source' => 'parent',
                            'source_channel_id' => (int) ($parent['id'] ?? 0),
                            'source_channel_name' => (string) ($parent['name'] ?? ''),
                            'can_inherit' => $canInherit,
                        ];
                    }
                }
                $parentId = (int) ($parent['parent_id'] ?? 0);
            }

            return self::globalResult($mode, $globalBackground, $canInherit);
        }

        $heroBackground = trim((string) ($channel['hero_bg'] ?? ''));
        if ($heroBackground !== '') {
            return self::localResult($mode, $heroBackground, 'custom', $canInherit);
        }
        if (!$compactContact) {
            $cover = trim((string) ($channel['image'] ?? ''));
            if ($cover !== '') {
                return self::localResult($mode, $cover, 'cover', $canInherit);
            }
            return self::globalResult($mode, $globalBackground, $canInherit);
        }

        return self::localResult($mode, '', 'builtin', $canInherit);
    }

    /** @return array{mode:string,background:string,source:string,source_channel_id:int,source_channel_name:string,can_inherit:bool} */
    private static function localResult(string $mode, string $background, string $source, bool $canInherit): array
    {
        return [
            'mode' => $mode,
            'background' => $background,
            'source' => $source,
            'source_channel_id' => 0,
            'source_channel_name' => '',
            'can_inherit' => $canInherit,
        ];
    }

    /** @return array{mode:string,background:string,source:string,source_channel_id:int,source_channel_name:string,can_inherit:bool} */
    private static function globalResult(string $mode, string $background, bool $canInherit): array
    {
        return self::localResult($mode, $background, $background !== '' ? 'global' : 'builtin', $canInherit);
    }
}
