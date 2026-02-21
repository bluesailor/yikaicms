<?php
declare(strict_types=1);

class ContentModel extends Model
{
    protected string $table = 'contents';
    protected string $defaultOrder = 'is_top DESC, publish_time DESC, id DESC';

    /**
     * 获取内容列表（支持栏目和过滤条件）
     */
    public function getList(int $channelId = 0, int $limit = 10, int $offset = 0, array $filters = []): array
    {
        $where = [];
        $params = [];

        if ($channelId > 0) {
            $includeChildren = $filters['include_children'] ?? false;
            $channelIds = $includeChildren ? channelModel()->getChildIds($channelId) : [$channelId];
            $placeholders = implode(',', array_fill(0, count($channelIds), '?'));
            $where[] = "c.channel_id IN ({$placeholders})";
            $params = array_merge($params, $channelIds);
        }

        $where[] = 'c.status = 1';

        if (!empty($filters['type'])) {
            $where[] = 'c.type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(c.title LIKE ? OR c.summary LIKE ?)';
            $params[] = '%' . $filters['keyword'] . '%';
            $params[] = '%' . $filters['keyword'] . '%';
        }
        if (!empty($filters['is_recommend'])) {
            $where[] = 'c.is_recommend = 1';
        }
        if (!empty($filters['is_hot'])) {
            $where[] = 'c.is_hot = 1';
        }
        if (!empty($filters['is_top'])) {
            $where[] = 'c.is_top = 1';
        }

        $whereSQL = implode(' AND ', $where);
        $sql = "SELECT c.*, ch.name as channel_name, ch.slug as channel_slug, ch.type as channel_type
                FROM {$this->tableName()} c
                LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
                WHERE {$whereSQL}
                ORDER BY {$this->defaultOrder}
                LIMIT ? OFFSET ?";

        $params[] = $limit;
        $params[] = $offset;
        return db()->fetchAll($sql, $params);
    }

    /**
     * 获取内容数量
     */
    public function getCount(int $channelId = 0, array $filters = []): int
    {
        $where = [];
        $params = [];

        if ($channelId > 0) {
            $includeChildren = $filters['include_children'] ?? false;
            $channelIds = $includeChildren ? channelModel()->getChildIds($channelId) : [$channelId];
            $placeholders = implode(',', array_fill(0, count($channelIds), '?'));
            $where[] = "channel_id IN ({$placeholders})";
            $params = array_merge($params, $channelIds);
        }

        $where[] = 'status = 1';

        if (!empty($filters['type'])) {
            $where[] = 'type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(title LIKE ? OR summary LIKE ?)';
            $params[] = '%' . $filters['keyword'] . '%';
            $params[] = '%' . $filters['keyword'] . '%';
        }

        $whereSQL = implode(' AND ', $where);
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} WHERE {$whereSQL}",
            $params
        );
    }

    /**
     * 获取详情（JOIN 栏目信息）
     */
    public function getDetail(int $id): ?array
    {
        return db()->fetchOne(
            "SELECT c.*, ch.name as channel_name, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.id = ?",
            [$id]
        );
    }

    /**
     * 获取已发布的内容（前台用，JOIN 栏目信息）
     */
    public function getPublished(int $id): ?array
    {
        return db()->fetchOne(
            "SELECT c.*, ch.name as channel_name, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.id = ? AND c.status = 1",
            [$id]
        );
    }

    /**
     * 按 slug 查找
     */
    public function findBySlug(string $slug): ?array
    {
        return db()->fetchOne(
            "SELECT c.*, ch.name as channel_name, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.slug = ? AND c.status = 1",
            [$slug]
        );
    }

    /**
     * 上一篇
     */
    public function getPrev(int $channelId, int $currentId): ?array
    {
        return db()->fetchOne(
            "SELECT c.id, c.title, c.slug, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.channel_id = ? AND c.status = 1 AND c.id < ? ORDER BY c.id DESC LIMIT 1",
            [$channelId, $currentId]
        );
    }

    /**
     * 下一篇
     */
    public function getNext(int $channelId, int $currentId): ?array
    {
        return db()->fetchOne(
            "SELECT c.id, c.title, c.slug, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.channel_id = ? AND c.status = 1 AND c.id > ? ORDER BY c.id ASC LIMIT 1",
            [$channelId, $currentId]
        );
    }

    /**
     * 相关内容
     */
    public function getRelated(int $channelId, int $excludeId, int $limit = 4): array
    {
        return db()->fetchAll(
            "SELECT c.id, c.title, c.cover, c.slug, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.channel_id = ? AND c.status = 1 AND c.id != ? ORDER BY " . (db()->isSqlite() ? 'RANDOM()' : 'RAND()') . " LIMIT ?",
            [$channelId, $excludeId, $limit]
        );
    }

    /**
     * 获取栏目下的第一条内容（单页用）
     */
    public function getFirstByChannel(int $channelId): ?array
    {
        return db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE channel_id = ? AND status = 1 ORDER BY {$this->defaultOrder} LIMIT 1",
            [$channelId]
        );
    }

    /**
     * 增加浏览量
     */
    public function incrementViews(int $id): int
    {
        return $this->increment($id, 'views');
    }

    /**
     * 增加下载次数
     */
    public function incrementDownloads(int $id): int
    {
        return $this->increment($id, 'download_count');
    }
}
