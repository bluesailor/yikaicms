<?php
/**
 * 相册多语言支持
 *
 * 问题：channel.type='album' 的栏目（如 /honor.html）所有语言版本的
 * channel.album_id 都指向同一个 zh-CN album，导致 /en/honor-en.html 显示
 * 中文相册标题、描述和照片说明。
 *
 * 修复：
 * 1) albums 表加 lang + translation_group_id 列（与 channels / contents 对齐）
 * 2) album_photos 表保持不变（photos 通过 album_id 隐式继承所属 album 的 lang）
 * 3) 把每个 zh album 复制成 en/ja 版本（图片地址保留，文字保留原中文供用户后续翻译）
 * 4) 自动把 album 类型 channel 的非源语言行的 album_id 指向新建的 en/ja album
 */

declare(strict_types=1);

return [
    'id'    => '20260512_album_i18n',
    'title' => '相册多语言：albums 加 lang 列 + 自动复制 EN/JA',
    'desc'  => '给 yikai_albums 加 lang/translation_group_id 列；把 zh-CN album 复制成 EN/JA 版本；把 album 类型 channel 的非源 lang 行的 album_id 重定向到对应新 album。'
               . '幂等：检测到 albums.lang 列已存在就跳过 ALTER；album 已存在 en/ja 翻译时不重复复制。',
    'check' => function (): bool {
        return function_exists('_columnExists') && _columnExists('albums', 'lang');
    },
    'sqls' => [],
    'php' => function (): string {
        $isSqlite = db()->isSqlite();

        // 1. ALTER: 加列（兼容 MySQL/SQLite，仅当列不存在时执行）
        if (!_columnExists('albums', 'lang')) {
            if ($isSqlite) {
                db()->execute("ALTER TABLE " . DB_PREFIX . "albums ADD COLUMN lang VARCHAR(10) NOT NULL DEFAULT 'zh-CN'");
                db()->execute("ALTER TABLE " . DB_PREFIX . "albums ADD COLUMN translation_group_id INTEGER NOT NULL DEFAULT 0");
            } else {
                db()->execute("ALTER TABLE `" . DB_PREFIX . "albums` ADD COLUMN `lang` VARCHAR(10) NOT NULL DEFAULT 'zh-CN' COMMENT '语言代码' AFTER `name`");
                db()->execute("ALTER TABLE `" . DB_PREFIX . "albums` ADD COLUMN `translation_group_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '翻译组ID' AFTER `lang`");
                try {
                    db()->execute("ALTER TABLE `" . DB_PREFIX . "albums` ADD INDEX `idx_alb_trans` (`translation_group_id`)");
                } catch (\Throwable $e) { /* 索引重复就忽略 */ }
            }
        }

        // 2. 给现有 album 回填 lang + translation_group_id = self.id
        db()->execute("UPDATE " . DB_PREFIX . "albums SET translation_group_id = id WHERE translation_group_id = 0");

        // 3. 找 album 类型 channel 的非源 lang 行（en / ja），如果它们的 album_id
        //    指向了源 lang 的 album（即与 zh-CN channel 的 album_id 相同），
        //    就给该 lang 复制一份新 album（含 photos），把 channel.album_id 指过去。
        $defaultLang = (string) (function_exists('config') ? config('site_lang', 'zh-CN') : 'zh-CN');

        $albumChannels = db()->fetchAll(
            "SELECT id, lang, translation_group_id, album_id FROM " . DB_PREFIX . "channels WHERE type = 'album' ORDER BY translation_group_id, lang"
        );

        // 把 channel 按 translation_group_id 分组
        $byGroup = [];
        foreach ($albumChannels as $c) $byGroup[(int)$c['translation_group_id']][(string)$c['lang']] = $c;

        $copied = 0;
        $now = time();
        foreach ($byGroup as $groupId => $langMap) {
            $srcChannel = $langMap[$defaultLang] ?? null;
            if (!$srcChannel || empty($srcChannel['album_id'])) continue;
            $srcAlbumId = (int) $srcChannel['album_id'];
            $srcAlbum = db()->fetchOne("SELECT * FROM " . DB_PREFIX . "albums WHERE id = ?", [$srcAlbumId]);
            if (!$srcAlbum) continue;

            foreach ($langMap as $lang => $chRow) {
                if ($lang === $defaultLang) continue;
                if ((int) $chRow['album_id'] !== $srcAlbumId) continue;  // 已指向其它 album，不动

                // 是否已为此 lang 复制过（避免重跑重复插入）
                $existingTrans = db()->fetchOne(
                    "SELECT id FROM " . DB_PREFIX . "albums WHERE translation_group_id = ? AND lang = ?",
                    [$srcAlbum['translation_group_id'] ?: $srcAlbumId, $lang]
                );
                $newAlbumId = $existingTrans['id'] ?? null;

                if (!$newAlbumId) {
                    // 复制 album 行
                    $newRow = $srcAlbum;
                    unset($newRow['id']);
                    $newRow['lang'] = $lang;
                    $newRow['translation_group_id'] = $srcAlbum['translation_group_id'] ?: $srcAlbumId;
                    $newRow['created_at'] = $now;
                    $newRow['updated_at'] = $now;
                    $newAlbumId = (int) db()->insert('albums', $newRow);

                    // 复制 album_photos 行（保留 image / sort_order，title/description 保留中文供编辑）
                    $photos = db()->fetchAll("SELECT * FROM " . DB_PREFIX . "album_photos WHERE album_id = ? AND status = 1", [$srcAlbumId]);
                    foreach ($photos as $p) {
                        unset($p['id']);
                        $p['album_id'] = $newAlbumId;
                        if (isset($p['created_at'])) $p['created_at'] = $now;
                        db()->insert('album_photos', $p);
                    }
                    $copied++;
                }

                // 把 channel.album_id 指向新 album
                db()->execute(
                    "UPDATE " . DB_PREFIX . "channels SET album_id = ? WHERE id = ?",
                    [$newAlbumId, (int) $chRow['id']]
                );
            }
        }

        if (class_exists('HtmlCache')) HtmlCache::invalidate();

        return '相册多语言：lang 列已加，' . $copied . ' 个 EN/JA album 副本已建并关联到对应 channel';
    },
];
