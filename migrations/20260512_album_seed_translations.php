<?php
/**
 * 默认示例相册（"荣誉资质" / honor）EN / JA 文字翻译种子
 *
 * 前置依赖：20260512_album_i18n（已加 lang 列并复制了 EN/JA album）
 *
 * 检测条件：translation_group_id 跟 zh "荣誉资质" 一致，但 album.name 仍是中文 — 说明
 * 用户尚未手工编辑过翻译，可以用这套默认翻译覆盖。如果用户已经改过 album 名，会自动跳过。
 *
 * Fresh 安装的 SQLite/MySQL 在跑这条之前已有 zh-CN album（id=1）+ photos，跑 album_i18n
 * 复制出 EN/JA album，再被本迁移翻译成对应语言文字。
 */

declare(strict_types=1);

return [
    'id'    => '20260512_album_seed_translations',
    'title' => '相册示例数据：honor EN/JA 翻译种子',
    'desc'  => '把默认示例相册（荣誉资质 / Honors / 受賞・認証）EN/JA album 的 name/description 和 6 张照片的 title 翻译成对应语言。'
               . '幂等：检测到该 album 已被手工编辑（不再是中文标题）则跳过；fresh 安装无对应 album 时也安全。',
    'check' => function (): bool {
        if (!function_exists('_columnExists') || !_columnExists('albums', 'lang')) return false;
        // EN album 已经不是中文名了 → 已应用
        $row = db()->fetchOne(
            "SELECT name FROM " . DB_PREFIX . "albums WHERE lang = 'en' AND name LIKE '%荣誉%' LIMIT 1"
        );
        return $row === null;
    },
    'sqls' => [],
    'php'  => function (): string {
        // ---- EN album ----
        $enAlbum = db()->fetchOne("SELECT id FROM " . DB_PREFIX . "albums WHERE lang = 'en' AND name = ?", ['荣誉资质']);
        $jaAlbum = db()->fetchOne("SELECT id FROM " . DB_PREFIX . "albums WHERE lang = 'ja' AND name = ?", ['荣誉资质']);

        $updated = 0;
        if ($enAlbum) {
            db()->execute(
                "UPDATE " . DB_PREFIX . "albums SET name = ?, description = ? WHERE id = ?",
                ['Honors & Certifications', 'A selection of awards and certifications the company has earned.', (int)$enAlbum['id']]
            );
            $updated++;

            // EN photo titles（按原 zh title 匹配定位，避免依赖 id）
            $enTitleMap = [
                '高新技术企业证书'      => 'High-Tech Enterprise Certification',
                'ISO9001质量管理体系认证' => 'ISO 9001 Quality Management Certification',
                '软件企业认定证书'      => 'Software Enterprise Certification',
                '年度最佳科技创新奖'    => 'Annual Best Tech Innovation Award',
                '优秀供应商荣誉证书'    => 'Excellent Supplier Honor Certificate',
                '行业十佳品牌奖'        => 'Industry Top 10 Brand Award',
            ];
            foreach ($enTitleMap as $zh => $en) {
                db()->execute(
                    "UPDATE " . DB_PREFIX . "album_photos SET title = ? WHERE album_id = ? AND title = ?",
                    [$en, (int)$enAlbum['id'], $zh]
                );
            }
        }

        if ($jaAlbum) {
            db()->execute(
                "UPDATE " . DB_PREFIX . "albums SET name = ?, description = ? WHERE id = ?",
                ['受賞・認証', '当社が取得した各種の受賞・認証一覧。', (int)$jaAlbum['id']]
            );
            $updated++;

            $jaTitleMap = [
                '高新技术企业证书'      => 'ハイテク企業認定証書',
                'ISO9001质量管理体系认证' => 'ISO 9001 品質マネジメント認証',
                '软件企业认定证书'      => 'ソフトウェア企業認定証書',
                '年度最佳科技创新奖'    => '年間最優秀技術革新賞',
                '优秀供应商荣誉证书'    => '優良サプライヤー栄誉証書',
                '行业十佳品牌奖'        => '業界トップ10ブランド賞',
            ];
            foreach ($jaTitleMap as $zh => $ja) {
                db()->execute(
                    "UPDATE " . DB_PREFIX . "album_photos SET title = ? WHERE album_id = ? AND title = ?",
                    [$ja, (int)$jaAlbum['id'], $zh]
                );
            }
        }

        if (class_exists('HtmlCache')) HtmlCache::invalidate();

        return "honor album 翻译已应用（更新 $updated 个 album + 12 个 photo title）";
    },
];
