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
     * 按 slug 查找（多语言感知：先找当前语言的翻译版本）
     */
    public function findBySlugLang(string $slug): ?array
    {
        $lang = siteLang();
        $defaultLang = (string)config('site_lang', 'zh-CN');
        if ($lang === $defaultLang || !isMultiLangEnabled('channels')) {
            return $this->findBySlug($slug);
        }
        // 先找源栏目，再通过 translation_group_id 找目标语言版本
        $src = db()->fetchOne("SELECT * FROM {$this->tableName()} WHERE slug = ? AND status = 1", [$slug]);
        if (!$src) return null;
        $groupId = (int)($src['translation_group_id'] ?: $src['id']);
        $translated = db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE translation_group_id = ? AND lang = ? AND status = 1",
            [$groupId, $lang]
        );
        return $translated ?: $src;
    }

    /**
     * 获取子栏目
     */
    public function getByParent(int $parentId = 0, bool $activeOnly = true, bool $navOnly = false, ?string $lang = null): array
    {
        $sql = "SELECT * FROM {$this->tableName()} WHERE parent_id = ?";
        $params = [$parentId];
        if ($activeOnly) {
            $sql .= " AND status = 1";
        }
        if ($navOnly) {
            $sql .= " AND is_nav = 1";
        }
        if ($lang !== null && isMultiLangEnabled('channels')) {
            $sql .= " AND lang = ?";
            $params[] = $lang;
        }
        $sql .= " ORDER BY {$this->defaultOrder}";
        return db()->fetchAll($sql, $params);
    }

    /**
     * 获取导航栏目（含子栏目，产品类动态注入产品分类）
     */
    public function getNav(): array
    {
        $langWhere = '';
        $langParams = [];
        if (isMultiLangEnabled('channels')) {
            $langWhere = ' AND lang = ?';
            $langParams[] = siteLang();
        }
        $channels = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE parent_id = 0 AND status = 1 AND is_nav = 1{$langWhere} ORDER BY {$this->defaultOrder}",
            $langParams
        );

        foreach ($channels as &$channel) {
            if ($channel['type'] === 'product') {
                // 动态读取产品分类作为子菜单（多层树形，仅 is_nav=1）
                $catSql = 'SELECT * FROM ' . DB_PREFIX . 'product_categories WHERE status = 1 AND is_nav = 1';
                $catParams = [];
                if (isMultiLangEnabled('product_categories')) {
                    $catSql .= ' AND lang = ?';
                    $catParams[] = siteLang();
                }
                $catSql .= ' ORDER BY sort_order ASC, id ASC';
                $allCats = db()->fetchAll($catSql, $catParams);

                // 按 parent_id 分组，递归构建嵌套 children 树
                $byParent = [];
                foreach ($allCats as $c) {
                    $byParent[(int)$c['parent_id']][] = $c;
                }
                $build = function (int $pid) use (&$build, $byParent): array {
                    $out = [];
                    foreach ($byParent[$pid] ?? [] as $cat) {
                        $out[] = [
                            'id' => 0,
                            'name' => $cat['name'],
                            'slug' => $cat['slug'],
                            'type' => 'product',
                            'link_url' => '',
                            'link_target' => '_self',
                            '_url' => productCategoryUrl($cat),
                            'children' => $build((int)$cat['id']),
                        ];
                    }
                    return $out;
                };
                $channel['children'] = $build(0);
            } else {
                $channel['children'] = db()->fetchAll(
                    "SELECT * FROM {$this->tableName()} WHERE parent_id = ? AND status = 1 AND is_nav = 1{$langWhere} ORDER BY {$this->defaultOrder}",
                    array_merge([(int) $channel['id']], $langParams)
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
    public function getTreeAll(int $parentId = 0, ?string $lang = null): array
    {
        $items = $this->getByParent($parentId, false, false, $lang);
        foreach ($items as &$item) {
            $item['children'] = $this->getTreeAll((int) $item['id'], $lang);
        }
        unset($item);
        return $items;
    }

    /**
     * 获取带缩进的平面列表（用于下拉选择）
     */
    public function getFlatList(int $parentId = 0, int $level = 0, ?string $lang = null): array
    {
        $result = [];
        $items = $this->getByParent($parentId, false, false, $lang);
        foreach ($items as $item) {
            $item['_level'] = $level;
            $item['_prefix'] = str_repeat('　', $level);
            $result[] = $item;
            $children = $this->getFlatList((int) $item['id'], $level + 1, $lang);
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
        $sql = "SELECT * FROM {$this->tableName()} WHERE is_home = 1 AND parent_id = 0 AND status = 1";
        $params = [];
        if (isMultiLangEnabled('channels')) {
            $sql .= " AND lang = ?";
            $params[] = siteLang();
        }
        $sql .= " ORDER BY {$this->defaultOrder}";
        return db()->fetchAll($sql, $params);
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
