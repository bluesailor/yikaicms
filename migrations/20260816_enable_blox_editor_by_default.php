<?php
/** 升级站点默认开启免费可用的 Blox 单页编辑器。 */

declare(strict_types=1);

return [
    'id' => '20260816_enable_blox_editor_by_default',
    'title' => '默认开启 Blox 可视化编辑器',
    'desc' => '升级后默认开启 Blox 单页编辑、预览和发布；免费版同样可用，高级能力继续单独校验授权。',
    'title_en' => 'Enable the Blox visual editor by default',
    'title_ja' => 'Blox ビジュアルエディターを既定で有効化',
    'desc_en' => 'Enables Blox page editing, preview and publishing after upgrade, including the free edition. Premium features remain separately licensed.',
    'desc_ja' => 'アップグレード後、無料版を含めて Blox のページ編集・プレビュー・公開を既定で有効にします。高度な機能は引き続き個別にライセンス確認されます。',
    'check' => static function (): bool {
        try {
            return (string) db()->fetchColumn(
                'SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ? LIMIT 1',
                ['blox_editor_enabled']
            ) === '1';
        } catch (Throwable) {
            return false;
        }
    },
    'php' => static function (): string {
        // 走 SettingModel 写值，同时清掉同一升级请求里可能已经建立的设置缓存。
        settingModel()->set('blox_editor_enabled', '1', 'system');
        db()->execute(
            'UPDATE ' . DB_PREFIX . 'settings SET name = ?, tip = ?, sort_order = ? WHERE `key` = ?',
            ['Blox 可视化编辑器', '默认开启，免费版也可编辑、预览和发布单页；高级能力单独校验授权', 4, 'blox_editor_enabled']
        );

        return 'Blox 可视化编辑器已默认开启；免费版可使用基础单页编辑。';
    },
];
