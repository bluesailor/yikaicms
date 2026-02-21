<?php
declare(strict_types=1);

class SettingModel extends Model
{
    protected string $table = 'settings';
    protected string $primaryKey = 'id';
    protected string $defaultOrder = '`group` ASC, sort_order ASC';

    /** 内存缓存 */
    private ?array $cache = null;

    /**
     * 获取所有设置为 key => value 映射
     */
    public function getAll(): array
    {
        if ($this->cache === null) {
            $rows = db()->fetchAll("SELECT `key`, `value` FROM {$this->tableName()}");
            $this->cache = [];
            foreach ($rows as $row) {
                $this->cache[$row['key']] = $row['value'];
            }
        }
        return $this->cache;
    }

    /**
     * 获取单个设置值
     */
    public function get(string $key, mixed $default = ''): mixed
    {
        $all = $this->getAll();
        return $all[$key] ?? $default;
    }

    /**
     * 设置单个值（存在则更新，不存在则插入）
     */
    public function set(string $key, string $value): int
    {
        $this->cache = null; // 清除缓存
        $existing = db()->fetchOne(
            "SELECT id FROM {$this->tableName()} WHERE `key` = ?",
            [$key]
        );
        if ($existing) {
            return db()->execute(
                "UPDATE {$this->tableName()} SET `value` = ? WHERE `key` = ?",
                [$value, $key]
            );
        }
        $this->create(['key' => $key, 'value' => $value, 'group' => 'basic', 'name' => $key, 'tip' => '']);
        return 1;
    }

    /**
     * 按分组获取
     */
    public function getByGroup(string $group): array
    {
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE `group` = ? ORDER BY sort_order ASC",
            [$group]
        );
    }

    /**
     * 批量保存 [key => value, ...]
     */
    public function saveBatch(array $settings): void
    {
        $this->cache = null;
        foreach ($settings as $key => $value) {
            db()->execute(
                "UPDATE {$this->tableName()} SET `value` = ? WHERE `key` = ?",
                [$value, $key]
            );
        }
    }
}
