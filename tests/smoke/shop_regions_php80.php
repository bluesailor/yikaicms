<?php
/** Read-only, standalone PHP 8.0 compatibility smoke for offline shipping regions. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Never bootstrap the CMS, Composer, site settings or a production database.
if (function_exists('db') || function_exists('config') || defined('ROOT_PATH')) {
    fwrite(STDERR, "Run this smoke as a standalone CLI script, without the CMS bootstrap.\n");
    exit(1);
}

$GLOBALS['shop_region_smoke_settings'] = [];
if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return $GLOBALS['shop_region_smoke_settings'][$key] ?? $default;
    }
}

// PHPUnit itself requires PHP 8.1+; load only the pure shipping functions.
require_once dirname(__DIR__, 2) . '/plugins/shop/lib/shipping.php';

$checkCount = 0;
$pathCount = 0;
$assert = static function (bool $condition, string $message) use (&$checkCount): void {
    ++$checkCount;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$makeAddress = static function (string $province, string $city, string $district): array {
    return [
        'province' => $province, 'city' => $city, 'district' => $district,
        'address' => 'Test Road 1',
        'province_code' => 'forged', 'city_code' => 'forged', 'district_code' => 'forged',
        'country' => 'US', 'region' => 'Forged region', 'region_version' => 'forged',
    ];
};

try {
    $tree = shopMainlandRegionTree();
    $assert(count($tree) === 31, 'Expected 31 mainland provinces.');
    $assert(array_column($tree, 'n') === shopMainlandProvinces(), 'Province options differ from the bundled tree.');
    $assert(shopMainlandRegionVersion() !== '', 'Region data version is missing.');

    // Every leaf is exercised, including municipalities, direct cities and 12-digit town/street codes.
    foreach ($tree as $province) {
        $assert(is_string($province['c']), 'Province code must be a string.');
        $assert($province['ch'] !== [], 'A province has no cities.');
        foreach ($province['ch'] as $city) {
            $assert($city['ch'] !== [], 'A city has no districts or town/street options.');
            foreach ($city['ch'] as $district) {
                $label = implode('/', [$province['n'], $city['n'], $district['n']]);
                $result = shopNormalizeMainlandAddress($makeAddress($province['n'], $city['n'], $district['n']));
                $assert($result['ok'], 'Valid path rejected: ' . $label);
                $address = $result['address'];
                $assert($address['province_code'] === $province['c'], 'Forged province code retained: ' . $label);
                $assert($address['city_code'] === $city['c'], 'Forged city code retained: ' . $label);
                $assert($address['district_code'] === $district['c'], 'Forged district code retained: ' . $label);
                $assert($address['country'] === 'CN', 'Forged country retained: ' . $label);
                $assert($address['region_version'] === shopMainlandRegionVersion(), 'Forged data version retained: ' . $label);
                $assert($address['address'] === 'Test Road 1', 'Detailed address changed: ' . $label);
                ++$pathCount;
            }
        }
    }
    $assert($pathCount >= 2800, 'Bundled tree unexpectedly contains too few address paths.');

    $invalidPaths = [
        ['广东省', '杭州市', '西湖区'],
        ['广东省', '广州市', '南山区'],
        ['广东省', '广州市', '浦东新区'],
        ['广东省', '东莞市', '石岐街道'],
        ['重庆市', '重庆市', '城口县'],
        ['重庆市', '县', '渝中区'],
        ['河南省', '济源市', '中原区'],
        ['新疆维吾尔自治区', '石河子市', '阿拉尔市'],
        ['广东省', '阿勒泰地区', '布尔津县'],
        ['广东省', 'Not a city', 'Not a district'],
    ];
    foreach ($invalidPaths as [$province, $city, $district]) {
        $result = shopValidateShippingAddress($makeAddress($province, $city, $district));
        $assert(!$result['ok'] && $result['error'] === 'shop_err_address_region', 'Invalid hierarchy was not rejected.');
    }

    foreach (['香港特别行政区', '澳门特别行政区', '台湾省'] as $province) {
        $result = shopNormalizeMainlandAddress($makeAddress($province, 'Not a city', 'Not a district'));
        $assert(!$result['ok'] && $result['error'] === 'shop_err_address_province', 'Non-mainland province was not rejected.');
    }
    foreach (['city', 'district', 'address'] as $field) {
        foreach (['', "bad\x00value", ['array input']] as $value) {
            $input = $makeAddress('广东省', '广州市', '天河区');
            $input[$field] = $value;
            $result = shopNormalizeMainlandAddress($input);
            $assert(!$result['ok'] && $result['error'] === 'shop_err_contact_address', 'Malformed address field was not rejected.');
        }
    }

    $GLOBALS['shop_region_smoke_settings'] = [
        'shop_shipping_excluded_provinces' => json_encode(['西藏自治区'], JSON_THROW_ON_ERROR),
        'shop_shipping_excluded_regions' => "广东省/东莞市/虎门镇\n河南省/济源市\n新疆维吾尔自治区/阿勒泰地区/布尔津县",
    ];
    $blockedPaths = [
        ['西藏自治区', '拉萨市', '城关区'],
        ['广东省', '东莞市', '虎门镇'],
        ['河南省', '济源市', '济源市'],
        ['新疆维吾尔自治区', '阿勒泰地区', '布尔津县'],
    ];
    foreach ($blockedPaths as [$province, $city, $district]) {
        $result = shopValidateShippingAddress($makeAddress($province, $city, $district));
        $assert(!$result['ok'] && $result['error'] === 'shop_err_shipping_unavailable', 'Excluded destination accepted.');
    }
    $allowed = shopValidateShippingAddress($makeAddress('广东省', '东莞市', '东城街道'));
    $assert($allowed['ok'], 'Allowed neighboring street was excluded.');

    $GLOBALS['shop_region_smoke_settings'] = [];
    $rules = "新疆维吾尔自治区 = 20\n新疆维吾尔自治区/阿勒泰地区 = 35.50\n新疆维吾尔自治区/阿勒泰地区/布尔津县 = 42.25";
    foreach ([['乌鲁木齐市', '天山区', 2000], ['阿勒泰地区', '富蕴县', 3550], ['阿勒泰地区', '布尔津县', 4225]] as [$city, $district, $cents]) {
        $result = shopValidateShippingAddress($makeAddress('新疆维吾尔自治区', $city, $district));
        $assert($result['ok'], 'Valid surcharge destination rejected.');
        $assert(shopShippingSurchargeCents($result['address'], $rules) === $cents, 'Longest surcharge rule did not win.');
    }
    $directCity = shopValidateShippingAddress($makeAddress('河南省', '济源市', '济源市'));
    $assert($directCity['ok'], 'Direct-admin city rejected.');
    $assert($directCity['address']['region'] === '河南省 济源市', 'Duplicate direct-admin city name was not collapsed.');
    $assert(shopShippingSurchargeCents($directCity['address'], "河南省 = 5\n河南省/济源市 = 15") === 1500, 'Direct-admin city surcharge failed.');

    echo 'PHP ' . PHP_VERSION . ': region data ' . shopMainlandRegionVersion() . ', ' . $pathCount
        . ' paths, ' . $checkCount . ' checks passed; no CMS bootstrap, database or file writes.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Region smoke failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
