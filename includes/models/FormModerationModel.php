<?php
declare(strict_types=1);

final class FormModerationModel extends Model
{
    protected string $table = 'forms';
    private const PREFIX = 'form_ip_block_';

    public static function normalizeIp(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return '';
        $packed = inet_pton($ip);
        if ($packed === false) return '';
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        return (string) inet_ntop($packed);
    }

    public function isBlocked(string $ip): bool
    {
        $ip = self::normalizeIp($ip);
        return $ip !== '' && (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [self::PREFIX . hash('sha256', $ip)]) > 0;
    }

    public function blockedIps(): array
    {
        $rows = db()->fetchAll('SELECT `key`, value FROM ' . DB_PREFIX . 'settings WHERE `key` LIKE ? ORDER BY id DESC', [self::PREFIX . '%']);
        $items = [];
        foreach ($rows as $row) {
            if (!str_starts_with($row['key'], self::PREFIX)) continue;
            $entry = json_decode($row['value'], true);
            if (is_array($entry) && self::normalizeIp((string) ($entry['ip'] ?? '')) !== '') $items[] = $entry;
        }
        return $items;
    }

    private function targetIp(int $id): string
    {
        $row = $this->find($id);
        if (!$row) throw new RuntimeException('form_ip_missing');
        $ip = self::normalizeIp((string) ($row['ip'] ?? ''));
        if ($ip === '') throw new RuntimeException('form_ip_invalid');
        return $ip;
    }

    private function matchingIds(string $ip): array
    {
        // Legacy records can contain expanded IPv6 or IPv4-mapped IPv6 addresses.
        $variants = [$ip];
        foreach (db()->fetchAll('SELECT DISTINCT ip FROM ' . DB_PREFIX . 'forms WHERE ip LIKE ?', ['%:%']) as $row) {
            if (self::normalizeIp((string) $row['ip']) === $ip) $variants[] = $row['ip'];
        }
        $variants = array_values(array_unique($variants));
        $rows = db()->fetchAll('SELECT id FROM ' . DB_PREFIX . 'forms WHERE ip IN (' . implode(',', array_fill(0, count($variants), '?')) . ') ORDER BY id', $variants);
        return array_map(static fn(array $row): int => (int) $row['id'], $rows);
    }

    public function inspect(int $id): array
    {
        $ip = $this->targetIp($id);
        return ['ip' => $ip, 'count' => count($this->matchingIds($ip)), 'blocked' => $this->isBlocked($ip)];
    }

    public function blockFromForm(int $id, bool $deleteMessages, int $expectedCount, int $adminId): array
    {
        $ip = $this->targetIp($id);
        db()->beginTransaction();
        try {
            $ids = $this->matchingIds($ip);
            if ($deleteMessages && count($ids) !== $expectedCount) throw new RuntimeException('form_ip_changed');
            $key = self::PREFIX . hash('sha256', $ip);
            $value = json_encode(['ip' => $ip, 'admin_id' => $adminId, 'created_at' => time()], JSON_THROW_ON_ERROR);
            // One row per IP avoids lost updates between concurrent administrators.
            $sql = 'INSERT INTO ' . DB_PREFIX . 'settings (`key`, value, `group`, name, type) VALUES (?, ?, ?, ?, ?)';
            $sql .= db()->isSqlite() ? ' ON CONFLICT(`key`) DO UPDATE SET value = excluded.value' : ' ON DUPLICATE KEY UPDATE value = VALUES(value)';
            db()->execute($sql, [$key, $value, 'form_moderation', 'Form IP block', 'text']);
            $deleted = 0;
            if ($deleteMessages) {
                // Delete only the checked snapshot; later arrivals are never swept into it.
                foreach (array_chunk($ids, 200) as $chunk) $deleted += $this->deleteByIds($chunk);
            }
            db()->commit();
            return ['ip' => $ip, 'deleted' => $deleted];
        } catch (Throwable $error) {
            db()->rollback();
            throw $error;
        }
    }

    public function unblock(string $ip): void
    {
        $ip = self::normalizeIp($ip);
        if ($ip === '') throw new RuntimeException('form_ip_invalid');
        db()->delete('settings', '`key` = ?', [self::PREFIX . hash('sha256', $ip)]);
    }
}
