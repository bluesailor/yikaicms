<?php
/**
 * 通用背景能力契约（2026-09-02 规划第 1 轮）：
 * 共享解析（backgroundDeclarations）、native 策略的存量兼容、注入拒绝、
 * 策略与控件组的一致性矩阵，以及盒模型服务端能力闸（行为收紧的防回归锚点）。
 *
 * 逐字节兼容的完整黄金对拍在 BuilderRenderTest（container/div 带 bg_color 的
 * 既有用例未改动即为证据）；本文件锁定新契约自身的行为。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use AbstractElement;
use BlockRenderer;
use BuilderRegistry;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxBackgroundStyleTest extends TestCase
{
    /** 包一个单列单元素的 section，返回完整渲染 */
    private function oneEl(array $el): string
    {
        return BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns'  => [['elements' => [$el]]],
        ]]));
    }

    public function testDeclarationsAcceptCssColorFamily(): void
    {
        $this->assertSame('background-color:#ff8800;', AbstractElement::backgroundDeclarations(['bg_color' => '#FF8800']));
        $this->assertSame('background-color:transparent;', AbstractElement::backgroundDeclarations(['bg_color' => 'transparent']));
        $this->assertSame('background-color:rgba(0,0,0,.5);', AbstractElement::backgroundDeclarations(['bg_color' => 'rgba(0,0,0,.5)']));
        $this->assertSame('background-color:var(--yk-color-primary);', AbstractElement::backgroundDeclarations(['bg_color' => 'var(--yk-color-primary)']));
    }

    public function testDeclarationsRejectInjectionAndNonScalar(): void
    {
        $this->assertSame('', AbstractElement::backgroundDeclarations([]));
        $this->assertSame('', AbstractElement::backgroundDeclarations(['bg_color' => '']));
        // 声明拼接注入：cssColor 白名单整体拒绝，而不是转义后输出
        $this->assertSame('', AbstractElement::backgroundDeclarations(['bg_color' => '#fff;display:none']));
        $this->assertSame('', AbstractElement::backgroundDeclarations(['bg_color' => 'url(javascript:1)']));
        $this->assertSame('', AbstractElement::backgroundDeclarations(['bg_color' => 'expression(alert(1))']));
        // {d,t,m} 数组形态明确拒绝：内联通路无响应式（见 respClasses 注释与规划 5.3）
        $this->assertSame('', AbstractElement::backgroundDeclarations(['bg_color' => ['d' => '#ffffff']]));
    }

    /** native 策略：合法值输出与旧实现同形；非法值整属性不出现 */
    public function testNativeElementsRenderAndReject(): void
    {
        foreach (['container', 'div'] as $type) {
            $ok = $this->oneEl(['type' => $type, 'data' => ['bg_color' => '#f5f5f5']]);
            $this->assertStringContainsString(' style="background-color:#f5f5f5;"', $ok, $type);

            $bad = $this->oneEl(['type' => $type, 'data' => ['bg_color' => '#fff;display:none']]);
            $this->assertStringNotContainsString('style=', $bad, $type);
        }
    }

    /** 策略值合法，且非 none 的元素必须经 controls() 声明 bg_color（键登记处约束） */
    public function testStrategyAndControlsConsistency(): void
    {
        $container = BuilderRegistry::get('container');
        $div = BuilderRegistry::get('div');
        $this->assertNotNull($container);
        $this->assertNotNull($div);
        $this->assertSame('native', $container->backgroundRenderStrategy());
        $this->assertSame('native', $div->backgroundRenderStrategy());

        foreach (BuilderRegistry::all() as $type => $el) {
            $strategy = $el->backgroundRenderStrategy();
            $this->assertContains($strategy, ['none', 'native', 'root'], "element {$type}");
            if ($strategy === 'none') {
                continue; // 专用背景实现（home-block 等）自带字段，不属通用契约
            }
            $keys = array_map(static fn(array $c): string => (string) ($c['key'] ?? ''), $el->controls());
            $this->assertContains('bg_color', $keys, "element {$type} declares '{$strategy}' but lacks bg_color control");
        }
    }

    /** 共享控件组带 group 键（第 2 轮面板分组的 schema 契约） */
    public function testSharedControlGroupsDeclareGroupKey(): void
    {
        $groupOf = static function (AbstractElement $el, string $key): ?string {
            foreach ($el->controls() as $c) {
                if (($c['key'] ?? '') === $key) {
                    return $c['group'] ?? null;
                }
            }
            return null;
        };
        $container = BuilderRegistry::get('container');
        $this->assertNotNull($container);
        $this->assertSame('background', $groupOf($container, 'bg_color'));

        $heading = BuilderRegistry::get('heading');
        $this->assertNotNull($heading);
        foreach (['animation', 'animation_speed', 'animation_delay'] as $key) {
            $this->assertSame('animation', $groupOf($heading, $key), $key);
        }
    }

    /** 策略 none 的元素喂入 bg_color 不得产生背景（服务端契约，而非仅编辑器不显示） */
    public function testNoneStrategyIgnoresBgColor(): void
    {
        $out = $this->oneEl(['type' => 'heading', 'data' => ['text' => 'A', 'bg_color' => '#ff8800']]);
        $this->assertStringNotContainsString('background-color', $out);
    }

    /** 第 4 轮：背景图 + 遮罩的声明矩阵（CSS 多背景层，遮罩只在有图时生效） */
    public function testDeclarationsWithImageAndOverlay(): void
    {
        $this->assertSame(
            'background-image:url("/uploads/a.jpg");background-size:cover;background-position:center;',
            AbstractElement::backgroundDeclarations(['bg_image' => '/uploads/a.jpg'])
        );
        $this->assertSame(
            'background-image:linear-gradient(rgba(0,0,0,.6),rgba(0,0,0,.6)),url("/uploads/a.jpg");background-size:cover;background-position:center;',
            AbstractElement::backgroundDeclarations(['bg_image' => '/uploads/a.jpg', 'bg_overlay' => '60'])
        );
        $this->assertSame(
            'background-color:#111827;background-image:linear-gradient(rgba(0,0,0,.4),rgba(0,0,0,.4)),url("/uploads/a.jpg");background-size:cover;background-position:center;',
            AbstractElement::backgroundDeclarations(['bg_color' => '#111827', 'bg_image' => '/uploads/a.jpg', 'bg_overlay' => '40'])
        );
        // 遮罩不叠在纯色上；非法档位按无遮罩处理；图非法则整段图声明消失（遮罩随之无效）
        $this->assertSame('background-color:#111827;', AbstractElement::backgroundDeclarations(['bg_color' => '#111827', 'bg_overlay' => '60']));
        $this->assertSame(
            'background-image:url("/uploads/a.jpg");background-size:cover;background-position:center;',
            AbstractElement::backgroundDeclarations(['bg_image' => '/uploads/a.jpg', 'bg_overlay' => '99'])
        );
        $this->assertSame('', AbstractElement::backgroundDeclarations(['bg_image' => 'javascript:alert(1)', 'bg_overlay' => '60']));
        $this->assertSame('', AbstractElement::backgroundDeclarations(['bg_image' => ['d' => '/uploads/a.jpg']]));
    }

    /** 第 4 轮：native（container）与 root（card）无需改动即获得图与遮罩 */
    public function testImageBackgroundFlowsThroughBothStrategies(): void
    {
        $container = $this->oneEl(['type' => 'container', 'data' => ['bg_image' => '/uploads/a.jpg', 'bg_overlay' => '60']]);
        $this->assertStringContainsString('linear-gradient(rgba(0,0,0,.6),rgba(0,0,0,.6)),url(&quot;/uploads/a.jpg&quot;)', $container);

        $card = $this->oneEl(['type' => 'card', 'data' => ['title' => 'T', 'bg_image' => '/uploads/a.jpg']]);
        $this->assertStringContainsString('background-image:url(', $card);
    }

    /** 第 4 轮：CTA 遮罩档位——缺省与显式 60 均输出既有 bg-black/60（存量逐字节不变） */
    public function testCtaOverlayTiers(): void
    {
        $legacy = $this->oneEl(['type' => 'cta', 'data' => ['title' => 'T', 'bg_image' => '/uploads/a.jpg']]);
        $this->assertStringContainsString('<div class="absolute inset-0 bg-black/60"></div>', $legacy);

        $explicit = $this->oneEl(['type' => 'cta', 'data' => ['title' => 'T', 'bg_image' => '/uploads/a.jpg', 'overlay' => '60']]);
        $this->assertSame($legacy, $explicit);

        $heavy = $this->oneEl(['type' => 'cta', 'data' => ['title' => 'T', 'bg_image' => '/uploads/a.jpg', 'overlay' => '80']]);
        $this->assertStringContainsString('bg-black/80', $heavy);

        $none = $this->oneEl(['type' => 'cta', 'data' => ['title' => 'T', 'bg_image' => '/uploads/a.jpg', 'overlay' => '']]);
        $this->assertStringNotContainsString('bg-black/', $none);
    }

    /** 第 5 轮：背景视频直链校验——合法地址 + 视频扩展名，平台链接与伪协议拒绝 */
    public function testBackgroundVideoUrlValidation(): void
    {
        $this->assertSame('/uploads/bg.mp4', AbstractElement::backgroundVideoUrl(['bg_video' => '/uploads/bg.mp4']));
        $this->assertSame('https://cdn.example.com/a.webm', AbstractElement::backgroundVideoUrl(['bg_video' => 'https://cdn.example.com/a.webm']));
        $this->assertSame('', AbstractElement::backgroundVideoUrl([]));
        $this->assertSame('', AbstractElement::backgroundVideoUrl(['bg_video' => 'javascript:alert(1)']));
        $this->assertSame('', AbstractElement::backgroundVideoUrl(['bg_video' => 'https://www.youtube.com/watch?v=x']));
        $this->assertSame('', AbstractElement::backgroundVideoUrl(['bg_video' => '/uploads/readme.txt']));
        $this->assertSame('', AbstractElement::backgroundVideoUrl(['bg_video' => ['d' => '/a.mp4']]));
    }

    /** 第 5 轮：容器视频三层结构；遮罩转 DOM 层且不再叠进图片 gradient；非法视频回落原路径 */
    public function testContainerVideoLayers(): void
    {
        $out = $this->oneEl(['type' => 'container', 'data' => [
            'bg_video' => '/uploads/bg.mp4', 'bg_overlay' => '60',
            'bg_color' => '#111827', 'bg_image' => '/uploads/a.jpg',
        ]]);
        $this->assertStringContainsString('class="yk-container blox-has-bg"', $out);
        $this->assertStringContainsString('<div class="blox-bg-media" aria-hidden="true"><video autoplay muted loop playsinline preload="metadata" src="/uploads/bg.mp4"></video></div>', $out);
        $this->assertStringContainsString('<div class="blox-bg-overlay" style="background:rgba(0,0,0,.6)"></div>', $out);
        // 底层样式保留色与图，但 gradient 不出现（遮罩已是 DOM 层）
        $this->assertStringContainsString('background-color:#111827;', $out);
        $this->assertStringContainsString('background-image:url(&quot;/uploads/a.jpg&quot;)', $out);
        $this->assertStringNotContainsString('linear-gradient', $out);
        // flex 布局移交内容层
        $this->assertStringContainsString('<div class="blox-content flex ', $out);

        // 无遮罩档位 → 无 overlay 层
        $plain = $this->oneEl(['type' => 'container', 'data' => ['bg_video' => '/uploads/bg.mp4']]);
        $this->assertStringNotContainsString('blox-bg-overlay', $plain);

        // 非法视频 → 回落无视频路径（无三层结构）
        $bad = $this->oneEl(['type' => 'container', 'data' => ['bg_video' => 'https://www.youtube.com/watch?v=x']]);
        $this->assertStringNotContainsString('blox-has-bg', $bad);
    }

    /** 第 5 轮：视频控件只出现在开启该能力的元素上（首批仅 container） */
    public function testVideoControlScopedToEnabledElements(): void
    {
        $hasVideo = static function (AbstractElement $el): bool {
            foreach ($el->controls() as $c) {
                if (($c['key'] ?? '') === 'bg_video') {
                    return true;
                }
            }
            return false;
        };
        $this->assertTrue($hasVideo(BuilderRegistry::get('container')));
        foreach (['div', 'card', 'quote', 'icon-box'] as $type) {
            $this->assertFalse($hasVideo(BuilderRegistry::get($type)), $type);
        }
    }

    /** root 策略（第 3 轮）：渲染后由 BlockRenderer 注入首标签；与盒模型声明共存 */
    public function testRootStrategyInjectsIntoFirstTag(): void
    {
        // blockquote 首标签注入
        $quote = $this->oneEl(['type' => 'quote', 'data' => ['text' => 'Q', 'bg_color' => '#f5f5f5']]);
        $this->assertStringContainsString('<blockquote', $quote);
        $this->assertStringContainsString('style="background-color:#f5f5f5;"', $quote);

        // 链接态 card 首标签是 <a>，同样注入；背景声明在盒模型声明之前
        $card = $this->oneEl(['type' => 'card', 'data' => [
            'title' => 'T', 'link' => '/x.html', 'bg_color' => '#111827', 'style_padding' => 'md',
        ]]);
        $this->assertStringContainsString('<a ', $card);
        $this->assertStringContainsString('style="background-color:#111827;padding:1rem!important;"', $card);

        // 非法值整属性不出现
        $bad = $this->oneEl(['type' => 'icon-box', 'data' => ['title' => 'T', 'bg_color' => '#fff;display:none']]);
        $this->assertStringNotContainsString('background-color', $bad);

        // 未设背景时输出与既有黄金一致：无 style 属性
        $plain = $this->oneEl(['type' => 'quote', 'data' => ['text' => 'Q']]);
        $this->assertStringNotContainsString('style=', $plain);
    }

    /** 盒模型服务端闸（行为收紧）：关闭元素直填 style_* 不再被应用；开启元素不受影响 */
    public function testBoxStyleServerSideGate(): void
    {
        // spacer 声明 supportsBoxStyles(): false，此前直填 style_margin 仍会注入
        $off = $this->oneEl(['type' => 'spacer', 'data' => ['size' => 'md', 'style_margin' => 'md']]);
        $this->assertStringNotContainsString('style=', $off);

        // heading 默认开启：闸门不得误伤既有路径
        $on = $this->oneEl(['type' => 'heading', 'data' => ['text' => 'A', 'style_margin' => 'md']]);
        $this->assertStringContainsString(' style="margin:1rem!important;"', $on);
    }
}
