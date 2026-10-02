<?php
/**
 * 栏目 / 分类导航元素（v2.0.4）：当前栏目识别、产品分类树、手风琴与高亮、小屏下拉框。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BlockRenderer;
use BuilderRegistry;
use CategoryNavElement;
use PageTitleElement;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

#[RunTestsInSeparateProcesses]
final class CategoryNavElementTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER DEFAULT 0, name TEXT, slug TEXT,
                type TEXT DEFAULT 'list', status INTEGER DEFAULT 1, is_nav INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0,
                icon TEXT DEFAULT '', image TEXT DEFAULT '', link_url TEXT DEFAULT '', lang TEXT DEFAULT 'zh-CN',
                created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0)",
            "CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER DEFAULT 0, title TEXT, status INTEGER DEFAULT 1,
                lang TEXT DEFAULT 'zh-CN', deleted_at INTEGER DEFAULT NULL)",
            "CREATE TABLE product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER DEFAULT 0, name TEXT, slug TEXT DEFAULT '',
                lang TEXT DEFAULT 'zh-CN', image TEXT DEFAULT '', status INTEGER DEFAULT 1, is_nav INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT 0)",
            "CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER DEFAULT 0, title TEXT, summary TEXT DEFAULT '',
                model TEXT DEFAULT '', status INTEGER DEFAULT 1, lang TEXT DEFAULT 'zh-CN', created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0,
                deleted_at INTEGER DEFAULT NULL)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('channelUrl')) {
            eval('function channelUrl(array $c): string { return ($c["type"] ?? "") === "link" ? htmlspecialchars((string) $c["link_url"], ENT_QUOTES) : "/" . $c["slug"] . "/"; }
                  function productCategoryUrl(array $c): string { return "/product/" . $c["slug"] . "/"; }
                  function getChannelBySlug(string $slug, bool $lang = false): ?array { return channelModel()->findBySlug($slug); }');
        }
        unset($GLOBALS['currentChannelId'], $GLOBALS['currentProductCategoryId']);
        BlockRenderer::$editChannelId = 0;
        BlockRenderer::$showHidden = false;
    }

    private function channel(string $name, string $slug, int $parent = 0, string $type = 'list', array $extra = []): int
    {
        return $this->insertRow('channels', $extra + [
            'name' => $name, 'slug' => $slug, 'type' => $type, 'parent_id' => $parent, 'status' => 1, 'is_nav' => 1,
            'sort_order' => 0, 'created_at' => time(), 'updated_at' => time(),
        ]);
    }

    /** @return array{0:int,1:int,2:int,3:int} 新闻、公司新闻、行业动态、行业动态下的展会 */
    private function newsTree(): array
    {
        $news = $this->channel('News', 'news');
        $company = $this->channel('Company', 'company', $news);
        $industry = $this->channel('Industry', 'industry', $news, 'list', ['icon' => 'bi:globe']);
        $expo = $this->channel('Expo', 'expo', $industry);
        $this->channel('About', 'about', 0, 'page');
        return [$news, $company, $industry, $expo];
    }

    public function testRegisteredAsFreeDynamicElement(): void
    {
        $element = BuilderRegistry::get('category-nav');
        self::assertInstanceOf(CategoryNavElement::class, $element);
        self::assertSame('dynamic', $element->category());
        foreach ($element->controls() as $control) {
            self::assertArrayNotHasKey('advanced', $control, '栏目导航是免费元素');
        }
        self::assertSame(['/assets/js/blox-category-nav.js'], $element->scriptsFor([]));
        self::assertSame([], $element->scriptsFor(['layout' => 'inline', 'mobile_select' => '0']));
    }

    public function testAutoFindsTopSectionAndMarksCurrentPath(): void
    {
        [$news, $company, $industry, $expo] = $this->newsTree();
        $GLOBALS['currentChannelId'] = $expo;

        $model = CategoryNavElement::buildModel(['depth' => '3']);
        self::assertSame('News', $model['root']['name']);
        self::assertSame(['Company', 'Industry'], array_column($model['items'], 'name'));
        [$companyItem, $industryItem] = $model['items'];
        self::assertFalse($companyItem['open']);
        self::assertTrue($industryItem['open'], '当前项的上级要展开');
        self::assertFalse($industryItem['active']);
        self::assertTrue($industryItem['children'][0]['active']);
        self::assertSame('/expo/', $industryItem['children'][0]['url']);

        $html = CategoryNavElement::renderModel($model, ['depth' => '3'], 'nav1');
        self::assertStringContainsString('aria-current="page">' , $html);
        self::assertMatchesRegularExpression('#<a [^>]*href="/expo/" aria-current="page">#', $html);
        // 手风琴：公司新闻没有子级不出按钮；行业动态在当前路径上，按钮是展开态、子列表不隐藏
        self::assertStringContainsString('aria-expanded="true" aria-controls="nav1-c' . $industry . '"', $html);
        self::assertStringContainsString('<ul id="nav1-c' . $industry . '" class="', $html);
        self::assertStringNotContainsString('id="nav1-c' . $industry . '" class="ms-4 border-s border-gray-100 pb-1" hidden', $html);
        self::assertStringContainsString('<i class="bi bi-globe', $html);
        unset($news, $company);
    }

    public function testDepthLimitAndCollapsedBranches(): void
    {
        [, $company, $industry] = $this->newsTree();
        $GLOBALS['currentChannelId'] = $company;

        $model = CategoryNavElement::buildModel(['depth' => '2']);
        $industryItem = $model['items'][1];
        self::assertCount(1, $industryItem['children']);
        self::assertSame([], CategoryNavElement::buildModel(['depth' => '1'])['items'][1]['children']);

        $html = CategoryNavElement::renderModel($model, [], 'n');
        self::assertStringContainsString('aria-expanded="false" aria-controls="n-c' . $industry . '"', $html);
        self::assertMatchesRegularExpression('#<ul id="n-c' . $industry . '" class="[^"]*" hidden>#', $html, '不在当前路径的分支收起');

        $expanded = CategoryNavElement::renderModel($model, ['expand' => 'all'], 'n');
        self::assertStringNotContainsString('data-yk-category-nav-toggle', $expanded);
        self::assertStringNotContainsString(' hidden>', $expanded);
    }

    public function testCurrentScopeFallsBackToSiblingsAndExplicitSourceWins(): void
    {
        [$news, $company, $industry] = $this->newsTree();
        $GLOBALS['currentChannelId'] = $company;

        $siblings = CategoryNavElement::buildModel(['scope' => 'current']);
        self::assertSame('News', $siblings['root']['name'], '没有下级时列同级');

        $GLOBALS['currentChannelId'] = $industry;
        self::assertSame(['Expo'], array_column(CategoryNavElement::buildModel(['scope' => 'current'])['items'], 'name'));

        unset($GLOBALS['currentChannelId']);
        self::assertSame([], CategoryNavElement::buildModel([]), '没有上下文且未指定栏目时不出');
        $fixed = CategoryNavElement::buildModel(['source' => 'channel:' . $news]);
        self::assertSame(['Company', 'Industry'], array_column($fixed['items'], 'name'));
    }

    public function testPageContextAndCanvasChannelAreRecognised(): void
    {
        [$news, , $industry] = $this->newsTree();
        $model = PageTitleElement::withPage(['id' => $industry], static fn (): string => json_encode(CategoryNavElement::buildModel([])));
        self::assertSame('News', json_decode($model, true)['root']['name']);

        BlockRenderer::$editChannelId = $industry;
        self::assertSame('News', CategoryNavElement::buildModel([])['root']['name']);
        self::assertSame(1, $news, '夹具：新闻栏目恰好是 1 号');
        BlockRenderer::$editChannelId = 1;   // 首页 / 头尾模板的占位 id 不算栏目
        self::assertSame([], CategoryNavElement::buildModel([]));
    }

    public function testProductCenterListsProductCategoriesWithCounts(): void
    {
        $product = $this->channel('Products', 'product', 0, 'product');
        $pumps = $this->insertRow('product_categories', ['name' => 'Pumps', 'slug' => 'pumps', 'parent_id' => 0, 'status' => 1, 'is_nav' => 1, 'image' => '/uploads/pumps.webp']);
        $valves = $this->insertRow('product_categories', ['name' => 'Valves', 'slug' => 'valves', 'parent_id' => 0, 'status' => 1, 'is_nav' => 1]);
        $hidden = $this->insertRow('product_categories', ['name' => 'Hidden', 'slug' => 'hidden', 'parent_id' => 0, 'status' => 1, 'is_nav' => 0]);
        $mini = $this->insertRow('product_categories', ['name' => 'Mini pumps', 'slug' => 'mini', 'parent_id' => $pumps, 'status' => 1, 'is_nav' => 1]);
        foreach ([$pumps, $mini, $mini, $valves] as $i => $categoryId) {
            $this->insertRow('products', ['title' => 'P' . $i, 'category_id' => $categoryId, 'status' => 1, 'created_at' => time(), 'updated_at' => time()]);
        }

        // 产品详情页：只有所属分类，没有栏目上下文
        $GLOBALS['currentProductCategoryId'] = $mini;
        $model = CategoryNavElement::buildModel(['show_count' => '1']);
        self::assertSame('Products', $model['root']['name']);
        self::assertFalse($model['root']['active']);
        self::assertSame(['Pumps', 'Valves'], array_column($model['items'], 'name'), '不在导航里的分类不列');
        self::assertSame(3, $model['items'][0]['count'], '数量含子分类');
        self::assertSame(1, $model['items'][1]['count']);
        self::assertTrue($model['items'][0]['open']);
        self::assertTrue($model['items'][0]['children'][0]['active']);
        self::assertSame('/product/mini/', $model['items'][0]['children'][0]['url']);

        $html = CategoryNavElement::renderModel($model, ['show_image' => '1'], 'p');
        self::assertStringContainsString('<img src="/uploads/pumps.webp"', $html);
        self::assertMatchesRegularExpression('#>3</span>#', $html);
        unset($product, $hidden);
    }

    public function testInlineLayoutCompactStyleAndMobileSelect(): void
    {
        [, $company] = $this->newsTree();
        $GLOBALS['currentChannelId'] = $company;
        $model = CategoryNavElement::buildModel([]);

        $inline = CategoryNavElement::renderModel($model, ['layout' => 'inline', 'mobile_select' => '0'], 'i');
        self::assertStringContainsString('<ul class="flex-wrap gap-2 p-3 flex">', $inline);
        self::assertStringNotContainsString('Expo', $inline, '横排只列一级');
        self::assertStringNotContainsString('<select', $inline);

        $compact = CategoryNavElement::renderModel($model, ['style' => 'compact'], 'c');
        self::assertStringNotContainsString('rounded-lg border', $compact);
        self::assertStringContainsString('data-yk-category-nav-select', $compact);
        self::assertMatchesRegularExpression('#<option value="/company/" selected>Company</option>#', $compact);
        self::assertStringContainsString('<option value="/expo/">— Expo</option>', $compact);
        self::assertStringContainsString('<ul class="space-y-0.5 hidden md:block">', $compact, '有下拉框时列表小屏隐藏');
    }

    public function testUnsafeLinksAndNamesAreEscaped(): void
    {
        $root = $this->channel('Root', 'root');
        $this->channel('<b>x</b>', 'x', $root);
        $this->channel('Evil', 'evil', $root, 'link', ['link_url' => 'javascript:alert(1)']);
        $this->channel('Ext', 'ext', $root, 'link', ['link_url' => 'https://example.com/?a=1&b=2']);
        $html = CategoryNavElement::renderModel(CategoryNavElement::buildModel(['source' => 'channel:' . $root]), ['mobile_select' => '0'], 'e');
        self::assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $html, '外链地址只转义一次');
    }

    public function testEmptyRendersNothingOnFrontAndAHintInTheEditor(): void
    {
        $element = new CategoryNavElement();
        self::assertSame('', $element->render([]));
        BlockRenderer::$showHidden = true;
        self::assertStringContainsString('border-dashed', $element->render([]));
    }
}
