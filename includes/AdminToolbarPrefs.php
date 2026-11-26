<?php
/**
 * 后台顶栏设置（2.3.1）：每个管理员自己决定顶栏右侧哪些快捷入口固定显示，没勾的收进「更多」菜单。
 *
 * 存在通用 metas 表（owner_type = admin_user，owner_id = 管理员 ID），不加表不加列：
 * metas 不是整站模板的可移植 owner（SiteTemplateData::metaOwnerPortable），偏好不会随模板导出。
 * 只存「收进更多」的项——没存过 = 全部固定 = 原来的顶栏；以后新增的入口默认固定显示。
 * 各入口能不能用（权限、AI 是否配置、语言是否 ≥ 2 种）仍由顶栏模板各自判断，这里只管放在哪。
 */

declare(strict_types=1);

final class AdminToolbarPrefs
{
    public const OWNER_TYPE = 'admin_user';
    public const META_KEY = 'admin_toolbar_hidden';

    /** 顶栏顺序（也是设置面板里的顺序）。 */
    public const ITEMS = ['search', 'language', 'notifications', 'help', 'visit', 'cache', 'ai'];

    /**
     * 只留已知项、去重、按顶栏顺序排。
     *
     * @return list<string>
     */
    public static function normalize(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $wanted = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $wanted[$item] = true;
            }
        }
        return array_values(array_filter(self::ITEMS, static fn (string $key): bool => isset($wanted[$key])));
    }

    /**
     * 该管理员收进「更多」的项；没保存过、或存的值读不懂都按默认（全部固定）。
     *
     * @return list<string>
     */
    public static function hidden(int $adminId): array
    {
        if ($adminId < 1) {
            return [];
        }
        $raw = getMeta(self::OWNER_TYPE, $adminId, self::META_KEY);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        try {
            return self::normalize(json_decode($raw, true, 4, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * 改一项的位置。只动这一项，别的保持原样——设置面板只列当前可用的入口，
     * 整份覆盖会把暂时不可用的项（比如 AI 还没配置）原来的选择冲掉。
     *
     * @return list<string> 保存后的完整列表
     */
    public static function setPinned(int $adminId, string $item, bool $pinned): array
    {
        if ($adminId < 1 || !in_array($item, self::ITEMS, true)) {
            throw new \InvalidArgumentException('toolbar_item');
        }
        $hidden = self::hidden($adminId);
        $hidden = $pinned ? array_diff($hidden, [$item]) : array_merge($hidden, [$item]);
        $hidden = self::normalize($hidden);
        if (!setMeta(self::OWNER_TYPE, $adminId, self::META_KEY, json_encode($hidden, JSON_THROW_ON_ERROR))) {
            throw new \RuntimeException('toolbar_save');
        }
        return $hidden;
    }

    /** 恢复默认：删掉保存的记录，回到原来的顶栏。 */
    public static function reset(int $adminId): void
    {
        if ($adminId > 0) {
            delMeta(self::OWNER_TYPE, $adminId, self::META_KEY);
        }
    }

    /** 删除管理员账号时一并清掉。 */
    public static function forget(int $adminId): void
    {
        if ($adminId > 0) {
            delMeta(self::OWNER_TYPE, $adminId);
        }
    }
}
