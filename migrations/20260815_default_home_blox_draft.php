<?php
/** 为 default 主题的经典首页自动准备 Blox 草稿。 */

declare(strict_types=1);

$hasStoredHomeBloxDocument = static function (): bool {
    try {
        $value = db()->fetchColumn(
            'SELECT value FROM ' . DB_PREFIX . 'settings'
            . ' WHERE `key` IN (?, ?) AND TRIM(COALESCE(value, \'\')) <> \'\' LIMIT 1',
            ['home_blox_data', 'home_blox_published']
        );
        return $value !== false && $value !== null;
    } catch (Throwable) {
        return false;
    }
};

$installationAlreadyIncludesMigration = static function (): bool {
    try {
        $version = (string) db()->fetchColumn(
            'SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ? LIMIT 1',
            ['cms_version']
        );
        return $version !== '' && version_compare($version, '1.18.0', '>=');
    } catch (Throwable) {
        return false;
    }
};

return [
    'id' => '20260815_default_home_blox_draft',
    'title' => '经典首页生成 Blox 草稿',
    'desc' => 'default 主题升级时自动把经典首页布局转换为 Blox 草稿，不发布、不覆盖已有 Blox 数据，并保留经典首页配置用于回退。',
    'title_en' => 'Create a Blox draft from the classic homepage',
    'title_ja' => '従来のトップページから Blox 下書きを作成',
    'desc_en' => 'Creates an unpublished Blox draft for default-theme classic homepages while preserving existing Blox data and legacy settings.',
    'desc_ja' => 'default テーマの従来トップページから未公開の Blox 下書きを作成し、既存の Blox データと従来設定を保持します。',
    'check' => static function () use ($hasStoredHomeBloxDocument, $installationAlreadyIncludesMigration): bool {
        return $installationAlreadyIncludesMigration()
            || (string) config('current_theme', 'default') !== 'default'
            || $hasStoredHomeBloxDocument();
    },
    'php' => static function () use ($hasStoredHomeBloxDocument): string {
        if ((string) config('current_theme', 'default') !== 'default') {
            return '当前不是 default 主题，已跳过经典首页转换。';
        }
        if ($hasStoredHomeBloxDocument()) {
            return '已存在 Blox 首页数据，未作覆盖。';
        }

        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $document = HomeBloxDocument::createDraftFromLegacy();

        return '已生成未发布的 Blox 首页草稿，共 ' . count($document['sections']) . ' 个区块。';
    },
];
