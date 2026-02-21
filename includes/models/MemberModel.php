<?php
declare(strict_types=1);

class MemberModel extends Model
{
    protected string $table = 'members';
    protected string $defaultOrder = 'id DESC';

    public function findByUsername(string $username): ?array
    {
        return $this->findBy('username', $username);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', $email);
    }

    public function isUsernameUnique(string $username, int $excludeId = 0): bool
    {
        $sql = "SELECT COUNT(*) FROM {$this->tableName()} WHERE username = ?";
        $params = [$username];
        if ($excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        return (int)db()->fetchColumn($sql, $params) === 0;
    }

    public function isEmailUnique(string $email, int $excludeId = 0): bool
    {
        if (empty($email)) return true;
        $sql = "SELECT COUNT(*) FROM {$this->tableName()} WHERE email = ?";
        $params = [$email];
        if ($excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        return (int)db()->fetchColumn($sql, $params) === 0;
    }
}
