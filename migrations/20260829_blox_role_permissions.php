<?php
/**
 * Blox 场景权限独立化，保持既有普通页面编辑能力不变。
 *
 * 原来 edit_page 直接放行普通 Blox 页面与区块模板；升级后补 blox_edit，管理员
 * 可再从角色中显式收紧。首页和全站设计此前仅超级管理员可用，因此不自动下放。
 */

declare(strict_types=1);

return [
    'id'    => '20260829_blox_role_permissions',
    'title' => 'Blox 可视化编辑权限分组',
    'desc'  => '新增 blox_edit、blox_home、blox_global 权限；已有 edit_page 的普通角色自动补 blox_edit，首页和全站设计权限仍只由管理员显式授予。',
    'check' => function (): bool {
        if (!db()->tableExists('roles')) {
            return true;
        }
        foreach (db()->fetchAll('SELECT permissions FROM ' . DB_PREFIX . 'roles') as $row) {
            $permissions = json_decode((string) ($row['permissions'] ?? '[]'), true) ?: [];
            if (in_array('*', $permissions, true) || !in_array('edit_page', $permissions, true)) {
                continue;
            }
            if (!in_array('blox_edit', $permissions, true)) {
                return false;
            }
        }
        return true;
    },
    'sqls' => [],
    'php' => function (): string {
        $changed = 0;
        foreach (db()->fetchAll('SELECT id, permissions FROM ' . DB_PREFIX . 'roles') as $row) {
            $permissions = json_decode((string) ($row['permissions'] ?? '[]'), true) ?: [];
            if (in_array('*', $permissions, true)
                || !in_array('edit_page', $permissions, true)
                || in_array('blox_edit', $permissions, true)) {
                continue;
            }
            $permissions[] = 'blox_edit';
            db()->execute(
                'UPDATE ' . DB_PREFIX . 'roles SET permissions = ? WHERE id = ?',
                [json_encode(array_values(array_unique($permissions)), JSON_UNESCAPED_UNICODE), (int) $row['id']]
            );
            $changed++;
        }
        return $changed > 0 ? "补齐 {$changed} 个角色的 Blox 编辑权限" : '无需变更';
    },
];
