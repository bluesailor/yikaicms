<?php
/**
 * 将轮播图分组高度从 fullscreen 布尔值升级为三态模式。
 */

declare(strict_types=1);

return [
    'id' => '20260812_banner_group_height_mode',
    'title' => '轮播图分组三态高度',
    'desc' => '轮播图分组支持固定高度、Header 下方首屏和全屏覆盖 Header，并迁移旧 fullscreen 设置。',
    'title_en' => 'Slider group height modes',
    'title_ja' => 'スライダーグループの高さモード',
    'desc_en' => 'Adds fixed, below-header screen and behind-header screen height modes while preserving legacy fullscreen settings.',
    'desc_ja' => '固定高さ、ヘッダー下の全画面、ヘッダー背面の全画面を追加し、従来の fullscreen 設定を維持します。',

    'check' => static function (): bool {
        return !db()->tableExists('banner_groups') || _columnExists('banner_groups', 'height_mode');
    },

    'sqls' => [],

    'php' => static function (): string {
        if (!db()->tableExists('banner_groups')) {
            return '轮播图分组表不存在，已跳过';
        }

        $added = _addColumn(
            'banner_groups',
            'height_mode',
            "varchar(20) NOT NULL DEFAULT 'fixed' COMMENT '高度模式 fixed/screen/cover-header'"
        );
        if ($added) {
            db()->execute(
                'UPDATE ' . DB_PREFIX . "banner_groups SET height_mode = 'screen' WHERE fullscreen = 1"
            );
        }

        return $added ? '已添加轮播图分组高度模式并迁移旧全屏设置' : '轮播图分组高度模式字段已存在';
    },
];
