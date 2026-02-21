<?php
declare(strict_types=1);

class RoleModel extends Model
{
    protected string $table = 'roles';
    protected string $defaultOrder = 'id ASC';

    /**
     * 获取所有有效角色
     */
    public function getActive(): array
    {
        return $this->where(['status' => 1]);
    }

    /**
     * 获取角色关联的用户数
     */
    public function getUserCount(int $roleId): int
    {
        return (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "users WHERE role_id = ?",
            [$roleId]
        );
    }
}
