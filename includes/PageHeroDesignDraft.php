<?php
/** 全局页面标题区的草稿、发布与并发修订管理。 */

declare(strict_types=1);

final class PageHeroDesignDraft
{
    private const DRAFT_KEY = 'page_hero_design_draft';
    private const PUBLISHED_REVISION_KEY = 'page_hero_design_published_revision';

    /**
     * @return array{
     *   draft:array{background:string,options:array<string,mixed>},
     *   published:array{background:string,options:array<string,mixed>},
     *   revision:int,published_revision:int,has_draft:bool
     * }
     */
    public static function snapshot(): array
    {
        $published = self::publishedState();
        $stored = self::decodeDraft((string) settingModel()->get(self::DRAFT_KEY, ''));
        $revision = max(0, (int) ($stored['revision'] ?? 0));
        $draft = isset($stored['state']) && is_array($stored['state'])
            ? self::normalizeState($stored['state'])
            : $published;

        return [
            'draft' => $draft,
            'published' => $published,
            'revision' => $revision,
            'published_revision' => max(0, (int) settingModel()->get(self::PUBLISHED_REVISION_KEY, '0')),
            'has_draft' => $draft !== $published,
        ];
    }

    /** @param array<string,mixed> $state */
    public static function saveDraft(array $state, int $expectedRevision): array
    {
        $snapshot = self::snapshot();
        if ($expectedRevision !== $snapshot['revision']) {
            throw new RuntimeException(__('blox_save_conflict'));
        }

        $revision = $snapshot['revision'] + 1;
        settingModel()->saveBatch([
            self::DRAFT_KEY => (string) json_encode([
                'revision' => $revision,
                'updated_at' => time(),
                'state' => self::normalizeState($state),
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);

        return self::snapshot();
    }

    /** @param array<string,mixed> $state */
    public static function publish(array $state, int $expectedRevision): array
    {
        $snapshot = self::saveDraft($state, $expectedRevision);
        $draft = $snapshot['draft'];
        settingModel()->saveBatch([
            'page_hero_default_bg' => $draft['background'],
            'page_hero_style_options' => PageHeroStyleResolver::encodeOptions($draft['options']),
            self::PUBLISHED_REVISION_KEY => (string) $snapshot['revision'],
        ]);
        // 设计 API 是轻量入口，不加载 HtmlCache 钩子；发布时仍须主动清掉烤入旧标题区的页面缓存。
        cacheClear();

        return self::snapshot();
    }

    /** @return array{background:string,options:array<string,mixed>} */
    private static function publishedState(): array
    {
        return self::normalizeState([
            'background' => (string) config('page_hero_default_bg', ''),
            'options' => (string) config('page_hero_style_options', ''),
        ]);
    }

    /**
     * @param array<string,mixed> $state
     * @return array{background:string,options:array<string,mixed>}
     */
    private static function normalizeState(array $state): array
    {
        return [
            'background' => trim((string) ($state['background'] ?? '')),
            'options' => PageHeroStyleResolver::normalizeOptions($state['options'] ?? []),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeDraft(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
