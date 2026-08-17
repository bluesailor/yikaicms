<?php

/** Repair untouched automatic homepage drafts created by the first Blox migration. */

declare(strict_types=1);

return [
    'id' => '20260815_refresh_legacy_home_blox_draft',
    'title' => '补全经典首页 Blox 草稿',
    'desc' => '重新识别首页产品、新闻等动态栏目，并保留统计与优势版块的原站内容；已编辑或已发布的 Blox 首页不会覆盖。',
    'title_en' => 'Complete the classic homepage Blox draft',
    'title_ja' => '従来トップページの Blox 下書きを補完',
    'desc_en' => 'Restores dynamic homepage channels and legacy block settings only for untouched automatic drafts.',
    'desc_ja' => '未編集の自動生成下書きのみ、動的チャンネルと従来のブロック設定を復元します。',
    'check' => static function (): bool {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        return !HomeBloxDocument::canRefreshLegacyImportDraft();
    },
    'php' => static function (): string {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $document = HomeBloxDocument::refreshLegacyImportDraft();
        if ($document === null) {
            return '草稿已由用户编辑或发布，未作覆盖。';
        }
        return '已补全经典首页 Blox 草稿，共 ' . count($document['sections']) . ' 个区块。';
    },
];
