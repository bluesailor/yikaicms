<?php
/** Draft and publication contract for a Blox-managed single page. */

declare(strict_types=1);

final class PageBloxDocument
{
    /** @return array{page:array<string,mixed>,document_json:string,base_revision:string,has_draft:bool,has_published:bool,has_unpublished_changes:bool,published_at:int} */
    public static function load(int $pageId): array
    {
        $page = self::page($pageId);
        $published = self::publishedRecord($pageId);
        $publishedJson = self::publishedDocumentJson($published);
        $draft = db()->tableExists('blox_page_drafts')
            ? bloxPageDraftModel()->findByPageId($pageId)
            : null;
        $hasDraft = $draft !== null && trim((string) ($draft['draft_data'] ?? '')) !== '';
        $documentJson = $hasDraft
            ? self::canonicalJson((string) $draft['draft_data'])
            : $publishedJson;

        return [
            'page' => $page,
            'document_json' => $documentJson,
            'base_revision' => BloxDocumentPipeline::fingerprint($documentJson),
            'has_draft' => $hasDraft,
            'has_published' => trim((string) ($published['blocks_data'] ?? '')) !== '',
            'has_unpublished_changes' => $hasDraft && !hash_equals(
                BloxDocumentPipeline::fingerprint($publishedJson),
                BloxDocumentPipeline::fingerprint($documentJson)
            ),
            'published_at' => (int) ($draft['published_at'] ?? 0),
        ];
    }

    /** @return array{base_revision:string,has_unpublished_changes:bool,sections:int} */
    public static function saveDraft(int $pageId, string $blocksJson, string $baseRevision = '', int $adminId = 0): array
    {
        self::assertStorageAvailable();
        $state = self::load($pageId);
        self::assertRevision($state['document_json'], $baseRevision);
        $processed = BloxDocumentPipeline::process($blocksJson, 'page');
        bloxPageDraftModel()->saveForPage($pageId, $processed['json'], $adminId);

        $published = self::publishedRecord($pageId);
        $publishedJson = self::publishedDocumentJson($published);

        return [
            'base_revision' => BloxDocumentPipeline::fingerprint($processed['json']),
            'has_unpublished_changes' => !hash_equals(
                BloxDocumentPipeline::fingerprint($publishedJson),
                BloxDocumentPipeline::fingerprint($processed['json'])
            ),
            'sections' => count($processed['sections']),
        ];
    }

    /** @return array{base_revision:string,published:bool,has_unpublished_changes:bool,sections:int} */
    public static function saveAndPublish(int $pageId, string $blocksJson, string $baseRevision = '', int $adminId = 0): array
    {
        self::assertStorageAvailable();
        $state = self::load($pageId);
        self::assertRevision($state['document_json'], $baseRevision);
        $processed = BloxDocumentPipeline::process($blocksJson, 'page');
        $page = $state['page'];
        $published = self::publishedRecord($pageId);
        $renderedHtml = renderBlocksToHtml($processed['json']);
        $now = time();

        $revisionTargets = [[
            'table' => 'channels',
            'id' => $pageId,
            'fields' => [
                'content' => (string) ($page['content'] ?? ''),
            ],
        ]];
        if ($published) {
            $revisionTargets[] = [
                'table' => 'contents',
                'id' => (int) $published['id'],
                'fields' => [
                    'content' => (string) ($published['content'] ?? ''),
                    'content_type' => (string) ($published['content_type'] ?? 'blocks'),
                    'blocks_data' => $published['blocks_data'] ?? null,
                ],
            ];
        }

        $database = db();
        $database->beginTransaction();
        try {
            bloxPageDraftModel()->saveForPage($pageId, $processed['json'], $adminId);
            recordContentRevision(
                'page',
                $pageId,
                (string) ($page['lang'] ?? ''),
                $revisionTargets,
                (string) ($page['name'] ?? '')
            );
            channelModel()->updateById($pageId, [
                'content' => $renderedHtml,
                'updated_at' => $now,
            ]);

            if ($published) {
                contentModel()->updateById((int) $published['id'], [
                    'content' => $renderedHtml,
                    'content_type' => 'blocks',
                    'blocks_data' => $processed['json'],
                    'updated_at' => $now,
                ]);
                $contentId = (int) $published['id'];
            } else {
                $contentId = contentModel()->create([
                    'channel_id' => $pageId,
                    'lang' => (string) ($page['lang'] ?? siteLang()),
                    'title' => (string) ($page['name'] ?? ''),
                    'content' => $renderedHtml,
                    'content_type' => 'blocks',
                    'blocks_data' => $processed['json'],
                    'status' => 1,
                    'publish_time' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            bloxPageDraftModel()->markPublished($pageId, $now);
            $database->commit();
        } catch (Throwable $e) {
            $database->rollback();
            throw $e;
        }

        cacheClear();
        do_action('data_changed', DB_PREFIX . 'contents', $contentId);

        return [
            'base_revision' => BloxDocumentPipeline::fingerprint($processed['json']),
            'published' => true,
            'has_unpublished_changes' => false,
            'sections' => count($processed['sections']),
        ];
    }

    public static function syncDraftFromPublished(int $pageId, int $adminId = 0): void
    {
        if (!db()->tableExists('blox_page_drafts')) {
            return;
        }
        self::page($pageId);
        $published = self::publishedRecord($pageId);
        bloxPageDraftModel()->saveForPage(
            $pageId,
            self::publishedDocumentJson($published),
            $adminId
        );
    }

    /** @return array<string,mixed> */
    private static function page(int $pageId): array
    {
        $page = $pageId > 0 ? channelModel()->findWhere(['id' => $pageId, 'type' => 'page']) : null;
        if (!$page) {
            throw new RuntimeException(__('blox_page_not_found'));
        }
        return $page;
    }

    /** @return array<string,mixed>|null */
    private static function publishedRecord(int $pageId): ?array
    {
        return contentModel()->queryOne(
            'SELECT * FROM ' . contentModel()->tableName()
            . ' WHERE channel_id = ? AND status = 1 AND deleted_at IS NULL'
            . ' ORDER BY is_top DESC, id DESC LIMIT 1',
            [$pageId]
        );
    }

    private static function canonicalJson(string $raw): string
    {
        $raw = trim($raw) !== '' ? $raw : '[]';
        $document = BloxDocumentPipeline::decode($raw);
        return json_encode([
            'schema' => $document['schema'],
            'settings' => $document['settings'],
            'sections' => $document['sections'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * 已有富文本页进入 Blox 时包装成一个可编辑 Text 元素。
     * 这只是读取时的初始文档；明确发布 Blox 前，线上记录继续保持 HTML。
     *
     * @param array<string,mixed>|null $published
     */
    private static function publishedDocumentJson(?array $published): string
    {
        $blocksData = trim((string) ($published['blocks_data'] ?? ''));
        if ($blocksData !== '') {
            return self::canonicalJson($blocksData);
        }

        $html = trim((string) ($published['content'] ?? ''));
        if ($html === '' || (string) ($published['content_type'] ?? 'html') === 'blocks') {
            return self::canonicalJson('[]');
        }

        return self::canonicalJson(json_encode([[
            'id' => 's_legacy',
            'settings' => [
                'bg_color' => '',
                'bg_image' => '',
                'padding' => 'md',
                'max_width' => 'default',
                'align_items' => 'stretch',
                'justify_items' => 'stretch',
                'gap' => 'lg',
            ],
            'columns' => [[
                'id' => 'c_legacy',
                'elements' => [[
                    'id' => 'e_legacy',
                    'type' => 'text',
                    'data' => ['html' => $html],
                ]],
            ]],
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function assertRevision(string $currentJson, string $baseRevision): void
    {
        if ($baseRevision !== '' && !BloxDocumentPipeline::revisionMatches($currentJson, $baseRevision)) {
            throw new RuntimeException(__('blox_save_conflict'));
        }
    }

    private static function assertStorageAvailable(): void
    {
        if (!db()->tableExists('blox_page_drafts')) {
            throw new RuntimeException(__('blox_page_draft_storage_missing'));
        }
    }
}
