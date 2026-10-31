<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAccessAuthContext.php';

/**
 * AI 外部访问令牌仓储（规划 §6.2）。秘密 32 字节随机，只存 SHA-256 摘要；明文只在签发时返回一次。
 * owner 一律由调用方传入的服务端身份决定；本类不读 session、不做后台鉴权（页面负责 session+CSRF+权限）。
 */
final class AiAccessTokenStore
{
    public const PREFIX = 'ykat_';
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 90;
    public const MAX_ACTIVE_PER_USER = 10;
    /** 可签发的范围：文章六项 + 单页只读 page_read（含 page_list 与 page_read 两个工具）+ 单页内容提案 page_content_propose；提案只进审核队列，应用/撤销只在后台。 */
    public const ISSUABLE_SCOPES = ['site_info', 'article_channel_list', 'article_list', 'article_read', 'article_draft_propose', 'proposal_status', 'page_read', 'page_content_propose'];
    /** 新建令牌表单默认勾选的范围：写入类（提交提案）与新开放的单页读取要用户主动勾选。 */
    public const DEFAULT_SCOPES = ['site_info', 'article_channel_list', 'article_list', 'article_read'];
    /** 最近使用时间的写入间隔：每个请求都写会把只读调用变成持续写库。 */
    private const TOUCH_INTERVAL = 60;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param list<mixed> $scopes
     * @return array{id:int,secret:string,prefix:string,expires_at:int}
     */
    public function issue(int $adminId, string $name, array $scopes, int $days, int $now): array
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');
        if ($adminId < 1 || $name === '' || mb_strlen($name) > 100 || $days < 1 || $days > self::MAX_DAYS) {
            throw new RuntimeException('ai_token_invalid_input', 400);
        }
        $scopes = self::normalizeScopes($scopes);
        $secret = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $prefix = substr($secret, 0, 12);
        $expires = $now + $days * 86400;

        // 名额检查与写入必须串行，否则并发签发会各自数到 9 再各插一枚。MySQL 先锁本账号的用户行；
        // SQLite 先写后数——写入即取得库级写锁，并发的另一笔在 busy_timeout 内排队，数到的一定含对方。
        if ($this->db->getPdo()->inTransaction()) throw new RuntimeException('ai_token_invalid_input', 400);
        $this->db->beginTransaction();
        try {
            if (!$this->db->isSqlite()) $this->db->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'users WHERE id = ? FOR UPDATE', [$adminId]);
            $id = (int) $this->db->insert('api_tokens', [
                'admin_id' => $adminId, 'name' => $name, 'token_prefix' => $prefix, 'token_hash' => hash('sha256', $secret),
                'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR), 'created_at' => $now, 'expires_at' => $expires,
                'revoked_at' => 0, 'revoked_by' => 0, 'last_used_at' => 0, 'last_used_ip' => '',
            ]);
            $active = (int) $this->db->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'api_tokens WHERE admin_id = ? AND revoked_at = 0 AND expires_at > ?', [$adminId, $now]);
            if ($active > self::MAX_ACTIVE_PER_USER) throw new RuntimeException('ai_token_limit_reached', 409);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
        return ['id' => $id, 'secret' => $secret, 'prefix' => $prefix, 'expires_at' => $expires];
    }

    /**
     * 列表不返回摘要。$adminId 为 null 时列出全部（仅供超管撤销他人令牌的视图）。
     * @return list<array<string,mixed>>
     */
    public function listTokens(?int $adminId): array
    {
        $sql = 'SELECT t.id, t.admin_id, u.username, t.name, t.token_prefix, t.scopes, t.created_at, t.expires_at, t.revoked_at, t.last_used_at, t.last_used_ip'
            . ' FROM ' . DB_PREFIX . 'api_tokens t LEFT JOIN ' . DB_PREFIX . 'users u ON u.id = t.admin_id';
        $rows = $adminId === null
            ? $this->db->fetchAll($sql . ' ORDER BY t.id DESC')
            : $this->db->fetchAll($sql . ' WHERE t.admin_id = ? ORDER BY t.id DESC', [$adminId]);
        return array_map(static function (array $row): array {
            $scopes = json_decode((string) $row['scopes'], true);
            $row['scopes'] = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
            return $row;
        }, $rows);
    }

    /** 本人可撤销自己的令牌；超管可撤销任何人的。已撤销的再撤销视为成功（幂等）。 */
    public function revoke(int $tokenId, int $actorId, bool $actorIsSuper, int $now): bool
    {
        $row = $this->db->fetchOne('SELECT id, admin_id, revoked_at FROM ' . DB_PREFIX . 'api_tokens WHERE id = ?', [$tokenId]);
        if ($row === null || (!$actorIsSuper && (int) $row['admin_id'] !== $actorId)) return false;
        if ((int) $row['revoked_at'] !== 0) return true;
        $this->db->execute('UPDATE ' . DB_PREFIX . 'api_tokens SET revoked_at = ?, revoked_by = ? WHERE id = ? AND revoked_at = 0', [$now, $actorId, $tokenId]);
        return true;
    }

    /** 账号停用/删除时撤销其全部令牌：重新启用账号不会让旧令牌复活。 */
    public function revokeAllFor(int $adminId, int $actorId, int $now): int
    {
        return $this->db->execute('UPDATE ' . DB_PREFIX . 'api_tokens SET revoked_at = ?, revoked_by = ? WHERE admin_id = ? AND revoked_at = 0', [$now, $actorId, $adminId]);
    }

    /** 供用户管理页调用：未升级（无令牌表）的站点直接返回 0。 */
    public static function revokeAccount(Database $db, int $adminId, int $actorId): int
    {
        if ($adminId < 1 || !$db->tableExists('api_tokens')) return 0;
        return (new self($db))->revokeAllFor($adminId, $actorId, time());
    }

    /** 供 AiAccessIdentity 的 lookup：入参是摘要，不接收明文。 */
    public function findByDigest(string $digest): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/D', $digest) !== 1) return null;
        return $this->db->fetchOne('SELECT id, admin_id, token_hash, scopes, expires_at, revoked_at FROM ' . DB_PREFIX . 'api_tokens WHERE token_hash = ?', [$digest]);
    }

    /** 记录最近使用，间隔内不重复写。 */
    public function touch(int $tokenId, string $ip, int $now): void
    {
        $this->db->execute('UPDATE ' . DB_PREFIX . 'api_tokens SET last_used_at = ?, last_used_ip = ? WHERE id = ? AND last_used_at < ?',
            [$now, substr($ip, 0, 45), $tokenId, $now - self::TOUCH_INTERVAL]);
    }

    /**
     * 不接受通配符或未知名称；去重后按工具目录的固定顺序保存。
     * @param list<mixed> $scopes
     * @return list<string>
     */
    public static function normalizeScopes(array $scopes): array
    {
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !in_array($scope, self::ISSUABLE_SCOPES, true)) throw new RuntimeException('ai_token_invalid_scope', 400);
        }
        $out = array_values(array_filter(AiAccessAuthContext::TOOLS, static fn(string $tool): bool => in_array($tool, $scopes, true)));
        if ($out === []) throw new RuntimeException('ai_token_invalid_scope', 400);
        return $out;
    }
}
