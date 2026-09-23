<?php
/** Draft and publication contract for Blox-managed data channel landing pages. */

declare(strict_types=1);

final class ChannelBloxDocument
{
    /**
     * 有 Blox 落地文档的栏目类型（顶级栏目）。编辑器入口、保存接口、画布预览、发布状态、
     * 前台 list.php 与页面列表都从这里判断，加一种类型只改这一处。
     * 资讯与案例存在 contents 表，用内容目录元素（content-catalog）；下载与招聘各有独立的表，
     * 分别用下载目录（download-catalog）与职位目录（job-catalog）。
     */
    public const CHANNEL_TYPES = ['list', 'case', 'download', 'job'];

    /** 数据在 contents 表的栏目类型：内容目录元素、编辑器内容侧栏、共享栏目列表模板只服务它们。 */
    public const CONTENT_TYPES = ['list', 'case'];

    public static function supportsType(string $channelType): bool
    {
        return in_array($channelType, self::CHANNEL_TYPES, true);
    }

    public static function usesContents(string $channelType): bool
    {
        return in_array($channelType, self::CONTENT_TYPES, true);
    }

    /**
     * 编辑器元素面板的上下文：各类型只出现与自己数据对应的目录元素。
     *
     * @psalm-suppress PossiblyUnusedMethod 调用方是 admin/blox_editor.php（不在 Psalm 扫描范围）
     */
    public static function paletteContext(string $channelType): string
    {
        return match ($channelType) {
            'download' => 'download-list',
            'job' => 'job-list',
            default => 'content-list',
        };
    }

    /** 栏目类型 → contents.type（资讯栏目存的是 article）。 */
    public static function contentType(string $channelType): string
    {
        return $channelType === 'list' ? 'article' : $channelType;
    }

    /** @return array{page:array<string,mixed>,document_json:string,published_document_json:string,base_revision:string,has_draft:bool,has_published:bool,has_unpublished_changes:bool,published_at:int} */
    public static function load(int $channelId): array
    {
        $channel = self::channel($channelId);
        $row = db()->tableExists('blox_page_drafts')
            ? bloxPageDraftModel()->findByPageId($channelId)
            : null;
        $publishedRaw = trim((string) ($row['published_data'] ?? ''));
        $publishedJson = $publishedRaw !== ''
            ? self::canonicalJson($publishedRaw)
            : self::defaultDocumentJson($channel);
        $draftRaw = trim((string) ($row['draft_data'] ?? ''));
        $hasDraft = $draftRaw !== '';
        $documentJson = $hasDraft ? self::canonicalJson($draftRaw) : $publishedJson;

        return [
            'page' => $channel,
            'document_json' => $documentJson,
            'published_document_json' => $publishedJson,
            'base_revision' => BloxDocumentPipeline::fingerprint($documentJson),
            'has_draft' => $hasDraft,
            'has_published' => $publishedRaw !== '',
            'has_unpublished_changes' => $hasDraft && (
                $publishedRaw === ''
                || !hash_equals(
                    BloxDocumentPipeline::fingerprint($publishedJson),
                    BloxDocumentPipeline::fingerprint($documentJson)
                )
            ),
            'published_at' => (int) ($row['published_at'] ?? 0),
        ];
    }

    /** @return array{base_revision:string,has_unpublished_changes:bool,sections:int} */
    public static function saveDraft(int $channelId, string $blocksJson, string $baseRevision = '', int $adminId = 0): array
    {
        self::assertDraftStorage();
        $state = self::load($channelId);
        self::assertRevision($state['document_json'], $baseRevision);
        $processed = BloxDocumentPipeline::process($blocksJson, 'page', trustedJson: $state['document_json']);
        BloxDocumentWriteLock::channel(
            $channelId,
            $state,
            static fn(): array => self::load($channelId),
            static fn(): int => bloxPageDraftModel()->saveForPage($channelId, $processed['json'], $adminId)
        );
        // 用量反向索引（全局类/全局查询）：保存即整体替换本文档的引用行
        BloxDocumentIndexes::update('channel:' . $channelId, $processed['sections']);
        $published = self::publishedJson($channelId);

        return [
            'base_revision' => BloxDocumentPipeline::fingerprint($processed['json']),
            'has_unpublished_changes' => $published === null
                || !hash_equals(
                    BloxDocumentPipeline::fingerprint($published),
                    BloxDocumentPipeline::fingerprint($processed['json'])
                ),
            'sections' => count($processed['sections']),
        ];
    }

    /** @return array{base_revision:string,published:bool,has_unpublished_changes:bool,sections:int} */
    public static function saveAndPublish(int $channelId, string $blocksJson, string $baseRevision = '', int $adminId = 0): array
    {
        self::assertDraftStorage();
        if (!bloxPageDraftModel()->hasPublishedStorage()) {
            throw new RuntimeException(__('blox_channel_storage_missing'));
        }
        $state = self::load($channelId);
        self::assertRevision($state['document_json'], $baseRevision);
        $processed = BloxDocumentPipeline::process($blocksJson, 'page', trustedJson: $state['document_json']);

        $rowId = BloxDocumentWriteLock::channel(
            $channelId,
            $state,
            static fn(): array => self::load($channelId),
            static fn(): int => bloxPageDraftModel()->publishForPage($channelId, $processed['json'], $adminId)
        );

        BloxDocumentIndexes::update('channel:' . $channelId, $processed['sections']);
        cacheClear();
        do_action('data_changed', 'blox_page_drafts', $rowId);

        return [
            'base_revision' => BloxDocumentPipeline::fingerprint($processed['json']),
            'published' => true,
            'has_unpublished_changes' => false,
            'sections' => count($processed['sections']),
        ];
    }

    /** @psalm-suppress PossiblyUnusedMethod 与 PageBloxDocument 同构的回滚契约，供隔离契约测试与栏目版本恢复流程使用 */
    public static function syncDraftFromPublished(int $channelId, int $adminId = 0): void
    {
        self::assertDraftStorage();
        $channel = self::channel($channelId);
        bloxPageDraftModel()->saveForPage(
            $channelId,
            self::publishedJson($channelId) ?? self::defaultDocumentJson($channel),
            $adminId
        );
    }

    public static function publishedJson(int $channelId): ?string
    {
        if (!db()->tableExists('blox_page_drafts') || !bloxPageDraftModel()->hasPublishedStorage()) {
            return null;
        }
        $row = bloxPageDraftModel()->findByPageId($channelId);
        $raw = trim((string) ($row['published_data'] ?? ''));
        return $raw !== '' ? self::canonicalJson($raw) : null;
    }

    /** @return array<string,mixed> */
    private static function channel(int $channelId): array
    {
        $channel = $channelId > 0 ? channelModel()->find($channelId) : null;
        if (!$channel || !self::supportsType((string) ($channel['type'] ?? ''))
            || (int) ($channel['parent_id'] ?? 0) !== 0) {
            throw new RuntimeException(__('blox_page_not_found'));
        }
        return $channel;
    }

    /** @param array<string,mixed> $channel */
    private static function defaultDocumentJson(array $channel): string
    {
        $intro = [[
            'id' => 'e_channel_title',
            'type' => 'heading',
            'data' => [
                'text' => (string) ($channel['name'] ?? __(match ((string) ($channel['type'] ?? '')) {
                    'case' => 'admin_case', 'download' => 'admin_download', 'job' => 'admin_job', default => 'admin_article',
                })),
                'level' => 'h1',
                'align' => 'center',
            ],
        ]];
        $description = trim((string) ($channel['description'] ?? ''));
        if ($description !== '') {
            $intro[] = [
                'id' => 'e_channel_intro',
                'type' => 'text',
                'data' => ['html' => '<p>' . e($description) . '</p>'],
            ];
        }

        return self::canonicalJson(json_encode([
            [
                'id' => 's_channel_intro',
                'settings' => [
                    'bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'narrow',
                    'align_items' => 'center', 'justify_items' => 'center', 'gap' => 'md',
                ],
                'columns' => [['id' => 'c_channel_intro', 'elements' => $intro]],
            ],
            [
                'id' => 's_content_catalog',
                'settings' => [
                    'bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'wide',
                    'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'lg',
                ],
                'columns' => [[
                    'id' => 'c_content_catalog',
                    'elements' => [self::catalogElement((string) ($channel['type'] ?? 'list'))],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> 默认文档里的目录元素，按栏目数据来源选型 */
    private static function catalogElement(string $channelType): array
    {
        return match ($channelType) {
            'download' => ['id' => 'e_download_catalog', 'type' => 'download-catalog',
                'data' => ['show_search' => true, 'show_categories' => true]],
            'job' => ['id' => 'e_job_catalog', 'type' => 'job-catalog', 'data' => ['show_pagination' => true]],
            default => ['id' => 'e_content_catalog', 'type' => 'content-catalog', 'data' => [
                'layout' => 'list', 'columns' => '3',
                'show_search' => true, 'show_categories' => true,
                'show_cover' => true, 'show_summary' => true,
                'show_channel' => true, 'show_author' => false,
                'show_date' => true, 'show_views' => true,
            ]],
        };
    }

    private static function canonicalJson(string $raw): string
    {
        $document = BloxDocumentPipeline::decode(trim($raw) !== '' ? $raw : '[]');
        return json_encode([
            'schema' => $document['schema'],
            'settings' => $document['settings'],
            'sections' => $document['sections'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function assertRevision(string $currentJson, string $baseRevision): void
    {
        BloxDocumentWriteLock::assertRevision($currentJson, $baseRevision);
    }

    private static function assertDraftStorage(): void
    {
        if (!db()->tableExists('blox_page_drafts')) {
            throw new RuntimeException(__('blox_page_draft_storage_missing'));
        }
    }
}
