<?php
/**
 * Blox 场景权限独立化，保持既有普通页面编辑能力不变。
 *
 * 原来 edit_page 直接放行普通 Blox 页面与区块模板；升级后补 blox_edit，管理员
 * 可再从角色中显式收紧。首页和全站设计此前仅超级管理员可用，因此不自动下放。
 */

declare(strict_types=1);

$normalizeBloxPermissions = static function (array $permissions): array {
    if (in_array('*', $permissions, true)) {
        return array_values(array_unique($permissions));
    }
    if (in_array('edit_page', $permissions, true) && !in_array('blox_edit', $permissions, true)) {
        $permissions[] = 'blox_edit';
    }
    // 旧版允许单独保存 blox_code。新权限模型要求它有可进入的编辑场景，
    // 因此升级时补齐普通页面编辑能力，保留原角色的实际用途。
    if (in_array('blox_code', $permissions, true)
        && array_intersect(['blox_edit', 'blox_home', 'blox_global'], $permissions) === []) {
        $permissions[] = 'edit_page';
        $permissions[] = 'blox_edit';
    }
    return array_values(array_unique($permissions));
};

return [
    'id'    => '20260829_blox_role_permissions',
    'title' => 'Blox 可视化编辑权限分组',
    'desc'  => '新增 blox_edit、blox_home、blox_global 权限；已有 edit_page 的普通角色自动补 blox_edit，首页和全站设计权限仍只由管理员显式授予。',
    'title_en' => 'Blox visual editor permission groups',
    'desc_en' => 'Adds blox_edit, blox_home, and blox_global permissions. Existing page editors retain Blox page access, while homepage and global design access remain explicitly assigned.',
    'title_ja' => 'Blox ビジュアルエディター権限グループ',
    'desc_ja' => 'blox_edit、blox_home、blox_global 権限を追加します。既存のページ編集者は Blox ページ編集権限を維持し、ホームページとグローバルデザインは明示的に割り当てます。',
    'check' => function () use ($normalizeBloxPermissions): bool {
        if (!db()->tableExists('roles')) {
            return true;
        }
        foreach (db()->fetchAll('SELECT permissions FROM ' . DB_PREFIX . 'roles') as $row) {
            $permissions = json_decode((string) ($row['permissions'] ?? '[]'), true) ?: [];
            if ($normalizeBloxPermissions($permissions) !== array_values(array_unique($permissions))) {
                return false;
            }
        }
        return true;
    },
    'sqls' => [],
    'php' => function () use ($normalizeBloxPermissions): string {
        $changed = 0;
        foreach (db()->fetchAll('SELECT id, permissions FROM ' . DB_PREFIX . 'roles') as $row) {
            $permissions = json_decode((string) ($row['permissions'] ?? '[]'), true) ?: [];
            $normalized = $normalizeBloxPermissions($permissions);
            if ($normalized === array_values(array_unique($permissions))) {
                continue;
            }
            db()->execute(
                'UPDATE ' . DB_PREFIX . 'roles SET permissions = ? WHERE id = ?',
                [json_encode($normalized, JSON_UNESCAPED_UNICODE), (int) $row['id']]
            );
            $changed++;
        }
        return $changed > 0 ? "补齐 {$changed} 个角色的 Blox 编辑权限" : '无需变更';
    },
];
