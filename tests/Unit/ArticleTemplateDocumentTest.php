<?php
/**
 * ArticleTemplateDocument + ArticleFieldElement 契约测试（DS-BLOX-01C）。
 *
 * 覆盖：控制器输出 → 渲染上下文归一、起始布局默认不应用、只切输出源、
 * 渲染上下文 try/finally 保护、空值逐项隐藏、伪造绑定字段被剔除。
 */
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class ArticleTemplateDocumentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    /** ContentDetailController::prepare() 的返回形状（字段照抄控制器）。 */
    private static function controllerVars(): array
    {
        return [
            'content' => [
                'id' => 77,
                'title' => '示例文章',
                'summary' => '摘要文字',
                'cover' => '/uploads/cover.jpg',
                'content' => '<p>正文</p>',
                'author' => '编辑部',
                'tags' => 'a,b',
                'views' => 12,
                'lang' => 'zh-CN',
                'publish_time' => 1700000000,
                'created_at' => 1690000000,
                'channel_id' => 5,
                'channel_name' => '公司新闻',
                'channel_slug' => 'company-news',
                'channel_type' => 'list',
            ],
            'channel' => ['id' => 5, 'name' => '公司新闻', 'slug' => 'company-news', 'type' => 'list'],
            'channelId' => 5,
            'prevContent' => ['id' => 76, 'title' => '上一篇', 'slug' => 'prev'],
            'nextContent' => ['id' => 78, 'title' => '下一篇', 'slug' => 'next'],
            'relatedContents' => [['id' => 79, 'title' => '相关一', 'slug' => 'rel']],
        ];
    }

    public function testContextNormalizesControllerOutputToSingleContract(): void
    {
        $ctx = ArticleTemplateDocument::contextFrom(self::controllerVars());

        $this->assertSame(77, $ctx['id']);
        $this->assertSame('示例文章', $ctx['title']);
        $this->assertSame('摘要文字', $ctx['summary']);
        $this->assertSame('/uploads/cover.jpg', $ctx['cover']);
        $this->assertSame('公司新闻', $ctx['channel_name']);
        $this->assertSame('list', $ctx['channel_type']);
        $this->assertSame(5, $ctx['channel_id']);
        // 控制器用的是 camelCase 键名，元素只认这里的统一键名
        $this->assertSame(76, $ctx['prev']['id']);
        $this->assertSame(78, $ctx['next']['id']);
        $this->assertCount(1, $ctx['related']);
    }

    public function testContextToleratesMissingOptionalFields(): void
    {
        $ctx = ArticleTemplateDocument::contextFrom(['content' => ['id' => 5]]);
        $this->assertSame(5, $ctx['id']);
        $this->assertSame('', $ctx['summary']);
        $this->assertSame('', $ctx['cover']);
        $this->assertNull($ctx['prev']);
        $this->assertNull($ctx['next']);
        $this->assertSame([], $ctx['related']);
    }

    public function testSeedIsArticleScopedAndNotAppliedByDefault(): void
    {
        $json = ArticleTemplateDocument::seed('ja');
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $scope = $decoded['settings']['detail_template'];

        $this->assertSame(DetailTemplateResolver::VERSION, $scope['version']);
        $this->assertSame('article', $scope['content_type']);
        $this->assertSame('ja', $scope['lang']);
        // 「复制为自定义模板」默认不全站应用：include 为空 = 未应用
        $this->assertSame([], $scope['include']);
        $this->assertSame('no_candidate', DetailTemplateResolver::resolve(
            [['id' => 1, 'type' => 'article-detail', 'status' => 1, 'scope' => DetailTemplateResolver::normalizeScope($scope)]],
            ['content_type' => 'article', 'content_id' => 77, 'lang' => 'ja', 'categories' => []]
        )['reason']);

        // 起始布局用动态字段，不写死示例内容
        $types = [];
        foreach ($decoded['sections'] as $section) {
            foreach ($section['columns'] as $column) {
                foreach ($column['elements'] as $element) {
                    $types[] = $element['type'];
                }
            }
        }
        $this->assertContains('article-title', $types);
        $this->assertContains('article-content', $types);
        $this->assertStringNotContainsString('示例', $json);
    }

    public function testChangeSourceKeepsScopeAndOnlySwitchesOutput(): void
    {
        $seed = ArticleTemplateDocument::seed('zh-CN');
        $native = ArticleTemplateDocument::changeSource($seed, 'native');
        $decoded = json_decode($native, true, 512, JSON_THROW_ON_ERROR);
        $scope = $decoded['settings']['detail_template'];

        $this->assertSame('native', $scope['source']);
        $this->assertSame('article', $scope['content_type'], '切换输出源不得改动作用域');
        $this->assertSame('zh-CN', $scope['lang']);
        $this->assertSame([], $scope['include']);
        $this->assertArrayNotHasKey('legacy', $scope, '内部按标记不落盘');

        // 切回自定义
        $custom = json_decode(ArticleTemplateDocument::changeSource($native, 'custom'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('custom', $custom['settings']['detail_template']['source']);

        $this->expectException(InvalidArgumentException::class);
        ArticleTemplateDocument::changeSource($seed, 'nonsense');
    }

    public function testWithContentRestoresPreviousContextEvenOnFailure(): void
    {
        $this->assertNull(ArticleTemplateDocument::currentContent());

        $first = ['id' => 1, 'title' => 'A'];
        $second = ['id' => 2, 'title' => 'B'];

        $seen = [];
        $html = ArticleTemplateDocument::withContent($first, static function () use (&$seen, $second): string {
            $seen[] = ArticleTemplateDocument::currentContent()['id'];
            // 嵌套渲染：内层用第二篇，退出后必须回到第一篇
            ArticleTemplateDocument::withContent($second, static function () use (&$seen): string {
                $seen[] = ArticleTemplateDocument::currentContent()['id'];
                return '';
            });
            $seen[] = ArticleTemplateDocument::currentContent()['id'];
            return '<p>ok</p>';
        });

        $this->assertSame('<p>ok</p>', $html);
        $this->assertSame([1, 2, 1], $seen, '嵌套渲染后上下文必须恢复');
        $this->assertNull(ArticleTemplateDocument::currentContent(), '退出后不得泄漏上下文');

        // 渲染抛异常也要恢复（try/finally）
        try {
            ArticleTemplateDocument::withContent($first, static function (): string {
                throw new RuntimeException('boom');
            });
            $this->fail('expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertNull(ArticleTemplateDocument::currentContent());
    }

    public function testFieldsRenderOnlyWhenValueExists(): void
    {
        $title = new ArticleFieldElement('title');
        $summary = new ArticleFieldElement('summary');
        $cover = new ArticleFieldElement('cover');
        $prevNext = new ArticleFieldElement('prev-next');
        $related = new ArticleFieldElement('related');
        $content = new ArticleFieldElement('content');

        $vars = self::controllerVars();
        $full = ArticleTemplateDocument::contextFrom($vars);
        $bare = ArticleTemplateDocument::contextFrom(['content' => ['id' => 5, 'title' => '只有标题']]);

        // 无上下文（渲染期外）一律空串
        $this->assertSame('', $title->render([]));

        ArticleTemplateDocument::withContent($bare, function () use ($title, $summary, $cover, $prevNext, $related, $content): string {
            $this->assertStringContainsString('只有标题', $title->render([]));
            $this->assertSame('', $summary->render([]), '无摘要不渲染空段落');
            $this->assertSame('', $cover->render([]), '无封面不显示占位图');
            $this->assertSame('', $prevNext->render([]), '无上下一篇整块隐藏');
            $this->assertSame('', $related->render([]), '无相关文章整块隐藏');
            $this->assertSame('', $content->render([]), '无正文不渲染空块');
            return '';
        });

        ArticleTemplateDocument::withContent($full, function () use ($summary, $cover, $prevNext, $related, $content): string {
            $this->assertStringContainsString('摘要文字', $summary->render([]));
            $this->assertStringContainsString('/uploads/cover.jpg', $cover->render([]));
            $this->assertStringContainsString('上一篇', $prevNext->render([]));
            $this->assertStringContainsString('下一篇', $prevNext->render([]));
            $this->assertStringContainsString('相关一', $related->render([]));
            $this->assertStringContainsString('<p>正文</p>', $content->render([]));
            return '';
        });
    }

    public function testFieldsIgnoreForgedBindingKeys(): void
    {
        $title = new ArticleFieldElement('title');
        $ctx = ArticleTemplateDocument::contextFrom(self::controllerVars());

        ArticleTemplateDocument::withContent($ctx, function () use ($title): string {
            // 旧导入文档/伪造数据里的绑定键必须被剔除，动态字段只输出当前文章
            $html = $title->render(['site_field' => 'site_name', 'site_name' => '站点名', 'loop_title' => 'X']);
            $this->assertStringContainsString('示例文章', $html);
            $this->assertStringNotContainsString('站点名', $html);
            return '';
        });
    }

    public function testPaletteVisibilityIsArticleTemplateOnly(): void
    {
        $element = new ArticleFieldElement('title');
        $this->assertTrue($element->paletteVisible('article-detail'));
        $this->assertFalse($element->paletteVisible('page'));
        $this->assertFalse($element->paletteVisible('product-detail'));
        $this->assertTrue($element->isDynamic());
        $this->assertSame('article-title', $element->type());
    }
}
