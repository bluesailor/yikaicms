<?php
/** 将 URL 模式设置从下拉改为带示例的单选项，不改变现有选择。 */

declare(strict_types=1);

return [
    'id' => '20260905_url_mode_radio',
    'title' => 'URL 模式改为单选设置',
    'desc' => '将 URL 访问模式显示为两个带示例的单选项，并保留站点当前选择。',
    'title_en' => 'Use radio choices for URL mode',
    'desc_en' => 'Shows URL access modes as two example-backed radio choices without changing the current site choice.',
    'title_ja' => 'URL モードをラジオ設定に変更',
    'desc_ja' => 'URLアクセスモードを例付きのラジオ選択に変更し、現在の設定は保持します。',
    'check' => static function (): bool {
        $row = db()->fetchOne(
            'SELECT `type` FROM ' . DB_PREFIX . 'settings WHERE `key` = ?',
            ['url_mode']
        );
        return $row !== null && (string) ($row['type'] ?? '') === 'radio';
    },
    'sqls' => [],
    'php' => static function (): string {
        db()->execute(
            'UPDATE ' . DB_PREFIX . 'settings SET `type` = ? WHERE `key` = ?',
            ['radio', 'url_mode']
        );
        return 'URL 访问模式已改为单选设置';
    },
];
