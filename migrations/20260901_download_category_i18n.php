<?php
/**
 * 下载分类补充英、日名称；默认三类只填空值，不覆盖用户已经维护的翻译。
 */

declare(strict_types=1);

return [
    'id'    => '20260901_download_category_i18n',
    'title' => '下载分类支持英文和日文名称',
    'desc'  => '为下载分类增加 name_en/name_ja，并补齐软件下载、文档资料、驱动程序三项默认翻译。',
    'title_en' => 'English and Japanese download category names',
    'desc_en' => 'Adds name_en and name_ja to download categories and translates the three built-in categories.',
    'title_ja' => 'ダウンロードカテゴリの英語・日本語名称',
    'desc_ja' => 'ダウンロードカテゴリに name_en と name_ja を追加し、標準3カテゴリの翻訳を設定します。',
    'check' => function (): bool {
        if (!db()->tableExists('download_categories')) {
            return true;
        }
        if (!_columnExists('download_categories', 'name_en') || !_columnExists('download_categories', 'name_ja')) {
            return false;
        }
        $missing = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "download_categories "
            . "WHERE slug IN ('software','document','driver') AND (name_en = '' OR name_ja = '')"
        );
        return $missing === 0;
    },
    'sqls' => [],
    'php' => function (): string {
        $table = DB_PREFIX . 'download_categories';
        if (!_columnExists('download_categories', 'name_en')) {
            db()->execute("ALTER TABLE `{$table}` ADD COLUMN `name_en` VARCHAR(100) NOT NULL DEFAULT ''");
        }
        if (!_columnExists('download_categories', 'name_ja')) {
            db()->execute("ALTER TABLE `{$table}` ADD COLUMN `name_ja` VARCHAR(100) NOT NULL DEFAULT ''");
        }

        $translations = [
            'software' => ['Software Downloads', 'ソフトウェア'],
            'document' => ['Documentation', 'ドキュメント'],
            'driver' => ['Drivers', 'ドライバー'],
        ];
        $changed = 0;
        foreach (db()->fetchAll("SELECT id, slug, name_en, name_ja FROM {$table}") as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if (!isset($translations[$slug])) {
                continue;
            }
            $data = [];
            if (trim((string) ($row['name_en'] ?? '')) === '') {
                $data['name_en'] = $translations[$slug][0];
            }
            if (trim((string) ($row['name_ja'] ?? '')) === '') {
                $data['name_ja'] = $translations[$slug][1];
            }
            if ($data !== []) {
                db()->update('download_categories', $data, 'id = ?', [(int) $row['id']]);
                $changed++;
            }
        }
        return $changed > 0 ? "补齐 {$changed} 个下载分类的翻译" : '无需变更';
    },
];
