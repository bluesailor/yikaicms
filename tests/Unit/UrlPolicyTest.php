<?php
/**
 * UrlPolicy——全站 URL 安全策略唯一权威实现（v1.18.6 统一收口）。
 * safeUrl()/safeHref()/trustedIframeHost()/VideoElement 白名单全部委托到这里，
 * 本测试锁定策略本体；各委托方的行为由既有 XSS/元素测试锁定。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UrlPolicy;

require_once ROOT_PATH . '/includes/UrlPolicy.php';

final class UrlPolicyTest extends TestCase
{
    public function testHrefAcceptsSafeShapes(): void
    {
        $this->assertSame('/a/b.html', UrlPolicy::href('/a/b.html'));
        $this->assertSame('#top', UrlPolicy::href('#top'));
        $this->assertSame('?page=2', UrlPolicy::href('?page=2'));
        $this->assertSame('https://x.y/z', UrlPolicy::href('https://x.y/z'));
        $this->assertSame('mailto:a@b.c', UrlPolicy::href('mailto:a@b.c'));
        $this->assertSame('tel:+8112345678', UrlPolicy::href('tel:+8112345678'));
    }

    public function testHrefRejectsDangerousShapes(): void
    {
        foreach (['javascript:alert(1)', '//evil.com/x', 'data:text/html,x',
            "java\tscript:x", 'vbscript:msgbox(1)', str_repeat('a', 3000)] as $bad) {
            $this->assertSame('', UrlPolicy::href($bad), $bad);
        }
        $this->assertSame('', UrlPolicy::href(null));
        $this->assertSame('', UrlPolicy::href(['x']));
    }

    public function testHrefActionSchemeSwitch(): void
    {
        $this->assertSame('', UrlPolicy::href('mailto:a@b.c', false));
        $this->assertSame('/x', UrlPolicy::href('/x', false));
    }

    public function testHrefLoopPlaceholderSwitch(): void
    {
        $tag = '{yk:field name=url /}';
        $this->assertSame($tag, UrlPolicy::href($tag, true, true));
        $this->assertSame('', UrlPolicy::href($tag));   // 默认不放行
        // 带附加属性（潜在注入载体）永远不豁免
        $this->assertSame('', UrlPolicy::href('{yk:field name=url fallback=x /}', true, true));
    }

    public function testImagePolicy(): void
    {
        $this->assertSame('/uploads/a.jpg', UrlPolicy::image('/uploads/a.jpg'));
        $this->assertSame('https://cdn.x/a.png', UrlPolicy::image('https://cdn.x/a.png'));
        $this->assertSame('', UrlPolicy::image('data:image/png;base64,x'));
        $this->assertSame('', UrlPolicy::image('//evil.com/a.png'));
        $this->assertSame('/a.png', UrlPolicy::image('<b>/a.png</b>'));   // strip_tags 前处理
        $this->assertSame('', UrlPolicy::image('a.png'));   // 裸相对名（无 / 前缀）历来拒绝
    }

    public function testCssImageLiteralCannotEscapeUrlContext(): void
    {
        $payload = "/a.jpg'); position:fixed; inset:0; background-image:url('https://evil.example/x.png";
        $literal = UrlPolicy::cssImageLiteral($payload);

        $this->assertSame(
            'url("/a.jpg%27); position:fixed; inset:0; background-image:url(%27https://evil.example/x.png")',
            $literal
        );
        $this->assertStringNotContainsString("url('/a.jpg')", $literal);
        $this->assertSame('url("/uploads/a%22b%5Cc.jpg")', UrlPolicy::cssImageLiteral('/uploads/a"b\\c.jpg'));
        $this->assertSame('', UrlPolicy::cssImageLiteral('javascript:alert(1)'));
    }

    public function testStoredImageCompatibilityIsNarrowAndRasterOnly(): void
    {
        $data = 'data:image/png;base64,iVBORw0KGgo=';
        $this->assertSame('https://cdn.example.com/a.png', UrlPolicy::storedImage('//cdn.example.com/a.png'));
        $this->assertSame('/uploads/legacy/a.jpg', UrlPolicy::storedImage('uploads/legacy/a.jpg'));
        $this->assertSame($data, UrlPolicy::storedImage($data));

        foreach (['uploads/../config.php', 'data:image/svg+xml;base64,PHN2Zz4=',
            'data:text/html;base64,PGgxPng8L2gxPg==', '//user@example.com/a.png', 'javascript:alert(1)'] as $bad) {
            $this->assertSame('', UrlPolicy::storedImage($bad), $bad);
        }
    }

    public function testRedirectAllowsOnlyRelativeOrSameOriginTargets(): void
    {
        $site = 'https://example.com';
        self::assertSame('/about?tab=1', UrlPolicy::redirect('/about?tab=1', $site));
        self::assertSame('https://example.com/news', UrlPolicy::redirect('https://example.com/news', $site));
        self::assertSame('https://example.com:443/news', UrlPolicy::redirect('https://example.com:443/news', $site));

        foreach ([
            '//evil.test/path',
            '/\\evil.test/path',
            'https://example.com.evil.test/path',
            'http://example.com/path',
            'https://example.com:444/path',
            "https://example.com/path\r\nX-Test: yes",
            'javascript:alert(1)',
        ] as $target) {
            self::assertSame('', UrlPolicy::redirect($target, $site), $target);
        }
    }

    public function testVideoEmbedHostsAreExactAndHttpsOnly(): void
    {
        $this->assertTrue(UrlPolicy::isTrustedVideoEmbed('https://www.youtube.com/embed/x'));
        $this->assertTrue(UrlPolicy::isTrustedVideoEmbed('https://player.bilibili.com/player.html?bvid=1'));
        $this->assertFalse(UrlPolicy::isTrustedVideoEmbed('http://www.youtube.com/embed/x'));
        $this->assertFalse(UrlPolicy::isTrustedVideoEmbed('https://youtube.com.evil.com/embed/x'));
        $this->assertFalse(UrlPolicy::isTrustedVideoEmbed('https://evil.com/embed/x'));
    }

    public function testIframeHostAllowsSubdomainsRejectsLookalikes(): void
    {
        $this->assertTrue(UrlPolicy::isTrustedIframeHost('https://www.youtube.com/embed/x'));
        $this->assertTrue(UrlPolicy::isTrustedIframeHost('//player.bilibili.com/p'));
        $this->assertFalse(UrlPolicy::isTrustedIframeHost('https://youtube.com.evil.com/x'));
        $this->assertFalse(UrlPolicy::isTrustedIframeHost('ftp://youtube.com/x'));
        $this->assertFalse(UrlPolicy::isTrustedIframeHost('/relative/youtube.com'));
    }
}
