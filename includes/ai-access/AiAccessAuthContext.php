<?php

declare(strict_types=1);

/** 仅由服务端身份解析器构造的请求快照；不接收客户端身份字段。 */
final readonly class AiAccessAuthContext
{
    public const TOOLS = ['site_info', 'article_channel_list', 'article_list', 'article_read', 'article_draft_propose', 'proposal_status', 'page_list', 'page_read', 'page_content_propose'];
    /** 工具名与令牌范围不同名的映射：单页只读的两个工具共用 page_read 一个范围 */
    private const TOOL_SCOPE = ['page_list' => 'page_read'];
    private const PAGE_TOOLS = ['page_list', 'page_read', 'page_content_propose'];

    /** @param list<string> $permissions @param list<string> $scopes */
    public function __construct(
        public int $actorId,
        public int $tokenId,
        private array $permissions,
        private array $scopes,
    ) {
    }

    public function allowsTool(string $tool): bool
    {
        if (!in_array($tool, self::TOOLS, true) || !in_array(self::TOOL_SCOPE[$tool] ?? $tool, $this->scopes, true) || !$this->hasPermission('ai_access')) return false;
        // 单页工具与后台单页编辑器同一道权限：blox_edit + edit_page（编辑器总开关由 AiBloxPageReader 再判）
        $pageEditor = $this->hasPermission('blox_edit') && $this->hasPermission('edit_page');
        if (in_array($tool, self::PAGE_TOOLS, true)) return $pageEditor;
        // proposal_status 只查本人提案：文章编辑或单页编辑任一身份都要能跟进自己提交的提案
        if ($tool === 'proposal_status') return $this->hasPermission('edit_article') || $pageEditor;
        return $tool === 'site_info' || $this->hasPermission('edit_article');
    }

    /** 账号权限（含超管通配）；工具白名单之外的服务端只读投影也用它判定 */
    public function hasPermission(string $permission): bool
    {
        return in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true);
    }
}
