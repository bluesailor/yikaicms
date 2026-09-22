<?php
/** 商城中国大陆地址、服务区和快递配置。 */

declare(strict_types=1);

use Yikai\Tests\TestCase;

final class ShopShippingTest extends TestCase
{
    /** @var mixed */
    private $savedOverrides;

    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/shipping.php';
    }

    protected function setUp(): void
    {
        $this->savedOverrides = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        $GLOBALS['yikai_config_runtime_overrides'] = [];
    }

    protected function tearDown(): void
    {
        if ($this->savedOverrides === null) {
            unset($GLOBALS['yikai_config_runtime_overrides']);
        } else {
            $GLOBALS['yikai_config_runtime_overrides'] = $this->savedOverrides;
        }
    }

    public function testMainlandProvinceBoundaryExcludesHongKongMacaoAndTaiwan(): void
    {
        $provinces = shopMainlandProvinces();
        $this->assertCount(31, $provinces);
        $this->assertContains('北京市', $provinces);
        $this->assertContains('新疆维吾尔自治区', $provinces);
        $this->assertNotContains('香港特别行政区', $provinces);
        $this->assertNotContains('澳门特别行政区', $provinces);
        $this->assertNotContains('台湾省', $provinces);
    }

    public function testStructuredAddressNormalizesMunicipalityWithoutDuplicateRegion(): void
    {
        $result = shopNormalizeMainlandAddress([
            'province' => '上海市',
            'city' => '上海市',
            'district' => '浦东新区',
            'address' => '世纪大道 1 号',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('CN', $result['address']['country']);
        $this->assertSame('上海市 浦东新区', $result['address']['region']);
        $this->assertSame('世纪大道 1 号', $result['address']['address']);
    }

    public function testInvalidProvinceAndIncompleteAddressFailClosed(): void
    {
        $invalidProvince = shopNormalizeMainlandAddress([
            'province' => '香港特别行政区', 'city' => '香港', 'district' => '中西区', 'address' => '测试地址',
        ]);
        $this->assertFalse($invalidProvince['ok']);
        $this->assertSame('shop_err_address_province', $invalidProvince['error']);

        $missingDistrict = shopNormalizeMainlandAddress([
            'province' => '广东省', 'city' => '东莞市', 'district' => '', 'address' => '测试地址',
        ]);
        $this->assertFalse($missingDistrict['ok']);
        $this->assertSame('shop_err_contact_address', $missingDistrict['error']);
    }

    public function testProvinceAndPathPrefixRulesBlockServiceAreas(): void
    {
        $GLOBALS['yikai_config_runtime_overrides'] = [
            'shop_shipping_excluded_provinces' => json_encode(['西藏自治区'], JSON_THROW_ON_ERROR),
            'shop_shipping_excluded_regions' => "海南省/三沙市\n新疆维吾尔自治区/阿勒泰地区/布尔津县",
        ];

        $province = shopValidateShippingAddress([
            'province' => '西藏自治区', 'city' => '拉萨市', 'district' => '城关区', 'address' => '测试路 1 号',
        ]);
        $city = shopValidateShippingAddress([
            'province' => '海南省', 'city' => '三沙市', 'district' => '西沙区', 'address' => '测试路 1 号',
        ]);
        $allowed = shopValidateShippingAddress([
            'province' => '海南省', 'city' => '海口市', 'district' => '龙华区', 'address' => '测试路 1 号',
        ]);

        $this->assertSame('shop_err_shipping_unavailable', $province['error']);
        $this->assertSame('shop_err_shipping_unavailable', $city['error']);
        $this->assertTrue($allowed['ok']);
    }

    public function testRegionAndCarrierConfigsAreNormalizedAndBounded(): void
    {
        $regions = shopNormalizeExcludedRegionConfig("海南省＞三沙市\n海南省/三沙市\n");
        $this->assertTrue($regions['ok']);
        $this->assertSame('海南省/三沙市', $regions['value']);
        $this->assertFalse(shopNormalizeExcludedRegionConfig('海外/测试')['ok']);

        $carriers = shopNormalizeCarrierConfig("顺丰速运\n京东物流\n顺丰速运\n");
        $this->assertTrue($carriers['ok']);
        $this->assertSame(['顺丰速运', '京东物流'], $carriers['carriers']);
        $this->assertFalse(shopNormalizeCarrierConfig(str_repeat('长', 51))['ok']);
    }

    public function testTrackingCarrierMustComeFromConfiguredList(): void
    {
        $GLOBALS['yikai_config_runtime_overrides'] = ['shop_shipping_carriers' => "顺丰速运\n商家配送"];

        $this->assertSame('', shopValidateTracking('', ''));
        $this->assertSame('', shopValidateTracking('商家配送', ''));
        $this->assertSame('', shopValidateTracking('顺丰速运', 'SF1234567890'));
        $this->assertSame('shop_err_tracking_company', shopValidateTracking('未知快递', '123'));
        $this->assertSame('shop_err_tracking_company', shopValidateTracking('', '123'));
        $this->assertSame('shop_err_tracking', shopValidateTracking('顺丰速运', 'bad number'));
    }

    public function testRegionSurchargeUsesLongestMatchingPath(): void
    {
        $raw = "新疆维吾尔自治区 = 20\n新疆维吾尔自治区/阿勒泰地区 = 35.50\n西藏自治区 = 40";
        $parsed = shopNormalizeRegionSurchargeConfig($raw);
        self::assertTrue($parsed['ok']);
        self::assertSame(3, count($parsed['rules']));
        self::assertSame(3550, shopShippingSurchargeCents([
            'province' => '新疆维吾尔自治区', 'city' => '阿勒泰地区', 'district' => '布尔津县',
        ], $parsed['value']));
        self::assertSame(2000, shopShippingSurchargeCents([
            'province' => '新疆维吾尔自治区', 'city' => '乌鲁木齐市', 'district' => '天山区',
        ], $parsed['value']));
        self::assertSame(0, shopShippingSurchargeCents([
            'province' => '上海市', 'city' => '上海市', 'district' => '浦东新区',
        ], $parsed['value']));
        self::assertFalse(shopNormalizeRegionSurchargeConfig('海外 = 10')['ok']);
        self::assertFalse(shopNormalizeRegionSurchargeConfig('新疆维吾尔自治区 = 0')['ok']);
    }

    public function testLegacyChongqingCountyExclusionMatchesCanonicalAddress(): void
    {
        $GLOBALS['yikai_config_runtime_overrides'] = [
            'shop_shipping_excluded_regions' => '重庆市/城口县',
        ];
        $blocked = shopValidateShippingAddress([
            'province' => '重庆市', 'city' => '县', 'district' => '城口县', 'address' => 'Test Road 1',
        ]);
        self::assertFalse($blocked['ok']);
        self::assertSame('shop_err_shipping_unavailable', $blocked['error']);
        $normalized = shopNormalizeExcludedRegionConfig("重庆市/城口县\n重庆市/县/城口县");
        self::assertTrue($normalized['ok']);
        self::assertSame('重庆市/县/城口县', $normalized['value']);
        self::assertCount(1, $normalized['rules']);
    }

    public function testLegacyAndCanonicalChongqingCountySurchargesAreEquivalent(): void
    {
        $current = ['province' => '重庆市', 'city' => '县', 'district' => '城口县'];
        $legacy = ['province' => '重庆市', 'city' => '重庆市', 'district' => '城口县'];
        foreach (['重庆市/城口县 = 20', '重庆市/县/城口县 = 20'] as $raw) {
            self::assertSame(2000, shopShippingSurchargeCents($current, $raw));
            self::assertSame(2000, shopShippingSurchargeCents($legacy, $raw));
            $normalized = shopNormalizeRegionSurchargeConfig($raw);
            self::assertTrue($normalized['ok']);
            self::assertSame('重庆市/县/城口县 = 20.00', $normalized['value']);
        }
    }

    public function testChongqingCountyGroupDoesNotBlockUrbanDistricts(): void
    {
        $GLOBALS['yikai_config_runtime_overrides'] = [
            'shop_shipping_excluded_regions' => '重庆市/县',
        ];
        $county = shopValidateShippingAddress([
            'province' => '重庆市', 'city' => '县', 'district' => '城口县', 'address' => 'Test Road 1',
        ]);
        $urban = shopValidateShippingAddress([
            'province' => '重庆市', 'city' => '重庆市', 'district' => '渝中区', 'address' => 'Test Road 1',
        ]);
        self::assertFalse($county['ok']);
        self::assertSame('shop_err_shipping_unavailable', $county['error']);
        self::assertTrue($urban['ok']);
        self::assertSame(['重庆市', '县'], shopShippingNormalizeRulePath(['重庆市', '县']));
        self::assertSame(['重庆市', '渝中区'], shopShippingNormalizeRulePath(['重庆市', '渝中区']));
        self::assertSame(['重庆市', 'Unknown county'], shopShippingNormalizeRulePath(['重庆市', 'Unknown county']));
        self::assertSame(0, shopShippingSurchargeCents($urban['address'], '重庆市/县 = 10'));
    }

    public function testSpecificChongqingCountySurchargeOverridesCountyGroup(): void
    {
        $current = ['province' => '重庆市', 'city' => '县', 'district' => '城口县'];
        $other = ['province' => '重庆市', 'city' => '县', 'district' => '丰都县'];
        foreach ([
            "重庆市/县 = 10\n重庆市/城口县 = 20",
            "重庆市/县/城口县 = 20\n重庆市/县 = 10",
        ] as $raw) {
            self::assertSame(2000, shopShippingSurchargeCents($current, $raw));
            self::assertSame(1000, shopShippingSurchargeCents($other, $raw));
        }
    }
}
