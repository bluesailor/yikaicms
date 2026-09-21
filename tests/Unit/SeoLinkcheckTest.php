<?php
/**
 * SEO 助手 2.0 缺口补齐的回归：
 *   - 失效链接检查（linkcheck.php）：href 提取 / 站内归一化 / 离线分类
 *   - Article/Product 详情页结构化数据（detail.php / product.php）与
 *     各主题 header JSON-LD 输出的 JSON_HEX_TAG 转义（标题含 </script> 逃逸防线）
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/plugins/seo/redirects.php';
require_once ROOT_PATH . '/plugins/seo/linkcheck.php';

final class SeoLinkcheckTest extends TestCase
{
    // ── href 提取 ───────────────────────────────────────────────

    public function testHrefsExtractsQuotedAndBareValuesWithDedup(): void
    {
        $html = '<p><a href="/a.html">A</a> <a href=\'/b.html\'>B</a> '
            . '<a href=/c.html>C</a> <a href="/a.html" class="x">A2</a> <a name="anchor">n</a></p>';
        self::assertSame(
            ['/a.html', '/b.html', '/c.html'],
            \seo_linkcheck_hrefs($html),
            '三种引号形态都要提取，重复去重，无 href 的 <a> 不产空串'
        );
    }

    // ── 归一化：站内判定与语言前缀 ───────────────────────────────

    /** @return array<string, array{0:string,1:?string}> */
    public static function normalizeCases(): array
    {
        return [
            '外链 http'          => ['https://other.com/x.html', null],
            '协议相对外链'        => ['//cdn.example.com/x.js', null],
            '锚点'               => ['#section', null],
            'mailto'             => ['mailto:a@b.com', null],
            'javascript'         => ['javascript:void(0)', null],
            '无斜杠相对路径'       => ['about.html', null],
            '站内绝对含 www'      => ['http://www.example.com/news/1.html', '/news/1.html'],
            '站内绝对无 www'      => ['https://example.com/news/1.html', '/news/1.html'],
            '语言前缀剥离'        => ['/en/news/1.html', '/news/1.html'],
            '语言前缀小写形态'    => ['/zh-cn/news/1.html', '/news/1.html'],
            '仅语言前缀归一根'    => ['/en', '/'],
            '尾斜杠归一'          => ['/news/', '/news'],
            'fragment 剥离'      => ['/news/1.html#toc', '/news/1.html'],
            '根路径保持'          => ['/', '/'],
            '查询参数保留'        => ['/detail.php?id=5&utm=x', '/detail.php?id=5&utm=x'],
        ];
    }

    private const HOST = 'example.com';
    private const PREFIXES = ['en', 'ja', 'zh-cn'];

    /** @dataProvider normalizeCases */
    public function testNormalizeClassifiesSiteLocalLinks(string $href, ?string $expected): void
    {
        self::assertSame(
            $expected,
            \seo_linkcheck_normalize($href, self::HOST, self::PREFIXES),
            $href
        );
    }

    // ── 分类：有效集 / 磁盘文件 / 重定向接管 / 死链 ───────────────

    public function testClassifyOrdersAliveChecksBeforeDeadVerdict(): void
    {
        $validSet = [
            '/news/live.html' => true,
            '/detail.php?id=7' => true,
            '/list.php?cat=about' => true,
        ];
        $redirectSources = ['/old-price.html' => true];
        $hrefs = [
            '/news/live.html',                  // 有效集：活
            '/detail.php?id=7',                 // 查询别名：活
            '/config/config.sample.php',        // 磁盘真实文件：活
            '/old-price.html',                  // 重定向接管
            '/gone/missing.html',               // 死链
            'https://external.example.com/x',   // 外链
        ];
        $verdict = \seo_linkcheck_classify($hrefs, $validSet, $redirectSources, self::HOST, self::PREFIXES);

        self::assertSame(5, $verdict['internal']);
        self::assertSame(1, $verdict['external']);
        self::assertSame(['/gone/missing.html'], $verdict['dead']);
        self::assertSame(['/old-price.html'], $verdict['redirected']);
    }

    // ── 详情页结构化数据 + JSON-LD 转义 ─────────────────────────

    public function testDetailEntriesEmitCompleteSchemaOrgTypes(): void
    {
        $detail = (string) file_get_contents(ROOT_PATH . '/detail.php');
        // Google Article 富结果要求 author/publisher；canonical 归属由 mainEntityOfPage 声明
        self::assertStringContainsString("'@type' => 'Article'", $detail);
        self::assertStringContainsString("'mainEntityOfPage'", $detail);
        self::assertStringContainsString("'publisher'", $detail);
        self::assertStringContainsString("'author'", $detail);

        $product = (string) file_get_contents(ROOT_PATH . '/product.php');
        self::assertStringContainsString("'@type' => 'Product'", $product);
        self::assertStringContainsString("'sku'", $product);
        self::assertStringContainsString("'brand'", $product);
        self::assertStringContainsString("'url' => \$canonicalUrl,", $product, 'Offer 需带商品页 URL');
    }

    /** @return array<int, string> */
    public static function jsonLdHeaders(): array
    {
        return [
            ['includes/header.php'],
            ['themes/default/layouts/header.php'],
            ['marketplace/themes/aurora/layouts/header.php'],
            ['marketplace/themes/minimal/layouts/header.php'],
            ['marketplace/themes/trade/layouts/header.php'],
        ];
    }

    /**
     * @dataProvider jsonLdHeaders
     * 标题/描述来自用户输入：json_encode 不带 JSON_HEX_TAG 时，内容里的 </script>
     * 能直接关闭脚本块注入属性（breadcrumbJsonLd 的注释点名过同款防线）。
     */
    public function testHeaderJsonLdEncodesWithTagEscaping(string $relPath): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/' . $relPath);
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $source, $blocks);
        self::assertNotEmpty($blocks[1], $relPath . ' 应有 JSON-LD 脚本块');
        foreach ($blocks[1] as $block) {
            self::assertStringContainsString('json_encode', $block, $relPath . ' 的 LD 块应经 json_encode 输出');
            self::assertStringContainsString(
                'JSON_HEX_TAG',
                $block,
                $relPath . ' 的 JSON-LD json_encode 必须带 JSON_HEX_TAG'
            );
        }
    }
}
