<?php
/**
 * 轮播图分组补齐与 Blox Banner 一致的运行参数。
 *
 * 分组管理负责传统轮播数据源和短代码；Blox 页面文档仍保留自己的区块级设置，
 * 两者不会互相覆盖。
 */

declare(strict_types=1);

$__bannerRuntimeColumns = [
    'effect' => "varchar(10) NOT NULL DEFAULT 'fade' COMMENT '切换效果 fade/slide'",
    'speed' => "smallint(5) unsigned NOT NULL DEFAULT 700 COMMENT '切换速度ms'",
    'content_motion' => "varchar(20) NOT NULL DEFAULT 'none' COMMENT '文字入场效果'",
    'background_motion' => "varchar(20) NOT NULL DEFAULT 'none' COMMENT '背景动效'",
    'stagger' => "smallint(5) unsigned NOT NULL DEFAULT 120 COMMENT '文字层错峰ms'",
    'navigation' => "tinyint(1) NOT NULL DEFAULT 1 COMMENT '显示前后导航'",
    'pagination' => "tinyint(1) NOT NULL DEFAULT 1 COMMENT '显示分页指示器'",
    'pause_hover' => "tinyint(1) NOT NULL DEFAULT 1 COMMENT '鼠标悬停暂停'",
];

return [
    'id' => '20260812_banner_group_runtime',
    'title' => '轮播图分组效果设置',
    'desc' => '为传统轮播分组补充切换效果、速度、文字与背景动效、导航、分页和悬停暂停设置；短代码与 Blox Banner 共用同一运行时。',
    'title_en' => 'Slider group effects',
    'title_ja' => 'スライダーグループのエフェクト',
    'desc_en' => 'Adds transition, motion, navigation, pagination and hover-pause options to slider groups so shortcodes can share the Blox Banner runtime.',
    'desc_ja' => 'スライダーグループに切替、モーション、ナビゲーション、ページネーション、ホバー停止を追加し、ショートコードと Blox Banner の実行環境を共通化します。',

    'check' => static function () use ($__bannerRuntimeColumns): bool {
        if (!db()->tableExists('banner_groups')) {
            return true;
        }
        foreach (array_keys($__bannerRuntimeColumns) as $column) {
            if (!_columnExists('banner_groups', $column)) {
                return false;
            }
        }
        return true;
    },

    'sqls' => [],

    'php' => static function () use ($__bannerRuntimeColumns): string {
        if (!db()->tableExists('banner_groups')) {
            return '轮播图分组表不存在，已跳过';
        }

        $added = [];
        foreach ($__bannerRuntimeColumns as $column => $definition) {
            if (_addColumn('banner_groups', $column, $definition)) {
                $added[] = $column;
            }
        }

        return $added === []
            ? '轮播图分组效果字段已存在'
            : ('已添加轮播图分组字段：' . implode('、', $added));
    },
];
