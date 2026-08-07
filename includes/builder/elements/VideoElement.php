<?php
/** 视频嵌入元素：支持 YouTube/Bilibili/直链，输出 16:9 响应式播放器。schema 驱动。 */

declare(strict_types=1);

final class VideoElement extends AbstractElement
{
    public function type(): string { return 'video'; }
    public function label(): string { return __('blox_el_video'); }
    public function icon(): string { return 'player-play'; }
    public function category(): string { return 'media'; }

    public function controls(): array
    {
        return [
            ['key' => 'url', 'type' => 'text', 'label' => __('blox_video_url'), 'default' => '', 'placeholder' => __('blox_video_ph')],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $url = trim((string) ($data['url'] ?? ''));
        if ($url === '') {
            return '';
        }
        $embed = self::toEmbed($url);
        // 平台嵌入（YouTube/Bilibili）：保留各自原生播放器，16:9 响应式包裹
        if ($embed !== null) {
            return '<div class="relative my-4" style="padding-bottom:56.25%;height:0;overflow:hidden">'
                . '<iframe src="' . htmlspecialchars($embed) . '" class="absolute inset-0 w-full h-full" style="border:0" allowfullscreen loading="lazy"></iframe></div>';
        }
        // 直链视频：Plyr 统一美化播放器（进度/音量/全屏/倍速；自带响应式）。
        // 加载器自守卫（window.__ykPlyr）——多个视频只注入一次 Plyr、初始化全部 .ykt-plyr；
        // 输出对每个直链视频恒定，不用 PHP 静态标志（避免单进程/测试的顺序依赖）。
        return '<div class="my-4"><video class="ykt-plyr" playsinline controls src="' . htmlspecialchars($url) . '"></video></div>'
            . '<script>(function(){if(window.__ykPlyr)return;window.__ykPlyr=1;'
            . 'var c=document.createElement("link");c.rel="stylesheet";c.href="/assets/plyr/plyr.min.css";document.head.appendChild(c);'
            . 'var s=document.createElement("script");s.src="/assets/plyr/plyr.min.js";'
            . 's.onload=function(){document.querySelectorAll(".ykt-plyr:not([data-plyr-ready])").forEach(function(v){v.setAttribute("data-plyr-ready","1");new Plyr(v);});};'
            . 'document.head.appendChild(s);})();</script>';
    }

    /** 常见平台链接 → 可嵌入 URL；无法识别的直链返回 null */
    private static function toEmbed(string $url): ?string
    {
        if (preg_match('~youtube\.com/watch\?v=([\w-]+)~', $url, $m) || preg_match('~youtu\.be/([\w-]+)~', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        if (preg_match('~bilibili\.com/video/(BV[\w]+)~', $url, $m)) {
            return 'https://player.bilibili.com/player.html?bvid=' . $m[1];
        }
        if (str_contains($url, '/embed/') || str_contains($url, 'player.')) {
            return $url; // 已是嵌入地址
        }
        return null;
    }
}
