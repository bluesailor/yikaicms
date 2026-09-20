<?php
/** Draft and publication contract for a Blox-managed single page. */

declare(strict_types=1);

final class PageBloxDocument
{
    /** @return array{page:array<string,mixed>,document_json:string,published_document_json:string,base_revision:string,has_draft:bool,has_published:bool,has_unpublished_changes:bool,uses_legacy_html:bool,published_at:int} */
    public static function load(int $pageId): array
    {
        $page = self::page($pageId);
        $published = self::publishedRecord($pageId);
        $publishedJson = self::publishedDocumentJson($published, $page);
        $draft = db()->tableExists('blox_page_drafts')
            ? bloxPageDraftModel()->findByPageId($pageId)
            : null;
        $hasDraft = $draft !== null && trim((string) ($draft['draft_data'] ?? '')) !== '';
        $documentJson = $hasDraft
            ? self::canonicalJson((string) $draft['draft_data'])
            : $publishedJson;
        $legacyHtml = trim((string) ($published['content'] ?? ''));
        if ($legacyHtml === '') {
            $legacyHtml = trim((string) ($page['content'] ?? ''));
        }
        // Mark new blank canvases explicitly; missing settings on older documents retain the theme title.
        if (!$hasDraft && ($page['type'] ?? '') === 'page' && $legacyHtml === ''
            && trim((string) ($published['blocks_data'] ?? '')) === ''
            && (string) ($published['content_type'] ?? 'html') !== 'blocks') {
            $document = BloxDocumentPipeline::decode($documentJson);
            $document['settings']['page_title_hidden'] = true;
            $documentJson = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $usesLegacyHtml = !$hasDraft
            && trim((string) ($published['blocks_data'] ?? '')) === ''
            && (string) ($published['content_type'] ?? 'html') !== 'blocks'
            && $legacyHtml !== '';

        return [
            'page' => $page,
            'document_json' => $documentJson,
            'published_document_json' => $publishedJson,
            'base_revision' => BloxDocumentPipeline::fingerprint($documentJson),
            'has_draft' => $hasDraft,
            'has_published' => trim((string) ($published['blocks_data'] ?? '')) !== '',
            'has_unpublished_changes' => $hasDraft && (
                trim((string) ($published['blocks_data'] ?? '')) === ''
                || !hash_equals(
                    BloxDocumentPipeline::fingerprint($publishedJson),
                    BloxDocumentPipeline::fingerprint($documentJson)
                )
            ),
            'uses_legacy_html' => $usesLegacyHtml,
            'published_at' => (int) ($draft['published_at'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $settings */
    public static function usesThemeTitle(array $settings): bool
    {
        return !in_array($settings['page_title_hidden'] ?? false, [true, 1, '1'], true);
    }

    /** @return array{base_revision:string,has_unpublished_changes:bool,sections:int} */
    public static function saveDraft(int $pageId, string $blocksJson, string $baseRevision = '', int $adminId = 0): array
    {
        self::assertStorageAvailable();
        $state = self::load($pageId);
        self::assertRevision($state['document_json'], $baseRevision);
        $processed = BloxDocumentPipeline::process($blocksJson, 'page', trustedJson: $state['document_json']);
        BloxDocumentWriteLock::channel(
            $pageId,
            $state,
            static fn(): array => self::load($pageId),
            static fn(): int => bloxPageDraftModel()->saveForPage($pageId, $processed['json'], $adminId)
        );
        // v1.23 全局类用量反向索引：保存即整体替换本文档的引用行
        BloxGlobalClasses::replaceDocumentRefs('page:' . $pageId, BloxGlobalClasses::collectReferences($processed['sections']));

        $published = self::publishedRecord($pageId);
        $publishedJson = self::publishedDocumentJson($published, $state['page']);

        return [
            'base_revision' => BloxDocumentPipeline::fingerprint($processed['json']),
            'has_unpublished_changes' => trim((string) ($published['blocks_data'] ?? '')) === ''
                || !hash_equals(
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
        $processed = BloxDocumentPipeline::process($blocksJson, 'page', trustedJson: $state['document_json']);
        $renderedHtml = PageTitleElement::withPage($state['page'], static fn(): string => renderBlocksToHtml($processed['json']));
        $now = time();

        $contentId = BloxDocumentWriteLock::channel($pageId, $state, static fn(): array => self::load($pageId), static function () use ($pageId, $processed, $adminId, $renderedHtml, $now): int {
            // 锁内重读：版本快照要记录真正被覆盖的那一份线上内容。
            $page = self::page($pageId);
            $published = self::publishedRecord($pageId);
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
                $contentId = (int) contentModel()->create([
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
            return $contentId;
        });

        BloxGlobalClasses::replaceDocumentRefs('page:' . $pageId, BloxGlobalClasses::collectReferences($processed['sections']));
        cacheClear();
        do_action('data_changed', DB_PREFIX . 'contents', $contentId);

        return [
            'base_revision' => BloxDocumentPipeline::fingerprint($processed['json']),
            'published' => true,
            'has_unpublished_changes' => false,
            'sections' => count($processed['sections']),
        ];
    }

    /**
     * 版本快照里的 Blox 文档；纯 HTML 版本返回空串。
     *
     * @param array<string,mixed> $revision
     */
    public static function revisionBlocks(array $revision): string
    {
        $snapshot = json_decode((string) ($revision['snapshot'] ?? ''), true);
        foreach (is_array($snapshot['targets'] ?? null) ? $snapshot['targets'] : [] as $target) {
            if (is_array($target) && ($target['table'] ?? '') === 'contents') {
                return trim((string) ($target['fields']['blocks_data'] ?? ''));
            }
        }
        return '';
    }

    /**
     * 恢复历史版本。历史属于同一页面不代表可重新开启任意旧专业配置：
     * 以当前服务端文档为基线按能力检查（纯 HTML 版本视为空文档，不能借此静默删除受保护内容），
     * 写回与草稿同步在同一把栏目锁内完成。
     *
     * @param array<string,mixed> $revision 已由调用方校验归属的版本行
     */
    public static function restoreRevision(int $pageId, array $revision, int $adminId = 0, string $adminName = ''): int
    {
        self::assertStorageAvailable();
        $state = self::load($pageId);
        $blocks = self::revisionBlocks($revision);
        BloxDocumentPipeline::process($blocks !== '' ? $blocks : '[]', 'page', trustedJson: $state['document_json']);

        return BloxDocumentWriteLock::channel($pageId, $state, static fn(): array => self::load($pageId), static function () use ($pageId, $revision, $adminId, $adminName): int {
            $restored = contentRevisionModel()->restoreRevision((int) $revision['id'], $adminId, $adminName);
            self::syncDraftFromPublished($pageId, $adminId);
            return $restored;
        });
    }

    /**
     * 版本快照里可编辑的 HTML 正文（无 blocks_data 的旧富文本版本）。
     * 与预览端点同口径：取第一个非空 content 字段；content_type=blocks 的行不算 HTML 来源。
     *
     * @param array<string,mixed> $revision
     */
    public static function revisionHtml(array $revision): string
    {
        $snapshot = json_decode((string) ($revision['snapshot'] ?? ''), true);
        foreach (is_array($snapshot['targets'] ?? null) ? $snapshot['targets'] : [] as $target) {
            if (!is_array($target)) {
                continue;
            }
            if ((string) (($target['fields'] ?? [])['content_type'] ?? '') === 'blocks') {
                continue;
            }
            $html = trim((string) (($target['fields'] ?? [])['content'] ?? ''));
            if ($html !== '') {
                return $html;
            }
        }
        return '';
    }

    /**
     * 把历史版本转换成可载入画布的文档（纯读取，不写任何表）。
     *
     * - 有 blocks_data：以当前服务端文档为基线走既有归一化与能力校验，
     *   历史里的旧专业配置不能借"载入"绕过校验；
     * - 纯 HTML：用与旧页读取一致的包装转换成单个可编辑元素；
     * - 两者皆空：抛出，调用方提示该版本无法载入画布。
     *
     * @param array<string,mixed> $revision 已由调用方校验归属的版本行
     * @param array{document_json:string, ...} $state 当前 PageBloxDocument::load() 结果
     */
    public static function revisionEditableDocument(array $revision, array $state): string
    {
        $blocks = self::revisionBlocks($revision);
        if ($blocks === '') {
            $html = self::revisionHtml($revision);
            if ($html === '') {
                throw new RuntimeException(__('blox_revision_not_loadable'));
            }
            $blocks = self::legacyHtmlDocumentJson($html);
        }
        $processed = BloxDocumentPipeline::process($blocks, 'page', trustedJson: $state['document_json']);
        return $processed['json'];
    }

    public static function syncDraftFromPublished(int $pageId, int $adminId = 0): void
    {
        if (!db()->tableExists('blox_page_drafts')) {
            return;
        }
        $page = self::page($pageId);
        $published = self::publishedRecord($pageId);
        bloxPageDraftModel()->saveForPage(
            $pageId,
            self::publishedDocumentJson($published, $page),
            $adminId
        );
    }

    /** @return array<string,mixed> */
    private static function page(int $pageId): array
    {
        $page = $pageId > 0 ? channelModel()->find($pageId) : null;
        $type = (string) ($page['type'] ?? '');
        if (!$page || !in_array($type, ['page', 'product'], true)
            || ($type === 'product' && (int) ($page['parent_id'] ?? 0) !== 0)) {
            throw new RuntimeException(__('blox_page_not_found'));
        }
        return $page;
    }

    /** @return array<string,mixed>|null */
    private static function publishedRecord(int $pageId): ?array
    {
        // Editing language can differ from the site's current front-end language.
        $page = self::page($pageId);
        return contentModel()->getFirstByChannel($pageId, (string) $page['lang']);
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
    private static function publishedDocumentJson(?array $published, array $page): string
    {
        $blocksData = trim((string) ($published['blocks_data'] ?? ''));
        if ($blocksData !== '') {
            return self::canonicalJson($blocksData);
        }

        if ((string) ($page['type'] ?? '') === 'product') {
            return self::productDocumentJson($page);
        }

        if ((string) ($published['content_type'] ?? 'html') === 'blocks') {
            return self::canonicalJson('[]');
        }

        $html = trim((string) ($published['content'] ?? ''));
        if ($html === '') {
            // 老站单页正文常存于 channels.content 且没有镜像的 contents 行——
            // 没有这个兜底时前台有内容、编辑器却开出空画布（longcool.cn 实例）。
            $html = trim((string) ($page['content'] ?? ''));
        }
        if ($html === '') {
            return self::canonicalJson('[]');
        }

        return self::legacyHtmlDocumentJson($html);
    }

    /** 旧富文本 HTML 包装成单个可编辑元素的 Blox 文档（读取用包装，不写库）。 */
    private static function legacyHtmlDocumentJson(string $html): string
    {
        $elements = [];
        $organization = OrgChartElement::extractLegacyHtml($html);
        if ($organization !== null) {
            $elements[] = [
                'id' => 'e_legacy_org',
                'type' => 'org-chart',
                'data' => [
                    'label' => __('blox_el_org_chart'),
                    'nodes' => $organization['nodes'],
                    'style' => $organization['style'],
                    'layout' => 'top',
                    'compact' => false,
                    'initial_depth' => 4,
                ],
            ];
            if ($organization['remaining_html'] !== '') {
                $elements[] = [
                    'id' => 'e_legacy_text',
                    'type' => 'text',
                    'data' => ['html' => $organization['remaining_html']],
                ];
            }
        } else {
            $elements[] = [
                'id' => 'e_legacy',
                'type' => 'text',
                'data' => ['html' => $html],
            ];
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
                'elements' => $elements,
            ]],
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array<string,mixed> $page */
    private static function productDocumentJson(array $page): string
    {
        $introElements = [[
            'id' => 'e_product_title',
            'type' => 'heading',
            'data' => [
                'text' => (string) ($page['name'] ?? __('admin_product')),
                'level' => 'h1',
                'align' => 'center',
            ],
        ]];
        $description = trim((string) ($page['description'] ?? ''));
        if ($description !== '') {
            $introElements[] = [
                'id' => 'e_product_intro',
                'type' => 'text',
                'data' => ['html' => '<p>' . e($description) . '</p>'],
            ];
        }

        return self::canonicalJson(json_encode([
            [
                'id' => 's_product_intro',
                'settings' => [
                    'bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'narrow',
                    'align_items' => 'center', 'justify_items' => 'center', 'gap' => 'md',
                ],
                'columns' => [[
                    'id' => 'c_product_intro',
                    'elements' => $introElements,
                ]],
            ],
            [
                'id' => 's_product_catalog',
                'settings' => [
                    'bg_color' => '', 'bg_image' => '', 'padding' => 'lg', 'max_width' => 'wide',
                    'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'lg',
                ],
                'columns' => [[
                    'id' => 'c_product_catalog',
                    'elements' => [[
                        'id' => 'e_product_catalog',
                        'type' => 'product-catalog',
                        'data' => [
                            'layout' => 'inherit', 'columns' => '4',
                            'show_search' => true, 'show_categories' => true, 'show_sort' => true,
                        ],
                    ]],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function assertRevision(string $currentJson, string $baseRevision): void
    {
        BloxDocumentWriteLock::assertRevision($currentJson, $baseRevision);
    }

    private static function assertStorageAvailable(): void
    {
        if (!db()->tableExists('blox_page_drafts')) {
            throw new RuntimeException(__('blox_page_draft_storage_missing'));
        }
    }
}
