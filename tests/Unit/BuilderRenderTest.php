<?php
/**
 * Tests for the page-builder engine (includes/builder/*).
 *
 * 锁定各元素与段/列包裹的 HTML 输出（黄金对拍的固化版：迁移时已验证与旧 renderBlocksToHtml
 * 逐字节一致，这里把关键输出固定下来，防回归）。引擎自包含（仅用 htmlspecialchars），无需 DB。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use BlockRenderer;
use BuilderRegistry;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BuilderRenderTest extends TestCase
{
    /** 包一个单列单元素的 section，返回完整渲染 */
    private function oneEl(array $el, array $sectionSettings = []): string
    {
        return BlockRenderer::render(json_encode([[
            'settings' => $sectionSettings,
            'columns'  => [['elements' => [$el]]],
        ]]));
    }

    /** 从单列 section 输出里剥掉固定的段/列外壳，取元素部分 */
    private function inner(string $full): string
    {
        $open = '<section class="py-8"><div class="max-w-6xl mx-auto px-4">';
        $close = '</div></section>';
        $this->assertStringStartsWith($open, $full);
        $this->assertStringEndsWith($close, $full);
        return substr($full, strlen($open), -strlen($close));
    }

    public function testEmptyAndInvalid(): void
    {
        $this->assertSame('', BlockRenderer::render(''));
        $this->assertSame('', BlockRenderer::render('[]'));
        $this->assertSame('', BlockRenderer::render('not-json'));
    }

    public function testHeading(): void
    {
        $out = $this->inner($this->oneEl(['type' => 'heading', 'data' => ['level' => 'h1', 'text' => 'Hi <x>']]));
        $this->assertSame('<h1 class="text-3xl font-bold mb-4">Hi &lt;x&gt;</h1>', $out);
        // 非法 level 回退 h2
        $out2 = $this->inner($this->oneEl(['type' => 'heading', 'data' => ['level' => 'h9', 'text' => 'A']]));
        $this->assertSame('<h2 class="text-2xl font-bold mb-4">A</h2>', $out2);
    }

    public function testText(): void
    {
        $out = $this->inner($this->oneEl(['type' => 'text', 'data' => ['html' => '<p>x</p>']]));
        $this->assertSame('<div class="prose prose-lg max-w-none"><p>x</p></div>', $out);
    }

    public function testImageVariants(): void
    {
        // 无 src → 空
        $this->assertSame('', $this->inner($this->oneEl(['type' => 'image', 'data' => []])));
        // lightbox
        $out = $this->inner($this->oneEl(['type' => 'image', 'data' => ['src' => '/a.jpg', 'alt' => 'A', 'click_action' => 'lightbox']]));
        $this->assertSame('<a href="/a.jpg" data-lightbox class="block cursor-zoom-in"><img class="w-full rounded-lg" src="/a.jpg" alt="A" loading="lazy"></a>', $out);
        // link + new tab
        $out2 = $this->inner($this->oneEl(['type' => 'image', 'data' => ['src' => '/b.jpg', 'click_action' => 'link', 'link_url' => '/x', 'link_new_tab' => 1]]));
        $this->assertStringContainsString('<a href="/x" target="_blank" rel="noopener" class="block">', $out2);
    }

    public function testButton(): void
    {
        $out = $this->inner($this->oneEl(['type' => 'button', 'data' => ['text' => 'Go', 'url' => '/go', 'new_tab' => 1]]));
        $this->assertStringContainsString('href="/go" target="_blank" rel="noopener"', $out);
        $this->assertStringContainsString('>Go</a>', $out);
    }

    public function testIconWithFeatherAlias(): void
    {
        $out = $this->inner($this->oneEl(['type' => 'icon', 'data' => ['icon' => 'zap', 'size' => 'lg', 'text' => 'T']]));
        // feather zap → tabler bolt；lg → 48px
        $this->assertStringContainsString('ti ti-bolt', $out);
        $this->assertStringContainsString('font-size:48px', $out);
        $this->assertStringContainsString('<div class="mt-1 text-sm">T</div>', $out);
    }

    public function testCodeRaw(): void
    {
        $out = $this->inner($this->oneEl(['type' => 'code', 'data' => ['html' => '<iframe></iframe>']]));
        $this->assertSame('<iframe></iframe>', $out);
    }

    public function testDividerAndSpacer(): void
    {
        $d = $this->inner($this->oneEl(['type' => 'divider', 'data' => ['style' => 'dashed', 'width' => 2, 'color' => '#333', 'spacing' => 'lg']]));
        $this->assertSame('<hr class="my-8 border-0" style="border-top:2px dashed #333">', $d);
        $s = $this->inner($this->oneEl(['type' => 'spacer', 'data' => ['size' => 'xl']]));
        $this->assertSame('<div class="h-24"></div>', $s);
    }

    public function testUnknownTypeSkipped(): void
    {
        $this->assertSame('', $this->inner($this->oneEl(['type' => 'no_such', 'data' => []])));
    }

    public function testSectionBgOpacityRgba(): void
    {
        $full = $this->oneEl(['type' => 'heading', 'data' => ['text' => 'A']], ['bg_color' => '#ff8800', 'bg_opacity' => 50]);
        $this->assertStringContainsString('style="background-color:rgba(255,136,0,0.5);"', $full);
    }

    public function testMultiColumnGridAndCard(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => ['gap' => 'md', 'col_card' => 1],
            'columns'  => [
                ['elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]],
                ['elements' => [['type' => 'heading', 'data' => ['text' => 'B']]]],
            ],
        ]]));
        $this->assertStringContainsString('grid grid-cols-1 md:grid-cols-2 gap-4', $out);
        $this->assertStringContainsString('bg-white rounded-xl border border-gray-100 shadow-sm p-6 h-full text-center', $out);
    }

    public function testRegistryHasBuiltins(): void
    {
        $types = array_keys(BuilderRegistry::all());
        foreach (['heading', 'text', 'image', 'button', 'icon', 'code', 'divider', 'spacer',
                  'list-dynamic', 'banner', 'nav'] as $t) {
            $this->assertContains($t, $types);
        }
    }

    // ---- schema：controls() / defaults() / meta()（后台 palette+表单据此驱动） ----

    public function testControlsAndDefaults(): void
    {
        $h = BuilderRegistry::get('heading');
        $keys = array_column($h->controls(), 'key');
        $this->assertSame(['text', 'level'], $keys);
        // defaults 从 controls 推导
        $this->assertSame(['text' => '', 'level' => 'h2'], $h->defaults());
    }

    public function testMetaShape(): void
    {
        $meta = BuilderRegistry::meta();
        // >=11：其它测试可能注册了临时元素（静态注册表跨测试持久），只断言内置齐全
        $this->assertGreaterThanOrEqual(11, count($meta));
        foreach (['heading', 'text', 'image', 'button', 'icon', 'code', 'divider', 'spacer', 'list-dynamic', 'banner', 'nav'] as $t) {
            $this->assertArrayHasKey($t, $meta);
        }
        $this->assertArrayHasKey('list-dynamic', $meta);
        $ld = $meta['list-dynamic'];
        $this->assertSame('动态列表', $ld['label']);
        $this->assertSame('dynamic', $ld['category']);
        $this->assertTrue($ld['dynamic']);
        $this->assertNotEmpty($ld['controls']);
        // 每个控件都有 key/type
        foreach ($ld['controls'] as $c) {
            $this->assertArrayHasKey('key', $c);
            $this->assertArrayHasKey('type', $c);
        }
    }

    public function testPluginElementAutoRegisters(): void
    {
        // 模拟插件注册一个只声明 controls 的新元素 → 无需手写 UI，defaults/meta 自动可用
        $el = new class extends \AbstractElement {
            public function type(): string { return 'test-plugin-el'; }
            public function controls(): array
            {
                return [['key' => 'title', 'type' => 'text', 'label' => '标题', 'default' => 'x']];
            }
            public function render(array $data, string $children = ''): string
            {
                return '<div>' . htmlspecialchars($data['title'] ?? '') . '</div>';
            }
        };
        BuilderRegistry::register($el);
        $this->assertSame(['title' => 'x'], BuilderRegistry::get('test-plugin-el')->defaults());
        $this->assertSame('<div>hi</div>', $this->inner($this->oneEl(['type' => 'test-plugin-el', 'data' => ['title' => 'hi']])));
    }

    // ---- 动态元素：测 buildMarkup() 拼出的 {yk:} 标签（纯字符串，不经 TagEngine/DB） ----

    public function testListDynamicBuildsTagMarkup(): void
    {
        $out = (new \ListDynamicElement())->buildMarkup([
            'source_type' => 'team', 'cat' => 'members', 'limit' => 4, 'recommend' => 1,
            'empty' => '暂无',
            'template' => [
                ['type' => 'heading', 'data' => ['level' => 'h3', 'text' => '{yk:field name=title /}']],
            ],
        ]);
        $this->assertStringStartsWith('{yk:list type=team cat=members limit=4 recommend=1 empty=暂无}', $out);
        // 子元素模板里 {yk:field} 原样带出（循环时逐条解析）
        $this->assertStringContainsString('<h3 class="text-xl font-bold mb-4">{yk:field name=title /}</h3>', $out);
        $this->assertStringEndsWith('{/yk:list}', $out);
    }

    public function testListDynamicCardModeGridAndToggles(): void
    {
        // 无 template → 内置卡片模式 + 网格
        $out = (new \ListDynamicElement())->buildMarkup([
            'source_type' => 'article', 'cat' => 'news', 'limit' => 6, 'columns' => 3,
            'show_image' => false, 'show_date' => true,
        ]);
        $this->assertStringContainsString('grid grid-cols-1 md:grid-cols-3 gap-6', $out); // 网格
        $this->assertStringContainsString('href="{yk:field name=url /}"', $out);          // 整卡链接
        $this->assertStringContainsString('{yk:field name=title /}', $out);               // 标题默认开
        $this->assertStringNotContainsString('<img src="{yk:field name=cover', $out);     // 封面关
        $this->assertStringContainsString('dateformat="Y-m-d"', $out);                    // 日期开
        $this->assertStringContainsString('{yk:field name=summary len=80 /}', $out);       // 摘要默认开
    }

    public function testListDynamicQuotesValuesWithSpaces(): void
    {
        $out = (new \ListDynamicElement())->buildMarkup([
            'source_type' => 'article', 'empty' => '暂无 内容',
            'template' => [['type' => 'text', 'data' => ['html' => 'x']]],
        ]);
        $this->assertStringContainsString('empty="暂无 内容"', $out);
    }

    public function testBannerAndNavMarkup(): void
    {
        $this->assertSame('{yk:banner group=home /}', (new \BannerElement())->buildMarkup(['group' => 'home']));
        $this->assertSame('', (new \BannerElement())->buildMarkup([])); // 空 group

        $n = (new \NavElement())->buildMarkup(['parent' => 'about']);
        $this->assertStringContainsString('{yk:nav parent=about}', $n);
        $this->assertStringContainsString('{yk:field name=url /}', $n); // 默认模板
    }

    public function testDynamicElementsMarkedDynamic(): void
    {
        foreach (['list-dynamic', 'banner', 'nav'] as $t) {
            $el = BuilderRegistry::get($t);
            $this->assertNotNull($el);
            $this->assertTrue($el->isDynamic(), "$t 应为动态元素");
        }
        $this->assertFalse(BuilderRegistry::get('heading')->isDynamic());
    }
}
