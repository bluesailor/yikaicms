<?php
/**
 * 内容版本历史模型（保存即存档 / 一键恢复）。
 *
 * 每次保存文章或单页前，调用 record() 把被覆盖的旧版本快照存下；编辑页用 listFor()
 * 列出最近版本，restore() 一键写回（恢复前会先把当前状态也存一版，可再退回）。
 * 每个目标默认保留最近 config('revision_keep', 5) 版，record() 后自动 prune()。
 *
 * 快照结构：{"targets":[{"table":"contents","id":180,"fields":{"content":"...",...}}, ...]}
 * table 不含前缀；仅允许白名单表（防篡改快照写任意表）。
 */

declare(strict_types=1);

class ContentRevisionModel extends Model
{
    protected string $table = 'content_revisions';
    protected string $defaultOrder = 'id DESC';

    /** 允许被快照/恢复的表（不含前缀）——白名单，杜绝越权写库 */
    private const ALLOWED_TABLES = ['contents', 'channels'];

    /** 保留版本数：config('revision_keep') 覆盖，默认 5，收敛到 1..50 */
    public function keepCount(): int
    {
        $n = (int) config('revision_keep', 5);
        return max(1, min(50, $n));
    }

    /**
     * 存档一个版本。$targets = [['table'=>'contents','id'=>180,'fields'=>['content'=>'...']], ...]
     * 表缺失（未升级）或任何异常都静默吞掉——存档失败绝不能阻断用户正常保存。
     */
    public function record(string $type, int $targetId, string $lang, array $targets, string $summary, int $adminId = 0, string $adminName = ''): void
    {
        $clean = $this->sanitizeTargets($targets);
        if ($targetId <= 0 || $clean === []) {
            return;
        }
        try {
            db()->insert($this->table, [
                'target_type' => $type,
                'target_id'   => $targetId,
                'lang'        => $lang,
                'snapshot'    => json_encode(['targets' => $clean], JSON_UNESCAPED_UNICODE),
                'summary'     => mb_substr($summary, 0, 200),
                'admin_id'    => $adminId,
                'admin_name'  => mb_substr($adminName, 0, 50),
                'created_at'  => time(),
            ]);
            $this->prune($type, $targetId);
        } catch (\Throwable $e) {
            error_log('[revision] record failed: ' . $e->getMessage());
        }
    }

    /** 最近版本列表（不含 snapshot 大字段）。表缺失返回 [] */
    public function listFor(string $type, int $targetId, int $limit = 20): array
    {
        try {
            return db()->fetchAll(
                "SELECT id, summary, admin_id, admin_name, created_at FROM {$this->tableName()}"
                . " WHERE target_type = ? AND target_id = ? ORDER BY id DESC LIMIT " . max(1, $limit),
                [$type, $targetId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** 取单条（含快照） */
    public function getOne(int $revId): ?array
    {
        try {
            return db()->fetchOne("SELECT * FROM {$this->tableName()} WHERE id = ?", [$revId]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 恢复某版本：先把「当前状态」存一版（恢复可回退），再按快照写回各表行。
     * 注意：命名避开基类 Model::restore()（软删除恢复），语义不同。
     * @return int 写回的目标行数
     * @throws \RuntimeException 版本不存在 / 快照为空
     */
    public function restoreRevision(int $revId, int $adminId = 0, string $adminName = ''): int
    {
        $rev = $this->getOne($revId);
        if (!$rev) {
            throw new \RuntimeException('revision not found');
        }
        $snap = json_decode((string) $rev['snapshot'], true);
        $targets = (is_array($snap) && isset($snap['targets']) && is_array($snap['targets'])) ? $snap['targets'] : [];
        $targets = $this->sanitizeTargets($targets);
        if ($targets === []) {
            throw new \RuntimeException('empty snapshot');
        }

        // 恢复前，先把当前状态存一版
        $curTargets = [];
        foreach ($targets as $t) {
            $cols = array_keys($t['fields']);
            $select = implode(', ', array_map(static fn (string $c): string => "`{$c}`", $cols));
            $cur = db()->fetchOne(
                "SELECT {$select} FROM " . DB_PREFIX . $t['table'] . " WHERE id = ?",
                [$t['id']]
            );
            if ($cur) {
                $curTargets[] = ['table' => $t['table'], 'id' => $t['id'], 'fields' => $cur];
            }
        }
        if ($curTargets !== []) {
            $this->record(
                (string) $rev['target_type'],
                (int) $rev['target_id'],
                (string) $rev['lang'],
                $curTargets,
                (string) $rev['summary'],
                $adminId,
                $adminName
            );
        }

        // 写回快照
        $n = 0;
        foreach ($targets as $t) {
            $fields = $t['fields'];
            $fields['updated_at'] = time();
            db()->update($t['table'], $fields, 'id = ?', [$t['id']]);
            $n++;
        }
        return $n;
    }

    /** 只保留最近 keepCount() 条，其余物理删除 */
    public function prune(string $type, int $targetId): void
    {
        try {
            $rows = db()->fetchAll(
                "SELECT id FROM {$this->tableName()} WHERE target_type = ? AND target_id = ? ORDER BY id DESC",
                [$type, $targetId]
            );
            foreach (array_slice($rows, $this->keepCount()) as $r) {
                db()->delete($this->table, 'id = ?', [(int) $r['id']]);
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }

    /**
     * 过滤 targets：仅保留白名单表、正整数 id、合法列名（[a-zA-Z0-9_]）、非空 fields。
     * @return list<array{table:string,id:int,fields:array<string,mixed>}>
     */
    private function sanitizeTargets(array $targets): array
    {
        $out = [];
        foreach ($targets as $t) {
            if (!is_array($t)) {
                continue;
            }
            $tbl = (string) ($t['table'] ?? '');
            $id  = (int) ($t['id'] ?? 0);
            $fields = (isset($t['fields']) && is_array($t['fields'])) ? $t['fields'] : [];
            if (!in_array($tbl, self::ALLOWED_TABLES, true) || $id <= 0 || $fields === []) {
                continue;
            }
            $safe = [];
            foreach ($fields as $col => $val) {
                if (is_string($col) && preg_match('/^[a-zA-Z0-9_]+$/', $col) && (is_scalar($val) || $val === null)) {
                    $safe[$col] = $val;
                }
            }
            if ($safe !== []) {
                $out[] = ['table' => $tbl, 'id' => $id, 'fields' => $safe];
            }
        }
        return $out;
    }
}
