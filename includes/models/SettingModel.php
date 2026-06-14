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
    public function set(string $key, string $value, string $group = 'basic'): int
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
        // INSERT 路径：手写 SQL 给 `key` / `group` 加反引号（MySQL 保留字，
        // 走 Model::create→db()->insert 通用路径会因列名不带反引号而报 1064 语法错误）
        db()->execute(
            "INSERT INTO {$this->tableName()} (`key`, `value`, `group`, `name`, `tip`) VALUES (?, ?, ?, ?, ?)",
            [$key, $value, $group, $key, '']
        );
        if (function_exists('do_action')) do_action('data_changed', $this->table);
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
     *
     * UPSERT 语义：行不存在时插入（这点对多语言 per-lang 翻译键
     * 如 home_about_content_en 至关重要——首次保存才能落地）。
     */
    public function saveBatch(array $settings): void
    {
        $this->cache = null;
        foreach ($settings as $key => $value) {
            $row = db()->fetchOne(
                "SELECT id FROM {$this->tableName()} WHERE `key` = ?",
                [(string) $key]
            );
            if ($row) {
                db()->execute(
                    "UPDATE {$this->tableName()} SET `value` = ? WHERE `key` = ?",
                    [(string) $value, (string) $key]
                );
            } else {
                // 新键：从 defaults.php 取正确的 group/name/type/tip（取不到才退回 basic），
                // 避免任意页面保存的设置都堆进"基础设置"、显示成裸 key。
                $grp = 'basic';
                $nm  = (string) $key;
                $tp  = 'text';
                $tip = '';
                if (function_exists('getDefaults')) {
                    foreach (getDefaults() as $g => $items) {
                        if (isset($items[$key])) {
                            $grp = (string) $g;
                            $nm  = (string) ($items[$key]['name'] ?? $key);
                            $tp  = (string) ($items[$key]['type'] ?? 'text');
                            $tip = (string) ($items[$key]['tip'] ?? '');
                            break;
                        }
                    }
                }
                db()->execute(
                    "INSERT INTO {$this->tableName()} (`key`, `value`, `group`, `name`, `tip`, `type`) VALUES (?, ?, ?, ?, ?, ?)",
                    [(string) $key, (string) $value, $grp, $nm, $tip, $tp]
                );
            }
        }
        // 设置变更后触发 data_changed，让前台 HTML 缓存自动失效
        if (function_exists('do_action')) {
            do_action('data_changed', $this->tableName(), 0);
        }
    }
}
