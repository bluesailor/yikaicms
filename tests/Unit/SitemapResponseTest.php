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

    /**
     * 安装种子里栏目的 updated_at 是 0，`??` 不跳过 0 —— 开发站 182 个地址里 18 个 lastmod
     * 成了 1970-01-01。每一条 lastmod 都必须经过跳过 0 的取值函数。
     */
    public function testEveryLastmodSkipsZeroTimestamps(): void
    {
        $src = (string) file_get_contents(ROOT_PATH . '/sitemap.php');
        preg_match_all("/'lastmod'\s*=>\s*([^\n]+)/", $src, $m);
        self::assertGreaterThanOrEqual(4, count($m[1]));
        foreach ($m[1] as $expr) {
            self::assertStringStartsWith('sitemapLastmod(', trim($expr), $expr);
        }
        self::assertMatchesRegularExpression('/\(int\) \$ts > 0/', $src, '只认正的时间戳');
    }
}
