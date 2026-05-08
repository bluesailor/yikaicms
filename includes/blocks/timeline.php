<?php
/**
 * Yikai CMS - 时间线区块（Timeline Block）
 *
 * 提供：
 *   - 通用渲染入口  timelineBlock(array $opts): string
 *   - 图标辅助       getTimelineIcon(string $icon): string
 *
 * 既被前台 history.php 直接调用，也作为短码 [timeline] 的渲染器。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit('Access Denied');

if (!function_exists('getTimelineIcon')) {
    function getTimelineIcon(string $icon): string
    {
        static $map = [
            'flag'        => '🚩',
            'rocket'      => '🚀',
            'award'       => '🏆',
            'users'       => '👥',
            'box'         => '📦',
            'trending-up' => '📈',
            'map'         => '🗺️',
            'handshake'   => '🤝',
            'building'    => '🏢',
            'star'        => '⭐',
            'heart'       => '❤️',
            'zap'         => '⚡',
            'target'      => '🎯',
            'globe'       => '🌍',
        ];
        return $map[$icon] ?? '';
    }
}

if (!function_exists('timelineBlock')) {
    /**
     * 渲染时间线区块为 HTML。
     *
     * 支持的选项：
     *   layout  string  vertical | horizontal | compact （默认从 config('timeline_layout') 取）
     *   sort    string  asc | desc                       （默认从 config('timeline_sort') 取）
     *   limit   int     0 = 不限                          （默认 0）
     *   source  string  timeline                          （预留 articles，当前仅 timeline 表）
     *
     * @param array $opts
     * @return string
     */
    function timelineBlock(array $opts = []): string
    {
        $opts = array_merge([
            'layout' => (string)config('timeline_layout', 'vertical'),
            'sort'   => (string)config('timeline_sort',   'desc'),
            'limit'  => 0,
            'source' => 'timeline',
        ], $opts);

        $layout = in_array($opts['layout'], ['vertical', 'horizontal', 'compact'], true) ? $opts['layout'] : 'vertical';
        $sort   = $opts['sort'] === 'asc' ? 'asc' : 'desc';
        $limit  = max(0, (int)$opts['limit']);

        // 取数据（当前仅支持 timeline 表，articles 数据源预留 v1.7）
        $items = timelineModel()->getActive();
        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        if (empty($items)) {
            return '<div class="text-center py-12 text-gray-500"><p>暂无内容</p></div>';
        }

        // 按年分组（vertical/compact 用）
        $grouped = [];
        foreach ($items as $it) {
            $grouped[(int)$it['year']][] = $it;
        }
        if ($sort === 'asc') ksort($grouped); else krsort($grouped);

        // 暴露给组件作用域的变量
        $timelines        = $items;
        $groupedTimelines = $grouped;
        $timelineSort     = $sort;

        ob_start();
        require theme_path('partials/timeline-' . $layout . '.php');
        return (string)ob_get_clean();
    }
}
