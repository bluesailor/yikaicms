<?php
/**
 * 纠正「投稿者」角色的描述，使其与实际权限一致。
 *
 * 原文写「不能删除或上传媒体」，但上传接口一直只判登录——这句话既是空话，
 * 又在权限排查时把人往沟里带（我们就是照着它去查，才发现 upload.php 没有闸）。
 * 现在规则明确为「能编辑就能传图」，描述必须跟上。
 *
 * 只在描述**仍是出厂原文**时才改：管理员改过的一律不动，避免覆盖客户自己的措辞。
 * 幂等。
 */

declare(strict_types=1);

/** 出厂原文 → 新文案，三语一一对应。 */
$__descFix = [
    'description'    => ['仅可撰写文章，不能删除或上传媒体', '仅可撰写文章并插图，不能删除内容'],
    'description_en' => ['Write articles only; cannot delete or upload media', 'Write and illustrate articles only; cannot delete'],
    'description_ja' => ['記事の作成のみ（削除・メディア不可）', '記事の作成と画像挿入のみ（削除不可）'],
];

return [
    'id'    => '20260730_contributor_role_desc',
    'title' => '纠正「投稿者」角色描述（可插图、不可删除）',
    'desc'  => '原描述称「不能上传媒体」，与实际权限不符且误导权限排查。仅当描述仍为出厂原文时更新，管理员改过的不动。',

    'check' => function () use ($__descFix): bool {
        if (!db()->tableExists('roles')) {
            return true;
        }
        foreach ($__descFix as $col => [$old, $_new]) {
            $n = db()->fetchOne(
                'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . "roles WHERE `{$col}` = ?",
                [$old]
            );
            if ((int) ($n['c'] ?? 0) > 0) {
                return false;
            }
        }
        return true;
    },

    'sqls' => [],

    'php' => function () use ($__descFix): string {
        $n = 0;
        foreach ($__descFix as $col => [$old, $new]) {
            $n += (int) db()->execute(
                'UPDATE ' . DB_PREFIX . "roles SET `{$col}` = ? WHERE `{$col}` = ?",
                [$new, $old]
            );
        }
        return $n > 0 ? "更新 {$n} 处描述" : '描述已被自定义或已是新文案，未改动';
    },
];
