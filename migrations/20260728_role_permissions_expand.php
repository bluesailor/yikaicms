<?php
/**
 * 角色权限细粒度化：把旧的粗粒度权限展开为 {动作}_{类型} 细能力（语义无损）。
 *
 * 旧 'content' → 五类内容的全部 edit_ + delete_（原能删的仍能删，升级不改变现有权限）。
 * 旧 'setting' → 丢弃（站点设置等结构项已归超管专属，由 '*' 覆盖）。
 * '*' 与 media/banner/link/form/member 保持不变。
 *
 * 幂等 check：无任何角色残留 legacy 'content'/'setting' 即视为已应用。
 */

declare(strict_types=1);

return [
    'id'    => '20260728_role_permissions_expand',
    'title' => '角色权限细粒度化（内容按类型 + 编辑/删除分离）',
    'desc'  => '把旧 content 权限展开为各内容类型的 edit_/delete_ 细能力，语义无损（原能删的仍能删）；升级后可在「角色管理」按需收紧删除权限。',
    'check' => function (): bool {
        if (!db()->tableExists('roles')) {
            return true;
        }
        foreach (db()->fetchAll('SELECT permissions FROM ' . DB_PREFIX . 'roles') as $r) {
            $p = json_decode((string) ($r['permissions'] ?? '[]'), true) ?: [];
            if (in_array('content', $p, true) || in_array('setting', $p, true)) {
                return false;
            }
        }
        return true;
    },
    'php' => function (): string {
        // content → 全部内容类型的 edit_+delete_（复用 permissions.php 单一数据源）
        $expandContent = [];
        foreach (contentPermTypes() as $t) {
            $expandContent[] = 'edit_' . $t;
            $expandContent[] = 'delete_' . $t;
        }
        $legacyDrop = ['setting'];   // 结构项归超管，旧串直接丢

        $n = 0;
        foreach (db()->fetchAll('SELECT id, permissions FROM ' . DB_PREFIX . 'roles') as $r) {
            $p = json_decode((string) ($r['permissions'] ?? '[]'), true) ?: [];
            if (in_array('*', $p, true)) {
                continue;   // 超管不动
            }
            $new = [];
            foreach ($p as $perm) {
                if ($perm === 'content') {
                    $new = array_merge($new, $expandContent);
                } elseif (in_array($perm, $legacyDrop, true)) {
                    continue;
                } else {
                    $new[] = $perm;   // media/banner/link/form/member 及已细化的键保持
                }
            }
            $new = array_values(array_unique($new));
            db()->execute(
                'UPDATE ' . DB_PREFIX . 'roles SET permissions = ? WHERE id = ?',
                [json_encode($new, JSON_UNESCAPED_UNICODE), (int) $r['id']]
            );
            $n++;
        }
        return "展开 {$n} 个角色的权限";
    },
];
