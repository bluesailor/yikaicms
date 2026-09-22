<?php
declare(strict_types=1);

class FormModel extends Model
{
    protected string $table = 'forms';
    protected string $defaultOrder = 'id DESC';

    /** @psalm-suppress PossiblyUnusedReturnValue Public model API returns the affected row count. */
    public function deleteById(int|string $id): int
    {
        if (!is_int($id) && !ctype_digit((string) $id)) return 0;
        return $this->deleteWithUploads([(int) $id], true);
    }

    /** @psalm-suppress PossiblyUnusedReturnValue Public model API returns the affected row count. */
    public function deleteByIds(array $ids): int
    {
        $validIds = [];
        foreach ($ids as $id) {
            if (!is_int($id) && !ctype_digit((string) $id)) continue;
            if ((int) $id > 0) $validIds[] = (int) $id;
        }
        return $this->deleteWithUploads(array_values(array_unique($validIds)), false);
    }

    /** @param list<int> $ids @return list<string> */
    public function uploadReferencesForIds(array $ids): array
    {
        if ($ids === []) return [];
        require_once dirname(__DIR__) . '/FormUploadService.php';
        $references = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $sql = 'SELECT extra FROM ' . $this->tableName() . ' WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')';
            foreach (db()->fetchAll($sql, $chunk) as $row) {
                $references = array_merge($references, FormUploadService::referencesFromExtra((string) ($row['extra'] ?? '')));
            }
        }
        return array_values(array_unique($references));
    }

    /** @param list<int> $ids */
    private function deleteWithUploads(array $ids, bool $single): int
    {
        if ($ids === []) return 0;
        if (db()->getPdo()->inTransaction()) throw new RuntimeException('form_delete_transaction_active');
        require_once dirname(__DIR__) . '/FormUploadService.php';
        $uploads = new FormUploadService();
        $staged = $uploads->stageRemoval($this->uploadReferencesForIds($ids));
        $committed = false;
        try {
            if (!db()->beginTransaction()) throw new RuntimeException('form_delete_transaction_failed');
            $deleted = $single ? parent::deleteById($ids[0]) : parent::deleteByIds($ids);
            try {
                if (!db()->commit()) throw new RuntimeException('form_delete_transaction_failed');
                $committed = true;
            } catch (Throwable $error) {
                if (db()->getPdo()->inTransaction()) throw $error;
                error_log('Form delete observer failed: ' . get_class($error));
                $committed = true;
            }
        } catch (Throwable $error) {
            if (!$committed) {
                if (db()->getPdo()->inTransaction()) {
                    try {
                        db()->rollback();
                    } catch (Throwable $rollbackError) {
                        error_log('Form delete rollback failed: ' . get_class($rollbackError));
                    }
                }
                $uploads->restoreStaged($staged);
            } else {
                $uploads->finalizeStaged($staged);
            }
            throw $error;
        }
        $uploads->finalizeStaged($staged);
        return $deleted;
    }

    /**
     * 获取表单列表（分页+筛选）
     */
    public function getList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'type = ?';
            $params[] = $filters['type'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'status = ?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['source'])) {
            $where[] = 'source = ?';
            $params[] = $filters['source'];
        }
        if (!empty($filters['product_id'])) {
            $where[] = 'product_id = ?';
            $params[] = (int) $filters['product_id'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(name LIKE ? OR phone LIKE ? OR content LIKE ? OR product_title LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} {$whereSQL}",
            $params
        );

        $items = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} {$whereSQL} ORDER BY {$this->defaultOrder} LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 更新跟进状态
     */
    public function updateFollow(int $id, int $status, string $adminName, string $note = ''): int
    {
        return $this->updateById($id, [
            'status'       => $status,
            'follow_admin' => $adminName,
            'follow_note'  => $note,
        ]);
    }

    /**
     * 各状态数量统计
     */
    public function getStatusCounts(): array
    {
        $rows = db()->fetchAll(
            "SELECT status, COUNT(*) as cnt FROM {$this->tableName()} GROUP BY status"
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int)$row['status']] = (int)$row['cnt'];
        }
        return $counts;
    }
}
