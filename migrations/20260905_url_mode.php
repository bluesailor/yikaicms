<?php
/** 为已安装站点补充 URL 与链接模式设置，默认保持漂亮 URL。 */

declare(strict_types=1);

return [
    'id' => '20260905_url_mode',
    'title' => 'URL 与链接访问模式',
    'desc' => '新增漂亮 URL 与无需 Rewrite 的动态 URL 切换；默认不改变现有站点行为。',
    'title_en' => 'URL and link access mode',
    'desc_en' => 'Adds a choice between pretty URLs and dynamic index.php URLs without changing the default behavior.',
    'title_ja' => 'URL とリンクのアクセスモード',
    'desc_ja' => 'きれいなURLとRewrite不要の動的URLを切り替えます。既定値では既存動作を変更しません。',
    'check' => static function (): bool {
        return db()->fetchOne(
            'SELECT 1 FROM ' . DB_PREFIX . 'settings WHERE `key` = ?',
            ['url_mode']
        ) !== null;
    },
    'sqls' => [],
    'php' => static function (): string {
        settingModel()->saveBatch(['url_mode' => 'pretty']);
        return '已新增 URL 访问模式设置（默认：漂亮 URL）';
    },
];
