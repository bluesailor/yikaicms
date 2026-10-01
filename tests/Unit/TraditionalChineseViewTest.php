<?php
/**
 * 繁体中文（zh-TW）是简体的「渲染视图」：整页输出前简→繁（includes/i18n/S2T.php）。
 *
 * 2.0.3 补齐的几处：行内 <script>（结构化数据、界面文案字典、灯箱标题）与前台 JSON 也要转；
 * 会回传给服务器比对的选项（表单选项、商城地区）映射回简体原值；访客输入的繁体搜索词转回简体。
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/i18n/S2T.php';

final class TraditionalChineseViewTest extends TestCase
{
    public function testInlineScriptsAreConvertedButStylesCommentsAndSkippedScriptsAreNot(): void
    {
        $html = '<p>产品中心</p>'
            . '<script type="application/ld+json">{"name":"公司简介"}</script>'
            . '<script>var t={"copy":"复制","copied":"\u5df2\u590d\u5236"};</script>'
            . '<script type="application/json" data-s2t="skip">{"n":"广东省"}</script>'
            . '<style>.a:after{content:"产品"}</style><!-- 产品 -->';
        $out = S2T::convert($html);

        self::assertStringContainsString('<p>產品中心</p>', $out);
        self::assertStringContainsString('{"name":"公司簡介"}', $out);
        self::assertStringContainsString('"copy":"複製"', $out);
        // 转义写法的汉字转完仍是转义写法：已複製
        self::assertStringContainsString('"copied":"\u5df2\u8907\u88fd"', $out);
        self::assertStringContainsString('{"n":"广东省"}', $out);
        self::assertStringContainsString('content:"产品"', $out);
        self::assertStringContainsString('<!-- 产品 -->', $out);
    }

    public function testJsonResponsesAreConvertedWithoutBreakingSyntax(): void
    {
        $json = (string) json_encode(['code' => 1, 'msg' => '请填写联系电话', 'escaped' => '请填写'], JSON_UNESCAPED_UNICODE);
        $json = str_replace('"escaped":"请填写"', '"escaped":"\u8bf7\u586b\u5199"', $json);
        $out = S2T::convertOutput($json);
        $decoded = json_decode($out, true);

        self::assertIsArray($decoded);
        self::assertSame('請填寫聯絡電話', $decoded['msg']);
        self::assertSame('請填寫', $decoded['escaped']);
    }

    public function testCanonicalMapsDisplayedTraditionalBackToStoredSimplified(): void
    {
        $allowed = ['产品咨询', '售后服务', '其他'];
        self::assertSame('产品咨询', S2T::canonical('產品諮詢', $allowed));
        self::assertSame('售后服务', S2T::canonical('售後服務', $allowed));
        self::assertSame('其他', S2T::canonical('其他', $allowed));
        self::assertSame('不存在', S2T::canonical('不存在', $allowed));
    }

    public function testVisitorSearchTermsMapBackToSimplified(): void
    {
        self::assertSame('产品与服务', S2T::toSimplified('產品與服務'));
        self::assertSame('联系我们', S2T::toSimplified('聯繫我們'));
        self::assertSame('后台 里面 头发', S2T::toSimplified('後臺 裡面 頭髮'));
        self::assertSame('ABC 123', S2T::toSimplified('ABC 123'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testFormChoicesSubmittedFromTheTraditionalSiteAreAccepted(): void
    {
        define('SITE_LANG', 'zh-TW');
        require_once ROOT_PATH . '/includes/FormFieldContract.php';
        $options = ['产品咨询', '售后服务'];

        self::assertSame('售后服务', FormFieldContract::choiceValue('select', '售後服務', $options));
        self::assertSame('产品咨询, 售后服务', FormFieldContract::choiceValue('checkbox', ['售後服務', '產品諮詢'], $options));
        $this->expectException(InvalidArgumentException::class);
        FormFieldContract::choiceValue('select', '別的', $options);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testShopRegionNamesFromTheTraditionalSiteMapBack(): void
    {
        define('SITE_LANG', 'zh-TW');
        require_once ROOT_PATH . '/plugins/shop/lib/regions.php';
        require_once ROOT_PATH . '/plugins/shop/lib/shipping.php';
        $province = shopMainlandRegionTree()[0];
        $city = $province['ch'][0];
        $district = $city['ch'][0];

        $mapped = shopMainlandRegionFromDisplay(S2T::text($province['n']), S2T::text($city['n']), S2T::text($district['n']));
        self::assertSame([$province['n'], $city['n'], $district['n']], $mapped);
        self::assertNotNull(shopMainlandRegionPath(...$mapped));
    }

    public function testOtherLanguagesAreUntouched(): void
    {
        require_once ROOT_PATH . '/includes/FormFieldContract.php';
        if (defined('SITE_LANG')) {
            self::markTestSkipped('SITE_LANG already fixed in this process');
        }
        $this->expectException(InvalidArgumentException::class);
        FormFieldContract::choiceValue('select', '售後服務', ['售后服务']);
    }
}
