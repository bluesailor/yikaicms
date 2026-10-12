<?php
/**
 * 客户评价模块（2.1.0）：建表、导入首页评价轮播里已有的评价与译文、给角色补权限。
 *
 * - 表 testimonials 按语言存行，同一条评价的各语言共享 translation_group_id。
 * - 表是空的才导入：取首页文档（已发布优先）里第一个评价轮播的条目作为原文，
 *   `_home_testimonials_i18n` 绑定里的英文 / 日文等译文按条目顺序成组。轮播本身不改，前台不变；
 *   站长在轮播的「数据来源」里改成「客户评价」后才用这张表。
 * - 有 edit_timeline / delete_timeline 的角色补 edit_testimonial / delete_testimonial（评价与发展历程同属网站展示内容）。
 */

declare(strict_types=1);

$testimonialPermMap = ['edit_timeline' => 'edit_testimonial', 'delete_timeline' => 'delete_testimonial'];

$testimonialRolesPending = static function () use ($testimonialPermMap): bool {
    if (!db()->tableExists('roles')) {
        return false;
    }
    foreach (db()->fetchAll('SELECT permissions FROM ' . DB_PREFIX . 'roles') as $role) {
        $perms = json_decode((string) ($role['permissions'] ?? '[]'), true);
        if (!is_array($perms) || in_array('*', $perms, true)) {
            continue;
        }
        foreach ($testimonialPermMap as $from => $to) {
            if (in_array($from, $perms, true) && !in_array($to, $perms, true)) {
                return true;
            }
        }
    }
    return false;
};

/** 首页文档里第一个评价轮播：[条目, 原文语言, 译文 [语言 => 条目]] */
$testimonialSeedFromHome = static function (): array {
    foreach (['home_blox_published', 'home_blox_data'] as $key) {
        $doc = json_decode((string) db()->fetchColumn('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$key]), true);
        $found = null;
        $walk = static function (mixed $node) use (&$walk, &$found): void {
            if ($found !== null || !is_array($node)) {
                return;
            }
            if (($node['type'] ?? null) === 'testimonial-carousel' && is_array($node['data']['items'] ?? null)) {
                $found = $node['data'];
                return;
            }
            foreach ($node as $child) {
                $walk($child);
            }
        };
        $walk($doc);
        if ($found !== null) {
            $binding = is_array($found['_home_testimonials_i18n'] ?? null) ? $found['_home_testimonials_i18n'] : [];
            $translations = [];
            foreach (is_array($binding['translations'] ?? null) ? $binding['translations'] : [] as $lang => $set) {
                if (is_string($lang) && is_array($set['items'] ?? null)) {
                    $translations[$lang] = array_values($set['items']);
                }
            }
            return [array_values($found['items']), (string) ($binding['lang'] ?? config('site_lang', 'zh-CN')), $translations];
        }
    }
    return [[], '', []];
};

return [
    'id' => '20261012_testimonials',
    'title' => '客户评价模块',
    'desc' => '新增「客户评价」管理（按语言存行），并把首页评价轮播里已有的评价与英文、日文译文导入；首页轮播本身不变，在轮播的「数据来源」里选「客户评价」后才改用这里的数据。有发展历程权限的角色同时获得客户评价权限。',
    'title_en' => 'Testimonials module',
    'title_ja' => 'お客様の声モジュール',
    'desc_en' => 'Adds a Testimonials manager (one row per language) and imports the testimonials already in the homepage carousel together with their English and Japanese translations. The carousel itself is unchanged until you set its data source to Testimonials. Roles that can edit the timeline also get testimonial permissions.',
    'desc_ja' => '「お客様の声」管理（言語ごとに 1 行）を追加し、トップページのカルーセルにある既存のお客様の声と英語・日本語訳を取り込みます。カルーセルは、データソースで「お客様の声」を選ぶまで変わりません。沿革を編集できるロールにはお客様の声の権限も付与されます。',
    'check' => static fn (): bool => db()->tableExists('testimonials') && !$testimonialRolesPending(),
    'php' => static function () use ($testimonialPermMap, $testimonialSeedFromHome): string {
        $table = DB_PREFIX . 'testimonials';
        if (!db()->tableExists('testimonials')) {
            if (db()->isSqlite()) {
                db()->execute("CREATE TABLE \"{$table}\" (
                    \"id\" INTEGER PRIMARY KEY AUTOINCREMENT, \"lang\" TEXT NOT NULL DEFAULT 'zh-CN',
                    \"translation_group_id\" INTEGER NOT NULL DEFAULT '0', \"name\" TEXT NOT NULL, \"role\" TEXT DEFAULT '',
                    \"content\" TEXT, \"avatar\" TEXT DEFAULT '', \"rating\" INTEGER DEFAULT '5', \"sort_order\" INTEGER DEFAULT '0',
                    \"status\" INTEGER DEFAULT '1', \"created_at\" INTEGER DEFAULT '0', \"updated_at\" INTEGER DEFAULT '0')");
                db()->execute("CREATE INDEX \"idx_tm_lang_{$table}\" ON \"{$table}\" (\"lang\",\"status\",\"sort_order\")");
                db()->execute("CREATE INDEX \"idx_tm_trans_{$table}\" ON \"{$table}\" (\"translation_group_id\")");
            } else {
                db()->execute("CREATE TABLE `{$table}` (
                    `id` int(10) unsigned NOT NULL AUTO_INCREMENT, `lang` varchar(10) NOT NULL DEFAULT 'zh-CN',
                    `translation_group_id` int(10) unsigned NOT NULL DEFAULT '0', `name` varchar(100) NOT NULL,
                    `role` varchar(200) DEFAULT '', `content` text, `avatar` varchar(500) DEFAULT '',
                    `rating` tinyint(3) unsigned DEFAULT '5', `sort_order` int(11) DEFAULT '0', `status` tinyint(4) DEFAULT '1',
                    `created_at` int(10) unsigned DEFAULT '0', `updated_at` int(10) unsigned DEFAULT '0',
                    PRIMARY KEY (`id`), KEY `idx_tm_lang` (`lang`,`status`,`sort_order`), KEY `idx_tm_trans` (`translation_group_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='客户评价'");
            }
        }

        $imported = 0;
        if ((int) db()->fetchColumn('SELECT COUNT(*) FROM ' . $table) === 0) {
            [$items, $sourceLang, $translations] = $testimonialSeedFromHome();
            $now = time();
            foreach ($items as $index => $item) {
                if (!is_array($item) || trim((string) ($item['content'] ?? '')) === '') {
                    continue;
                }
                $row = static fn (string $lang, array $text): array => [
                    'lang' => $lang,
                    'name' => mb_substr(trim(strip_tags((string) ($text['name'] ?? $item['name'] ?? ''))), 0, 100),
                    'role' => mb_substr(trim(strip_tags((string) ($text['role'] ?? $item['role'] ?? ''))), 0, 200),
                    'content' => trim(strip_tags((string) ($text['content'] ?? $item['content'] ?? ''))),
                    'avatar' => (string) ($item['avatar'] ?? ''),
                    'rating' => max(1, min(5, (int) ($item['rating'] ?? 5))),
                    'sort_order' => $index,
                    'status' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $groupId = (int) db()->insert('testimonials', $row($sourceLang, $item));
                db()->update('testimonials', ['translation_group_id' => $groupId], 'id = ?', [$groupId]);
                $imported++;
                foreach ($translations as $lang => $set) {
                    if (is_array($set[$index] ?? null) && trim((string) ($set[$index]['content'] ?? '')) !== '') {
                        db()->insert('testimonials', ['translation_group_id' => $groupId] + $row($lang, $set[$index]));
                        $imported++;
                    }
                }
            }
        }

        $granted = 0;
        foreach (db()->fetchAll('SELECT id, permissions FROM ' . DB_PREFIX . 'roles') as $role) {
            $perms = json_decode((string) ($role['permissions'] ?? '[]'), true);
            if (!is_array($perms) || in_array('*', $perms, true)) {
                continue;
            }
            $added = false;
            foreach ($testimonialPermMap as $from => $to) {
                if (in_array($from, $perms, true) && !in_array($to, $perms, true)) {
                    $perms[] = $to;
                    $added = true;
                }
            }
            if ($added) {
                db()->update('roles', ['permissions' => json_encode(array_values($perms))], 'id = ?', [(int) $role['id']]);
                $granted++;
            }
        }
        return 'imported ' . $imported . ', roles ' . $granted;
    },
];
