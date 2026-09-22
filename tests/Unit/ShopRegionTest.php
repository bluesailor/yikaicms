<?php
/** Offline mainland region hierarchy and shipping validation regression tests. */

declare(strict_types=1);

use Yikai\Tests\TestCase;

final class ShopRegionTest extends TestCase
{
    /** @var mixed */
    private $savedOverrides;

    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/regions.php';
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

    public function testBundledDataIsPinnedAndLimitedToMainlandProvinces(): void
    {
        self::assertSame('2026.0.1', shopMainlandRegionVersion());
        self::assertSame(
            '153f67889222d83323c63220bd6e3625221aac2dfd7f3ebc731f7156ac8ee94b',
            hash_file('sha256', ROOT_PATH . '/plugins/shop/data/cn-division/pca.json')
        );

        $tree = shopMainlandRegionTree();
        self::assertCount(31, $tree);
        self::assertSame(array_column($tree, 'n'), shopMainlandProvinces());
        self::assertNotContains('香港特别行政区', shopMainlandProvinces());
        self::assertNotContains('澳门特别行政区', shopMainlandProvinces());
        self::assertNotContains('台湾省', shopMainlandProvinces());

        $provinceCodes = [];
        foreach ($tree as $province) {
            self::assertIsString($province['c']);
            self::assertMatchesRegularExpression('/^[0-9]{2}$/', $province['c']);
            self::assertNotEmpty($province['n']);
            self::assertNotEmpty($province['ch']);
            $provinceCodes[] = $province['c'];
            foreach ($province['ch'] as $city) {
                self::assertIsString($city['c']);
                self::assertMatchesRegularExpression('/^[0-9]{4}(?:[0-9]{2})?$/', $city['c']);
                self::assertNotEmpty($city['n']);
                self::assertNotEmpty($city['ch']);
                foreach ($city['ch'] as $district) {
                    self::assertIsString($district['c']);
                    self::assertMatchesRegularExpression('/^[0-9]{6}(?:[0-9]{6})?$/', $district['c']);
                    self::assertNotEmpty($district['n']);
                }
            }
        }
        self::assertCount(31, array_unique($provinceCodes));
    }

    /** @dataProvider validAddressProvider */
    public function testValidHierarchyDerivesTrustedCodes(
        string $province,
        string $city,
        string $district,
        string $provinceCode,
        string $cityCode,
        string $districtCode,
        string $region
    ): void {
        $result = shopNormalizeMainlandAddress([
            'province' => $province,
            'city' => $city,
            'district' => $district,
            'address' => '  Test Road 1  ',
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('', $result['error']);
        self::assertSame('CN', $result['address']['country']);
        self::assertSame($province, $result['address']['province']);
        self::assertSame($city, $result['address']['city']);
        self::assertSame($district, $result['address']['district']);
        self::assertSame($provinceCode, $result['address']['province_code']);
        self::assertSame($cityCode, $result['address']['city_code']);
        self::assertSame($districtCode, $result['address']['district_code']);
        self::assertSame('2026.0.1', $result['address']['region_version']);
        self::assertSame($region, $result['address']['region']);
        self::assertSame('Test Road 1', $result['address']['address']);
    }

    /** @return array<string,array{string,string,string,string,string,string,string}> */
    public static function validAddressProvider(): array
    {
        return [
            'standard city' => ['广东省', '广州市', '天河区', '44', '4401', '440106', '广东省 广州市 天河区'],
            'Beijing municipality' => ['北京市', '北京市', '朝阳区', '11', '1101', '110105', '北京市 朝阳区'],
            'Shanghai municipality' => ['上海市', '上海市', '浦东新区', '31', '3101', '310115', '上海市 浦东新区'],
            'Chongqing district' => ['重庆市', '重庆市', '渝中区', '50', '5001', '500103', '重庆市 渝中区'],
            'Chongqing county group' => ['重庆市', '县', '城口县', '50', '5002', '500229', '重庆市 县 城口县'],
            'Dongguan street' => ['广东省', '东莞市', '东城街道', '44', '4419', '441900003000', '广东省 东莞市 东城街道'],
            'Dongguan town' => ['广东省', '东莞市', '虎门镇', '44', '4419', '441900121000', '广东省 东莞市 虎门镇'],
            'Zhongshan street' => ['广东省', '中山市', '石岐街道', '44', '4420', '442000001000', '广东省 中山市 石岐街道'],
            'Zhongshan town' => ['广东省', '中山市', '小榄镇', '44', '4420', '442000118000', '广东省 中山市 小榄镇'],
            'Jiyuan direct city' => ['河南省', '济源市', '济源市', '41', '419001', '419001', '河南省 济源市'],
            'Shihezi direct city' => ['新疆维吾尔自治区', '石河子市', '石河子市', '65', '659001', '659001', '新疆维吾尔自治区 石河子市'],
            'Caohu direct city' => ['新疆维吾尔自治区', '草湖市', '草湖市', '65', '659013', '659013', '新疆维吾尔自治区 草湖市'],
            'Hekang new county' => ['新疆维吾尔自治区', '和田地区', '和康县', '65', '6532', '653228', '新疆维吾尔自治区 和田地区 和康县'],
            'Hean new county' => ['新疆维吾尔自治区', '和田地区', '和安县', '65', '6532', '653229', '新疆维吾尔自治区 和田地区 和安县'],
        ];
    }

    public function testSubmittedCodesCannotOverrideNamesOrDatasetVersion(): void
    {
        $result = shopNormalizeMainlandAddress([
            'province' => ' 广东省 ', 'city' => ' 广州市 ', 'district' => ' 天河区 ', 'address' => 'Test Road 1',
            'country' => 'US', 'region' => 'Forged region',
            'province_code' => '65', 'city_code' => '6532', 'district_code' => '653229',
            'region_version' => 'forged-version',
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('CN', $result['address']['country']);
        self::assertSame('广东省 广州市 天河区', $result['address']['region']);
        self::assertSame('44', $result['address']['province_code']);
        self::assertSame('4401', $result['address']['city_code']);
        self::assertSame('440106', $result['address']['district_code']);
        self::assertSame('2026.0.1', $result['address']['region_version']);
    }

    /** @dataProvider invalidHierarchyProvider */
    public function testUnrelatedOrNonexistentHierarchyIsRejected(string $province, string $city, string $district): void
    {
        $result = shopNormalizeMainlandAddress([
            'province' => $province, 'city' => $city, 'district' => $district, 'address' => 'Test Road 1',
            'province_code' => '44', 'city_code' => '4401', 'district_code' => '440106',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('shop_err_address_region', $result['error']);
        self::assertArrayNotHasKey('address', $result);
    }

    /** @return array<string,array{string,string,string}> */
    public static function invalidHierarchyProvider(): array
    {
        return [
            'city from another province' => ['广东省', '杭州市', '西湖区'],
            'district from another city' => ['广东省', '广州市', '南山区'],
            'district from another province' => ['广东省', '广州市', '浦东新区'],
            'nonexistent city' => ['广东省', 'Not a city', '天河区'],
            'nonexistent district' => ['广东省', '广州市', 'Not a district'],
            'street from another direct city' => ['广东省', '东莞市', '石岐街道'],
            'county from wrong Chongqing branch' => ['重庆市', '重庆市', '城口县'],
            'district from wrong Chongqing branch' => ['重庆市', '县', '渝中区'],
            'unrelated direct city child' => ['河南省', '济源市', '中原区'],
            'unrelated Xinjiang direct city child' => ['新疆维吾尔自治区', '石河子市', '阿拉尔市'],
            'slash cannot inject a path' => ['广东省', '广州市/深圳市', '南山区'],
        ];
    }

    /** @dataProvider malformedFieldProvider */
    public function testIncompleteOrMalformedFieldsKeepExistingValidationErrors(string $field, string $value): void
    {
        $input = ['province' => '广东省', 'city' => '广州市', 'district' => '天河区', 'address' => 'Test Road 1'];
        $input[$field] = $value;
        $result = shopNormalizeMainlandAddress($input);

        self::assertFalse($result['ok']);
        self::assertSame('shop_err_contact_address', $result['error']);
    }

    /** @return array<string,array{string,string}> */
    public static function malformedFieldProvider(): array
    {
        return [
            'missing city' => ['city', ''],
            'blank city' => ['city', '  '],
            'missing district' => ['district', ''],
            'missing detail' => ['address', ''],
            'long city' => ['city', str_repeat('a', 51)],
            'long district' => ['district', str_repeat('a', 51)],
            'long detail' => ['address', str_repeat('a', 301)],
            'city control character' => ['city', "广州\x00市"],
            'district control character' => ['district', "天河\n区"],
            'detail control character' => ['address', "Test\x7fRoad"],
        ];
    }

    public function testNonMainlandProvinceStillHasItsSpecificError(): void
    {
        foreach (['', '香港特别行政区', '澳门特别行政区', '台湾省', 'Not a province'] as $province) {
            $result = shopNormalizeMainlandAddress([
                'province' => $province, 'city' => '广州市', 'district' => '天河区', 'address' => 'Test Road 1',
            ]);
            self::assertFalse($result['ok']);
            self::assertSame('shop_err_address_province', $result['error']);
        }
    }

    public function testServiceAreaExclusionStillUsesCanonicalAddressDespiteForgedCodes(): void
    {
        $GLOBALS['yikai_config_runtime_overrides'] = [
            'shop_shipping_excluded_provinces' => json_encode(['西藏自治区'], JSON_THROW_ON_ERROR),
            'shop_shipping_excluded_regions' => "新疆维吾尔自治区/阿勒泰地区/布尔津县\n河南省/济源市\n广东省/东莞市/虎门镇",
        ];
        $blockedAddresses = [
            ['西藏自治区', '拉萨市', '城关区'],
            ['新疆维吾尔自治区', '阿勒泰地区', '布尔津县'],
            ['河南省', '济源市', '济源市'],
            ['广东省', '东莞市', '虎门镇'],
        ];
        foreach ($blockedAddresses as [$province, $city, $district]) {
            $result = shopValidateShippingAddress([
                'province' => $province, 'city' => $city, 'district' => $district, 'address' => 'Test Road 1',
                'province_code' => '31', 'city_code' => '3101', 'district_code' => '310115',
                'region' => '上海市 浦东新区',
            ]);
            self::assertFalse($result['ok']);
            self::assertSame('shop_err_shipping_unavailable', $result['error']);
        }

        $allowed = shopValidateShippingAddress([
            'province' => '广东省', 'city' => '东莞市', 'district' => '东城街道', 'address' => 'Test Road 1',
        ]);
        self::assertTrue($allowed['ok']);
    }

    public function testChangingOnlyProvinceCannotBypassServiceAreaExclusion(): void
    {
        $GLOBALS['yikai_config_runtime_overrides'] = [
            'shop_shipping_excluded_provinces' => json_encode(['新疆维吾尔自治区'], JSON_THROW_ON_ERROR),
        ];
        $result = shopValidateShippingAddress([
            'province' => '广东省', 'city' => '阿勒泰地区', 'district' => '布尔津县', 'address' => 'Test Road 1',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('shop_err_address_region', $result['error']);
    }

    public function testLongestSurchargeRuleUsesValidatedHierarchy(): void
    {
        $rules = "新疆维吾尔自治区 = 20\n新疆维吾尔自治区/阿勒泰地区 = 35.50\n新疆维吾尔自治区/阿勒泰地区/布尔津县 = 42.25";
        $addresses = [
            ['乌鲁木齐市', '天山区', 2000],
            ['阿勒泰地区', '富蕴县', 3550],
            ['阿勒泰地区', '布尔津县', 4225],
        ];
        foreach ($addresses as [$city, $district, $expectedCents]) {
            $result = shopValidateShippingAddress([
                'province' => '新疆维吾尔自治区', 'city' => $city, 'district' => $district, 'address' => 'Test Road 1',
                'province_code' => '31', 'city_code' => '3101', 'district_code' => '310115',
            ]);
            self::assertTrue($result['ok']);
            self::assertSame($expectedCents, shopShippingSurchargeCents($result['address'], $rules));
        }

        $directCity = shopValidateShippingAddress([
            'province' => '河南省', 'city' => '济源市', 'district' => '济源市', 'address' => 'Test Road 1',
        ]);
        self::assertTrue($directCity['ok']);
        self::assertSame(1500, shopShippingSurchargeCents($directCity['address'], "河南省 = 5\n河南省/济源市 = 15"));
    }
}
