<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAccessAuthContext.php';

/** 不包含后台 auth，不调用插件/AI，也不写登录状态。真实令牌仓储由 S2 接入。 */
final class AiAccessIdentity
{
    /** @param array<string,string> $overrides */
    public function __construct(private Database $database, private bool $configuredDemo = false, private array $overrides = [])
    {
    }

    /**
     * lookup 接收摘要，只返回服务端可信记录；这里没有查询串/cookie/会话回退。
     * @param callable(string):?array $lookup
     */
    public function authenticate(string $authorization, callable $lookup, int $now): AiAccessAuthContext
    {
        if (preg_match('/^Bearer ([A-Za-z0-9_-]{43,128})$/iD', $authorization, $match) !== 1) {
            throw new RuntimeException('ai_access_invalid_token', 401);
        }
        try {
            $policy = $this->policy();
            if (($policy['ai_access_enabled'] ?? '0') !== '1' || $this->configuredDemo || ($policy['demo_mode'] ?? '0') !== '0') {
                throw new RuntimeException('ai_access_disabled', 403);
            }
            $digest = hash('sha256', $match[1]);
            $token = $lookup($digest);
            if (!is_array($token) || !is_string($token['token_hash'] ?? null) || !hash_equals($digest, $token['token_hash'])
                || !array_key_exists('revoked_at', $token) || ($token['revoked_at'] !== null && $token['revoked_at'] !== 0 && $token['revoked_at'] !== '0')
                || !self::positiveId($token['id'] ?? null) || !self::positiveId($token['admin_id'] ?? null)
                || !self::positiveId($token['expires_at'] ?? null) || (int) $token['expires_at'] <= $now) {
                throw new RuntimeException('ai_access_invalid_token', 401);
            }
            $scopes = self::stringList($token['scopes'] ?? null);
            if ($scopes === null || array_diff($scopes, AiAccessAuthContext::TOOLS) !== []) {
                throw new RuntimeException('ai_access_invalid_token', 401);
            }
            $user = $this->database->fetchOne('SELECT id, username, role_id, status FROM ' . DB_PREFIX . 'users WHERE id = ?', [(int) $token['admin_id']]);
            if ($user === null || (int) $user['status'] !== 1) throw new RuntimeException('ai_access_identity_invalid', 401);
            if ((string) $user['username'] === 'yikai-support' || (int) $user['id'] === (int) ($policy['support_access_user_id'] ?? 0)) {
                throw new RuntimeException('ai_access_support_denied', 403);
            }
            $role = $this->database->fetchOne('SELECT permissions, status FROM ' . DB_PREFIX . 'roles WHERE id = ?', [(int) $user['role_id']]);
            $permissions = $role === null ? null : self::stringList($role['permissions'] ?? null);
            if ($role === null || (int) $role['status'] !== 1 || $permissions === null) throw new RuntimeException('ai_access_role_invalid', 403);
            if (!in_array('*', $permissions, true) && !in_array('ai_access', $permissions, true)) throw new RuntimeException('ai_access_permission_denied', 403);
            return new AiAccessAuthContext((int) $user['id'], (int) $token['id'], $permissions, $scopes);
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException && str_starts_with($error->getMessage(), 'ai_access_') && in_array($error->getCode(), [401, 403], true)) throw $error;
            throw new RuntimeException('ai_access_unavailable', 503);
        }
    }

    /** @return array<string,string> */
    private function policy(): array
    {
        $rows = $this->database->fetchAll('SELECT `key`, `value` FROM ' . DB_PREFIX . 'settings WHERE `key` IN (?, ?, ?)', ['ai_access_enabled', 'demo_mode', 'support_access_user_id']);
        $values = [];
        foreach ($rows as $row) $values[(string) $row['key']] = (string) $row['value'];
        return array_replace($values, $this->overrides);
    }

    private static function positiveId(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0;
    }

    /** @return list<string>|null */
    private static function stringList(mixed $json): ?array
    {
        if (!is_string($json) || strlen($json) > 8192) return null;
        try {
            $list = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($list) || !array_is_list($list)) return null;
        foreach ($list as $value) if (!is_string($value) || $value === '') return null;
        return array_values(array_unique($list));
    }
}
