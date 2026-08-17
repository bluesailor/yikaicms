<?php
/**
 * 首页自定义区块（home_custom_<N>）按语言分流读取。
 *
 * 起因（2026-08-11 演示站）：定价表/FAQ 这类自定义区块原先用 config() 直读 base，
 * 全站三语共用同一份内容——英文站、日文站前台都会显示中文那份，货币符号更无从区分。
 * 修法：渲染改走 configJsonLang()，非默认语言优先读 home_custom_<N>_<lang>，
 * 没有变体则回落 base（= 默认语言）。演示站借此让中/英/日各显 ¥ / $ / ¥。
 *
 * 本测试锁三件事：非默认语言选中对应后缀变体；缺变体时回落 base；默认语言直取 base。
 * 契约要与 includes/builder/HomeBloxRenderContext.php 的 custom: 渲染读取保持一致。
 */
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class HomeCustomBlockLangTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // config()/siteLang()/configJsonLang() 均由 tests/bootstrap.php 镜像桩提供，读 $GLOBALS['_test_config']。
        // 默认语言 = zh-CN：base 存中文，_en/_ja 只存非默认语言。
        $GLOBALS['_test_config']['home_custom_1']    = json_encode(['title' => '价格方案'], JSON_UNESCAPED_UNICODE);
        $GLOBALS['_test_config']['home_custom_1_en'] = json_encode(['title' => 'Pricing Plans'], JSON_UNESCAPED_UNICODE);
        // 故意不给 home_custom_1_ja：验证缺变体时回落 base
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_test_config']['site_lang'],
            $GLOBALS['_test_config']['home_custom_1'],
            $GLOBALS['_test_config']['home_custom_1_en']
        );
        parent::tearDown();
    }

    private function title(): string
    {
        return (string) (json_decode(configJsonLang('home_custom_1'), true)['title'] ?? '');
    }

    public function testNonDefaultLanguagePicksSuffixVariant(): void
    {
        $GLOBALS['_test_config']['site_lang'] = 'en';
        $this->assertSame('Pricing Plans', $this->title(), '英文站必须读到 _en 变体');
    }

    public function testFallsBackToBaseWhenVariantMissing(): void
    {
        $GLOBALS['_test_config']['site_lang'] = 'ja';
        $this->assertSame('价格方案', $this->title(), '缺 _ja 变体时回落 base（默认语言内容）');
    }

    public function testDefaultLanguageReadsBase(): void
    {
        $GLOBALS['_test_config']['site_lang'] = 'zh-CN';
        $this->assertSame('价格方案', $this->title(), '默认语言直取 base');
    }
}
