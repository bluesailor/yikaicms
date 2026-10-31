<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAccessAuthContext.php';
require_once __DIR__ . '/AiArticleDraftRules.php';
require_once __DIR__ . '/AiProposalService.php';

/**
 * v2.2.0 拟定的六个外部工具（规划 §7.3）的原型实现。只读查询加一个只写提案表的工具；
 * 不调用 Abilities 注册表、模型或站点 AI 供应商，也不经过 AiService 的模型循环。
 * 工具清单按调用身份过滤（scope × 角色权限），顺序固定。
 */
final class AiMcpTools
{
    public const LIST_DEFAULT = 20;
    public const LIST_MAX = 50;
    public const CONTENT_CHUNK_BYTES = 128000;
    public const MAX_RESULT_BYTES = 256000;

    public function __construct(private Database $db, private AiProposalService $proposals, private Closure $clock)
    {
    }

    /** @return list<array<string,mixed>> */
    public function definitions(AiAccessAuthContext $context): array
    {
        $out = [];
        foreach (AiAccessAuthContext::TOOLS as $name) {
            if ($context->allowsTool($name)) $out[] = ['name' => $name] + self::schema($name);
        }
        return $out;
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    public function call(string $name, array $args, AiAccessAuthContext $context): array
    {
        $allowed = array_keys((array) (self::schema($name)['inputSchema']['properties'] ?? []));
        if (array_diff(array_keys($args), $allowed) !== []) throw new RuntimeException('ai_mcp_invalid_arguments', 400);
        return match ($name) {
            'site_info' => $this->siteInfo($context),
            'article_channel_list' => $this->channels($args),
            'article_list' => $this->articles($args),
            'article_read' => $this->article($args),
            'article_draft_propose' => $this->propose($args, $context),
            'proposal_status' => $this->status($args, $context),
            default => throw new RuntimeException('ai_mcp_invalid_arguments', 400),
        };
    }

    private function siteInfo(AiAccessAuthContext $context): array
    {
        $settings = $this->settings(['site_name', 'site_lang']);
        $tools = array_values(array_filter(AiAccessAuthContext::TOOLS, $context->allowsTool(...)));
        return ['site_name' => (string) ($settings['site_name'] ?? ''), 'default_language' => (string) ($settings['site_lang'] ?? ''),
            'languages' => $this->languages(), 'tools' => $tools,
            'limits' => ['list_max' => self::LIST_MAX, 'content_chunk_bytes' => self::CONTENT_CHUNK_BYTES, 'proposal_max_items' => AiArticleDraftRules::MAX_ITEMS],
            'proposal_policy' => 'Proposals are saved for review only; an editor applies them as unpublished drafts.'];
    }

    private function channels(array $args): array
    {
        $lang = $this->langArg($args['lang'] ?? null);
        $rows = $this->db->fetchAll('SELECT id, lang, parent_id, name, slug FROM ' . DB_PREFIX . "channels WHERE type = 'list' AND status = 1 AND lang = ? ORDER BY sort_order, id", [$lang]);
        return ['lang' => $lang, 'channels' => array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'parent_id' => (int) $r['parent_id'],
            'name' => (string) $r['name'], 'slug' => (string) $r['slug']], $rows)];
    }

    private function articles(array $args): array
    {
        $lang = $this->langArg($args['lang'] ?? null);
        $limit = $args['limit'] ?? self::LIST_DEFAULT;
        if (!is_int($limit) || $limit < 1 || $limit > self::LIST_MAX) throw new RuntimeException('ai_mcp_invalid_arguments', 400);
        $where = "type = 'article' AND deleted_at IS NULL AND lang = ?";
        $params = [$lang];
        if (array_key_exists('channel_id', $args)) {
            if (!is_int($args['channel_id']) || $args['channel_id'] < 1) throw new RuntimeException('ai_mcp_invalid_arguments', 400);
            $where .= ' AND channel_id = ?';
            $params[] = $args['channel_id'];
        }
        $status = $args['status'] ?? 'any';
        if (!in_array($status, ['any', 'draft', 'published'], true)) throw new RuntimeException('ai_mcp_invalid_arguments', 400);
        if ($status !== 'any') $where .= $status === 'draft' ? ' AND status = 0' : ' AND status = 1';
        if (array_key_exists('cursor', $args)) {
            // 游标是上一页最后一条的 id（不透明字符串），按 id 倒序做键集分页，翻页期间新增记录不会造成重复。
            if (!is_string($args['cursor']) || preg_match('/^a([1-9][0-9]{0,9})$/D', $args['cursor'], $m) !== 1) throw new RuntimeException('ai_mcp_invalid_arguments', 400);
            $where .= ' AND id < ?';
            $params[] = (int) $m[1];
        }
        $rows = $this->db->fetchAll('SELECT id, lang, channel_id, title, status, publish_time, updated_at FROM ' . DB_PREFIX . "contents WHERE {$where} ORDER BY id DESC LIMIT " . ($limit + 1), $params);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = array_map(static fn(array $r): array => ['id' => (int) $r['id'], 'lang' => (string) $r['lang'], 'channel_id' => (int) $r['channel_id'],
            'title' => (string) $r['title'], 'status' => (int) $r['status'] === 1 ? 'published' : 'draft', 'updated_at' => (int) $r['updated_at']], $rows);
        $out = ['lang' => $lang, 'items' => $items];
        if ($more && $items !== []) $out['next_cursor'] = 'a' . $items[count($items) - 1]['id'];
        return $out;
    }

    private function article(array $args): array
    {
        $id = $args['id'] ?? null;
        $offset = $args['content_offset'] ?? 0;
        if (!is_int($id) || $id < 1 || !is_int($offset) || $offset < 0) throw new RuntimeException('ai_mcp_invalid_arguments', 400);
        $row = $this->db->fetchOne('SELECT id, lang, channel_id, title, subtitle, summary, content, tags, status, updated_at FROM ' . DB_PREFIX
            . "contents WHERE id = ? AND type = 'article' AND deleted_at IS NULL", [$id]);
        if ($row === null || !in_array((string) $row['lang'], $this->languages(), true)) throw new RuntimeException('ai_mcp_not_found', 404);
        $content = (string) ($row['content'] ?? '');
        if ($offset > strlen($content)) throw new RuntimeException('ai_mcp_invalid_arguments', 400);
        $base = ['id' => (int) $row['id'], 'lang' => (string) $row['lang'], 'channel_id' => (int) $row['channel_id'], 'title' => (string) $row['title'],
            'subtitle' => (string) $row['subtitle'], 'summary' => (string) ($row['summary'] ?? ''), 'tags' => (string) $row['tags'],
            'status' => (int) $row['status'] === 1 ? 'published' : 'draft', 'updated_at' => (int) $row['updated_at'],
            'content_offset' => $offset, 'content_bytes' => strlen($content)];
        // 结果同时以 structuredContent 和 JSON 文本各出现一次，转义还会膨胀；按实际编码大小缩小分段，
        // 保证整条响应不超过上限，而不是在协议层报错或截断。
        $max = self::CONTENT_CHUNK_BYTES;
        do {
            $chunk = self::utf8Chunk($content, $offset, $max);
            $out = $base + ['content' => $chunk];
            if ($offset + strlen($chunk) < strlen($content)) $out['content_next_offset'] = $offset + strlen($chunk);
            $max = intdiv($max, 2);
        } while (self::encodedSize($out) > self::MAX_RESULT_BYTES - 4096 && $max >= 1024);
        return $out;
    }

    private function propose(array $args, AiAccessAuthContext $context): array
    {
        $key = $args['idempotency_key'] ?? null;
        $items = $args['items'] ?? null;
        if (!is_string($key) || !is_array($items)) throw new RuntimeException('ai_mcp_invalid_arguments', 400);
        $result = $this->proposals->proposeFromToken($context, $key, $items, ($this->clock)());
        return $result + ['next_step' => 'An editor reviews this proposal in the admin; nothing is saved to articles until then. Use proposal_status to follow it.'];
    }

    private function status(array $args, AiAccessAuthContext $context): array
    {
        $id = $args['proposal_id'] ?? null;
        if (!is_string($id) || preg_match('/^[0-9a-f]{32}$/D', $id) !== 1) throw new RuntimeException('ai_mcp_invalid_arguments', 400);
        // 只查本人提案：他人的与不存在的结果相同。
        $set = $this->db->fetchOne('SELECT id, created_at, expires_at FROM ' . DB_PREFIX . 'ai_proposals WHERE public_id = ? AND owner_id = ?', [$id, $context->actorId]);
        if ($set === null) throw new RuntimeException('ai_mcp_not_found', 404);
        $rows = $this->db->fetchAll('SELECT public_id, operation, target_id, status, status_reason, result_content_id FROM ' . DB_PREFIX . 'ai_proposal_items WHERE proposal_id = ? ORDER BY seq', [(int) $set['id']]);
        return ['proposal_id' => $id, 'created_at' => (int) $set['created_at'], 'expires_at' => (int) $set['expires_at'],
            'items' => array_map(static fn(array $r): array => ['item_id' => (string) $r['public_id'], 'op' => (string) $r['operation'],
                'target_id' => (int) $r['target_id'], 'status' => (string) $r['status'], 'reason' => (string) $r['status_reason'],
                'draft_id' => (int) $r['result_content_id']], $rows)];
    }

    private function langArg(mixed $lang): string
    {
        if (!is_string($lang) || !in_array($lang, $this->languages(), true)) throw new RuntimeException('ai_mcp_language_unavailable', 400);
        return $lang;
    }

    /** @return list<string> 原型语言政策与 AiArticleDraftRules 一致；正式实现改用 LanguageRegistry。 */
    private function languages(): array
    {
        $settings = $this->settings(['enabled_languages', 'site_lang']);
        $list = json_decode((string) ($settings['enabled_languages'] ?? '[]'), true);
        $langs = is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
        if (isset($settings['site_lang'])) array_unshift($langs, (string) $settings['site_lang']);
        return array_values(array_unique($langs));
    }

    /** @param list<string> $keys @return array<string,string> */
    private function settings(array $keys): array
    {
        $rows = $this->db->fetchAll('SELECT `key`, `value` FROM ' . DB_PREFIX . 'settings WHERE `key` IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys);
        $out = [];
        foreach ($rows as $row) $out[(string) $row['key']] = (string) $row['value'];
        return $out;
    }

    /** 工具结果编码后的字节数：structuredContent 一份 + 文本内容（JSON 字符串里的 JSON）一份。 */
    private static function encodedSize(array $data): int
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return strlen($json) + strlen(json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** 按字节分段且不切断 UTF-8 多字节字符。 */
    private static function utf8Chunk(string $text, int $offset, int $max): string
    {
        $chunk = substr($text, $offset, $max);
        if ($offset + strlen($chunk) < strlen($text)) {
            while ($chunk !== '' && (ord($chunk[strlen($chunk) - 1]) & 0xC0) === 0x80) $chunk = substr($chunk, 0, -1);
            if ($chunk !== '' && ord($chunk[strlen($chunk) - 1]) >= 0xC0) $chunk = substr($chunk, 0, -1);
        }
        return $chunk;
    }

    /** @return array<string,mixed> */
    private static function schema(string $name): array
    {
        $lang = ['type' => 'string', 'description' => 'Language code, one of site_info.languages'];
        $none = ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false];
        $item = ['type' => 'object', 'additionalProperties' => false, 'required' => ['op', 'lang'], 'properties' => [
            'op' => ['type' => 'string', 'enum' => ['create', 'update']],
            'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'update only: an existing unpublished article'],
            'lang' => $lang,
            'channel_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'create only: from article_channel_list'],
            'title' => ['type' => 'string', 'maxLength' => 255], 'subtitle' => ['type' => 'string', 'maxLength' => 255],
            'summary' => ['type' => 'string', 'maxLength' => 2000], 'content' => ['type' => 'string', 'description' => 'HTML; sanitized on the server'],
            'tags' => ['type' => 'string', 'maxLength' => 255],
        ]];
        $readOnly = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        return match ($name) {
            'site_info' => ['title' => 'Site info', 'description' => 'Site name, enabled languages, the tools this token may use and request limits.',
                'inputSchema' => $none, 'annotations' => $readOnly],
            'article_channel_list' => ['title' => 'Article channels', 'description' => 'Enabled article list channels for one language.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['lang'], 'properties' => ['lang' => $lang]], 'annotations' => $readOnly],
            'article_list' => ['title' => 'List articles', 'description' => 'Article summaries in one language, newest first; excludes the recycle bin. Follow next_cursor for more.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['lang'], 'properties' => [
                    'lang' => $lang, 'channel_id' => ['type' => 'integer', 'minimum' => 1],
                    'status' => ['type' => 'string', 'enum' => ['any', 'draft', 'published']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::LIST_MAX], 'cursor' => ['type' => 'string']]], 'annotations' => $readOnly],
            'article_read' => ['title' => 'Read article', 'description' => 'One article. Content is returned in chunks of at most 128000 bytes (smaller when escaping would exceed the response limit); pass content_next_offset as content_offset to continue.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['id'], 'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1], 'content_offset' => ['type' => 'integer', 'minimum' => 0]]], 'annotations' => $readOnly],
            'article_draft_propose' => ['title' => 'Propose article drafts', 'description' => 'Submit new or changed article drafts for editor review. Nothing is published or saved to articles by this call. Reuse the same idempotency_key when retrying the same submission.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['idempotency_key', 'items'], 'properties' => [
                    'idempotency_key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9._:-]{8,128}$'],
                    'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => AiArticleDraftRules::MAX_ITEMS, 'items' => $item]]],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]],
            'proposal_status' => ['title' => 'Proposal status', 'description' => 'Review status of one of your own proposals.',
                'inputSchema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['proposal_id'], 'properties' => [
                    'proposal_id' => ['type' => 'string', 'pattern' => '^[0-9a-f]{32}$']]], 'annotations' => $readOnly],
            default => [],
        };
    }
}
