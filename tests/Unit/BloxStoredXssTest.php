<?php
/**
 * Blox 存储型 XSS 防线回归测试（v1.18.5 加固，源自第三轮代码审计）：
 *   - sanitizeHtml()：iframe Host 精确比对（youtube.com.evil.com 不再放行）
 *   - TextElement：富文本渲染层净化（历史脏数据兜底）
 *   - BloxDocumentPipeline：richtext 保存层净化
 *   - Button / CTA / Card / Image：href 伪协议拦截（safeHref）
 *   - VideoElement：iframe 嵌入严格 Host 白名单 + 直链视频扩展名约束
 *   - BloxElementPolicy：code 元素需要 blox_code 权限（服务端强制）
 */

declare(strict_types=1);

// BloxElementPolicy 靠全局 hasPermission() 判断会话能力；单测环境没有 auth.php，
// 这里给一个可控桩（必须在全局命名空间，策略类才解析得到）：默认放行一切
//（等价超管，不影响其它测试），本文件用 $GLOBALS['_test_admin_perms'] 收紧后
// 再断言拒绝行为。
namespace {
    require_once ROOT_PATH . '/includes/security.php';
    require_once ROOT_PATH . '/includes/builder/bootstrap.php';

    if (!function_exists('hasPermission')) {
        function hasPermission(string $permission): bool
        {
            $perms = $GLOBALS['_test_admin_perms'] ?? null;
            if ($perms === null) {
                return true;
            }
            return in_array('*', $perms, true) || in_array($permission, $perms, true);
        }
    }
}

namespace Yikai\Tests\Unit {

use AbstractElement;
use BloxDocumentPipeline;
use BloxElementPolicy;
use ButtonElement;
use CardElement;
use CtaElement;
use ImageElement;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TextElement;
use VideoElement;

final class BloxStoredXssTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['_test_admin_perms']);
    }

    // ---- sanitizeHtml：iframe Host 校验 ----

    public function testSanitizeHtmlKeepsTrustedIframe(): void
    {
        $html = '<iframe src="https://www.youtube.com/embed/abc123"></iframe>';
        $this->assertStringContainsString('youtube.com/embed/abc123', sanitizeHtml($html));

        $protocolRelative = '<iframe src="//player.bilibili.com/player.html?bvid=BV1"></iframe>';
        $this->assertStringContainsString('player.bilibili.com', sanitizeHtml($protocolRelative));
    }

    public function testSanitizeHtmlRejectsLookalikeIframeHost(): void
    {
        foreach ([
            'https://youtube.com.evil.com/embed/x',      // 子串伪装（旧 str_contains 会放行）
            'https://evilyoutube.com/embed/x',
            'https://evil.com/youtube.com/embed/x',      // 域名藏在 path 里
            'ftp://youtube.com/embed/x',                 // 非 http(s) 协议
            '/local/youtube.com.html',                   // 相对路径无 Host
        ] as $src) {
            $out = sanitizeHtml('<iframe src="' . $src . '"></iframe>');
            $this->assertStringNotContainsString('<iframe', $out, "iframe src={$src} 不应被放行");
        }
    }

    public function testSanitizeHtmlStillStripsScriptsAndEvents(): void
    {
        $out = sanitizeHtml('<p onclick="x()">hi</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function testSanitizeHtmlStripsVbscriptAndDataHtmlKeepsDataImage(): void
    {
        // v1.18.6 补口：vbscript 与非图片 data: 伪协议同样剥离
        $out = sanitizeHtml('<a href="vbscript:msgbox(1)">a</a><a href="data:text/html,<script>x</script>">b</a>');
        $this->assertStringNotContainsString('vbscript:', $out);
        $this->assertStringNotContainsString('data:text/html', $out);

        // data:image/* 是富文本内联图的合法形态，不误伤
        $img = '<img src="data:image/png;base64,iVBORw0KGgo=">';
        $this->assertStringContainsString('data:image/png', sanitizeHtml($img));
    }

    // ---- TextElement：渲染层净化 ----

    public function testTextElementSanitizesRichtextOnRender(): void
    {
        $out = (new TextElement())->render(['html' => '<p>ok</p><script>alert(1)</script>']);
        $this->assertStringContainsString('<p>ok</p>', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    // ---- BloxDocumentPipeline：保存层净化 ----

    public function testPipelineSanitizesRichtextOnSave(): void
    {
        $json = json_encode([[
            'columns' => [['elements' => [[
                'type' => 'text',
                'data' => ['html' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)>'],
            ]]]],
        ]]);
        $processed = BloxDocumentPipeline::process($json);
        $this->assertStringNotContainsString('<script', $processed['json']);
        $this->assertStringNotContainsString('onerror', $processed['json']);
        $this->assertStringContainsString('<p>ok</p>', $processed['json']);
    }

    // ---- href 伪协议拦截 ----

    public function testSafeHrefContract(): void
    {
        $this->assertSame('/about.html', AbstractElement::safeHref('/about.html'));
        $this->assertSame('#anchor', AbstractElement::safeHref('#anchor'));
        $this->assertSame('?page=2', AbstractElement::safeHref('?page=2'));
        $this->assertSame('https://example.com/x', AbstractElement::safeHref('https://example.com/x'));
        $this->assertSame('mailto:a@b.c', AbstractElement::safeHref('mailto:a@b.c'));
        $this->assertSame('', AbstractElement::safeHref('javascript:alert(1)'));
        $this->assertSame('', AbstractElement::safeHref('//evil.com/x'));
        $this->assertSame('', AbstractElement::safeHref('data:text/html,x'));
        $this->assertSame('', AbstractElement::safeHref("java\tscript:alert(1)"));
    }

    public function testButtonRejectsJavascriptHref(): void
    {
        $out = (new ButtonElement())->render(['text' => 'Go', 'url' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('href="#"', $out);
    }

    public function testButtonKeepsNormalHref(): void
    {
        $out = (new ButtonElement())->render(['text' => 'Go', 'url' => '/contact.html']);
        $this->assertStringContainsString('href="/contact.html"', $out);
    }

    public function testCtaRejectsJavascriptHref(): void
    {
        $out = (new CtaElement())->render(['title' => 'T', 'btn_text' => 'Go', 'btn_url' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringContainsString('href="#"', $out);
    }

    public function testCardRejectsJavascriptLinkFallsBackToDiv(): void
    {
        $out = (new CardElement())->render(['title' => 'T', 'link' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('<a ', $out);
        $this->assertStringContainsString('<div', $out);
    }

    public function testCardKeepsNormalLink(): void
    {
        $out = (new CardElement())->render(['title' => 'T', 'link' => '/p/1.html']);
        $this->assertStringContainsString('<a href="/p/1.html"', $out);
    }

    public function testImageLinkRejectsJavascriptHref(): void
    {
        $out = (new ImageElement())->render([
            'src' => '/uploads/a.jpg', 'click_action' => 'link', 'link_url' => 'javascript:alert(1)',
        ]);
        $this->assertStringNotContainsString('javascript:', $out);
        $this->assertStringNotContainsString('<a ', $out);   // 退化为普通图片
        $this->assertStringContainsString('<img', $out);
    }

    public function testImageLightboxRejectsUnsafeSrcHref(): void
    {
        $out = (new ImageElement())->render([
            'src' => 'javascript:alert(1)', 'click_action' => 'lightbox',
        ]);
        $this->assertStringNotContainsString('<a ', $out);
    }

    // ---- VideoElement：嵌入白名单 ----

    public function testVideoAcceptsTrustedEmbedHosts(): void
    {
        $out = (new VideoElement())->render(['url' => 'https://www.youtube.com/embed/abc']);
        $this->assertStringContainsString('<iframe', $out);

        $out = (new VideoElement())->render(['url' => 'https://player.bilibili.com/player.html?bvid=BV1x']);
        $this->assertStringContainsString('<iframe', $out);
    }

    public function testVideoRejectsUntrustedEmbedUrls(): void
    {
        foreach ([
            'https://evil.com/embed/x',                 // 旧 str_contains('/embed/') 会放行
            'https://player.evil.com/x',                // 旧 str_contains('player.') 会放行
            'https://youtube.com.evil.com/embed/x',     // Host 伪装
            'http://www.youtube.com/embed/x',           // 非 https
        ] as $url) {
            $out = (new VideoElement())->render(['url' => $url]);
            $this->assertSame('', $out, "不可信嵌入地址应输出空：{$url}");
        }
    }

    public function testVideoDirectFileRequiresVideoExtension(): void
    {
        $out = (new VideoElement())->render(['url' => '/uploads/demo.mp4']);
        $this->assertStringContainsString('<video', $out);

        $this->assertSame('', (new VideoElement())->render(['url' => 'https://example.com/page.html']));
        $this->assertSame('', (new VideoElement())->render(['url' => 'javascript:alert(1)//x.mp4']));
    }

    // ---- BloxElementPolicy：code 元素权限 ----

    private function codeDocJson(bool $nested = false): string
    {
        $code = ['type' => 'code', 'data' => ['html' => '<script>alert(1)</script>']];
        $element = $nested
            ? ['type' => 'container', 'data' => ['children' => [$code]]]
            : $code;
        return (string) json_encode([[
            'columns' => [['elements' => [$element]]],
        ]]);
    }

    public function testPipelineRejectsCodeElementWithoutBloxCodePermission(): void
    {
        $GLOBALS['_test_admin_perms'] = ['edit_page'];   // 默认「内容编辑」形态
        $this->expectException(RuntimeException::class);
        BloxDocumentPipeline::process($this->codeDocJson());
    }

    public function testPipelineRejectsNestedCodeElementWithoutPermission(): void
    {
        $GLOBALS['_test_admin_perms'] = ['edit_page'];
        $this->expectException(RuntimeException::class);
        BloxDocumentPipeline::process($this->codeDocJson(true));
    }

    public function testPipelineAllowsCodeElementWithBloxCodePermission(): void
    {
        $GLOBALS['_test_admin_perms'] = ['edit_page', 'blox_code'];
        $processed = BloxDocumentPipeline::process($this->codeDocJson());
        $this->assertStringContainsString('"code"', $processed['json']);
    }

    public function testPipelineAllowsCodeElementForSuperAdmin(): void
    {
        $GLOBALS['_test_admin_perms'] = ['*'];
        $processed = BloxDocumentPipeline::process($this->codeDocJson());
        $this->assertStringContainsString('"code"', $processed['json']);
    }

    /**
     * 实体编码与 srcdoc 绕过（codex 审计 P1-1，2026-08-22 复现并修复）。
     *
     * 浏览器读属性值时会解码 HTML 实体，所以 href="java&#x73;cript:..." 在浏览器
     * 眼里就是 javascript:，按原文做正则一个都拦不住。srcdoc 更狠——它的值是一整份
     * HTML 文档、浏览器会解码后当页面执行，而 iframe 的 src 白名单看不见它。
     */
    public function testEntityEncodedProtocolsAndSrcdocAreStripped(): void
    {
        $vectors = [
            '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>',
            '<a href="java&#x73;cript:alert(1)">x</a>',
            '<a href="jav&#10;ascript:alert(1)">x</a>',
            "<a href=\"jav\tascript:alert(1)\">x</a>",
            '<a href="data&colon;text/html;base64,PHN2Zz4=">x</a>',
        ];
        foreach ($vectors as $v) {
            $out = \HtmlPolicy::richText($v);
            $decoded = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertStringNotContainsStringIgnoringCase('srcdoc', $decoded, $v);
            self::assertDoesNotMatchRegularExpression('/(java|vb)script\s*:/i', $decoded, $v);
            self::assertDoesNotMatchRegularExpression('/data\s*:\s*text/i', $decoded, $v);
        }
    }

    /** 合法链接与内联图不能被上面的解码逻辑改坏。 */
    public function testLegitimateUrlsSurviveEntityDecoding(): void
    {
        $out = \HtmlPolicy::richText('<a href="/about.html?a=1&amp;b=2">ok</a>');
        self::assertStringContainsString('/about.html?a=1', $out);
        $img = \HtmlPolicy::richText('<img src="data:image/png;base64,iVBOR">');
        self::assertStringContainsString('data:image/png', $img);
    }

    public function testPolicyAllowsNonCodeElementsForEditor(): void
    {
        $GLOBALS['_test_admin_perms'] = ['edit_page'];
        BloxElementPolicy::assertSectionsAllowed([[
            'columns' => [['elements' => [['type' => 'text', 'data' => ['html' => '<p>ok</p>']]]]],
        ]]);
        $this->addToAssertionCount(1);   // 未抛异常即通过
    }
}

}
