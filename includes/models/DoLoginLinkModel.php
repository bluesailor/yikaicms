<?php
declare(strict_types=1);

final class DoLoginLinkModel extends Model
{
    protected string $table = 'dologin_links';

    public function ready(): bool
    {
        return db()->tableExists($this->table);
    }

    private function verifier(string $value): string
    {
        if (!defined('ENCRYPT_KEY') || strlen((string) ENCRYPT_KEY) < 16) {
            throw new RuntimeException('Easy Login requires a site encryption key.');
        }
        return hash_hmac('sha256', 'dologin-v1|' . $value, (string) ENCRYPT_KEY);
    }

    private function identity(array $user): string
    {
        // PDO 在 PHP 8.0 返回字符串、8.1 起返回整数；统一成整数，站点升级 PHP 后已发出的链接不会因类型变化失效
        return $this->verifier('identity|' . json_encode([
            (int) $user['id'], (string) $user['password'], (int) $user['role_id'], (string) ($user['totp_secret'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }

    private function activeUser(int $id): ?array
    {
        $user = userModel()->find($id);
        if (!$user || (int) $user['status'] !== 1) return null;
        $role = roleModel()->find((int) $user['role_id']);
        return $role && (int) ($role['status'] ?? 1) === 1 ? $user : null;
    }

    private function activeIssuer(int $id): bool
    {
        $user = $this->activeUser($id);
        if (!$user) return false;
        $role = roleModel()->find((int) $user['role_id']);
        $permissions = json_decode((string) ($role['permissions'] ?? '[]'), true);
        return is_array($permissions) && in_array('*', $permissions, true);
    }

    /** @return array{id:int, token:string} */
    public function issue(int $userId, int $issuerId, int $minutes, string $note): array
    {
        $user = $this->activeUser($userId);
        if (!$user || !$this->activeIssuer($issuerId) || !in_array($minutes, [15, 60, 1440, 10080], true)
            || mb_strlen($note) > 200) {
            throw new InvalidArgumentException('Invalid login link request.');
        }
        $token = bin2hex(random_bytes(32));
        $id = db()->insert($this->table, [
            'user_id' => $userId, 'created_by' => $issuerId,
            'token_hash' => $this->verifier('token|' . $token), 'identity_hash' => $this->identity($user),
            'note' => $note, 'created_at' => time(), 'expires_at' => time() + $minutes * 60,
        ]);
        return ['id' => (int) $id, 'token' => $token];
    }

    /** Consume before authentication; one conditional UPDATE arbitrates concurrent requests. */
    public function redeem(string $token, string $ip): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || !$this->ready()) return null;
        $hash = $this->verifier('token|' . $token);
        $link = $this->findWhere(['token_hash' => $hash]);
        if (!$link || (int) $link['used_at'] !== 0 || (int) $link['revoked_at'] !== 0
            || (int) $link['expires_at'] <= time() || !$this->activeIssuer((int) $link['created_by'])) return null;
        $user = $this->activeUser((int) $link['user_id']);
        if (!$user || !hash_equals((string) $link['identity_hash'], $this->identity($user))) return null;
        $used = db()->execute(
            'UPDATE ' . DB_PREFIX . 'dologin_links SET used_at = ?, used_ip = ?
             WHERE id = ? AND token_hash = ? AND used_at = 0 AND revoked_at = 0 AND expires_at > ?',
            [time(), filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '', $link['id'], $hash, time()]
        );
        return $used === 1 ? $user : null;
    }

    public function revoke(int $id): void
    {
        db()->execute('UPDATE ' . DB_PREFIX . 'dologin_links SET revoked_at = ? WHERE id = ? AND used_at = 0 AND revoked_at = 0', [time(), $id]);
    }

    public function recent(): array
    {
        return db()->fetchAll('SELECT l.id, l.note, l.created_at, l.expires_at, l.used_at, l.revoked_at, l.used_ip,
            u.username FROM ' . DB_PREFIX . 'dologin_links l LEFT JOIN ' . DB_PREFIX . 'users u ON u.id = l.user_id ORDER BY l.id DESC LIMIT 100');
    }
}
