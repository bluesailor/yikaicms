<?php

declare(strict_types=1);

/** 仅由服务端身份解析器构造的请求快照；不接收客户端身份字段。 */
final readonly class AiAccessAuthContext
{
    public const TOOLS = ['site_info', 'article_channel_list', 'article_list', 'article_read', 'article_draft_propose', 'proposal_status'];

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
        if (!in_array($tool, self::TOOLS, true) || !in_array($tool, $this->scopes, true) || !$this->hasPermission('ai_access')) return false;
        return $tool === 'site_info' || $this->hasPermission('edit_article');
    }

    private function hasPermission(string $permission): bool
    {
        return in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true);
    }
}
