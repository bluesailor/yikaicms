<?php
declare(strict_types=1);

class UserModel extends Model
{
    protected string $table = 'users';
    protected string $defaultOrder = 'id ASC';

    /**
     * 按用户名查找（含状态过滤）
     */
    public function findByUsername(string $username): ?array
    {
        return $this->findWhere(['username' => $username, 'status' => 1]);
    }

    /**
     * 检查用户名是否唯一
     */
    public function isUsernameUnique(string $username, int $excludeId = 0): bool
    {
        $sql = "SELECT id FROM {$this->tableName()} WHERE username = ?";
        $params = [$username];
        if ($excludeId > 0) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }
        return db()->fetchOne($sql, $params) === null;
    }

    /**
     * 更新最后登录信息
     */
    public function updateLastLogin(int $id, string $ip): void
    {
        $user = $this->find($id);
        if ($user) {
            db()->update($this->table, [
                'last_login_time' => time(),
                'last_login_ip'   => $ip,
                'login_count'     => $user['login_count'] + 1,
            ], 'id = ?', [$id]);
        }
    }

    /**
     * 修改密码
     */
    public function setPassword(int $id, string $password): int
    {
        return $this->updateById($id, [
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'updated_at' => time(),
        ]);
    }
}
