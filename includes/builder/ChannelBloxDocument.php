<?php
/** Draft and publication contract for Blox-managed data channel landing pages. */

declare(strict_types=1);

final class ChannelBloxDocument
{
    /** @return array{page:array<string,mixed>,document_json:string,base_revision:string,has_draft:bool,has_published:bool,has_unpublished_changes:bool,published_at:int} */
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
        $processed = BloxDocumentPipeline::process($blocksJson, 'page');
        bloxPageDraftModel()->saveForPage($channelId, $processed['json'], $adminId);
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
        $processed = BloxDocumentPipeline::process($blocksJson, 'page');

        $database = db();
        $database->beginTransaction();
        try {
            $rowId = bloxPageDraftModel()->publishForPage($channelId, $processed['json'], $adminId);
            $database->commit();
        } catch (Throwable $e) {
            $database->rollback();
            throw $e;
        }

        cacheClear();
        do_action('data_changed', DB_PREFIX . 'blox_page_drafts', $rowId);

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
        if (!$channel || (string) ($channel['type'] ?? '') !== 'list'
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
                'text' => (string) ($channel['name'] ?? __('admin_article')),
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
                    'elements' => [[
                        'id' => 'e_content_catalog',
                        'type' => 'content-catalog',
                        'data' => [
                            'layout' => 'list', 'columns' => '3',
                            'show_search' => true, 'show_categories' => true,
                            'show_cover' => true, 'show_summary' => true,
                            'show_channel' => true, 'show_author' => false,
                            'show_date' => true, 'show_views' => true,
                        ],
                    ]],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
        if ($baseRevision !== '' && !BloxDocumentPipeline::revisionMatches($currentJson, $baseRevision)) {
            throw new RuntimeException(__('blox_save_conflict'));
        }
    }

    private static function assertDraftStorage(): void
    {
        if (!db()->tableExists('blox_page_drafts')) {
            throw new RuntimeException(__('blox_page_draft_storage_missing'));
        }
    }
}
