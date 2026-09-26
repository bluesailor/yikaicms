<?php
/**
 * 导航「当前位置」唯一判定（includes/NavCurrent.php）：
 *   - 链接落点即当前页 → 'page'；首页虚拟项只认首页上下文
 *   - 栏目页 → 'page'；详情页 / 旧 $currentSlug 约定 / 子项命中 → 'true'（所在区域）
 *   - 动态 URL 模式（/index.php?yk_route=…）下首页链接不能处处命中
 *   - 导航元素把状态落到 aria-current 与高亮类上
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use NavCurrent;
use NavElement;
use NavMegaElement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class NavCurrentTest extends TestCase
{
    protected function setUp(): void
    {
        NavCurrent::reset();
        unset($GLOBALS['currentChannelId'], $GLOBALS['currentSlug'], $GLOBALS['isHomePage']);
        $_SERVER['HTTP_HOST'] = 'demo.test';
    }

    protected function tearDown(): void
    {
        NavCurrent::reset();
        unset($_SERVER['HTTP_HOST']);
    }

    public function testHomeItemFollowsHomeContextOnly(): void
    {
        $home = ['_is_home' => true, '_url' => '/'];
        NavCurrent::capture(0, '', true);
        $this->assertSame('page', NavCurrent::state($home, '/'));
        NavCurrent::capture(5, '', false);
        $this->assertSame('', NavCurrent::state($home, '/about.html'));
    }

    public function testLinkLandingIsCurrentPage(): void
    {
        $about = ['_url' => '/about.html'];
        $this->assertSame('page', NavCurrent::state($about, '/about.html'));
        $this->assertSame('page', NavCurrent::state($about, '/about.html?utm_source=x'));
        $this->assertSame('', NavCurrent::state($about, '/contact.html'));
    }

    public function testPathNormalisationAndSameHostAbsoluteLinks(): void
    {
        $this->assertSame('page', NavCurrent::state(['_url' => '/ja/about/'], '/ja/about'));
        $this->assertSame('page', NavCurrent::state(['_url' => 'https://demo.test/news/'], '/news'));
        $this->assertSame('', NavCurrent::state(['_url' => 'https://other.test/news/'], '/news'));
        $this->assertSame('', NavCurrent::state(['_url' => 'mailto:hi@demo.test'], '/'));
        $this->assertSame('', NavCurrent::state(['_url' => '#'], '/'));
        // channelUrl() 对外链栏目返回已转义的 URL
        $this->assertSame('page', NavCurrent::state(['_url' => '/list.php?id=3&amp;lang=ja'], '/list.php?lang=ja&id=3'));
    }

    public function testQueryLinksMatchAsSubset(): void
    {
        $node = ['_url' => '/index.php?yk_route=list&id=3'];
        $this->assertSame('page', NavCurrent::state($node, '/index.php?yk_route=list&id=3&page=2'));
        $this->assertSame('', NavCurrent::state($node, '/index.php?yk_route=list&id=4'));
    }

    public function testRootLinkDoesNotMatchEveryDynamicRoute(): void
    {
        $root = ['_url' => '/'];   // 菜单组里的自定义「首页」链接
        $this->assertSame('', NavCurrent::state($root, '/index.php?yk_route=list&id=3'));
        NavCurrent::capture(0, '', true);
        $this->assertSame('page', NavCurrent::state($root, '/'));
    }

    public function testChannelPageVersusDetailPage(): void
    {
        $news = ['id' => 7, 'slug' => 'news', '_url' => '/news/'];
        NavCurrent::capture(7);
        $this->assertSame('page', NavCurrent::state($news, '/news/page/2'));
        NavCurrent::markDetail();
        $this->assertSame('true', NavCurrent::state($news, '/news/article/9.html'));
    }

    public function testLegacySlugContextMeansSectionOnly(): void
    {
        NavCurrent::capture(0, 'product');
        $this->assertSame('true', NavCurrent::state(['id' => 2, 'slug' => 'product', '_url' => '/product/'], '/cart'));
        // 产品分类节点 id=0：不因 slug 碰撞误亮
        $this->assertSame('', NavCurrent::state(['id' => 0, 'slug' => 'product', '_url' => '/product/c1/'], '/cart'));
    }

    public function testChildHitMarksParentAsSection(): void
    {
        $product = ['id' => 2, 'slug' => 'product', '_url' => '/product/', 'children' => [
            ['id' => 0, '_url' => '/product/pumps/', 'children' => []],
        ]];
        $this->assertSame('true', NavCurrent::state($product, '/product/pumps/'));
        // 同时是当前栏目时，子项已是当前页 → 父项降为所在区域，避免两个 aria-current="page"
        NavCurrent::capture(2);
        $this->assertSame('true', NavCurrent::state($product, '/product/pumps/'));
    }

    public function testGlobalsFallbackWithoutCapture(): void
    {
        $GLOBALS['currentChannelId'] = 4;
        $this->assertSame('page', NavCurrent::state(['id' => 4, '_url' => '/about.html'], '/elsewhere'));
        $GLOBALS['isHomePage'] = true;
        $this->assertSame('page', NavCurrent::state(['_is_home' => true, '_url' => '/'], '/'));
    }

    public function testAttr(): void
    {
        $this->assertSame(' aria-current="page"', NavCurrent::attr('page'));
        $this->assertSame(' aria-current="true"', NavCurrent::attr('true'));
        $this->assertSame('', NavCurrent::attr(''));
        $this->assertSame('', NavCurrent::attr('bogus'));
    }

    public function testNavElementMenuNodeMarksCurrentAndSection(): void
    {
        $_SERVER['REQUEST_URI'] = '/product/pumps/';
        $render = new ReflectionMethod(NavElement::class, 'renderMenuNode');
        $render->setAccessible(true);
        $node = ['name' => '产品', '_url' => '/product/', 'children' => [
            ['name' => '水泵', '_url' => '/product/pumps/'],
            ['name' => '阀门', '_url' => '/product/valves/'],
        ]];
        $html = $render->invoke(new NavElement(), $node, true, false);
        $this->assertStringContainsString('href="/product/" aria-current="true" class="inline-flex items-center gap-1 text-primary"', $html);
        $this->assertStringContainsString('href="/product/pumps/" aria-current="page"', $html);
        $this->assertStringNotContainsString('href="/product/valves/" aria-current', $html);

        $leaf = $render->invoke(new NavElement(), ['name' => '关于', '_url' => '/about.html'], false, false);
        $this->assertSame('<li><a href="/about.html" class="hover:text-primary">关于</a></li>', $leaf);
        unset($_SERVER['REQUEST_URI']);
    }

    public function testNavElementTemplateCarriesCurrentFields(): void
    {
        $markup = (new NavElement())->buildMarkup(['dropdown' => true]);
        $this->assertStringContainsString('{yk:if field=is_active op=eq value=1} aria-current="{yk:field name=nav_current /}"', $markup);
        $this->assertSame(2, substr_count($markup, 'aria-current='));
    }

    public function testMegaColumnMarksCurrentGrandchild(): void
    {
        $_SERVER['REQUEST_URI'] = '/solutions/water/';
        $column = new ReflectionMethod(NavMegaElement::class, 'renderColumn');
        $column->setAccessible(true);
        $html = $column->invoke(new NavMegaElement(), ['name' => '方案', '_url' => '/solutions/', 'children' => [
            ['name' => '水处理', '_url' => '/solutions/water/'],
        ]], false, false);
        $this->assertStringContainsString('href="/solutions/" aria-current="true"', $html);
        $this->assertStringContainsString('href="/solutions/water/" aria-current="page"', $html);
        unset($_SERVER['REQUEST_URI']);
    }
}
