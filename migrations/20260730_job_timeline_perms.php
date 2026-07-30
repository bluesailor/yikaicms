<?php
/**
 * 招聘与发展历程独立成权限键（语义无损）。
 *
 * 原先两者都借文章的权限：job.php / job_edit.php 借 edit_article + delete_article，
 * timeline.php 借 edit_article + delete_page。「能写文章」不等于「能改招聘岗位」，
 * 语义混乱，且删除档借错了对象（时间线的删除挂在单页权限上）。
 *
 * 迁移规则——保证升级前后谁能做什么完全不变：
 *   有 edit_article   → 补 edit_job、edit_timeline
 *   有 delete_article → 补 delete_job
 *   有 delete_page    → 补 delete_timeline（原时间线删除就是挂在这上面）
 *
 * 超管（'*'）不动。幂等：目标键都已具备即视为已应用。
 */

declare(strict_types=1);

/**
 * 来源键 => 要补的新键。
 * 用局部变量而非 const：loadAll() 对每个迁移文件是 require 而非 require_once，
 * 重复加载会撞「Constant already defined」告警（其余迁移也都是这个写法）。
 */
$__jtMap = [
    'edit_article'   => ['edit_job', 'edit_timeline'],
    'delete_article' => ['delete_job'],
    'delete_page'    => ['delete_timeline'],
];

return [
    'id'    => '20260730_job_timeline_perms',
    'title' => '招聘 / 发展历程 独立权限键',
    'desc'  => '新增 edit_job、delete_job、edit_timeline、delete_timeline。原持有 edit_article 的角色自动获得两个 edit_ 新键，delete_article → delete_job，delete_page → delete_timeline，升级前后权限完全一致；之后可在「角色管理」按需单独收紧。',

    'check' => function () use ($__jtMap): bool {
        if (!db()->tableExists('roles')) {
            return true;
        }
        foreach (db()->fetchAll('SELECT permissions FROM ' . DB_PREFIX . 'roles') as $r) {
            $p = json_decode((string) ($r['permissions'] ?? '[]'), true) ?: [];
            if (in_array('*', $p, true)) {
                continue;
            }
            foreach ($__jtMap as $src => $targets) {
                if (!in_array($src, $p, true)) {
                    continue;
                }
                foreach ($targets as $t) {
                    if (!in_array($t, $p, true)) {
                        return false;
                    }
                }
            }
        }
        return true;
    },

    'sqls' => [],

    'php' => function () use ($__jtMap): string {
        $changed = 0;
        foreach (db()->fetchAll('SELECT id, permissions FROM ' . DB_PREFIX . 'roles') as $r) {
            $p = json_decode((string) ($r['permissions'] ?? '[]'), true) ?: [];
            if (in_array('*', $p, true)) {
                continue;
            }
            $before = $p;
            foreach ($__jtMap as $src => $targets) {
                if (in_array($src, $p, true)) {
                    $p = array_merge($p, $targets);
                }
            }
            $p = array_values(array_unique($p));
            if ($p === $before) {
                continue;
            }
            db()->execute(
                'UPDATE ' . DB_PREFIX . 'roles SET permissions = ? WHERE id = ?',
                [json_encode($p, JSON_UNESCAPED_UNICODE), (int) $r['id']]
            );
            $changed++;
        }
        return $changed > 0 ? "补齐 {$changed} 个角色的招聘/历程权限" : '无需变更';
    },
];
