<?php
/**
 * Dispatcher::match() 与 .htaccess 伪静态规则的对拍测试。
 * 路由表两边必须同序同义——改任何一边都要跑本测试。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Dispatcher.php';

final class DispatcherTest extends TestCase
{
    /** @return array<string, array{0:string, 1:?string, 2:array<string,string>}> */
    public static function routeProvider(): array
    {
        return [
            // 路径 → 目标文件, 期望参数
            'sitemap'        => ['/sitemap.xml', 'sitemap.php', []],
            'search'         => ['/search.html', 'search.php', []],
            'news index'     => ['/news.html', 'news.php', []],
            'news page'      => ['/news/page/2.html', 'news.php', ['page' => '2']],
            'article id'     => ['/news/article/15.html', 'article.php', ['id' => '15']],
            'article slug'   => ['/news/article/my-post.html', 'article.php', ['slug' => 'my-post']],
            'news cat page'  => ['/news/company/page/3.html', 'news.php', ['cat' => 'company', 'page' => '3']],
            'news cat'       => ['/news/company.html', 'news.php', ['cat' => 'company']],
            'list id'        => ['/list/7.html', 'list.php', ['id' => '7']],
            'list id page'   => ['/list/7/page/2.html', 'list.php', ['id' => '7', 'page' => '2']],
            'slug page'      => ['/cases/page/2.html', 'list.php', ['slug' => 'cases', 'page' => '2']],
            'case id'        => ['/case/9.html', 'detail.php', ['id' => '9']],
            'case slug'      => ['/case/big-project.html', 'detail.php', ['slug' => 'big-project']],
            'job'            => ['/job/3.html', 'job_detail.php', ['id' => '3']],
            'download det'   => ['/download/detail/4.html', 'detail.php', ['id' => '4']],
            'download cat pg'=> ['/download/software/page/2.html', 'list.php', ['slug' => 'download', 'cat' => 'software', 'page' => '2']],
            'download cat'   => ['/download/software.html', 'list.php', ['slug' => 'download', 'cat' => 'software']],
            'detail id'      => ['/detail/12.html', 'detail.php', ['id' => '12']],
            'product id'     => ['/product/5.html', 'product.php', ['id' => '5']],
            'product slug'   => ['/product/smart-device/iot-gateway.html', 'product.php', ['slug' => 'iot-gateway']],
            'product cat pg' => ['/product/smart-device/page/2.html', 'list.php', ['slug' => 'product', 'cat' => 'smart-device', 'page' => '2']],
            'product cat'    => ['/product/smart-device.html', 'list.php', ['slug' => 'product', 'cat' => 'smart-device']],
            'history'        => ['/about/history.html', 'history.php', []],
            'contact'        => ['/contact.html', 'contact.php', []],
            'page id'        => ['/page/6.html', 'page.php', ['id' => '6']],
            'page nested'    => ['/about/company.html', 'page.php', ['parent' => 'about', 'slug' => 'company']],
            'page slug'      => ['/about.html', 'page.php', ['slug' => 'about']],
            'api v1'         => ['/api/v1/contents', 'api/v1/index.php', ['resource' => 'contents']],
        ];
    }

    /** @dataProvider routeProvider */
    public function testMatchMirrorsHtaccess(string $path, string $file, array $params): void
    {
        $hit = Dispatcher::match($path);
        $this->assertNotNull($hit, "应命中: $path");
        $this->assertSame($file, $hit['file'], "目标文件: $path");
        $this->assertSame($params, $hit['params'], "参数: $path");
    }

    public function testLangPrefix(): void
    {
        $hit = Dispatcher::match('/ja/news/article/8.html');
        $this->assertSame('article.php', $hit['file']);
        $this->assertSame(['id' => '8'], $hit['params']);
        $this->assertSame('ja', $hit['lang']);

        // 语言前缀首页：file 为空、lang 生效（index.php 继续渲染首页）
        $home = Dispatcher::match('/ja/');
        $this->assertSame('', $home['file']);
        $this->assertSame('ja', $home['lang']);
    }

    public function testNoMatchReturnsNull(): void
    {
        $this->assertNull(Dispatcher::match('/no-such-path'));            // 无 .html 后缀
        $this->assertNull(Dispatcher::match('/foo/bar/baz/qux.html'));    // 四段不在表内
        $this->assertNull(Dispatcher::match('/News.html'));               // 大写不匹配（与 htaccess 同义）
    }

    public function testSpecificBeatsGeneric(): void
    {
        // contact.html 必须命中专用文件而非通用单页规则
        $this->assertSame('contact.php', Dispatcher::match('/contact.html')['file']);
        // news/xxx.html 必须归新闻分类而非 page.php 的 parent/slug
        $this->assertSame('news.php', Dispatcher::match('/news/anything.html')['file']);
    }
}
