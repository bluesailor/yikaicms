<?php
/**
 * 灯箱渲染契约（E05 切片 C）。
 *
 * 三条验收项对应三组断言：
 *   · 无灯箱的页面不得引入灯箱资源（"无灯箱页面不多加载相关资源"）；
 *   · 分组名会进 CSS 选择器，必须收敛字符集，非法值降级为不分组而不是照样输出；
 *   · 脚本失败仍有可用链接——渲染出的 <a href> 指向原图，不依赖 JS 才能看图。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxAssetCollector;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxLightboxTest extends TestCase
{
    protected function setUp(): void
    {
        BloxAssetCollector::resetForTests();
    }

    private function render(array $data): string
    {
        $element = \BuilderRegistry::get('image');
        self::assertNotNull($element);
        return $element->render($data + ['src' => '/uploads/photo.jpg']);
    }

    public function testLightboxAssetsLoadOnlyWhenAnImageUsesIt(): void
    {
        $this->render(['click_action' => '']);
        $quiet = BloxAssetCollector::scripts();
        self::assertNotContains('/assets/js/blox-lightbox.js', $quiet,
            '没有灯箱图片的页面不该引入灯箱脚本');

        $this->render(['click_action' => 'lightbox']);
        self::assertContains('/assets/js/blox-lightbox.js', BloxAssetCollector::scripts());
        self::assertContains('/assets/css/blox-lightbox.css', BloxAssetCollector::styles());
    }

    /** 分组名会进 querySelector，必须只允许安全字符。 */
    public function testGroupNameIsConfinedToSafeCharacters(): void
    {
        $html = $this->render(['click_action' => 'lightbox', 'lightbox_group' => 'pets-2024']);
        self::assertStringContainsString('data-lightbox="pets-2024"', $html);

        foreach (['a"b', "a'b", 'a b', 'a]b', str_repeat('x', 40), '"],*'] as $bad) {
            $html = $this->render(['click_action' => 'lightbox', 'lightbox_group' => $bad]);
            self::assertStringNotContainsString($bad, $html, '非法分组名不得原样输出：' . $bad);
            self::assertStringContainsString(' data-lightbox', $html, '降级为不分组，而不是丢掉灯箱');
        }
    }

    /** 脚本失败也要能看图：链接本身指向原图。 */
    public function testLinkRemainsUsableWithoutScript(): void
    {
        $html = $this->render(['click_action' => 'lightbox']);
        self::assertStringContainsString('href="/uploads/photo.jpg"', $html);
        self::assertStringContainsString('<img', $html);
    }

    /** 说明文字要转义后进属性，且有长度上限。 */
    public function testCaptionIsEscapedAndBounded(): void
    {
        $html = $this->render(['click_action' => 'lightbox', 'lightbox_caption' => '小明 & "阿花"']);
        self::assertStringContainsString('data-caption="', $html);
        self::assertStringNotContainsString('data-caption="小明 & "阿花""', $html, '引号必须转义');

        $long = str_repeat('说明', 400);
        $html = $this->render(['click_action' => 'lightbox', 'lightbox_caption' => $long]);
        self::assertStringNotContainsString($long, $html, '超长说明必须截断');
    }

    /** 伪协议的 src 不得变成灯箱链接。 */
    public function testUnsafeSourceDoesNotBecomeALightboxLink(): void
    {
        $element = \BuilderRegistry::get('image');
        self::assertNotNull($element);
        $html = $element->render(['src' => 'javascript:alert(1)', 'click_action' => 'lightbox']);
        self::assertStringNotContainsString('data-lightbox', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }
}
