<?php
/** 视频嵌入元素：支持 YouTube/Bilibili/直链，输出 16:9 响应式播放器。schema 驱动。 */

declare(strict_types=1);

final class VideoElement extends AbstractElement
{
    public function type(): string { return 'video'; }
    public function label(): string { return '视频'; }
    public function icon(): string { return 'player-play'; }
    public function category(): string { return 'media'; }

    public function controls(): array
    {
        return [
            ['key' => 'url', 'type' => 'text', 'label' => '视频地址', 'default' => '', 'placeholder' => 'YouTube/Bilibili 链接或 .mp4 直链'],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $url = trim((string) ($data['url'] ?? ''));
        if ($url === '') {
            return '';
        }
        $embed = self::toEmbed($url);
        $wrap = '<div class="relative my-4" style="padding-bottom:56.25%;height:0;overflow:hidden">';
        if ($embed !== null) {
            return $wrap . '<iframe src="' . htmlspecialchars($embed) . '" class="absolute inset-0 w-full h-full" style="border:0" allowfullscreen loading="lazy"></iframe></div>';
        }
        // 直链视频
        return $wrap . '<video src="' . htmlspecialchars($url) . '" class="absolute inset-0 w-full h-full" controls></video></div>';
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
