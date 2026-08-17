<?php
/**
 * 单张轮播可覆盖分组的文字与背景动效。
 */

declare(strict_types=1);

$__bannerItemRuntimeColumns = [
    'content_motion' => "varchar(20) NOT NULL DEFAULT 'inherit' COMMENT '文字动效，inherit继承分组'",
    'background_motion' => "varchar(20) NOT NULL DEFAULT 'inherit' COMMENT '背景动效，inherit继承分组'",
];

return [
    'id' => '20260812_banner_item_runtime',
    'title' => '单张轮播动效覆盖',
    'desc' => '每张传统轮播图可独立覆盖分组的文字与背景动效，默认继承分组设置。',
    'title_en' => 'Per-slide motion overrides',
    'title_ja' => 'スライド別モーション設定',
    'desc_en' => 'Allows each traditional slide to override the group content and background motion. Existing slides inherit group settings.',
    'desc_ja' => '各スライドでグループの文字・背景モーションを上書きできます。既存スライドはグループ設定を継承します。',

    'check' => static function () use ($__bannerItemRuntimeColumns): bool {
        if (!db()->tableExists('banners')) {
            return true;
        }
        foreach (array_keys($__bannerItemRuntimeColumns) as $column) {
            if (!_columnExists('banners', $column)) {
                return false;
            }
        }
        return true;
    },

    'sqls' => [],
    'php' => static function () use ($__bannerItemRuntimeColumns): string {
        if (!db()->tableExists('banners')) {
            return '轮播图表不存在，已跳过';
        }

        $added = [];
        foreach ($__bannerItemRuntimeColumns as $column => $definition) {
            if (_addColumn('banners', $column, $definition)) {
                $added[] = $column;
            }
        }

        return $added === []
            ? '单张轮播动效字段已存在'
            : ('已添加单张轮播字段：' . implode('、', $added));
    },
];
