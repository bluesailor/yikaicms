<?php
/**
 * Tests for includes/TagEngine.php — 模板标签引擎 {yk:...}。
 *
 * 走共享内存 SQLite（同 Controllers 测试）：channels/contents/products 建最小表、
 * 种子数据，数据助手复用 tests/Controllers/_fixtures/helpers.php 的 DB 版；
 * fixtures 缺的 getContents / URL 助手 / e() 在下方全局块补齐（function_exists 防重复）。
 */

declare(strict_types=1);

namespace {

require_once dirname(__DIR__) . '/Controllers/_fixtures/helpers.php';

if (!function_exists('e')) {
    function e(?string $str): string
    {
        return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('getContents')) {
    function getContents(int $channelId = 0, int $limit = 10, int $offset = 0, array $where = []): array
    {
        return contentModel()->getList($channelId, $limit, $offset, $where + ['_skip_lang' => 1]);
    }
}
if (!function_exists('getContent')) {
    function getContent(int $id): ?array
    {
        return db()->fetchOne('SELECT * FROM contents WHERE id = ? AND status = 1', [$id]) ?: null;
    }
}
if (!function_exists('getProduct')) {
    function getProduct(int $id): ?array
    {
        return db()->fetchOne('SELECT * FROM products WHERE id = ? AND status = 1', [$id]) ?: null;
    }
}
if (!function_exists('contentUrl')) {
    function contentUrl(array $content): string
    {
        return '/news/article/' . ($content['id'] ?? 0) . '.html';
    }
}
if (!function_exists('productUrl')) {
    function productUrl(array $product): string
    {
        return '/product/' . ($product['id'] ?? 0) . '.html';
    }
}
if (!function_exists('channelUrl')) {
    function channelUrl(array $channel): string
    {
        return '/' . ($channel['slug'] ?? '') . '.html';
    }
}
if (!function_exists('renderBannerShortcode')) {
    function renderBannerShortcode(string $slug): string
    {
        return '<div class="banner-stub">' . $slug . '</div>';
    }
}
// 扩展字段回退：owner 直接用内容 type（自定义模型 key），值从 $GLOBALS['_tag_meta'] 取
if (!function_exists('resolveExtFieldOwner')) {
    function resolveExtFieldOwner(string $type): string
    {
        return $type === '' ? 'content' : $type;
    }
}
if (!function_exists('getMeta')) {
    function getMeta(string $ownerType, int $ownerId, string $key, mixed $default = null): mixed
    {
        return $GLOBALS['_tag_meta'][$ownerType][$ownerId][$key] ?? $default;
    }
}

require_once ROOT_PATH . '/includes/TagEngine.php';

} // end namespace {}

namespace Yikai\Tests\Unit {

use TagEngine;
use Yikai\Tests\TestCase;

final class TagEngineTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, type TEXT DEFAULT 'list',
                status INTEGER DEFAULT 1, is_nav INTEGER DEFAULT 1
            )",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
                title TEXT NOT NULL, slug TEXT, summary TEXT, cover TEXT, content TEXT,
                type TEXT DEFAULT 'article',
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN',
                deleted_at INTEGER DEFAULT NULL
            )",
            "CREATE TABLE product_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, status INTEGER DEFAULT 1
            )",
            "CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER DEFAULT 0,
                title TEXT NOT NULL, model TEXT, summary TEXT, cover TEXT,
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0,
                is_hot INTEGER DEFAULT 0, is_new INTEGER DEFAULT 0,
                sort_order INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN',
                deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_test_config'] = [];
        TagEngine::setItem(null); // 清掉上个测试遗留的「当前条目」，避免泄漏
    }

    private function seedNews(): void
    {
        $this->insertRow('channels', ['name' => '新闻', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'First', 'publish_time' => 200]);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Second', 'publish_time' => 100]);
    }

    // ---- 解析基础 ----

    public function testPlainTextPassesThrough(): void
    {
        $this->assertSame('hello <b>world</b>', TagEngine::render('hello <b>world</b>'));
        $this->assertSame('', TagEngine::render(''));
        $this->assertSame('', TagEngine::render(null));
    }

    public function testUnknownTagIsLeftUntouched(): void
    {
        $tpl = '{yk:nonexistent foo=1 /} and {yk:alsounknown}x{/yk:alsounknown}';
        $this->assertSame($tpl, TagEngine::render($tpl));
    }

    public function testParseAttrsQuotedSingleAndBare(): void
    {
        $attrs = TagEngine::parseAttrs(' type="article" cat=\'news\' limit=6 empty="暂无 内容"');
        $this->assertSame('article', $attrs['type']);
        $this->assertSame('news', $attrs['cat']);
        $this->assertSame('6', $attrs['limit']);
        $this->assertSame('暂无 内容', $attrs['empty']);
    }

    // ---- {yk:list} ----

    public function testListRendersInnerPerItem(): void
    {
        $this->seedNews();
        $out = TagEngine::render(
            '{yk:list type=article cat=news}<li data-i="{yk:field name=_index /}"><a href="{yk:field name=url /}">{yk:field name=title /}</a></li>{/yk:list}'
        );
        // 默认排序 publish_time DESC → First(200) 在前
        $this->assertSame(
            '<li data-i="1"><a href="/news/article/1.html">First</a></li>'
            . '<li data-i="2"><a href="/news/article/2.html">Second</a></li>',
            $out
        );
    }

    public function testListLimitAndOffset(): void
    {
        $this->seedNews();
        $out = TagEngine::render('{yk:list type=article cat=news limit=1 offset=1}{yk:field name=title /}{/yk:list}');
        $this->assertSame('Second', $out);
    }

    public function testListEmptyAttrShownWhenNoItems(): void
    {
        $this->insertRow('channels', ['name' => '空栏目', 'slug' => 'void', 'type' => 'list']);
        $out = TagEngine::render('{yk:list type=article cat=void empty="暂无内容"}<li>x</li>{/yk:list}');
        $this->assertSame('暂无内容', $out);
        $out = TagEngine::render('{yk:list type=article cat=void}<li>x</li>{/yk:list}');
        $this->assertSame('', $out);
    }

    public function testListUnknownCatSlugYieldsEmptyNotAllContents(): void
    {
        $this->seedNews(); // 库里有内容，但 cat 未命中不应退化成"查全部"
        $out = TagEngine::render('{yk:list type=article cat=no-such empty="none"}{yk:field name=title /}{/yk:list}');
        $this->assertSame('none', $out);
    }

    public function testListSkipsUnpublished(): void
    {
        $this->seedNews();
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Draft', 'status' => 0]);
        $out = TagEngine::render('{yk:list type=article cat=news}[{yk:field name=title /}]{/yk:list}');
        $this->assertSame('[First][Second]', $out);
    }

    public function testListRecommendFlagFilters(): void
    {
        $this->seedNews();
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Star', 'is_recommend' => 1]);
        $out = TagEngine::render('{yk:list type=article cat=news recommend=1}{yk:field name=title /}{/yk:list}');
        $this->assertSame('Star', $out);
    }

    public function testListProductTypeWithCategorySlug(): void
    {
        $this->insertRow('product_categories', ['name' => '软管', 'slug' => 'hose']);
        $this->insertRow('products', ['category_id' => 1, 'title' => 'Widget']);
        $this->insertRow('products', ['category_id' => 0, 'title' => 'Other']);
        $out = TagEngine::render('{yk:list type=product cat=hose}<a href="{yk:field name=url /}">{yk:field name=title /}</a>{/yk:list}');
        $this->assertSame('<a href="/product/1.html">Widget</a>', $out);
    }

    // ---- {yk:field} ----

    public function testFieldEscapesHtmlByDefault(): void
    {
        $this->insertRow('channels', ['name' => 'n', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => '<b>Bold</b>']);
        $out = TagEngine::render('{yk:list type=article cat=news}{yk:field name=title /}{/yk:list}');
        $this->assertSame('&lt;b&gt;Bold&lt;/b&gt;', $out);
    }

    public function testFieldEscZeroOutputsRaw(): void
    {
        $this->insertRow('channels', ['name' => 'n', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 't', 'content' => '<p>Body</p>']);
        $out = TagEngine::render('{yk:list type=article cat=news}{yk:field name=content esc=0 /}{/yk:list}');
        $this->assertSame('<p>Body</p>', $out);
    }

    public function testFieldLenTruncatesMultibyte(): void
    {
        $this->insertRow('channels', ['name' => 'n', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 't', 'summary' => '这是一段很长的中文摘要文字']);
        $out = TagEngine::render('{yk:list type=article cat=news}{yk:field name=summary len=5 /}{/yk:list}');
        $this->assertSame('这是一段很…', $out);
    }

    public function testFieldDateFormatsTimestamp(): void
    {
        $this->insertRow('channels', ['name' => 'n', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 't', 'publish_time' => 1750000000]);
        $out = TagEngine::render('{yk:list type=article cat=news}{yk:field name=date dateformat="Y" /}{/yk:list}');
        $this->assertSame(date('Y', 1750000000), $out);
    }

    public function testFieldOutsideListReturnsEmpty(): void
    {
        $this->assertSame('', TagEngine::render('{yk:field name=title /}'));
    }

    public function testFieldDefaultUsedWhenMissing(): void
    {
        $this->insertRow('channels', ['name' => 'n', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 't']);
        $out = TagEngine::render('{yk:list type=article cat=news}{yk:field name=author default="佚名" /}{/yk:list}');
        $this->assertSame('佚名', $out);
    }

    public function testFieldDefaultAlsoAppliesToEmptyValues(): void
    {
        $this->insertRow('channels', ['name' => 'n', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 't', 'summary' => '']);
        $out = TagEngine::render('{yk:list type=article cat=news}{yk:field name=summary default="暂无摘要" /}{/yk:list}');
        $this->assertSame('暂无摘要', $out);
        $encoded = TagEngine::render('{yk:list type=article cat=news}{yk:field name=summary fallback=No%20summary /}{/yk:list}');
        $this->assertSame('No summary', $encoded);
    }

    public function testListPaginationUsesIndependentQueryParamAndPreservesQueryString(): void
    {
        $this->insertRow('channels', ['name' => 'n', 'slug' => 'news', 'type' => 'list']);
        foreach ([500 => 'A', 400 => 'B', 300 => 'C', 200 => 'D', 100 => 'E'] as $time => $title) {
            $this->insertRow('contents', ['channel_id' => 1, 'title' => $title, 'publish_time' => $time]);
        }
        $oldGet = $_GET;
        $oldUri = $_SERVER['REQUEST_URI'] ?? null;
        $_GET['ykq_test'] = '2';
        $_SERVER['REQUEST_URI'] = '/news.html?foo=bar&ykq_test=2';
        try {
            $out = TagEngine::render(
                '{yk:list type=article cat=news limit=2 page_param=ykq_test}'
                . '[{yk:field name=title /}]{/yk:list}'
                . '{yk:list-pagination type=article cat=news limit=2 page_param=ykq_test /}'
            );
        } finally {
            $_GET = $oldGet;
            if ($oldUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $oldUri;
            }
        }

        $this->assertStringStartsWith('[C][D]', $out);
        $this->assertStringContainsString('aria-current="page">2</span>', $out);
        $this->assertStringContainsString('href="/news.html?foo=bar" rel="prev"', $out);
        $this->assertStringContainsString('foo=bar&amp;ykq_test=3', $out);
    }

    // ---- {yk:channel} / {yk:banner} / {yk:config} ----

    public function testChannelTagBySlugFieldUrl(): void
    {
        $this->insertRow('channels', ['name' => '关于我们', 'slug' => 'about', 'type' => 'page']);
        $this->assertSame('/about.html', TagEngine::render('{yk:channel slug=about field=url /}'));
        $this->assertSame('关于我们', TagEngine::render('{yk:channel slug=about /}'));
        $this->assertSame('', TagEngine::render('{yk:channel slug=missing /}'));
    }

    public function testBannerTagDelegatesToShortcodeRenderer(): void
    {
        $this->assertSame('<div class="banner-stub">home</div>', TagEngine::render('{yk:banner group=home /}'));
    }

    public function testConfigTagWhitelist(): void
    {
        $GLOBALS['_test_config'] = ['site_name' => '易凯', 'smtp_pass' => 'secret'];
        $this->assertSame('易凯', TagEngine::render('{yk:config name=site_name /}'));
        // 白名单外的键（如邮箱密码）绝不输出
        $this->assertSame('', TagEngine::render('{yk:config name=smtp_pass /}'));
        $this->assertSame('', TagEngine::render('{yk:config name=smtp_pass default=hidden /}'));
    }

    // ---- {yk:nav} ----

    public function testNavRendersTopLevelChannels(): void
    {
        $this->insertRow('channels', ['name' => '首页', 'slug' => 'home', 'type' => 'page', 'is_nav' => 1]);
        $this->insertRow('channels', ['name' => '关于', 'slug' => 'about', 'type' => 'page', 'is_nav' => 1]);
        $out = TagEngine::render('{yk:nav}<a href="{yk:field name=url /}">{yk:field name=name /}</a>{/yk:nav}');
        $this->assertSame('<a href="/home.html">首页</a><a href="/about.html">关于</a>', $out);
    }

    public function testNavParentBySlug(): void
    {
        $this->insertRow('channels', ['name' => '产品', 'slug' => 'product', 'type' => 'product', 'is_nav' => 1]);
        $this->insertRow('channels', ['name' => '子类A', 'slug' => 'cat-a', 'parent_id' => 1, 'is_nav' => 1]);
        $this->insertRow('channels', ['name' => '子类B', 'slug' => 'cat-b', 'parent_id' => 1, 'is_nav' => 1]);
        $out = TagEngine::render('{yk:nav parent=product}[{yk:field name=name /}]{/yk:nav}');
        $this->assertSame('[子类A][子类B]', $out);
    }

    public function testNavNavOnlyFilter(): void
    {
        $this->insertRow('channels', ['name' => '显示', 'slug' => 'shown', 'is_nav' => 1]);
        $this->insertRow('channels', ['name' => '隐藏', 'slug' => 'hidden', 'is_nav' => 0]);
        // 默认 nav_only=1 只出导航栏目
        $this->assertSame('显示', TagEngine::render('{yk:nav}{yk:field name=name /}{/yk:nav}'));
        // nav_only=0 全部
        $this->assertSame('显示隐藏', TagEngine::render('{yk:nav nav_only=0}{yk:field name=name /}{/yk:nav}'));
    }

    public function testNavUnknownParentSlugEmpty(): void
    {
        $this->insertRow('channels', ['name' => 'X', 'slug' => 'x', 'is_nav' => 1]);
        $out = TagEngine::render('{yk:nav parent=no-such empty="无"}{yk:field name=name /}{/yk:nav}');
        $this->assertSame('无', $out);
    }

    // ---- {yk:subnav} 多级下拉（r9）----

    /** 建两层树：产品(1){子类A(2),子类B(3)} + 关于(4，叶子) */
    private function seedNavTree(): void
    {
        $this->insertRow('channels', ['name' => '产品', 'slug' => 'product', 'type' => 'product', 'is_nav' => 1]);
        $this->insertRow('channels', ['name' => '子类A', 'slug' => 'cat-a', 'parent_id' => 1, 'is_nav' => 1]);
        $this->insertRow('channels', ['name' => '子类B', 'slug' => 'cat-b', 'parent_id' => 1, 'is_nav' => 1]);
        $this->insertRow('channels', ['name' => '关于', 'slug' => 'about', 'type' => 'page', 'is_nav' => 1]);
    }

    /** 黄金对拍：不含 {yk:subnav}/has_children 的单层模板，输出与 r8 前逐字节一致（子级不泄漏） */
    public function testNavSingleLevelOutputUnchangedOnTree(): void
    {
        $this->seedNavTree();
        $out = TagEngine::render('{yk:nav}<a href="{yk:field name=url /}">{yk:field name=name /}</a>{/yk:nav}');
        $this->assertSame('<a href="/product.html">产品</a><a href="/about.html">关于</a>', $out);
    }

    public function testSubnavLoopsChildrenInsideNav(): void
    {
        $this->seedNavTree();
        $out = TagEngine::render('{yk:nav}<li>{yk:field name=name /}{yk:subnav wrap=ul class="dd"}<i>{yk:field name=name /}</i>{/yk:subnav}</li>{/yk:nav}');
        $this->assertSame('<li>产品<ul class="dd"><i>子类A</i><i>子类B</i></ul></li><li>关于</li>', $out);
    }

    /** 叶子栏目：连 wrap 包裹一起省略（悬停下拉不出空面板） */
    public function testSubnavLeafOmitsWrapper(): void
    {
        $this->insertRow('channels', ['name' => '关于', 'slug' => 'about', 'is_nav' => 1]);
        $out = TagEngine::render('{yk:nav}{yk:subnav wrap=ul class="dd"}x{/yk:subnav}{/yk:nav}');
        $this->assertSame('', $out);
    }

    public function testNavHasChildrenConditionalCaret(): void
    {
        $this->seedNavTree();
        $out = TagEngine::render('{yk:nav}[{yk:field name=name /}{yk:if field=has_children op=eq value=1}▾{/yk:if}]{/yk:nav}');
        $this->assertSame('[产品▾][关于]', $out);
    }

    /** subnav 显式 nav_only=0 时不吃 nav 的预取缓存，重查全部子级 */
    public function testSubnavNavOnlyOverrideBypassesCache(): void
    {
        $this->seedNavTree();
        $this->insertRow('channels', ['name' => '隐藏子类', 'slug' => 'cat-c', 'parent_id' => 1, 'is_nav' => 0]);
        $out = TagEngine::render('{yk:nav parent=0 limit=1}{yk:subnav nav_only=0}[{yk:field name=name /}]{/yk:subnav}{/yk:nav}');
        $this->assertSame('[子类A][子类B][隐藏子类]', $out);
    }

    public function testSubnavLimit(): void
    {
        $this->seedNavTree();
        $out = TagEngine::render('{yk:nav limit=1}{yk:subnav limit=1}[{yk:field name=name /}]{/yk:subnav}{/yk:nav}');
        $this->assertSame('[子类A]', $out);
    }

    /** 非栏目上下文（文章正文里误用）安全空输出 */
    public function testSubnavOutsideChannelContextEmpty(): void
    {
        TagEngine::setItem(['id' => 5, 'title' => 'A'], 'content');
        $out = TagEngine::render('{yk:subnav empty="无"}x{/yk:subnav}');
        $this->assertSame('无', $out);
        TagEngine::setItem(null);
    }

    // ---- 自定义模型：{yk:list type=<key>} 过滤 + {yk:field} meta 回退 ----

    public function testListFiltersByCustomModelType(): void
    {
        $this->insertRow('channels', ['name' => '团队', 'slug' => 'team', 'type' => 'team']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Alice', 'type' => 'team']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Draft', 'type' => 'article']); // 同栏目但非 team
        $out = TagEngine::render('{yk:list type=team cat=team}[{yk:field name=title /}]{/yk:list}');
        $this->assertSame('[Alice]', $out); // 只出 type=team 的
    }

    public function testFieldMetaFallbackForCustomField(): void
    {
        $this->insertRow('channels', ['name' => '团队', 'slug' => 'team', 'type' => 'team']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Alice', 'type' => 'team']);
        // 自定义字段 role 不是 contents 原生列 → 回退查 meta（owner=team, id=1）
        $GLOBALS['_tag_meta'] = ['team' => [1 => ['role' => 'CTO']]];
        $out = TagEngine::render('{yk:list type=team cat=team}{yk:field name=title /}-{yk:field name=role /}{/yk:list}');
        $this->assertSame('Alice-CTO', $out);
        unset($GLOBALS['_tag_meta']);
    }

    public function testFieldMetaFallbackDefaultWhenMissing(): void
    {
        $this->insertRow('channels', ['name' => '团队', 'slug' => 'team', 'type' => 'team']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Bob', 'type' => 'team']);
        $out = TagEngine::render('{yk:list type=team cat=team}{yk:field name=role default="员工" /}{/yk:list}');
        $this->assertSame('员工', $out); // meta 无值 → default
    }

    // ---- {yk:if} / {yk:else} ----

    public function testIfNotemptyDefault(): void
    {
        TagEngine::setItem(['id' => 1, 'is_hot' => 1, 'sub' => '']);
        $this->assertSame('HOT', TagEngine::render('{yk:if field=is_hot}HOT{/yk:if}'));
        $this->assertSame('', TagEngine::render('{yk:if field=sub}X{/yk:if}'));       // 空 → 不渲染
        $this->assertSame('', TagEngine::render('{yk:if field=missing}X{/yk:if}'));   // 缺字段 → 不渲染
    }

    public function testIfElseBranch(): void
    {
        $tpl = '{yk:if field=price op=lte value=0}免费{yk:else/}收费{/yk:if}';
        TagEngine::setItem(['id' => 1, 'price' => '0']);
        $this->assertSame('免费', TagEngine::render($tpl));
        TagEngine::setItem(['id' => 1, 'price' => '100']);
        $this->assertSame('收费', TagEngine::render($tpl));
    }

    public function testIfComparisonOps(): void
    {
        TagEngine::setItem(['id' => 1, 'views' => '50', 'title' => 'Hello World']);
        $this->assertSame('Y', TagEngine::render('{yk:if field=views op=gt value=10}Y{yk:else/}N{/yk:if}'));
        $this->assertSame('N', TagEngine::render('{yk:if field=views op=lt value=10}Y{yk:else/}N{/yk:if}'));
        $this->assertSame('Y', TagEngine::render('{yk:if field=views op=eq value=50}Y{/yk:if}'));
        $this->assertSame('Y', TagEngine::render('{yk:if field=title op=contains value=World}Y{/yk:if}'));
        $this->assertSame('Y', TagEngine::render('{yk:if field=views op=in value="10,50,99"}Y{/yk:if}'));
    }

    public function testIfConfigCondition(): void
    {
        $GLOBALS['_test_config'] = ['show_price' => '1'];
        $this->assertSame('P', TagEngine::render('{yk:if config=show_price value=1}P{/yk:if}'));
        $this->assertSame('', TagEngine::render('{yk:if config=show_price value=0}P{/yk:if}'));
    }

    public function testIfInsideListPerRow(): void
    {
        $this->seedNews(); // First(pt200), Second(pt100)
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Star', 'is_hot' => 1, 'publish_time' => 50]);
        $out = TagEngine::render('{yk:list type=article cat=news}{yk:field name=title /}{yk:if field=is_hot}★{/yk:if} {/yk:list}');
        $this->assertSame('First Second Star★ ', $out);
    }

    // ---- 当前条目上下文（setItem）：正文里可直接用 {yk:field}/{yk:if} ----

    public function testFieldUsesCurrentItemOutsideList(): void
    {
        TagEngine::setItem(['id' => 7, 'title' => '本篇', '_type' => 'content']);
        $this->assertSame('本篇', TagEngine::render('{yk:field name=title /}'));
        $this->assertSame('/news/article/7.html', TagEngine::render('{yk:field name=url /}'));
    }

    // ---- {yk:list id=} 指定条目 ----

    public function testListByExplicitIdsInGivenOrder(): void
    {
        $this->seedNews(); // 1=First, 2=Second
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Third']); // id 3
        $out = TagEngine::render('{yk:list type=article id="3,1"}[{yk:field name=title /}]{/yk:list}');
        $this->assertSame('[Third][First]', $out);
    }

    // ---- {yk:list related=} 相关内容（同栏目、排除本篇）----

    public function testListRelatedExcludesCurrentItem(): void
    {
        $this->seedNews(); // ch1: First(id1,pt200), Second(id2,pt100)
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Third', 'publish_time' => 90]); // id3
        TagEngine::setItem(['id' => 1, 'channel_id' => 1, 'type' => 'article', '_type' => 'content']);
        $out = TagEngine::render('{yk:list type=article related=1}[{yk:field name=title /}]{/yk:list}');
        $this->assertSame('[Second][Third]', $out); // 排除本篇 id1，按 publish_time DESC
    }
}

} // end namespace Yikai\Tests\Unit
