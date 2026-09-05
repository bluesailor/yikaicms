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

    public function testLanguagePrefixIsAvailableBeforeCmsInitialization(): void
    {
        $this->assertSame('en', Dispatcher::languagePrefixFromPath('/en/download-en.html'));
        $this->assertSame('ja', Dispatcher::languagePrefixFromPath('/ja/'));
        $this->assertNull(Dispatcher::languagePrefixFromPath('/download.html'));

        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/index.php');
        $dispatcher = strpos($index, "require_once __DIR__ . '/includes/Dispatcher.php'");
        $detect = strpos($index, 'Dispatcher::languagePrefixFromPath($__incomingPath)');
        $init = strpos($index, "require_once __DIR__ . '/includes/init.php'");
        $this->assertIsInt($dispatcher);
        $this->assertIsInt($detect);
        $this->assertIsInt($init);
        $this->assertLessThan($init, $dispatcher);
        $this->assertLessThan($init, $detect);
    }

    public function testDynamicQueryUsesAWhitelistAndMirrorsExistingEntrypoints(): void
    {
        $home = Dispatcher::dynamicQuery(['yk_route' => 'home', 'lang' => 'ja']);
        $this->assertSame(['file' => '', 'params' => [], 'lang' => 'ja', 'canonical' => '/ja/'], $home);

        $article = Dispatcher::dynamicQuery(['yk_route' => 'article', 'slug' => 'release-note', 'lang' => 'en']);
        $this->assertSame('article.php', $article['file']);
        $this->assertSame(['slug' => 'release-note'], $article['params']);
        $this->assertSame('/en/news/article/release-note.html', $article['canonical']);

        $page = Dispatcher::dynamicQuery(['yk_route' => 'page', 'parent' => 'service-ja', 'slug' => 'process-ja']);
        $this->assertSame('page.php', $page['file']);
        $this->assertSame(['slug' => 'process-ja', 'parent' => 'service-ja'], $page['params']);
        $this->assertSame('/service-ja/process-ja.html', $page['canonical']);

        $search = Dispatcher::dynamicQuery([
            'yk_route' => 'search',
            'keyword' => '智能',
            'type' => 'download',
            'page' => '2',
        ]);
        $this->assertSame('search.php', $search['file']);
        $this->assertSame(['keyword' => '智能', 'type' => 'download', 'page' => '2'], $search['params']);
        $this->assertSame('/search.html', $search['canonical']);
    }

    public function testDynamicQueryRejectsAmbiguousOrUnsafeInput(): void
    {
        $this->assertNull(Dispatcher::dynamicQuery(['yk_route' => 'unknown']));
        $this->assertNull(Dispatcher::dynamicQuery(['yk_route' => 'article', 'id' => '1', 'slug' => 'post']));
        $this->assertNull(Dispatcher::dynamicQuery(['yk_route' => 'article', 'slug' => '../admin']));
        $this->assertNull(Dispatcher::dynamicQuery(['yk_route' => 'job', 'id' => '0']));
        $this->assertNull(Dispatcher::dynamicQuery(['yk_route' => 'home', 'lang' => 'fr']));
        $this->assertNull(Dispatcher::dynamicQuery(['yk_route' => 'search', 'type' => 'sql']));
        $this->assertNull(Dispatcher::dynamicQuery(['yk_route' => 'list', 'slug' => 'news', 'page' => '0']));
    }

    public function testIndexPreparesDynamicQueryBeforeInitialization(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/index.php');
        $decode = strpos($index, 'Dispatcher::dynamicQuery($__queryInput)');
        $init = strpos($index, "require_once __DIR__ . '/includes/init.php'");
        $this->assertIsInt($decode);
        $this->assertIsInt($init);
        $this->assertLessThan($init, $decode);
        $this->assertStringContainsString("YK_CANONICAL_PATH", (string) file_get_contents(dirname(__DIR__, 2) . '/includes/Dispatcher.php'));
    }

    public function testNoMatchReturnsNull(): void
    {
        $this->assertNull(Dispatcher::match('/no-such-path'));            // 无 .html 后缀
        $this->assertNull(Dispatcher::match('/foo/bar/baz/qux.html'));    // 四段不在表内
        $this->assertNull(Dispatcher::match('/News.html'));               // 大写不匹配（与 htaccess 同义）
    }

    /**
     * 站点自定义路由：dispatch_routes 过滤器前置的规则必须能命中。
     * 必须前置——内置表末尾的 `([a-z0-9_-]+).html → page.php` 会吃掉排在其后的一切。
     * 这是旧站历史 URL（ShopEx 的 /cat-73.html 等）在 catch-all 主机上的唯一出路。
     */
    public function testCustomRoutesMustBePrepended(): void
    {
        $builtin = (new ReflectionClass(Dispatcher::class))->getConstant('ROUTES');
        $mine    = [['#^cat-(\d+)\.html$#', 'list.php', ['cat_id'], []]];

        $hit = Dispatcher::match('/cat-73.html', array_merge($mine, $builtin));
        $this->assertNotNull($hit);
        $this->assertSame('list.php', $hit['file']);
        $this->assertSame(['cat_id' => '73'], $hit['params']);

        // 内置规则不受影响
        $this->assertSame('contact.php', Dispatcher::match('/contact.html', array_merge($mine, $builtin))['file']);
        // 不传自定义表时默认行为不变：被通用单页规则接走
        $this->assertSame('page.php', Dispatcher::match('/cat-73.html')['file']);
        // 反例固化：追加到末尾无效（通用规则先命中），文档里的「必须前置」不是随口说的
        $this->assertSame('page.php', Dispatcher::match('/cat-73.html', array_merge($builtin, $mine))['file']);
    }

    public function testSpecificBeatsGeneric(): void
    {
        // contact.html 必须命中专用文件而非通用单页规则
        $this->assertSame('contact.php', Dispatcher::match('/contact.html')['file']);
        // news/xxx.html 必须归新闻分类而非 page.php 的 parent/slug
        $this->assertSame('news.php', Dispatcher::match('/news/anything.html')['file']);
    }
}
