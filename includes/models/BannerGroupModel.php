<?php
/**
 * Yikai CMS - 轮播图分组 Model
 *
 * PHP 8.0+
 */

declare(strict_types=1);

class BannerGroupModel extends Model
{
    protected string $table = 'banner_groups';
    protected string $defaultOrder = 'sort_order ASC, id ASC';

    /**
     * 按 slug 查找启用的分组
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findWhere(['slug' => $slug, 'status' => 1]);
    }

    /**
     * 返回 [slug => name] 映射（后台位置下拉用）
     */
    public function getAllAsMap(): array
    {
        $groups = $this->all();
        $map = [];
        foreach ($groups as $g) {
            $map[$g['slug']] = $g['name'];
        }
        return $map;
    }

    /**
     * 获取启用的分组
     */
    public function getActive(): array
    {
        return $this->where(['status' => 1]);
    }

    /**
     * 该分组下的轮播图数量
     * @param string      $slug 分组标识
     * @param string|null $lang 指定语言则只统计该语言（用于列表显示，与按语言筛选的列表一致）；
     *                          为 null 时统计所有语言（用于删除前的占用检查）
     */
    public function getBannerCount(string $slug, ?string $lang = null): int
    {
        if ($lang !== null) {
            return (int) db()->fetchColumn(
                "SELECT COUNT(*) FROM " . DB_PREFIX . "banners WHERE position = ? AND lang = ?",
                [$slug, $lang]
            );
        }
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "banners WHERE position = ?",
            [$slug]
        );
    }

    /**
     * 批量更新排序
     */
    public function updateSort(array $ids): void
    {
        foreach ($ids as $index => $id) {
            db()->update($this->table, ['sort_order' => $index], 'id = ?', [(int) $id]);
        }
    }
}
