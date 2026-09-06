<?php
/** 为已有 URL 模式设置补齐单选项数据，确保后台可显示两个模式。 */

declare(strict_types=1);

$urlModeOptions = [
    'pretty' => '漂亮URL（需要Rewrite）',
    'query' => '动态URL（无需Rewrite）',
];

return [
    'id' => '20260905_url_mode_options',
    'title' => '补齐 URL 模式选项',
    'desc' => '为已有 URL 访问模式设置补齐漂亮 URL 与动态 URL 的选项数据，不改变当前选择。',
    'title_en' => 'Complete URL mode options',
    'desc_en' => 'Adds the two URL mode choices to existing installations without changing the current selection.',
    'title_ja' => 'URL モードの選択肢を補完',
    'desc_ja' => '既存インストールに2つのURLモード選択肢を追加し、現在の選択は変更しません。',
    'check' => static function () use ($urlModeOptions): bool {
        $row = db()->fetchOne(
            'SELECT `options` FROM ' . DB_PREFIX . 'settings WHERE `key` = ?',
            ['url_mode']
        );
        $options = json_decode((string) ($row['options'] ?? ''), true);
        return is_array($options) && $options === $urlModeOptions;
    },
    'sqls' => [],
    'php' => static function () use ($urlModeOptions): string {
        db()->execute(
            'UPDATE ' . DB_PREFIX . 'settings SET `options` = ? WHERE `key` = ?',
            [json_encode($urlModeOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'url_mode']
        );
        return 'URL 访问模式选项已补齐';
    },
];
