<?php
/** 轮播项支持图片或本地直链视频，图片继续作为视频封面。 */

declare(strict_types=1);

$__bannerVideoColumns = [
    'media_type' => "varchar(10) NOT NULL DEFAULT 'image' COMMENT '媒体类型：image/video'",
    'video' => "varchar(500) NOT NULL DEFAULT '' COMMENT '视频文件直链'",
    'video_mobile_mode' => "varchar(10) NOT NULL DEFAULT 'poster' COMMENT '移动端：poster/video'",
];

return [
    'id' => '20260904_banner_video',
    'title' => '轮播图视频媒体',
    'desc' => '轮播项可使用 MP4/WebM 等直链视频，并保留图片作为封面和移动端兜底。',
    'title_en' => 'Video banner media',
    'desc_en' => 'Allows banner slides to use direct MP4/WebM video files with an image poster and mobile fallback.',
    'title_ja' => 'バナー動画メディア',
    'desc_ja' => 'バナースライドで MP4/WebM の動画を使用し、画像をポスターとモバイル用フォールバックとして保持します。',

    'check' => static function () use ($__bannerVideoColumns): bool {
        if (!db()->tableExists('banners')) {
            return true;
        }
        foreach (array_keys($__bannerVideoColumns) as $column) {
            if (!_columnExists('banners', $column)) {
                return false;
            }
        }
        return true;
    },

    'sqls' => [],
    'php' => static function () use ($__bannerVideoColumns): string {
        if (!db()->tableExists('banners')) {
            return '轮播图表不存在，已跳过';
        }
        $added = [];
        foreach ($__bannerVideoColumns as $column => $definition) {
            if (_addColumn('banners', $column, $definition)) {
                $added[] = $column;
            }
        }
        return $added === [] ? '轮播视频字段已存在' : ('已添加轮播视频字段：' . implode('、', $added));
    },
];
