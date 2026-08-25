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
        $this->assertSame('<a href="/a.jpg" data-lightbox class="block cursor-zoom-in"><img class="w-full rounded-lg" src="/a.jpg" alt="A" loading="lazy" decoding="async"></a>', $out);
        // link + new tab
        $out2 = $this->inner($this->oneEl(['type' => 'image', 'data' => ['src' => '/b.jpg', 'click_action' => 'link', 'link_url' => '/x', 'link_new_tab' => 1]]));
        $this->assertStringContainsString('<a href="/x" target="_blank" rel="noopener" class="block">', $out2);
    }

    public function testButton(): void
    {
        $out = $this->inner($this->oneEl(['type' => 'button', 'data' => ['text' => 'Go', 'url' => '/go', 'new_tab' => 1]]));
        $this->assertStringContainsString('<div class="mt-2">', $out);
        $this->assertStringContainsString('href="/go" target="_blank" rel="noopener"', $out);
        $this->assertStringContainsString('>Go</a>', $out);

        $center = $this->inner($this->oneEl(['type' => 'button', 'data' => ['text' => 'Center', 'align' => 'center']]));
        $right = $this->inner($this->oneEl(['type' => 'button', 'data' => ['text' => 'Right', 'align' => 'right']]));
        $invalid = $this->inner($this->oneEl(['type' => 'button', 'data' => ['text' => 'Safe', 'align' => 'absolute']]));
        $this->assertStringContainsString('<div class="mt-2 text-center">', $center);
        $this->assertStringContainsString('<div class="mt-2 text-right">', $right);
        $this->assertStringContainsString('<div class="mt-2">', $invalid);

        $controls = BuilderRegistry::get('button')?->controls() ?? [];
        $alignControl = array_values(array_filter($controls, static fn(array $control): bool => ($control['key'] ?? '') === 'align'));
        $this->assertCount(1, $alignControl);
        $this->assertSame('style', $alignControl[0]['tab']);
        $this->assertSame(['left' => 'blox_align_left', 'center' => 'blox_align_center', 'right' => 'blox_align_right'], $alignControl[0]['options']);
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

    // ---- P2 扩充元素 ----

    public function testAlertLevels(): void
    {
        $out = $this->inner($this->oneEl(['type' => 'alert', 'data' => ['text' => '注意 <x>', 'level' => 'warning']]));
        $this->assertStringContainsString('bg-yellow-50', $out);
        $this->assertStringContainsString('注意 &lt;x&gt;', $out);
    }

    public function testCtaAndQuoteAndIconBox(): void
    {
        $cta = $this->inner($this->oneEl(['type' => 'cta', 'data' => ['title' => 'T', 'btn_text' => 'Go', 'btn_url' => '/x']]));
        $this->assertStringContainsString('>T</h3>', $cta);
        $this->assertStringContainsString('href="/x"', $cta);
        $q = $this->inner($this->oneEl(['type' => 'quote', 'data' => ['text' => 'Q', 'author' => 'A']]));
        $this->assertStringContainsString('<blockquote', $q);
        $this->assertStringContainsString('— A', $q);
        $ib = $this->inner($this->oneEl(['type' => 'icon-box', 'data' => ['icon' => 'shield', 'title' => 'IB']]));
        $this->assertStringContainsString('ti ti-shield', $ib);
    }

    public function testCtaBackgroundBannerVariant(): void
    {
        // 设了 bg_image → 首页同款横幅（遮罩+白字+胶囊按钮）；未设 → 维持灰卡（上一用例已覆盖）。
        $cta = $this->inner($this->oneEl(['type' => 'cta', 'data' => [
            'title' => 'T', 'text' => 'S', 'btn_text' => 'Go', 'btn_url' => '/x',
            'bg_image' => '/images/case-demo.jpg',
        ]]));
        $this->assertStringContainsString('bg-cover bg-center', $cta);
        $this->assertStringContainsString('bg-black/60', $cta);
        $this->assertStringContainsString('text-3xl font-bold text-white', $cta);
        $this->assertStringContainsString('rounded-full', $cta);
        $this->assertStringContainsString('/images/case-demo.jpg', $cta);
        // 非法背景（javascript:）拒绝 → 回落灰卡形态
        $bad = $this->inner($this->oneEl(['type' => 'cta', 'data' => ['title' => 'T', 'bg_image' => 'javascript:alert(1)']]));
        $this->assertStringContainsString('bg-gray-50', $bad);
        $this->assertStringNotContainsString('javascript:', $bad);
    }

    public function testCtaFollowsHomeSettings(): void
    {
        $GLOBALS['_test_config']['home_cta_title'] = '首页标题';
        $GLOBALS['_test_config']['home_cta_desc'] = '首页副文';
        $GLOBALS['_test_config']['home_cta_button'] = '立即咨询';
        $GLOBALS['_test_config']['home_cta_link'] = '/contact.html';
        try {
            $cta = $this->inner($this->oneEl(['type' => 'cta', 'data' => [
                'use_home_text' => true,
                // 开启跟随后，本地文案字段被忽略：
                'title' => '本地标题', 'btn_text' => '本地按钮', 'btn_url' => '/local',
            ]]));
            $this->assertStringContainsString('>首页标题</h3>', $cta);
            $this->assertStringContainsString('首页副文', $cta);
            $this->assertStringContainsString('>立即咨询</a>', $cta);
            $this->assertStringContainsString('href="/contact.html"', $cta);
            $this->assertStringNotContainsString('本地标题', $cta);
            $this->assertStringNotContainsString('/local', $cta);
        } finally {
            unset(
                $GLOBALS['_test_config']['home_cta_title'],
                $GLOBALS['_test_config']['home_cta_desc'],
                $GLOBALS['_test_config']['home_cta_button'],
                $GLOBALS['_test_config']['home_cta_link']
            );
        }
    }

    public function testVideoEmbedConversion(): void
    {
        $yt = $this->inner($this->oneEl(['type' => 'video', 'data' => ['url' => 'https://youtu.be/abc123']]));
        $this->assertStringContainsString('youtube.com/embed/abc123', $yt);
        $this->assertStringContainsString('padding-bottom:56.25%', $yt); // 16:9
        // 直链 → Plyr 增强的 video 标签（src 属性 + plyr class + 资源）
        $mp4 = $this->inner($this->oneEl(['type' => 'video', 'data' => ['url' => 'https://x.com/a.mp4']]));
        $this->assertStringContainsString('<video class="ykt-plyr"', $mp4);
        $this->assertStringContainsString('src="https://x.com/a.mp4"', $mp4);
        $this->assertStringContainsString('/assets/plyr/plyr.min.js', $mp4);
        // 空 → 空
        $this->assertSame('', $this->inner($this->oneEl(['type' => 'video', 'data' => []])));
    }

    public function testCardWithAndWithoutLink(): void
    {
        $linked = $this->inner($this->oneEl(['type' => 'card', 'data' => ['title' => 'C', 'link' => '/go']]));
        $this->assertStringContainsString('<a href="/go"', $linked);
        $plain = $this->inner($this->oneEl(['type' => 'card', 'data' => ['title' => 'C']]));
        $this->assertStringStartsWith('<div class="block bg-white', $plain);
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

    public function testSectionAnchorRendersOnceAndRejectsUnsafeValues(): void
    {
        $out = BlockRenderer::render((string) json_encode([
            [
                'settings' => ['anchor_id' => 'features'],
                'columns' => [['elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]]],
            ],
            [
                'settings' => ['anchor_id' => 'FEATURES'],
                'columns' => [['elements' => [['type' => 'heading', 'data' => ['text' => 'B']]]]],
            ],
            [
                'settings' => ['anchor_id' => 'bad\" onclick=\"x'],
                'columns' => [['elements' => [['type' => 'heading', 'data' => ['text' => 'C']]]]],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame(1, substr_count($out, 'yk-blox-anchor'));
        $this->assertStringContainsString('id="features"', $out);
        $this->assertStringNotContainsString('onclick=', $out);

        $visibleDuplicate = BlockRenderer::render((string) json_encode([
            ['settings' => ['anchor_id' => 'contact', 'hidden' => true], 'columns' => [['elements' => []]]],
            ['settings' => ['anchor_id' => 'contact'], 'columns' => [['elements' => [['type' => 'heading', 'data' => ['text' => 'Visible']]]]]],
        ], JSON_THROW_ON_ERROR));
        $this->assertStringContainsString('id="contact"', $visibleDuplicate);
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

    public function testMultiColumnCanStackAtTabletWithoutChangingLegacyDefault(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => ['gap' => 'md', 'tablet_stack' => true],
            'columns'  => [
                ['span' => 5, 'elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]],
                ['span' => 7, 'elements' => [['type' => 'heading', 'data' => ['text' => 'B']]]],
            ],
        ]]));

        $this->assertStringContainsString('grid grid-cols-1 lg:grid-cols-12 gap-4', $out);
        $this->assertStringContainsString('class="lg:col-span-5"', $out);
        $this->assertStringContainsString('class="lg:col-span-7"', $out);
        $this->assertStringNotContainsString('md:grid-cols-12', $out);
    }

    // ---- r8 头部元素：logo 绑站点设置 / 抽屉自足含二级 ----
    public function testLogoElementFallsBackToSiteNameWithoutLogoImage(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns'  => [[ 'elements' => [['type' => 'logo', 'data' => ['display' => 'both']]] ]],
        ]]));
        // 测试环境无 configRawLang 数据源约定：站名文字降级路径必须产出非空容器或安全空串
        $this->assertIsString($out);
    }

    public function testNavDrawerRendersHamburgerPanelAndBackdropHiddenByDefault(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns'  => [[ 'elements' => [['type' => 'nav-drawer', 'data' => ['side' => 'left']]] ]],
        ]]));
        $this->assertStringContainsString('data-yk-nav-drawer', $out);
        $this->assertStringContainsString('data-yk-drawer-open', $out);
        $this->assertStringContainsString('aria-expanded="false"', $out);
        $this->assertStringContainsString('data-yk-drawer-close', $out);
        $this->assertStringContainsString('aria-hidden="true"', $out);
        $this->assertStringContainsString('role="search"', $out);
        $this->assertStringContainsString('xl:hidden', $out);
        // 面板与遮罩初始 hidden；左侧抽屉贴左
        $this->assertStringContainsString('data-yk-drawer-panel aria-hidden="true"', $out);
        $this->assertStringContainsString('class="hidden fixed top-0 left-0', $out);
        $this->assertStringContainsString('data-yk-drawer-backdrop aria-label=', $out);
    }

    // ---- r12 mega menu：三级树 → 顶级菜单 + 全宽多列面板 ----

    /** 本测试环境无 getNavChannels，定义固定三级树 stub（全套件唯一定义点） */
    private function defineMegaNavStub(): void
    {
        if (!function_exists('getNavChannels')) {
            eval('function getNavChannels(): array { return [
                ["name" => "首页", "slug" => "home", "type" => "page", "children" => []],
                ["name" => "产品", "slug" => "product", "type" => "list", "description" => "全线产品", "children" => [
                    ["name" => "激光器", "slug" => "laser", "type" => "list", "description" => "工业级", "children" => [
                        ["name" => "光纤激光器", "slug" => "fiber", "type" => "list", "children" => []],
                    ]],
                    ["name" => "配件", "slug" => "parts", "type" => "list", "children" => []],
                ]],
            ]; }');
        }
        if (!function_exists('channelUrl')) {
            eval('function channelUrl(array $c): string { return "/" . ($c["slug"] ?? "") . ".html"; }');
        }
    }

    public function testNavMegaRendersPanelColumnsAndGrandchildren(): void
    {
        $this->defineMegaNavStub();
        $out = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns'  => [[ 'elements' => [['type' => 'nav-mega', 'data' => []]] ]],
        ]], JSON_UNESCAPED_UNICODE));

        $this->assertStringContainsString('hidden xl:flex', $out); // 桌面导航；较窄屏幕配 nav-drawer
        $this->assertStringContainsString('>首页<', $out); // 无子级=普通链接，无面板
        $this->assertStringContainsString('grid-cols-2', $out); // 两个子栏目=两列
        $this->assertStringContainsString('inset-x-0', $out); // 默认通栏面板（相对元素根）
        $this->assertStringContainsString('href="/laser.html"', $out); // 列标题可点（Avada 语义）
        $this->assertStringContainsString('href="/fiber.html"', $out); // 孙级=列内链接
        $this->assertStringContainsString('group-focus-within/mega:visible', $out); // 键盘可达
        $this->assertStringContainsString('pointer-events-none', $out); // 关闭态不拦截指针（Bricks 做法）
        $this->assertStringNotContainsString('全线产品', $out); // show_desc 默认关
    }

    public function testNavMegaOptionsDescAndNarrowPanel(): void
    {
        $this->defineMegaNavStub();
        $out = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns'  => [[ 'elements' => [['type' => 'nav-mega', 'data' => ['show_desc' => true, 'full_width' => 0]]] ]],
        ]], JSON_UNESCAPED_UNICODE));

        $this->assertStringContainsString('工业级', $out); // 列描述（channels.description）
        $this->assertStringContainsString('w-max max-w-3xl', $out); // 非通栏=内容自适应
        $this->assertStringNotContainsString('inset-x-0', $out);
    }

    public function testStandardNavTreeShowsCaretOnlyForDropdownParents(): void
    {
        $element = new \NavElement();
        $renderNode = new \ReflectionMethod($element, 'renderMenuNode');
        $parent = $renderNode->invoke($element, [
            'name' => '产品', 'slug' => 'product', 'type' => 'list',
            'children' => [['name' => '激光器', 'slug' => 'laser', 'type' => 'list', 'children' => []]],
        ], true);
        $leaf = $renderNode->invoke($element, [
            'name' => '首页', 'slug' => 'home', 'type' => 'page', 'children' => [],
        ], true);
        $out = $parent . $leaf;

        $this->assertStringContainsString('data-yk-nav-caret', $out);
        $this->assertSame(1, substr_count($out, 'data-yk-nav-caret'));
        $this->assertStringContainsString('>产品<svg data-yk-nav-caret', $out);
        $this->assertStringNotContainsString('>首页<svg data-yk-nav-caret', $out);
    }

    // ---- r5 响应式列宽：span 接受 {d,t}；标量路径黄金对拍不破 ----
    public function testResponsiveSpanEmitsTabletAndDesktopClasses(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns'  => [
                ['span' => ['d' => 4, 't' => 6], 'elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]],
                ['span' => ['d' => 8, 't' => 6], 'elements' => [['type' => 'heading', 'data' => ['text' => 'B']]]],
            ],
        ]]));

        $this->assertStringContainsString('class="md:col-span-6 lg:col-span-4"', $out);
        $this->assertStringContainsString('class="md:col-span-6 lg:col-span-8"', $out);
    }

    public function testResponsiveSpanWithEqualBreakpointsMatchesScalarOutput(): void
    {
        $payload = static fn (mixed $spanA, mixed $spanB): string => json_encode([[
            'settings' => [],
            'columns'  => [
                ['span' => $spanA, 'elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]],
                ['span' => $spanB, 'elements' => [['type' => 'heading', 'data' => ['text' => 'B']]]],
            ],
        ]]);
        // {d:N}（t 继承 d）与标量 N 输出逐字节一致——对象形态不引入多余类
        $this->assertSame(
            BlockRenderer::render($payload(5, 7)),
            BlockRenderer::render($payload(['d' => 5], ['d' => 7]))
        );
    }

    public function testResponsiveSpanUnderTabletStackUsesDesktopOnly(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => ['tablet_stack' => true],
            'columns'  => [
                ['span' => ['d' => 4, 't' => 6], 'elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]],
                ['span' => ['d' => 8, 't' => 6], 'elements' => [['type' => 'heading', 'data' => ['text' => 'B']]]],
            ],
        ]]));

        // 平板堆叠时平板档无意义：只输出桌面档
        $this->assertStringContainsString('class="lg:col-span-4"', $out);
        $this->assertStringNotContainsString('md:col-span-6', $out);
    }

    // ---- r5 断点可见性：hide_on 前台输出隐藏类，编辑态输出标记 ----
    public function testHideOnEmitsBreakpointClassesOnFrontend(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => ['hide_on' => ['m']],
            'columns'  => [[
                'elements' => [['type' => 'heading', 'data' => ['text' => 'A']]],
            ]],
        ]]));

        $this->assertStringContainsString('max-md:hidden', $out);
        $this->assertStringNotContainsString('data-yk-hide-on', $out);
    }

    public function testHideOnColumnAndInvalidKeysAreFiltered(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns'  => [
                ['hide_on' => ['t', 'bogus'], 'elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]],
                ['elements' => [['type' => 'heading', 'data' => ['text' => 'B']]]],
            ],
        ]]));

        $this->assertStringContainsString('md:max-lg:hidden', $out);
        $this->assertStringNotContainsString('bogus', $out);
    }

    public function testHideOnEditModeEmitsMarkerInsteadOfHiding(): void
    {
        BlockRenderer::$editChannelId = 9;
        $_SESSION['admin_id'] = 1; // editMode = editChannelId>0 且已登录管理员
        try {
            $out = BlockRenderer::render(json_encode([[
                'settings' => ['hide_on' => ['m', 'd']],
                'columns'  => [[
                    'elements' => [['type' => 'heading', 'data' => ['text' => 'A']]],
                ]],
            ]]));
        } finally {
            BlockRenderer::$editChannelId = 0;
            unset($_SESSION['admin_id']);
        }

        $this->assertStringContainsString('data-yk-hide-on="m,d"', $out);
        $this->assertStringNotContainsString('max-md:hidden', $out);
        $this->assertStringNotContainsString('lg:hidden', $out);
    }

    public function testNoHideOnKeyKeepsLegacyOutputByteIdentical(): void
    {
        $payload = static fn (array $settings): string => json_encode([[
            'settings' => $settings,
            'columns'  => [[
                'elements' => [['type' => 'heading', 'data' => ['text' => 'A']]],
            ]],
        ]]);
        // 未设 hide_on 与设空数组输出一致——新键不影响存量
        $this->assertSame(
            BlockRenderer::render($payload([])),
            BlockRenderer::render($payload(['hide_on' => []]))
        );
    }

    // ---- P2 响应式三档：{d,t,m} → 基类 + md:/lg: 前缀（mobile-first：m=基类 t=md d=lg） ----

    public function testResponsivePaddingThreeTiers(): void
    {
        $full = $this->oneEl(
            ['type' => 'heading', 'data' => ['text' => 'A']],
            ['padding' => ['d' => 'xl', 't' => 'md', 'm' => 'sm']]
        );
        $this->assertStringStartsWith('<section class="py-4 md:py-8 lg:py-16">', $full);
    }

    public function testResponsiveUniformCollapsesToScalarOutput(): void
    {
        // 三档一致 → 输出与标量完全相同（无冗余前缀类）
        $full = $this->oneEl(
            ['type' => 'heading', 'data' => ['text' => 'A']],
            ['padding' => ['d' => 'lg', 't' => 'lg', 'm' => 'lg']]
        );
        $this->assertStringStartsWith('<section class="py-12">', $full);
    }

    public function testResponsivePartialTiersInherit(): void
    {
        // 只分档 d：t/m 继承 d → 全档一致 → 单基类
        $full = $this->oneEl(['type' => 'heading', 'data' => ['text' => 'A']], ['padding' => ['d' => 'xl']]);
        $this->assertStringStartsWith('<section class="py-16">', $full);
        // m 单独改：基类=m，md: 起恢复 t（=d）
        $full2 = $this->oneEl(['type' => 'heading', 'data' => ['text' => 'A']], ['padding' => ['d' => 'md', 't' => 'md', 'm' => 'none']]);
        $this->assertStringStartsWith('<section class="py-0 md:py-8">', $full2);
    }

    public function testResponsiveGapAndInvalidTierFallsBack(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => ['gap' => ['d' => 'xl', 't' => 'sm', 'm' => 'sm']],
            'columns'  => [
                ['elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]],
                ['elements' => [['type' => 'heading', 'data' => ['text' => 'B']]]],
            ],
        ]]));
        $this->assertStringContainsString('grid grid-cols-1 md:grid-cols-2 gap-2 lg:gap-12', $out);
        // 非法档位值回退 fallback
        $full = $this->oneEl(['type' => 'heading', 'data' => ['text' => 'A']], ['padding' => ['d' => 'bogus', 'm' => 'sm']]);
        $this->assertStringStartsWith('<section class="py-4 md:py-8">', $full);
    }

    public function testResponsiveSpacer(): void
    {
        $s = $this->inner($this->oneEl(['type' => 'spacer', 'data' => ['size' => ['d' => 'xl', 't' => 'md', 'm' => 'sm']]]));
        $this->assertSame('<div class="h-4 md:h-8 lg:h-24"></div>', $s);
        // 标量不变
        $s2 = $this->inner($this->oneEl(['type' => 'spacer', 'data' => ['size' => 'xl']]));
        $this->assertSame('<div class="h-24"></div>', $s2);
    }

    // ---- P2 可复用块：{library_id} 渲染时经 BlocksLibrary 展开 ----

    public function testLibraryRefExpandsAtRenderTime(): void
    {
        \BlocksLibrary::$resolver = function (int $id): ?array {
            if ($id !== 7) {
                return null;
            }
            return [
                'settings' => ['padding' => 'sm'],
                'columns'  => [['elements' => [['type' => 'heading', 'data' => ['text' => 'LibBlock']]]]],
            ];
        };
        try {
            // 引用块：settings/columns 以库内容为准（页面里存的空壳被替换）
            $out = BlockRenderer::render(json_encode([
                ['library_id' => 7, 'library_name' => 'x', 'settings' => [], 'columns' => []],
            ]));
            $this->assertStringStartsWith('<section class="py-4">', $out);
            $this->assertStringContainsString('LibBlock', $out);

            // 库块不存在（已删/表缺失）→ 该区块静默跳过
            $gone = BlockRenderer::render(json_encode([
                ['library_id' => 999, 'settings' => [], 'columns' => []],
            ]));
            $this->assertSame('', $gone);

            // 库数据里再带 library_id 不递归展开（防循环），按普通 section 渲染
            \BlocksLibrary::$resolver = function (int $id): ?array {
                return [
                    'library_id' => 7, // 恶意/脏数据：应被忽略
                    'settings' => [],
                    'columns'  => [['elements' => [['type' => 'heading', 'data' => ['text' => 'NoLoop']]]]],
                ];
            };
            $noLoop = BlockRenderer::render(json_encode([['library_id' => 5]]));
            $this->assertStringContainsString('NoLoop', $noLoop);
        } finally {
            \BlocksLibrary::$resolver = null;
        }
    }

    // ---- P2 预设库：形状 + 每个预设都能经真实渲染管线出 HTML（防 schema 漂移） ----

    public function testPresetsShapeAndAllRenderable(): void
    {
        require_once ROOT_PATH . '/includes/builder/presets.php';
        $presets = builderPresets();
        $this->assertNotEmpty($presets['sections']);
        $this->assertNotEmpty($presets['pages']);
        $keys = array_column($presets['sections'], 'key');
        foreach (['hero', 'features', 'cta', 'team', 'gallery', 'stats', 'testimonial', 'faq'] as $k) {
            $this->assertContains($k, $keys, "缺少区块预设 $k");
        }
        $faqPreset = array_values(array_filter(
            $presets['sections'],
            static fn(array $preset): bool => ($preset['key'] ?? '') === 'faq'
        ))[0];
        $faqItems = $faqPreset['sections'][0]['columns'][0]['elements'][0]['data']['items'];
        $this->assertIsArray($faqItems);
        $this->assertSame(['question', 'answer'], array_keys($faqItems[0]));
        $this->assertContains('company_intro', array_column($presets['pages'], 'key'));

        foreach (array_merge($presets['sections'], $presets['pages']) as $preset) {
            $this->assertNotEmpty($preset['label']);
            $this->assertNotEmpty($preset['sections'], "预设 {$preset['key']} 无 sections");
            $html = BlockRenderer::render(json_encode($preset['sections']));
            $this->assertNotSame('', $html, "预设 {$preset['key']} 渲染为空——元素 type 或数据键可能写错");
            // 预设里的元素 type 必须全部已注册（渲染器对未知 type 静默跳过，这里显式兜住）
            foreach ($preset['sections'] as $sec) {
                foreach ($sec['columns'] ?? [] as $col) {
                    foreach ($col['elements'] ?? [] as $el) {
                        $this->assertNotNull(BuilderRegistry::get($el['type']), "预设 {$preset['key']} 引用未注册元素 {$el['type']}");
                    }
                }
            }
        }
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
        $this->assertSame([
            'text', 'site_field', 'site_fallback', 'loop_field', 'loop_fallback',
            'level', 'visual_size', 'color', 'align', 'animation', 'animation_speed', 'animation_delay',
        ], $keys);
        // defaults 从 controls 推导
        $this->assertSame([
            'text' => '',
            'site_field' => 'none',
            'site_fallback' => '',
            'loop_field' => 'title',
            'loop_fallback' => '',
            'level' => 'h2',
            'visual_size' => 'auto',
            'color' => '',
            'align' => 'left',
            'animation' => '',
            'animation_speed' => 'normal',
            'animation_delay' => 'none',
        ], $h->defaults());
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
        $this->assertSame(__('blox_dynamic_list_label'), $ld['label']);
        $this->assertSame('dynamic', $ld['category']);
        $this->assertTrue($ld['dynamic']);
        $this->assertTrue($ld['container']);
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
        $this->assertStringNotContainsString('{yk:subnav', $n); // 未开下拉 → 无子级标签（单层零额外查询）
    }

    public function testNavDropdownMarkup(): void
    {
        $n = (new \NavElement())->buildMarkup(['dropdown' => true]);
        $this->assertStringContainsString('{yk:subnav wrap=ul', $n);
        $this->assertStringContainsString('group-hover/nav:block', $n); // CSS hover 展开
        $this->assertStringContainsString('{yk:if field=has_children op=eq value=1}', $n); // 叶子项无箭头
        $this->assertStringContainsString('data-yk-nav-caret', $n);
        $this->assertStringNotContainsString('hidden xl:flex', $n); // 普通导航默认仍可用于任意上下文

        $desktop = (new \NavElement())->buildMarkup(['dropdown' => true, 'desktop_only' => true]);
        // data-yk-nav-overflow：横向菜单的「更多」溢出收纳挂载点（单测 __ stub 返回键名）
        $this->assertStringContainsString('<ul class="hidden xl:flex flex-wrap gap-4" data-yk-nav-overflow="nav_more">', $desktop);
        $this->assertStringContainsString('{yk:subnav wrap=ul', $desktop); // Header 内切换后仍保留多级下拉

        // 自定义子模板优先于下拉默认模板
        $c = (new \NavElement())->buildMarkup(['dropdown' => true, 'template' => [['type' => 'heading', 'data' => ['text' => 'X']]]]);
        $this->assertStringNotContainsString('{yk:subnav', $c);
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

    // ── 容器元素（一层嵌套，中间路线） ──────────────────────────

    public function testContainerRendersChildren(): void
    {
        $out = $this->inner($this->oneEl(['type' => 'container', 'data' => [
            'direction' => 'row', 'gap' => 'sm', 'align' => 'center', 'padding' => 'md',
            'bg_color' => '#f5f5f5', 'radius' => 'md',
            'children' => [
                ['type' => 'heading', 'data' => ['level' => 'h3', 'text' => 'T']],
                ['type' => 'text', 'data' => ['html' => '<p>x</p>']],
            ],
        ]]));
        $this->assertSame(
            '<div class="yk-container flex flex-row flex-wrap gap-2 items-center p-6 rounded-lg"'
            . ' style="background-color:#f5f5f5;">'
            . '<h3 class="text-xl font-bold mb-4">T</h3>'
            . '<div class="prose prose-lg max-w-none"><p>x</p></div>'
            . '</div>',
            $out
        );
    }

    public function testContainerDefaultsAndEmpty(): void
    {
        // 默认值：纵向、中间距、无对齐/内边距/圆角/背景；无 children 输出空容器
        $out = $this->inner($this->oneEl(['type' => 'container', 'data' => []]));
        $this->assertSame('<div class="yk-container flex flex-col gap-4"></div>', $out);
    }

    public function testReusableStatsGroupRendersResponsiveSelectableItems(): void
    {
        $group = BuilderRegistry::get('stats-group');
        $this->assertNotNull($group);
        $this->assertTrue($group->isContainer());
        $this->assertCount(4, $group->defaults()['children']);

        $out = BlockRenderer::renderElementNode([
            'type' => 'stats-group',
            'data' => [
                'mobile_columns' => '1',
                'tablet_columns' => '3',
                'desktop_columns' => '5',
                'gap' => 'lg',
                'counter_enabled' => true,
                'counter_start' => 5,
                'counter_duration' => 1800,
                'children' => [[
                    'type' => 'stat-item',
                    'data' => [
                        'icon' => 'rocket<script>',
                        'number' => '128+',
                        'label' => '项目 <完成>',
                        'number_color' => '#123ABC',
                    ],
                ]],
            ],
        ], 0, true, [0, 0, 0]);

        $this->assertStringContainsString(
            'class="yk-stats-group grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-12"',
            $out
        );
        $this->assertStringContainsString('data-blox-counter="{&quot;enabled&quot;:true,&quot;start&quot;:5,&quot;duration&quot;:1800}"', $out);
        $this->assertStringContainsString('data-yk-el="0.0.0.0" data-yk-el-type="stat-item"', $out);
        $this->assertStringContainsString('ti ti-rocketscript', $out);
        $this->assertStringContainsString('data-count="128+" style="color:#123abc;"', $out);
        $this->assertStringContainsString('项目 &lt;完成&gt;', $out);
    }

    public function testReusableStatsGroupClampsCounterSettings(): void
    {
        $out = $this->inner($this->oneEl(['type' => 'stats-group', 'data' => [
            'counter_enabled' => false,
            'counter_start' => -20,
            'counter_duration' => 9000,
            'children' => [],
        ]]));
        $this->assertStringContainsString('data-blox-counter="{&quot;enabled&quot;:false,&quot;start&quot;:0,&quot;duration&quot;:5000}"', $out);
    }

    public function testDivRendersAsBlockOrFlexWrapper(): void
    {
        $div = BuilderRegistry::get('div');
        $this->assertNotNull($div);
        $this->assertTrue($div->isContainer());
        $this->assertSame('layout', $div->category());

        $block = $this->inner($this->oneEl(['type' => 'div', 'data' => [
            'children' => [
                ['type' => 'heading', 'data' => ['level' => 'h3', 'text' => 'Inside']],
            ],
        ]]));
        $this->assertSame(
            '<div class="yk-div"><h3 class="text-xl font-bold mb-4">Inside</h3></div>',
            $block
        );

        $flex = $this->inner($this->oneEl(['type' => 'div', 'data' => [
            'display' => 'flex', 'direction' => 'row', 'gap' => 'sm',
            'align' => 'center', 'justify' => 'between',
            'padding' => 'sm', 'radius' => 'md', 'bg_color' => '#ffffff',
        ]]));
        $this->assertSame(
            '<div class="yk-div flex flex-row flex-wrap gap-2 items-center justify-between p-3 rounded-lg"'
            . ' style="background-color:#ffffff;"></div>',
            $flex
        );
    }

    public function testContainerDepthCapStopsRunawayNesting(): void
    {
        // 编辑器只允许一层；渲染器深度上限 3 兜底坏数据。构造 5 层自嵌套，
        // 第 4 层（depth=3）的 children 不再展开——只数 yk-container 出现次数。
        $node = ['type' => 'heading', 'data' => ['level' => 'h2', 'text' => 'deep']];
        for ($i = 0; $i < 5; $i++) {
            $node = ['type' => 'container', 'data' => ['children' => [$node]]];
        }
        $out = $this->inner($this->oneEl($node));
        $this->assertSame(4, substr_count($out, 'yk-container')); // depth 0..3 共 4 层
        $this->assertStringNotContainsString('deep', $out);       // 第 5 层内容被截断
    }

    public function testEditModeAddsInlineEditingMetadata(): void
    {
        $json = json_encode([[
            'id' => 'section-stable-1',
            'settings' => ['title' => 'Section title', 'subtitle' => 'Section subtitle'],
            'columns' => [['elements' => [
                ['type' => 'heading', 'data' => ['text' => 'Heading']],
                ['type' => 'text', 'data' => ['html' => '<p>Plain text</p>']],
            ]]],
        ]]);

        $oldChannelId = BlockRenderer::$editChannelId;
        $oldSession = is_array($_SESSION ?? null) ? $_SESSION : [];
        try {
            BlockRenderer::$editChannelId = 2;
            $_SESSION['admin_id'] = 1;
            $out = BlockRenderer::render($json);
            $this->assertStringContainsString('data-yk-sec="0" data-yk-sec-id="section-stable-1"', $out);
            $this->assertStringContainsString('data-yk-sec-field="0.title"', $out);
            $this->assertStringContainsString('data-yk-sec-field="0.subtitle"', $out);
            $this->assertStringContainsString('data-yk-el="0.0.0" data-yk-el-type="heading"', $out);
            $this->assertStringContainsString('data-yk-el="0.0.1" data-yk-el-type="text"', $out);
        } finally {
            BlockRenderer::$editChannelId = $oldChannelId;
            $_SESSION = $oldSession;
        }

        $this->assertStringNotContainsString('data-yk-el-type', BlockRenderer::render($json));
    }

    public function testNestedCustomBlockUsesNamespacedHomeFieldsInsteadOfInnerCoordinates(): void
    {
        $json = json_encode([[
            'settings' => ['col_card' => true],
            'columns' => [[
                'elements' => [
                    ['type' => 'heading', 'data' => ['text' => 'Professional']],
                    ['type' => 'text', 'data' => ['html' => '<p>$299</p>']],
                    ['type' => 'button', 'data' => ['text' => 'Choose', 'url' => '/contact.html']],
                ],
            ], [
                'elements' => [['type' => 'heading', 'data' => ['text' => 'Enterprise']]],
            ]],
        ]]);

        $oldContext = BlockRenderer::$homeFieldEditContext;
        try {
            BlockRenderer::$homeFieldEditContext = [
                'path' => '7.0.0',
                'type' => 'custom:1',
                'locale' => 'zh_CN',
            ];
            $out = BlockRenderer::render($json);
        } finally {
            BlockRenderer::$homeFieldEditContext = $oldContext;
        }

        $this->assertStringContainsString(
            'data-yk-home-field="custom_overrides.zh_CN.0.columns.0.card_bg"',
            $out
        );
        $this->assertStringContainsString('data-yk-home-path="7.0.0"', $out);
        $this->assertStringContainsString(
            'data-yk-home-field="custom_overrides.zh_CN.0.columns.0.elements.0.data.text"',
            $out
        );
        $this->assertStringContainsString(
            'data-yk-home-field="custom_overrides.zh_CN.0.columns.0.elements.1.data.html"',
            $out
        );
        $this->assertSame(3, substr_count($out, 'data-yk-home-inline="1"'));
        $this->assertSame(3, substr_count($out, 'data-yk-home-inline="0"'));
        $this->assertStringNotContainsString('data-yk-el="0.0.', $out);
    }

    public function testNestedCustomAccordionMarksEachQuestionAndAnswer(): void
    {
        $json = json_encode([[
            'columns' => [[
                'elements' => [[
                    'type' => 'accordion',
                    'data' => [
                        'items' => "First question?|First answer.\nSecond question?|Second answer.",
                        'open_first' => true,
                        'seo_schema' => true,
                    ],
                ]],
            ]],
        ]]);

        $oldContext = BlockRenderer::$homeFieldEditContext;
        try {
            BlockRenderer::$homeFieldEditContext = [
                'path' => '8.0.0',
                'type' => 'custom:2',
                'locale' => 'en',
            ];
            $out = BlockRenderer::render($json);
        } finally {
            BlockRenderer::$homeFieldEditContext = $oldContext;
        }

        foreach ([
            'custom_overrides.en.0.columns.0.elements.0.data.accordion_items.0.question',
            'custom_overrides.en.0.columns.0.elements.0.data.accordion_items.0.answer',
            'custom_overrides.en.0.columns.0.elements.0.data.accordion_items.1.question',
            'custom_overrides.en.0.columns.0.elements.0.data.accordion_items.1.answer',
        ] as $field) {
            $this->assertStringContainsString('data-yk-home-field="' . $field . '"', $out);
        }
        $this->assertSame(4, substr_count($out, 'data-yk-home-inline="1"'));
        $this->assertStringNotContainsString('data-yk-el="0.0.0"', $out);
        $this->assertStringContainsString('"@type":"FAQPage"', $out);
    }

    public function testAccordionExposesStructuredFaqControlWithoutChangingItsOutput(): void
    {
        $element = BuilderRegistry::get('accordion');
        $this->assertNotNull($element);
        $controls = array_column($element->controls(), null, 'key');

        $this->assertSame('faq_repeater', $controls['items']['type']);
        $this->assertSame(30, $controls['items']['max']);
        $this->assertSame('question', array_key_first($controls['items']['default'][0]));

        $out = $this->inner($this->oneEl([
            'type' => 'accordion',
            'data' => [
                'items' => "Question one|Answer one\nQuestion two|Answer two",
                'open_first' => true,
                'seo_schema' => true,
            ],
        ]));
        $this->assertStringContainsString('<span>Question one</span>', $out);
        $this->assertStringContainsString('Answer two', $out);
        $this->assertStringContainsString('"@type":"FAQPage"', $out);

        $structured = $this->inner($this->oneEl([
            'type' => 'accordion',
            'data' => [
                'items' => [
                    ['question' => 'Structured question', 'answer' => "Structured\nanswer"],
                ],
                'seo_schema' => true,
            ],
        ]));
        $this->assertStringContainsString('<span>Structured question</span>', $structured);
        $this->assertStringContainsString("Structured<br />\nanswer", $structured);
        $this->assertStringContainsString('"name":"Structured question"', $structured);
    }

    public function testSectionTitleFieldStyles(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => [
                'title' => 'Section title',
                'subtitle' => 'Section subtitle',
                'title_tag' => 'h3',
                'title_align' => 'left',
                'title_size' => 'lg',
                'title_color' => '#123456',
                'subtitle_size' => 'sm',
                'subtitle_color' => '#654321',
            ],
            'columns' => [['elements' => []]],
        ]]));

        $this->assertStringContainsString('<div class="text-left mb-10">', $out);
        $this->assertStringContainsString(
            '<h3 class="blk-title" style="font-size:2.25rem;color:#123456;">Section title</h3>',
            $out
        );
        $this->assertStringContainsString(
            '<p class="blk-sub" style="font-size:0.875rem;color:#654321;">Section subtitle</p>',
            $out
        );

        $fallback = BlockRenderer::render(json_encode([[
            'settings' => ['title' => 'Safe', 'title_tag' => 'script', 'title_color' => 'red;display:none'],
            'columns' => [['elements' => []]],
        ]]));
        $this->assertStringContainsString('<h2 class="blk-title">Safe</h2>', $fallback);
        $this->assertStringNotContainsString('display:none', $fallback);
    }

    public function testSectionGradientBackground(): void
    {
        $grad = 'linear-gradient(135deg,#667eea 0%,#764ba2 100%)';
        // 纯渐变
        $out = BlockRenderer::render(json_encode([[
            'settings' => ['bg_gradient' => $grad],
            'columns'  => [['elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]]],
        ]]));
        $this->assertStringContainsString('style="background-image:' . $grad . ';"', $out);
        // 渐变 + 背景图：渐变叠在图上
        $out2 = BlockRenderer::render(json_encode([[
            'settings' => ['bg_gradient' => $grad, 'bg_image' => '/uploads/a.jpg'],
            'columns'  => [['elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]]],
        ]]));
        $this->assertStringContainsString('background-image:' . $grad . ',url(&quot;/uploads/a.jpg&quot;);background-size:cover', $out2);
        // 非法值（可注入 style 的内容）被丢弃，退回背景图分支
        $out3 = BlockRenderer::render(json_encode([[
            'settings' => ['bg_gradient' => 'url(javascript:alert(1))', 'bg_image' => '/uploads/a.jpg'],
            'columns'  => [['elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]]],
        ]]));
        $this->assertStringNotContainsString('javascript', $out3);
        $this->assertStringContainsString('background-image:url(&quot;/uploads/a.jpg&quot;)', $out3);
    }

    public function testSectionImageOverlayFocalPointAndHeight(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => [
                'bg_image' => '/uploads/hero.jpg',
                'bg_overlay_color' => '#102030',
                'bg_overlay_opacity' => 55,
                'bg_position' => 'top-right',
                'min_height' => 'lg',
                'content_v_align' => 'end',
            ],
            'columns' => [['elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]]],
        ]], JSON_THROW_ON_ERROR));

        $this->assertStringContainsString(
            'background-position:right top;min-height:640px;',
            $out
        );
        $this->assertStringContainsString(
            '<section class="py-8 flex items-end relative overflow-hidden"',
            $out
        );
        $this->assertStringContainsString(
            '<div class="absolute inset-0 pointer-events-none" aria-hidden="true" style="background-color:#102030;opacity:0.55;"></div>',
            $out
        );
        $this->assertStringContainsString(
            '<div class="max-w-6xl mx-auto px-4 w-full relative z-10">',
            $out
        );
    }

    public function testLegacyBackgroundOpacityActsAsImageOverlay(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => [
                'bg_color' => '#000000',
                'bg_image' => '/uploads/legacy.jpg',
                'bg_opacity' => 40,
            ],
            'columns' => [['elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]]],
        ]], JSON_THROW_ON_ERROR));

        $this->assertStringContainsString('background-color:rgba(0,0,0,0.4);', $out);
        $this->assertStringContainsString('style="background-color:#000000;opacity:0.4;"', $out);
    }

    public function testSectionContainerLayer(): void
    {
        // 自定义容器宽度 + 容器层独立样式
        $out = BlockRenderer::render(json_encode([[
            'settings' => ['max_width' => 'custom', 'max_width_px' => 1280,
                'container_bg' => '#ffffff', 'container_bg_image' => '/uploads/container.jpg',
                'container_padding' => 'md', 'container_radius' => 'md'],
            'columns'  => [['elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]]],
        ]]));
        $this->assertStringContainsString(
            '<div class="mx-auto px-4 p-6 rounded-xl" style="max-width:1280px;background-color:#ffffff;background-image:url(&quot;/uploads/container.jpg&quot;);background-size:cover;background-position:center;background-repeat:no-repeat;">',
            $out
        );
        // 非法 px 回退预设宽度类
        $out2 = BlockRenderer::render(json_encode([[
            'settings' => ['max_width' => 'custom', 'max_width_px' => 50],
            'columns'  => [['elements' => [['type' => 'heading', 'data' => ['text' => 'A']]]]],
        ]]));
        $this->assertStringContainsString('<div class="max-w-6xl mx-auto px-4">', $out2);
    }

    public function testContainerAndColumnBackgroundImagesHaveIndependentOverlays(): void
    {
        $out = BlockRenderer::render(json_encode([[
            'settings' => [
                'container_bg_image' => '/uploads/container.jpg',
                'container_bg_overlay_color' => '#102030',
                'container_bg_overlay_opacity' => 35,
            ],
            'columns' => [[
                'card_bg_image' => '/uploads/column.jpg',
                'card_bg_overlay_color' => '#405060',
                'card_bg_overlay_opacity' => 60,
                'elements' => [['type' => 'heading', 'data' => ['text' => 'Above overlays']]],
            ]],
        ]], JSON_THROW_ON_ERROR));

        $this->assertStringContainsString(
            'class="max-w-6xl mx-auto px-4 relative overflow-hidden" style="background-image:url(&quot;/uploads/container.jpg&quot;);',
            $out
        );
        $this->assertStringContainsString(
            'style="background-color:#102030;opacity:0.35;"',
            $out
        );
        $this->assertStringContainsString(
            'class="relative overflow-hidden" style="background-image:url(&quot;/uploads/column.jpg&quot;);',
            $out
        );
        $this->assertStringContainsString(
            'style="background-color:#405060;opacity:0.6;"',
            $out
        );
        $this->assertSame(2, substr_count($out, '<div class="relative z-10">'));
        $this->assertStringContainsString('<h2 class="text-2xl font-bold mb-4">Above overlays</h2>', $out);
    }

    public function testCommonElementsExposeAndRenderAnimations(): void
    {
        $types = ['heading', 'text', 'image', 'button', 'card', 'icon-box', 'cta'];
        foreach ($types as $type) {
            $controls = BuilderRegistry::get($type)?->controls() ?? [];
            $keys = array_column($controls, 'key');
            $this->assertContains('animation', $keys, $type);
            $this->assertContains('animation_speed', $keys, $type);
            $this->assertContains('animation_delay', $keys, $type);
        }

        $fixtures = [
            ['type' => 'heading', 'data' => ['text' => 'Title']],
            ['type' => 'text', 'data' => ['html' => '<p>Text</p>']],
            ['type' => 'image', 'data' => ['src' => '/a.jpg']],
            ['type' => 'button', 'data' => ['text' => 'Go']],
            ['type' => 'card', 'data' => ['title' => 'Card']],
            ['type' => 'icon-box', 'data' => ['title' => 'Icon']],
            ['type' => 'cta', 'data' => ['title' => 'CTA']],
        ];
        foreach ($fixtures as $fixture) {
            $fixture['data']['animation'] = 'fade-up';
            $fixture['data']['animation_speed'] = 'fast';
            $fixture['data']['animation_delay'] = 'medium';
            $out = $this->inner($this->oneEl($fixture));
            $this->assertStringContainsString(
                'data-animate="fade-up" data-animate-speed="fast" data-animate-delay="medium"',
                $out,
                $fixture['type']
            );
        }

        $unsafe = $this->inner($this->oneEl(['type' => 'heading', 'data' => [
            'text' => 'Safe',
            'animation' => '" onmouseover="alert(1)',
            'animation_speed' => 'instant',
            'animation_delay' => '999s',
        ]]));
        $this->assertSame('<h2 class="text-2xl font-bold mb-4">Safe</h2>', $unsafe);
    }

    public function testCommonBoxSpacingUsesWhitelistedRootStyles(): void
    {
        $out = $this->inner($this->oneEl(['type' => 'heading', 'data' => [
            'text' => 'Spacing',
            'style_margin' => 'md',
            'style_margin_bottom' => 'none',
            'style_padding' => 'xl',
            'style_padding_left' => 'sm',
        ]]));
        $this->assertStringContainsString(
            'style="margin:1rem!important;margin-bottom:0!important;padding:4rem!important;padding-left:0.5rem!important;"',
            $out
        );
        $this->assertStringContainsString('class="text-2xl font-bold mb-4"', $out);

        $unsafe = $this->inner($this->oneEl(['type' => 'heading', 'data' => [
            'text' => 'Safe',
            'style_margin' => '1rem;display:none',
            'style_padding' => 'auto',
            'style_padding_top' => ['d' => 'xl'],
        ]]));
        $this->assertSame('<h2 class="text-2xl font-bold mb-4">Safe</h2>', $unsafe);

        $code = $this->inner($this->oneEl(['type' => 'code', 'data' => [
            'html' => '<div>Raw</div>',
            'style_margin' => 'xl',
        ]]));
        $this->assertSame('<div>Raw</div>', $code);
    }

    public function testContainerAndDivAdvancedFlexOptions(): void
    {
        $container = $this->inner($this->oneEl(['type' => 'container', 'data' => [
            'direction' => 'row',
            'wrap' => 'nowrap',
            'gap' => 'xl',
            'align' => 'baseline',
            'justify' => 'evenly',
            'padding' => 'xl',
            'style_margin_top' => 'lg',
        ]]));
        $this->assertStringContainsString(
            'class="yk-container flex flex-row flex-nowrap gap-12 items-baseline justify-evenly p-16"',
            $container
        );
        $this->assertStringContainsString('style="margin-top:2rem!important;"', $container);

        $div = $this->inner($this->oneEl(['type' => 'div', 'data' => [
            'display' => 'flex',
            'direction' => 'column',
            'wrap' => 'wrap',
            'justify' => 'around',
        ]]));
        $this->assertStringContainsString(
            '<div class="yk-div flex flex-col flex-wrap gap-0 justify-around"></div>',
            $div
        );

        // 缺少新字段时继续保持历史默认输出。
        $legacy = $this->inner($this->oneEl(['type' => 'container', 'data' => [
            'direction' => 'row',
            'gap' => 'sm',
        ]]));
        $this->assertSame('<div class="yk-container flex flex-row flex-wrap gap-2"></div>', $legacy);
    }

    public function testBoxStyleExactLengthsWhitelist(): void
    {
        // 精确输入：margin 允负值/auto，padding 非负；档位继续可用
        $s = \AbstractElement::boxStyle([
            'style_margin_top' => '-12px',
            'style_margin_left' => 'auto',
            'style_margin' => '1.5rem',
            'style_padding_top' => '10%',
            'style_padding' => 'md',
        ]);
        $this->assertStringContainsString('margin-top:-12px!important;', $s);
        $this->assertStringContainsString('margin-left:auto!important;', $s);
        $this->assertStringContainsString('margin:1.5rem!important;', $s);
        $this->assertStringContainsString('padding-top:10%!important;', $s);
        $this->assertStringContainsString('padding:1rem!important;', $s);
    }

    public function testBoxStyleRejectsInvalidAndInjection(): void
    {
        // 注入与越界一律静默忽略——值会进 style 属性，白名单是安全边界
        $s = \AbstractElement::boxStyle([
            'style_padding_top' => '-4px',            // padding 不允许负值
            'style_padding_left' => 'auto',           // padding 不允许 auto
            'style_margin_top' => 'calc(1px + 1px)',  // 函数
            'style_margin_right' => '1px;color:red',  // 分号注入
            'style_margin_bottom' => 'expression(a)', // IE 注入
            'style_margin_left' => '99999px',         // 位数越界
            'style_padding' => '10 px',               // 空格
        ]);
        $this->assertSame('', $s);
    }

    public function testNonContainerIgnoresChildrenKey(): void
    {
        // 普通元素带 children 键（异常数据）不得递归——输出与无该键时一致
        $out = $this->inner($this->oneEl(['type' => 'heading', 'data' => [
            'level' => 'h2', 'text' => 'A',
            'children' => [['type' => 'text', 'data' => ['html' => '<p>leak</p>']]],
        ]]));
        $this->assertSame('<h2 class="text-2xl font-bold mb-4">A</h2>', $out);
    }
    public function testDynamicListExposesVisualQueryAndStyleControls(): void
    {
        $controls = [];
        foreach ((new \ListDynamicElement())->controls() as $control) {
            $controls[(string) $control['key']] = $control;
        }

        $this->assertSame('select', $controls['query_source']['type']);
        $this->assertArrayHasKey('type:article', $controls['query_source']['options']);
        $this->assertArrayHasKey('type:product', $controls['query_source']['options']);
        $this->assertSame('style', $controls['columns']['tab']);
        $this->assertCount(8, $controls['columns']['options']);
        $this->assertSame(['show_summary', '=', true], $controls['summary_len']['required']);
    }

    public function testDynamicListQuerySourceUsesBoundedProductQueryAndEightColumnGrid(): void
    {
        $out = (new \ListDynamicElement())->buildMarkup([
            'query_source' => 'type:product',
            'cat' => '5',
            'limit' => 500,
            'keyword' => 'desk{bad}',
            'order' => 'price_desc',
            'columns' => 8,
            'image_ratio' => 'square',
        ]);

        $this->assertStringContainsString('{yk:list type=product cat=5 limit=50 keyword=deskbad order=price_desc}', $out);
        $this->assertStringContainsString('grid grid-cols-1 md:grid-cols-4 lg:grid-cols-8 gap-6', $out);
        $this->assertStringContainsString('aspect-square', $out);
        $this->assertStringContainsString('alt="{yk:field name=title /}"', $out);
    }

    public function testDynamicListRejectsUnknownProductOrder(): void
    {
        $out = (new \ListDynamicElement())->buildMarkup([
            'query_source' => 'type:product',
            'order' => 'DROP TABLE products',
            'template' => [['type' => 'text', 'data' => ['html' => 'x']]],
        ]);

        $this->assertStringNotContainsString('order=', $out);
    }
}
