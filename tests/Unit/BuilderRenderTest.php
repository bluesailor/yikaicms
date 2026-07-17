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
        foreach (['heading', 'text', 'image', 'button', 'icon', 'code', 'divider', 'spacer'] as $t) {
            $this->assertContains($t, $types);
        }
    }
}
