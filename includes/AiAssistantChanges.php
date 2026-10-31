<?php
/**
 * Yikai CMS - 后台 AI 助手的「应用 / 撤销」（v2.2 S3，ADR-002 的内部来源适配）
 *
 * 助手把写操作暂存在会话（AiStaging）；这里负责真正执行与撤销：
 * - 每条提案一个事务：锁目标 → 在锁内重新取「操作前快照」→ 执行 → 记变更日志 → 记应用后版本，同一事务提交；
 *   任何一步失败整条回滚，不会出现“改了但没有撤销记录”。
 * - 只把已应用的条目移出暂存；未选的、失败的留着，可以再点一次应用。
 * - 撤销前核对目标仍等于应用后的版本：之后被人改过（或再被别的操作改过）就拒绝，不覆盖。
 *
 * 外部 AI 客户端走 AiProposalService（提案 → 审核），不经过这里；这里的记录 proposal_item_id 恒为 0。
 * 返回结构保持 api_ai_apply.php / api_ai_undo.php 原有的前端契约。
 */

declare(strict_types=1);

require_once __DIR__ . '/AiStaging.php';
require_once __DIR__ . '/AiChangeLog.php';

final class AiAssistantChanges
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @param list<string>|null $onlyIds null = 整组
     * @return array{success:bool,applied:list<array<string,mixed>>,errors:list<string>}
     */
    public function applyStaged(int $adminId, string $adminName, string $setId, ?array $onlyIds): array
    {
        $items = AiStaging::items($setId);
        // 表要在事务外就绪：旧站第一次用时 ensureTable 会建表，MySQL 的 DDL 会隐式提交事务。
        AiChangeLog::ensureTable();
        $tracked = $this->hasRevisionColumns();
        $applied = [];
        $errors = [];
        $done = [];
        foreach ($items as $item) {
            if ($onlyIds !== null && !in_array($item['id'], $onlyIds, true)) continue;
            $ability = (string) $item['ability'];
            $input = (array) ($item['input'] ?? []);
            $summary = (string) ($item['preview']['summary'] ?? $ability);
            if (!Abilities::isMutating($ability)) { $errors[] = "「{$summary}」不是可应用的写操作"; continue; }
            if (!Abilities::permitted($ability)) { $errors[] = "「{$summary}」权限不足"; continue; }
            if ($ability === 'cms_generate_seo_summary' && (empty($input['summary']) || empty($input['source_revision']))) {
                $errors[] = '请重新生成 SEO 摘要提案，应用时不能调用模型';
                continue;
            }
            try {
                $logId = $this->transaction(function () use ($adminId, $adminName, $ability, $input, $summary, $tracked): int {
                    $this->lockTarget($input);
                    // 锁内重新取快照：撤销用的是执行前一刻的真实值，不是对话时的旧预览。
                    $prev = Abilities::previewChange($ability, $input);
                    if (empty($prev['success'])) throw new RuntimeException((string) ($prev['error'] ?? '预览失败'));
                    $exec = Abilities::execute($ability, $input);
                    if (empty($exec['success'])) throw new RuntimeException((string) ($exec['error'] ?? '未知错误'));
                    $this->touchContent($input);
                    return $this->record($adminId, $adminName, $ability, $input, $summary, $prev['preview']['before'] ?? null,
                        $tracked ? $this->revision($ability, $input) : null);
                });
                $applied[] = ['proposal_id' => $item['id'], 'ability' => $ability, 'summary' => $summary, 'log_id' => $logId];
                $done[] = (string) $item['id'];
            } catch (Throwable $error) {
                $errors[] = "「{$summary}」应用失败：" . self::reason($error);
            }
        }
        // 只移出已应用的条目，避免重复应用；未选与失败的留在暂存里。
        AiStaging::remove($setId, $done);
        return ['success' => $errors === [], 'applied' => $applied, 'errors' => $errors];
    }

    /**
     * 撤销一条助手改动。返回 ['success'=>true,'row'=>日志行] 或 ['success'=>false,'error'=>文案]。
     * @return array{success:bool,error?:string,row?:array<string,mixed>}
     */
    public function undo(int $adminId, int $logId): array
    {
        $row = $logId > 0 ? AiChangeLog::get($logId) : AiChangeLog::lastUndoable($adminId);
        if (!$row) return ['success' => false, 'error' => '没有可撤销的记录'];
        if ((int) $row['admin_id'] !== $adminId) return ['success' => false, 'error' => '无权撤销该记录'];
        if (AiChangeLog::isProposalChange($row)) return ['success' => false, 'error' => '这条改动来自外部 AI 草稿提案，请在「AI 草稿提案」页撤销'];
        try {
            return $this->transaction(function () use ($row, $adminId): array {
                $log = $this->lockRow('ai_change_log', (int) $row['id']);
                if ($log === null) return ['success' => false, 'error' => '没有可撤销的记录'];
                if ((int) $log['undone'] === 1) return ['success' => false, 'error' => '该记录已撤销'];
                $ability = (string) $log['ability'];
                $before = json_decode((string) $log['before_json'], true);
                $input = json_decode((string) $log['input_json'], true);
                if (!is_array($input)) $input = [];
                $this->lockTarget($input);
                // 只有目标仍是应用后的样子才回退；之后的人工修改一律不覆盖。升级前的旧记录没有版本，按旧行为处理。
                $expected = (string) ($log['after_revision'] ?? '');
                if ($expected !== '' && !hash_equals($expected, (string) $this->revision($ability, $input))) {
                    return ['success' => false, 'error' => '撤销失败：该内容在 AI 修改之后又被改过，为免覆盖新的修改，未执行撤销'];
                }
                $r = Abilities::revertChange($ability, $before, $input);
                if (empty($r['success'])) throw new RuntimeException((string) ($r['error'] ?? '未知'));
                $this->touchContent($input);
                $sets = 'undone = 1, undone_at = ?';
                $params = [time()];
                if (array_key_exists('undone_by', $log)) { $sets .= ', undone_by = ?'; $params[] = $adminId; }
                $params[] = (int) $log['id'];
                $this->db->execute('UPDATE ' . DB_PREFIX . "ai_change_log SET {$sets} WHERE id = ? AND undone = 0", $params);
                return ['success' => true, 'row' => $log];
            });
        } catch (Throwable $error) {
            return ['success' => false, 'error' => '撤销失败：' . self::reason($error)];
        }
    }

    /** 应用后版本：能力预览给出的“当前值”的摘要。撤销时重算，不同即说明被改过。 */
    private function revision(string $ability, array $input): ?string
    {
        // SEO 的固定来源版本只用于写入前检查；撤销改为核对写入后的实际内容。
        if ($ability === 'cms_generate_seo_summary' && function_exists('cms_seo_summary_row')) {
            return cms_seo_summary_revision(cms_seo_summary_row((int) ($input['id'] ?? 0)));
        }
        if (function_exists('settingModel')) settingModel()->clearCache(); // 设置有请求内快照，必须读最新
        $prev = Abilities::previewChange($ability, $input);
        if (empty($prev['success'])) return null;
        return hash('sha256', (string) json_encode([$ability, $prev['preview']['before'] ?? null], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function record(int $adminId, string $adminName, string $ability, array $input, string $summary, mixed $before, ?string $after): int
    {
        $target = (string) ($input['key'] ?? $input['id'] ?? '');
        $row = [
            'admin_id' => $adminId, 'admin_name' => $adminName, 'ability' => $ability, 'target' => mb_substr($target, 0, 200),
            'summary' => mb_substr($summary, 0, 500), 'before_json' => json_encode($before, JSON_UNESCAPED_UNICODE),
            'input_json' => json_encode($input, JSON_UNESCAPED_UNICODE), 'created_at' => time(), 'undone' => 0, 'undone_at' => 0,
        ];
        if ($after !== null) {
            $row += ['source_ns' => 'internal', 'target_type' => isset($input['key']) ? 'setting' : (isset($input['id']) ? 'content' : ''),
                'target_id' => (int) ($input['id'] ?? 0), 'operation' => 'ability', 'after_revision' => $after];
        }
        $cols = array_keys($row);
        $this->db->execute('INSERT INTO ' . DB_PREFIX . 'ai_change_log (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($row));
        return (int) $this->db->getPdo()->lastInsertId();
    }

    /** 锁住要改的那一行：内容按 id，设置按 key。MySQL 先锁再读，后续读到的就是最新提交。 */
    private function lockTarget(array $input): void
    {
        if (isset($input['id']) && is_int($input['id'])) {
            $this->lockRow('contents', $input['id']);
        } elseif (isset($input['key']) && is_string($input['key'])) {
            $sql = $this->db->isSqlite() ? 'UPDATE ' . DB_PREFIX . 'settings SET id = id WHERE `key` = ?' : 'SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ? FOR UPDATE';
            $this->db->isSqlite() ? $this->db->execute($sql, [$input['key']]) : $this->db->fetchAll($sql, [$input['key']]);
        } elseif ($this->db->isSqlite()) {
            // SQLite 库级写锁：事务第一条就取得，其它写事务按 busy_timeout 排队。
            $this->db->execute('UPDATE ' . DB_PREFIX . 'ai_change_log SET id = id WHERE 1 = 0');
        }
    }

    private function lockRow(string $table, int $id): ?array
    {
        if ($this->db->isSqlite()) {
            $this->db->execute('UPDATE ' . DB_PREFIX . $table . ' SET id = id WHERE id = ?', [$id]);
            return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . $table . ' WHERE id = ?', [$id]);
        }
        return $this->db->fetchOne('SELECT * FROM ' . DB_PREFIX . $table . ' WHERE id = ? FOR UPDATE', [$id]);
    }

    /** 内容类能力用裸 SQL 写 contents，不经模型钩子：补发 data_changed，页面缓存在提交后失效。 */
    private function touchContent(array $input): void
    {
        if (isset($input['id']) && is_int($input['id']) && !isset($input['key']) && function_exists('do_action')) {
            do_action('data_changed', 'contents', $input['id']);
        }
    }

    private function hasRevisionColumns(): bool
    {
        require_once __DIR__ . '/Migrator.php';
        return _columnExists('ai_change_log', 'after_revision');
    }

    /**
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        if ($this->db->getPdo()->inTransaction()) throw new LogicException('ai_assistant_nested_transaction');
        $this->db->beginTransaction();
        try {
            $result = $work();
        } catch (Throwable $error) {
            if ($this->db->getPdo()->inTransaction()) $this->db->rollback();
            throw $error;
        }
        // 撤销被拒（返回 success=false）时也没有任何写入，提交与回滚等价；统一提交。
        $this->db->commit();
        return $result;
    }

    /** 给用户看的失败原因：能力自己的业务提示原样给出，数据库错误不带 SQL。 */
    private static function reason(Throwable $error): string
    {
        if ($error instanceof PDOException) {
            $code = (int) ($error->errorInfo[1] ?? 0);
            return in_array($code, [1213, 1205, 5, 6], true) ? '数据库忙，未做任何修改，请重试' : '数据库错误，未做任何修改';
        }
        return $error->getMessage() !== '' ? $error->getMessage() : '未知错误';
    }
}
