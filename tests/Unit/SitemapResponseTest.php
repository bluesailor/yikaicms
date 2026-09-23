<?php
/**
 * sitemap.php 的响应契约。
 *
 * 页面缓存命中时直接输出并 exit，排在 HtmlCache::start() 后面的 header() 根本执行不到。
 * 2026-09-23 实测：开启页面缓存后，/sitemap.xml 除当天首次外全部以 text/html 下发
 * （X-Cache: HIT）——搜索引擎把它当 HTML 处理，站点地图等于没交。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SitemapResponseTest extends TestCase
{
    public function testXmlTypeAndEnabledCheckComeBeforeThePageCache(): void
    {
        $src = (string) file_get_contents(ROOT_PATH . '/sitemap.php');
        $cache = strpos($src, 'HtmlCache::start(');
        $type = strpos($src, "header('Content-Type: application/xml");
        $enabled = strpos($src, "config('seo_sitemap_enabled'");

        self::assertNotFalse($cache);
        self::assertNotFalse($type);
        self::assertNotFalse($enabled);
        self::assertLessThan($cache, $type, '缓存命中会 exit，XML 类型必须先发');
        self::assertLessThan($cache, $enabled, '关闭站点地图后不能再从缓存里吐旧文件');
    }
}
