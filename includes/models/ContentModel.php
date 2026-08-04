<?php
declare(strict_types=1);

class ContentModel extends Model
{
    protected string $table = 'contents';
    protected string $defaultOrder = 'is_top DESC, publish_time DESC, id DESC';
    protected bool $softDelete = true;

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

        if (isMultiLangEnabled('contents') && empty($filters['_skip_lang'])) {
            $where[] = 'c.lang = ?';
            $params[] = $filters['lang'] ?? siteLang();
        }

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

        $orderBy = $this->getEffectiveOrder();
        $whereSQL = implode(' AND ', $where) . $this->softDeleteGuard('c.');
        $sql = "SELECT c.*, ch.name as channel_name, ch.slug as channel_slug, ch.type as channel_type
                FROM {$this->tableName()} c
                LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
                WHERE {$whereSQL}
                ORDER BY {$orderBy}
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

        if (isMultiLangEnabled('contents') && empty($filters['_skip_lang'])) {
            $where[] = 'lang = ?';
            $params[] = $filters['lang'] ?? siteLang();
        }

        if (!empty($filters['type'])) {
            $where[] = 'type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(title LIKE ? OR summary LIKE ?)';
            $params[] = '%' . $filters['keyword'] . '%';
            $params[] = '%' . $filters['keyword'] . '%';
        }

        $whereSQL = implode(' AND ', $where) . $this->softDeleteGuard();
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
             WHERE c.id = ? AND c.status = 1 AND c.deleted_at IS NULL",
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
             WHERE c.slug = ? AND c.status = 1 AND c.deleted_at IS NULL",
            [$slug]
        );
    }

    /**
     * 按 slug 查找 + 多语言感知。
     *
     * URL `/ja/case/<src_slug>.html` 进来时 slug 是源 slug（不带 -ja 后缀），
     * 通过 translation_group_id 跳转到当前 siteLang 对应翻译行；找不到回退源。
     */
    public function findBySlugLang(string $slug): ?array
    {
        $lang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');

        // 草稿预览：带 preview 参数时先按 slug 直取（不限 status），
        // 真正的放行仍由 ContentDetailController 校验签名与管理员身份——
        // 这里只是把 id 解析出来，否则草稿在 slug 这一步就 404，走不到校验。
        if (trim((string) ($_GET['preview'] ?? '')) !== '') {
            $draft = db()->fetchOne(
                "SELECT c.*, ch.name as channel_name, ch.slug as channel_slug, ch.type as channel_type
                 FROM {$this->tableName()} c
                 LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
                 WHERE c.slug = ? AND c.deleted_at IS NULL
                 ORDER BY (c.lang = ?) DESC, c.id ASC LIMIT 1",
                [$slug, $lang]
            );
            if ($draft) {
                return $draft;
            }
        }

        // 直接尝试 slug + 当前语言（slug 可能已带语言后缀）
        $direct = db()->fetchOne(
            "SELECT c.*, ch.name as channel_name, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.slug = ? AND c.lang = ? AND c.status = 1 AND c.deleted_at IS NULL
             LIMIT 1",
            [$slug, $lang]
        );
        if ($direct) return $direct;

        // 先找源行
        $src = db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE slug = ? AND status = 1 AND deleted_at IS NULL",
            [$slug]
        );
        if (!$src) return null;
        $groupId = (int) ($src['translation_group_id'] ?: $src['id']);

        // 再按 group + 当前 lang 找翻译行（带 channel join 拼前台需要的字段）
        $translated = db()->fetchOne(
            "SELECT c.*, ch.name as channel_name, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.translation_group_id = ? AND c.lang = ? AND c.status = 1 AND c.deleted_at IS NULL
             LIMIT 1",
            [$groupId, $lang]
        );
        if ($translated) return $translated;

        // 翻译缺失：回退到源行（前台显示源语言版本，不至于 404）
        return $this->findBySlug($slug);
    }

    /**
     * 上一篇
     */
    public function getPrev(int $channelId, int $currentId): ?array
    {
        return db()->fetchOne(
            "SELECT c.id, c.title, c.slug, c.type, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.channel_id = ? AND c.status = 1 AND c.deleted_at IS NULL AND c.id < ? ORDER BY c.id DESC LIMIT 1",
            [$channelId, $currentId]
        );
    }

    /**
     * 下一篇
     */
    public function getNext(int $channelId, int $currentId): ?array
    {
        return db()->fetchOne(
            "SELECT c.id, c.title, c.slug, c.type, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.channel_id = ? AND c.status = 1 AND c.deleted_at IS NULL AND c.id > ? ORDER BY c.id ASC LIMIT 1",
            [$channelId, $currentId]
        );
    }

    /**
     * 相关内容
     */
    public function getRelated(int $channelId, int $excludeId, int $limit = 4): array
    {
        return db()->fetchAll(
            "SELECT c.id, c.title, c.cover, c.slug, c.type, c.created_at, ch.slug as channel_slug, ch.type as channel_type
             FROM {$this->tableName()} c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.channel_id = ? AND c.status = 1 AND c.deleted_at IS NULL AND c.id != ? ORDER BY " . (db()->isSqlite() ? 'RANDOM()' : 'RAND()') . " LIMIT ?",
            [$channelId, $excludeId, $limit]
        );
    }

    /**
     * 获取栏目下的第一条内容（单页用）
     */
    public function getFirstByChannel(int $channelId): ?array
    {
        // 加 lang 过滤防御：同一 channel_id 上若有多语言行（迁移/种子残留），
        // 用 siteLang() 锁定，不串语言。
        $lang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
        return db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE channel_id = ? AND status = 1 AND deleted_at IS NULL AND lang = ? ORDER BY {$this->defaultOrder} LIMIT 1",
            [$channelId, $lang]
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

    /**
     * 定时发布：将已到发布时间的定时内容（status=3）提升为已发布（status=1）。
     * 无需 cron，由 init.php 在访问时限流触发。返回提升的条数。
     */
    public function promoteDue(): int
    {
        $now = time();
        return db()->execute(
            "UPDATE {$this->tableName()} SET status = 1, updated_at = ?
             WHERE status = 3 AND publish_time > 0 AND publish_time <= ?",
            [$now, $now]
        );
    }

    /**
     * 获取含 sort_order 的排序（兼容未升级的数据库）
     */
    public function getEffectiveOrder(): string
    {
        static $hasSortOrder = null;
        if ($hasSortOrder === null) {
            try {
                $hasSortOrder = (bool)db()->fetchOne(
                    db()->isSqlite()
                        ? "SELECT 1 FROM pragma_table_info('" . $this->tableName() . "') WHERE name='sort_order'"
                        : "SHOW COLUMNS FROM `{$this->tableName()}` LIKE 'sort_order'"
                );
            } catch (\Throwable $e) {
                $hasSortOrder = false;
            }
        }
        return $hasSortOrder
            ? 'sort_order DESC, ' . $this->defaultOrder
            : $this->defaultOrder;
    }
}
