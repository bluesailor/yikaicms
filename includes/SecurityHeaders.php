<?php
/**
 * 每个请求都发的安全响应头（2.1.3）。
 *
 * 以前这几行写在 config.php 里，可安装器用的模板 config.sample.php 从来没有——
 * 只有很早从 config.php.example 抄出来的站才带。新装站点因此前台、后台都不发
 * X-Frame-Options，后台能被别的网站套进框架做点击劫持。config.php 是各站私有文件、
 * 升级不覆盖，所以放进随包升级的代码里，由 functions.php 在每个入口加载时发出。
 * 老站 config.php 里那几行照旧会发，同名头后发覆盖前发，不会重复。
 *
 * 只放不会误伤页面的头：不限制脚本来源（后台与构建器大量内联脚本），
 * 不加 Permissions-Policy（联系页地图、视频嵌入要用），HSTS 由站长在服务器上开。
 */

declare(strict_types=1);

final class SecurityHeaders
{
    /** @return list<string> */
    public static function lines(): array
    {
        return [
            // 本站后台的编辑画布、修订预览都是同源框架；别的网站一律不许套
            'X-Frame-Options: SAMEORIGIN',
            "Content-Security-Policy: frame-ancestors 'self'",
            'X-Content-Type-Options: nosniff',
            'Referrer-Policy: strict-origin-when-cross-origin',
        ];
    }

    public static function send(): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        foreach (self::lines() as $line) {
            header($line);
        }
    }
}
