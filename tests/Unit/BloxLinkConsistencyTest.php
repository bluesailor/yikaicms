<?php
/**
 * V2.0.0 链接一致性：标题 / 按钮 / 图片共用同一套新窗口、rel、title、aria-label 规则，
 * 并保留 safeHref 安全管线（空链接、非法协议、动态值缺失各自的退化行为不变）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AbstractElement;
use ButtonElement;
use HeadingElement;
use ImageElement;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxLinkConsistencyTest extends TestCase
{
    /** @return array<string,array{0:callable(array<string,mixed>):string,1:string,2:string}> 元素、URL 键、新窗口键 */
    public static function elements(): array
    {
        return [
            'heading' => [static fn(array $data): string => (new HeadingElement())->render($data + ['text' => 'Title']), 'url', 'new_tab'],
            'button' => [static fn(array $data): string => (new ButtonElement())->render($data + ['text' => 'Go']), 'url', 'new_tab'],
            'image' => [static fn(array $data): string => (new ImageElement())->render($data + ['src' => '/a.jpg', 'click_action' => 'link']), 'link_url', 'link_new_tab'],
        ];
    }

    /** @dataProvider elements */
    public function testNewWindowRelTitleAndAriaLabelAreTheSameForEveryElement(callable $render, string $urlKey, string $tabKey): void
    {
        $internal = $render([$urlKey => '/contact.html', $tabKey => true, 'link_rel' => 'nofollow',
            'link_title' => 'Contact "us"', 'link_aria_label' => '联系我们']);
        self::assertStringContainsString('href="/contact.html" ', $internal);
        self::assertStringContainsString(' target="_blank" rel="nofollow noopener" title="Contact &quot;us&quot;" aria-label="联系我们"', $internal);

        // 外链开新窗口再加 noreferrer
        $external = $render([$urlKey => 'https://example.com/x', $tabKey => '1']);
        self::assertStringContainsString(' target="_blank" rel="noopener noreferrer"', $external);

        // 存储里的 "0" / "false" 不是开新窗口（按钮与图片此前用 !empty() 判断，会误开）
        foreach (['0', 'false', false, ''] as $off) {
            $html = $render([$urlKey => '/contact.html', $tabKey => $off]);
            self::assertStringNotContainsString('target=', $html);
            self::assertStringNotContainsString('rel=', $html);
        }

        // rel 只接受白名单；title / aria-label 留空不输出，控制字符与引号不能逃出属性
        $sanitized = $render([$urlKey => '/x', 'link_rel' => 'nofollow" onclick="x', 'link_title' => '  ', 'link_aria_label' => "a\"\n><script>"]);
        self::assertStringNotContainsString('onclick', $sanitized);
        self::assertStringNotContainsString(' title=', $sanitized);
        self::assertStringContainsString('aria-label="a&quot; &gt;&lt;script&gt;"', $sanitized);
        self::assertStringNotContainsString('<script>', $sanitized);
    }

    public function testEmptyInvalidAndMissingDynamicLinksKeepTheirSafeFallbacks(): void
    {
        $heading = new HeadingElement();
        $button = new ButtonElement();
        $image = new ImageElement();
        foreach (['', 'javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', '//evil.test/x', "java\tscript:x"] as $bad) {
            // 标题：不渲染链接
            self::assertStringNotContainsString('<a ', $heading->render(['text' => 'T', 'url' => $bad, 'new_tab' => true]), $bad);
            // 按钮：退化为 #，不带新窗口外链语义
            $html = $button->render(['text' => 'B', 'url' => $bad, 'new_tab' => true]);
            self::assertStringContainsString('href="#"', $html, $bad);
            self::assertStringNotContainsString('javascript', strtolower($html));
            // 图片：退化为普通图片
            self::assertStringNotContainsString('<a ', $image->render(['src' => '/a.jpg', 'click_action' => 'link', 'link_url' => $bad]), $bad);
        }
        // 动态值缺失：未知的站点字段 / 动态标签解析为空时同样按空链接处理
        self::assertStringNotContainsString('<a ', $heading->render(['text' => 'T', 'site_url_field' => 'not_a_field']));
        self::assertStringContainsString('href="#"', $button->render(['text' => 'B', 'site_url_field' => 'not_a_field']));
        self::assertStringNotContainsString('<a ', $image->render(['src' => '/a.jpg', 'click_action' => 'link', 'link_url' => '{{missing.provider}}']));
    }

    public function testLinkAttributeControlsAreDeclaredOnAllThreeElements(): void
    {
        foreach ([new HeadingElement(), new ButtonElement(), new ImageElement()] as $element) {
            $keys = array_column($element->controls(), 'key');
            foreach (['link_rel', 'link_title', 'link_aria_label'] as $key) {
                self::assertContains($key, $keys, $element->type() . ' ' . $key);
            }
        }
        self::assertSame(['nofollow', 'sponsored', 'ugc'], AbstractElement::LINK_REL_OPTIONS);
    }
}
