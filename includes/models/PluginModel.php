<?php
declare(strict_types=1);

class PluginModel extends Model
{
    protected string $table = 'plugins';
    protected string $defaultOrder = 'id ASC';

    /**
     * 按 slug 查找
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('slug', $slug);
    }

    /**
     * 获取所有已激活插件的 slug 列表
     */
    public function getActiveSlugs(): array
    {
        $rows = db()->fetchAll(
            "SELECT slug FROM {$this->tableName()} WHERE status = 1"
        );
        return array_column($rows, 'slug');
    }

    /**
     * 激活插件（不存在则自动注册）
     */
    public function activate(string $slug): void
    {
        $existing = $this->findBySlug($slug);
        if ($existing) {
            db()->update($this->table, [
                'status'       => 1,
                'activated_at' => time(),
            ], 'slug = ?', [$slug]);
        } else {
            $this->create([
                'slug'         => $slug,
                'status'       => 1,
                'installed_at' => time(),
                'activated_at' => time(),
            ]);
        }
    }

    /**
     * 停用插件
     */
    public function deactivate(string $slug): void
    {
        db()->update($this->table, [
            'status'       => 0,
            'activated_at' => 0,
        ], 'slug = ?', [$slug]);
    }

    /**
     * 注册插件（安装但不激活）
     */
    public function register(string $slug): int|string
    {
        return $this->create([
            'slug'         => $slug,
            'status'       => 0,
            'installed_at' => time(),
            'activated_at' => 0,
        ]);
    }

    /**
     * 按 slug 删除
     */
    public function deleteBySlug(string $slug): int
    {
        return db()->execute(
            "DELETE FROM {$this->tableName()} WHERE slug = ?",
            [$slug]
        );
    }
}
