<?php
declare(strict_types=1);

class ChannelModel extends Model
{
    protected string $table = 'channels';
    protected string $defaultOrder = 'sort_order ASC, id ASC';

    /**
     * 按 slug 查找
     */
    public function findBySlug(string $slug): ?array
    {
        return db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE slug = ? AND status = 1",
            [$slug]
        );
    }

    /**
     * 获取子栏目
     */
    public function getByParent(int $parentId = 0, bool $activeOnly = true, bool $navOnly = false): array
    {
        $sql = "SELECT * FROM {$this->tableName()} WHERE parent_id = ?";
        if ($activeOnly) {
            $sql .= " AND status = 1";
        }
        if ($navOnly) {
            $sql .= " AND is_nav = 1";
        }
        $sql .= " ORDER BY {$this->defaultOrder}";
        return db()->fetchAll($sql, [$parentId]);
    }

    /**
     * 获取导航栏目（含子栏目，产品类动态注入产品分类）
     */
    public function getNav(): array
    {
        $channels = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE parent_id = 0 AND status = 1 AND is_nav = 1 ORDER BY {$this->defaultOrder}"
        );

        foreach ($channels as &$channel) {
            if ($channel['type'] === 'product') {
                // 动态读取产品分类作为子菜单（仅 is_nav=1）
                $cats = db()->fetchAll(
                    'SELECT * FROM ' . DB_PREFIX . 'product_categories WHERE parent_id = 0 AND status = 1 AND is_nav = 1 ORDER BY sort_order ASC, id ASC'
                );
                $channel['children'] = [];
                foreach ($cats as $cat) {
                    $channel['children'][] = [
                        'id' => 0,
                        'name' => $cat['name'],
                        'slug' => $cat['slug'],
                        'type' => 'product',
                        'link_url' => '',
                        'link_target' => '_self',
                        '_url' => productCategoryUrl($cat),
                    ];
                }
            } else {
                $channel['children'] = db()->fetchAll(
                    "SELECT * FROM {$this->tableName()} WHERE parent_id = ? AND status = 1 AND is_nav = 1 ORDER BY {$this->defaultOrder}",
                    [(int) $channel['id']]
                );
            }
        }
        unset($channel);

        return $channels;
    }

    /**
     * 递归获取栏目树（仅启用的）
     */
    public function getTree(int $parentId = 0): array
    {
        $items = $this->getByParent($parentId, true);
        foreach ($items as &$item) {
            $item['children'] = $this->getTree((int) $item['id']);
        }
        unset($item);
        return $items;
    }

    /**
     * 递归获取栏目树（全部，后台用）
     */
    public function getTreeAll(int $parentId = 0): array
    {
        $items = $this->getByParent($parentId, false);
        foreach ($items as &$item) {
            $item['children'] = $this->getTreeAll((int) $item['id']);
        }
        unset($item);
        return $items;
    }

    /**
     * 获取带缩进的平面列表（用于下拉选择）
     */
    public function getFlatList(int $parentId = 0, int $level = 0): array
    {
        $result = [];
        $items = $this->getByParent($parentId, false);
        foreach ($items as $item) {
            $item['_level'] = $level;
            $item['_prefix'] = str_repeat('　', $level);
            $result[] = $item;
            $children = $this->getFlatList((int) $item['id'], $level + 1);
            $result = array_merge($result, $children);
        }
        return $result;
    }

    /**
     * 递归获取所有子栏目 ID（含自身）
     */
    public function getChildIds(int $channelId): array
    {
        $ids = [$channelId];
        $children = db()->fetchAll(
            "SELECT id FROM {$this->tableName()} WHERE parent_id = ?",
            [$channelId]
        );
        foreach ($children as $child) {
            $ids = array_merge($ids, $this->getChildIds((int) $child['id']));
        }
        return $ids;
    }

    /**
     * 获取首页展示的栏目
     */
    public function getHomeChannels(): array
    {
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE is_home = 1 AND parent_id = 0 AND status = 1 ORDER BY {$this->defaultOrder}"
        );
    }

    /**
     * 是否有子栏目
     */
    public function hasChildren(int $id): bool
    {
        return (bool) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} WHERE parent_id = ?",
            [$id]
        );
    }

    /**
     * 批量更新排序
     */
    public function updateSort(array $ids): void
    {
        foreach ($ids as $sort => $id) {
            $this->updateById((int) $id, ['sort_order' => $sort]);
        }
    }
}
