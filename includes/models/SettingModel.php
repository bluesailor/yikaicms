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
            $affected = db()->execute(
                "UPDATE {$this->tableName()} SET `value` = ? WHERE `key` = ?",
                [$value, $key]
            );
            $this->notifySaved([$key => $value]);
            return $affected;
        }
        // INSERT 路径：手写 SQL 给 `key` / `group` 加反引号（MySQL 保留字，
        // 走 Model::create→db()->insert 通用路径会因列名不带反引号而报 1064 语法错误）
        db()->execute(
            "INSERT INTO {$this->tableName()} (`key`, `value`, `group`, `name`, `tip`) VALUES (?, ?, ?, ?, ?)",
            [$key, $value, $group, $key, '']
        );
        $this->notifySaved([$key => $value]);
        return 1;
    }

    public function clearCache(): void
    {
        $this->cache = null;
    }

    /** @param array<string,mixed> $settings */
    private function notifySaved(array $settings): void
    {
        // Listeners may read settings immediately; discard the old snapshot first.
        $this->clearCache();
        if ($settings !== [] && function_exists('do_action')) {
            do_action('data_changed', $this->table, 0, $settings);
            do_action('setting_saved', $settings);
        }
    }

    /** Empty/unknown payloads conservatively invalidate; only known runtime stamps are exempt. */
    public static function affectsPageCache(array $settings): bool
    {
        if ($settings === []) return true;
        foreach (array_keys($settings) as $key) {
            if ($key !== 'sched_sweep_at' && !preg_match('/^cron_[a-z0-9_]+_(last|status|msg|ms)$/D', (string) $key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 按分组获取
     */
    /**
     * 切换默认语言后的行角色归位。
     *
     * 不变量：base 行 = 默认语言内容，<key>_<lang> = 其他语言。前台读取是
     * 后缀优先（configLang），切默认语言若不归位，后台表单显示旧语言、
     * 保存写 base 但前台读后缀——改了不生效（2026-08-09 实测事故）。
     *
     * 归位规则（仅处理存在 <key>_<新默认> 行的键，数据驱动、幂等）：
     *   1) base 现值（旧默认语言内容）落到 <key>_<旧默认>（已有则不覆盖）；
     *   2) <key>_<新默认> 值提升为 base；
     *   3) 删除 <key>_<新默认> 行。
     * 无新默认后缀行的键不动——base 继续按「语言无关兜底」参与前台回退链。
     *
     * @return int 归位的键数
     */
    public function normalizeDefaultLangRows(string $newDefault, string $oldDefault): int
    {
        if ($newDefault === '' || $newDefault === $oldDefault) {
            return 0;
        }
        $suffix = '_' . $newDefault;
        // LIKE 里 _ 与 % 是通配符，须转义；语言码含 '-'（zh-CN）无需处理。
        //
        // 转义符特意用 '!' 而不是反斜杠：反斜杠在 MySQL 的字符串字面量里**本身**就是
        // 转义符，PHP 双引号串里的 "ESCAPE '\\'" 解析后只剩一个反斜杠，SQL 收到
        // ESCAPE '\' —— 其中 \' 被当成转义的引号，字符串永远闭合不了，直接 1064。
        // 症状是「切换站点默认语言就 500」。换个不参与转义的字符最省心。
        $pattern = '%' . str_replace(['!', '_', '%'], ['!!', '!_', '!%'], $suffix);
        $rows = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE `key` LIKE ? ESCAPE '!'",
            [$pattern]
        );
        $count = 0;
        foreach ($rows as $row) {
            $baseKey = substr((string) $row['key'], 0, -strlen($suffix));
            if ($baseKey === '') {
                continue;
            }
            $base = db()->fetchOne("SELECT * FROM {$this->tableName()} WHERE `key` = ?", [$baseKey]);
            // 1) 保全旧默认语言内容
            if ($base !== null && $oldDefault !== '') {
                $oldKey = $baseKey . '_' . $oldDefault;
                $oldRow = db()->fetchOne("SELECT id FROM {$this->tableName()} WHERE `key` = ?", [$oldKey]);
                if (!$oldRow && (string) $base['value'] !== (string) $row['value']) {
                    $this->set($oldKey, (string) $base['value'], (string) ($base['group'] ?? 'basic'));
                }
            }
            // 2) 提升为 base
            $this->set($baseKey, (string) $row['value'], (string) ($row['group'] ?? 'basic'));
            // 3) 删除后缀行
            db()->execute("DELETE FROM {$this->tableName()} WHERE `key` = ?", [(string) $row['key']]);
            $this->notifySaved([(string) $row['key'] => null]);
            $count++;
        }
        return $count;
    }

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
        $saved = [];
        try {
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
                $saved[(string) $key] = (string) $value;
            }
        } finally {
            // Some writes may have succeeded before a later SQL error.
            $this->notifySaved($saved);
        }
    }
}
