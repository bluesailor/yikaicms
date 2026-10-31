<?php

declare(strict_types=1);

/**
 * MCP 运行时的单页文档只读来源。外部访问入口刻意不加载函数库与模型（见 AiAccessRuntime），
 * 所以这里直接查三张表；文档解码与指纹复用 BloxDocumentPipeline，它依赖的构建器类在读文档时
 * 才加载 builder/bootstrap.php（只定义类，不碰 session/权限函数，实测约 60 ms）。
 * Blox 文档页的 base_revision 必须与 PageBloxDocument::load() 一致（AiPageDocumentSourceTest 守住）。
 *
 * 纯 HTML 老页不做构建器式包装（那段转换属于编辑器），只返回 HTML 原文；
 * 它的 revision 带 html- 前缀，只用于翻页一致性，不能作为后续修改的基线。
 */
final class AiPageDocumentSource
{
    /**
     * $locking：在写事务里（提案应用/撤销）用锁定读。MySQL 可重复读下，事务里第一次普通读就固定了快照，
     * 之后等到栏目锁也只会读到旧草稿——会把并发提交的编辑器保存覆盖掉；锁定读总是读最新已提交的行。
     * SQLite 是库级写锁，没有这个问题，照常普通读。
     */
    public function __construct(private Database $db, private bool $locking = false)
    {
    }

    private function forUpdate(): string
    {
        return $this->locking && !$this->db->isSqlite() ? ' FOR UPDATE' : '';
    }

    /** @return array<string,mixed>|null 普通单页（type = page）的栏目行；其它类型与不存在一样返回 null */
    public function page(int $pageId): ?array
    {
        if ($pageId < 1) return null;
        $row = $this->db->fetchOne('SELECT id, type, lang, name, status, content FROM ' . DB_PREFIX . 'channels WHERE id = ?' . $this->forUpdate(), [$pageId]);
        return $row !== null && (string) ($row['type'] ?? '') === 'page' ? $row : null;
    }

    /**
     * @param array<string,mixed> $page page() 返回的行
     * @return array{document_json:string,legacy_html:?string,base_revision:string,has_draft:bool,has_published:bool,has_unpublished_changes:bool}
     */
    public function load(array $page): array
    {
        require_once dirname(__DIR__) . '/builder/bootstrap.php';
        $pageId = (int) $page['id'];
        $published = $this->db->fetchOne('SELECT content, content_type, blocks_data FROM ' . DB_PREFIX
            . 'contents WHERE channel_id = ? AND status = 1 AND deleted_at IS NULL AND lang = ? ORDER BY is_top DESC, publish_time DESC, id DESC LIMIT 1' . $this->forUpdate(),
            [$pageId, (string) $page['lang']]);
        $draft = $this->db->tableExists('blox_page_drafts')
            ? $this->db->fetchOne('SELECT draft_data FROM ' . DB_PREFIX . 'blox_page_drafts WHERE page_id = ?' . $this->forUpdate(), [$pageId])
            : null;
        $draftJson = trim((string) ($draft['draft_data'] ?? ''));
        $blocksData = trim((string) ($published['blocks_data'] ?? ''));
        $blocksType = (string) ($published['content_type'] ?? 'html') === 'blocks';
        $legacyHtml = trim((string) ($published['content'] ?? ''));
        if ($legacyHtml === '') $legacyHtml = trim((string) ($page['content'] ?? ''));

        $state = ['has_draft' => $draftJson !== '', 'has_published' => $blocksData !== ''];
        if ($draftJson === '' && $blocksData === '' && !$blocksType && $legacyHtml !== '') {
            return $state + ['document_json' => '', 'legacy_html' => $legacyHtml,
                'base_revision' => 'html-' . hash('sha256', $legacyHtml), 'has_unpublished_changes' => false];
        }
        $state['legacy_html'] = null;

        $publishedJson = self::canonical($blocksData !== '' ? $blocksData : '[]');
        if ($draftJson !== '') {
            $documentJson = self::canonical($draftJson);
        } else {
            $documentJson = $publishedJson;
            // 与编辑器一致：从未保存过的空白单页标记隐藏主题标题，这会进入指纹
            if ($blocksData === '' && !$blocksType) {
                $document = BloxDocumentPipeline::decode($documentJson);
                $document['settings']['page_title_hidden'] = true;
                $documentJson = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }
        return $state + [
            'document_json' => $documentJson,
            'base_revision' => BloxDocumentPipeline::fingerprint($documentJson),
            'has_unpublished_changes' => $draftJson !== '' && ($blocksData === ''
                || !hash_equals(BloxDocumentPipeline::fingerprint($publishedJson), BloxDocumentPipeline::fingerprint($documentJson))),
        ];
    }

    private static function canonical(string $raw): string
    {
        $document = BloxDocumentPipeline::decode($raw);
        return json_encode(['schema' => $document['schema'], 'settings' => $document['settings'], 'sections' => $document['sections']],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
