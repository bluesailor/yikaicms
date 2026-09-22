<?php
/**
 * 中国大陆收货地址、配送范围与快递公司配置。
 *
 * 地址按省级地区 / 市 / 区县（或镇街）结构化保存；服务区规则按路径前缀匹配，
 * 例如「海南省/三沙市」会排除三沙市下全部区级地址。
 */

declare(strict_types=1);

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/regions.php';

/** @return list<string> 中国大陆 31 个省级行政区（不含港澳台）。 */
function shopMainlandProvinces(): array
{
    return array_column(shopMainlandRegionTree(), 'n');
}

/** @return list<string> 出厂快递公司；后台可删改顺序。 */
function shopDefaultShippingCarriers(): array
{
    return ['顺丰速运', '京东物流', '中国邮政', '邮政EMS', '中通快递', '圆通速递', '申通快递', '韵达速递', '极兔速递', '德邦快递', '商家配送'];
}

/** 是否含控制字符；地址节点和配置项都必须是单行可见文本。 */
function shopShippingHasControlChars(string $value): bool
{
    return preg_match('/[\x00-\x1F\x7F]/u', $value) === 1;
}

/** 连续相同节点折叠（直辖市常见「上海市/上海市/浦东新区」）。@return list<string> */
function shopShippingCollapsePath(array $parts): array
{
    $result = [];
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '' || ($result !== [] && $result[count($result) - 1] === $part)) {
            continue;
        }
        $result[] = $part;
    }
    return $result;
}

/** Keep pre-cascade Chongqing county rules equivalent to the dataset's county group. @return list<string> */
function shopShippingNormalizeRulePath(array $parts): array
{
    $path = shopShippingCollapsePath($parts);
    if (count($path) === 2 && $path[0] === '重庆市'
        && shopMainlandRegionPath('重庆市', '县', $path[1]) !== null) {
        return ['重庆市', '县', $path[1]];
    }
    return $path;
}

/**
 * 结构化并验证大陆地址。
 *
 * @param array<string,mixed> $input
 * @return array{ok:bool,error:string,address?:array<string,string>}
 */
function shopNormalizeMainlandAddress(array $input): array
{
    foreach (['province', 'city', 'district', 'address'] as $field) {
        if (isset($input[$field]) && !is_string($input[$field])) {
            return ['ok' => false, 'error' => 'shop_err_contact_address'];
        }
    }
    $province = trim((string) ($input['province'] ?? ''));
    $city = trim((string) ($input['city'] ?? ''));
    $district = trim((string) ($input['district'] ?? ''));
    $detail = trim((string) ($input['address'] ?? ''));

    if (!in_array($province, shopMainlandProvinces(), true)) {
        return ['ok' => false, 'error' => 'shop_err_address_province'];
    }
    foreach ([$city, $district] as $part) {
        if ($part === '' || mb_strlen($part) > 50 || shopShippingHasControlChars($part)) {
            return ['ok' => false, 'error' => 'shop_err_contact_address'];
        }
    }
    if ($detail === '' || mb_strlen($detail) > 300 || shopShippingHasControlChars($detail)) {
        return ['ok' => false, 'error' => 'shop_err_contact_address'];
    }
    $codes = shopMainlandRegionPath($province, $city, $district);
    if ($codes === null) {
        return ['ok' => false, 'error' => 'shop_err_address_region'];
    }

    $region = implode(' ', shopShippingCollapsePath([$province, $city, $district]));
    return [
        'ok' => true,
        'error' => '',
        'address' => [
            'country' => 'CN',
            'province' => $province,
            'city' => $city,
            'district' => $district,
            // Codes come from the bundled tree, never from client-supplied hidden fields.
            'province_code' => $codes['province_code'],
            'city_code' => $codes['city_code'],
            'district_code' => $codes['district_code'],
            'region_version' => shopMainlandRegionVersion(),
            // 保留 region 兼容已上线的后台/订单展示模板。
            'region' => $region,
            'address' => $detail,
        ],
    ];
}

/**
 * 校验后台逐行填写的排除路径。
 *
 * @return array{ok:bool,error:string,value:string,rules:list<list<string>>}
 */
function shopNormalizeExcludedRegionConfig(string $raw): array
{
    if (mb_strlen($raw) > 10000) {
        return ['ok' => false, 'error' => 'shop_err_shipping_regions', 'value' => '', 'rules' => []];
    }
    $lines = preg_split('/\R/u', $raw) ?: [];
    $normalized = [];
    $rules = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = shopShippingNormalizeRulePath(preg_split('/[\/>＞]+/u', $line) ?: []);
        if ($parts === [] || count($parts) > 3 || !in_array($parts[0], shopMainlandProvinces(), true)) {
            return ['ok' => false, 'error' => 'shop_err_shipping_regions', 'value' => '', 'rules' => []];
        }
        foreach ($parts as $part) {
            if (mb_strlen($part) > 50 || shopShippingHasControlChars($part)) {
                return ['ok' => false, 'error' => 'shop_err_shipping_regions', 'value' => '', 'rules' => []];
            }
        }
        $key = implode('/', $parts);
        if (!isset($normalized[$key])) {
            $normalized[$key] = $key;
            $rules[] = $parts;
        }
        if (count($rules) > 100) {
            return ['ok' => false, 'error' => 'shop_err_shipping_regions', 'value' => '', 'rules' => []];
        }
    }

    return ['ok' => true, 'error' => '', 'value' => implode("\n", array_values($normalized)), 'rules' => $rules];
}

/**
 * 指定地区附加运费。逐行格式：省/市/区县 = 金额；最长路径优先。
 * @return array{ok:bool,error:string,value:string,rules:list<array{path:list<string>,cents:int}>}
 */
function shopNormalizeRegionSurchargeConfig(string $raw): array
{
    $fail = static fn(): array => ['ok' => false, 'error' => 'shop_err_shipping_surcharges', 'value' => '', 'rules' => []];
    if (mb_strlen($raw) > 10000) {
        return $fail();
    }
    $normalized = [];
    $rules = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $pair = preg_split('/\s*=\s*/u', $line, 2);
        if (!is_array($pair) || count($pair) !== 2) {
            return $fail();
        }
        $parts = shopShippingNormalizeRulePath(preg_split('/[\/>＞]+/u', trim($pair[0])) ?: []);
        if ($parts === [] || count($parts) > 3 || !in_array($parts[0], shopMainlandProvinces(), true)) {
            return $fail();
        }
        foreach ($parts as $part) {
            if (mb_strlen($part) > 50 || shopShippingHasControlChars($part)) {
                return $fail();
            }
        }
        $cents = shopValidSalePriceCents(trim($pair[1]));
        if ($cents === null) {
            return $fail();
        }
        $key = implode('/', $parts);
        if (isset($normalized[$key])) {
            return $fail();
        }
        $normalized[$key] = $key . ' = ' . shopCentsToDecimal($cents);
        $rules[] = ['path' => $parts, 'cents' => $cents];
        if (count($rules) > 100) {
            return $fail();
        }
    }
    return ['ok' => true, 'error' => '', 'value' => implode("\n", array_values($normalized)), 'rules' => $rules];
}

/** @return list<array{path:list<string>,cents:int}> */
function shopShippingSurchargeRules(?string $raw = null): array
{
    $parsed = shopNormalizeRegionSurchargeConfig(
        $raw ?? (string) config('shop_shipping_region_surcharges', '')
    );
    return $parsed['ok'] ? $parsed['rules'] : [];
}

/** 最长匹配路径的附加运费（分）；无匹配为 0。 */
function shopShippingSurchargeCents(array $address, ?string $raw = null): int
{
    $path = shopShippingNormalizeRulePath([
        (string) ($address['province'] ?? ''),
        (string) ($address['city'] ?? ''),
        (string) ($address['district'] ?? ''),
    ]);
    $bestDepth = 0;
    $bestCents = 0;
    foreach (shopShippingSurchargeRules($raw) as $rule) {
        $depth = count($rule['path']);
        if ($depth > $bestDepth && shopShippingPathMatchesRule($path, $rule['path'])) {
            $bestDepth = $depth;
            $bestCents = $rule['cents'];
        }
    }
    return $bestCents;
}

/** @return list<list<string>> 当前所有排除规则（整省勾选 + 细分路径）。 */
function shopShippingExcludedRules(): array
{
    $rules = [];
    $storedProvinces = json_decode((string) config('shop_shipping_excluded_provinces', '[]'), true);
    foreach (is_array($storedProvinces) ? $storedProvinces : [] as $province) {
        if (is_string($province) && in_array($province, shopMainlandProvinces(), true)) {
            $rules[] = [$province];
        }
    }
    $specific = shopNormalizeExcludedRegionConfig((string) config('shop_shipping_excluded_regions', ''));
    if ($specific['ok']) {
        foreach ($specific['rules'] as $rule) {
            $rules[] = $rule;
        }
    }
    return $rules;
}

/** @param list<string> $path @param list<string> $rule */
function shopShippingPathMatchesRule(array $path, array $rule): bool
{
    if (count($rule) > count($path)) {
        return false;
    }
    foreach ($rule as $index => $part) {
        if (($path[$index] ?? null) !== $part) {
            return false;
        }
    }
    return true;
}

/**
 * 地址格式 + 服务区统一门禁；订单核心与结算入口都必须调用。
 *
 * @param array<string,mixed> $input
 * @return array{ok:bool,error:string,address?:array<string,string>}
 */
function shopValidateShippingAddress(array $input): array
{
    $normalized = shopNormalizeMainlandAddress($input);
    if (!$normalized['ok']) {
        return $normalized;
    }
    $address = $normalized['address'];
    $path = shopShippingNormalizeRulePath([$address['province'], $address['city'], $address['district']]);
    foreach (shopShippingExcludedRules() as $rule) {
        if (shopShippingPathMatchesRule($path, $rule)) {
            return ['ok' => false, 'error' => 'shop_err_shipping_unavailable'];
        }
    }
    return $normalized;
}

/**
 * 快递公司配置：每行一个，最多 30 个。
 *
 * @return array{ok:bool,error:string,value:string,carriers:list<string>}
 */
function shopNormalizeCarrierConfig(string $raw): array
{
    if (mb_strlen($raw) > 3000) {
        return ['ok' => false, 'error' => 'shop_err_shipping_carriers', 'value' => '', 'carriers' => []];
    }
    $carriers = [];
    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $carrier = trim($line);
        if ($carrier === '') {
            continue;
        }
        if (mb_strlen($carrier) > 50 || shopShippingHasControlChars($carrier)) {
            return ['ok' => false, 'error' => 'shop_err_shipping_carriers', 'value' => '', 'carriers' => []];
        }
        $carriers[$carrier] = $carrier;
        if (count($carriers) > 30) {
            return ['ok' => false, 'error' => 'shop_err_shipping_carriers', 'value' => '', 'carriers' => []];
        }
    }
    $values = array_values($carriers);
    return ['ok' => true, 'error' => '', 'value' => implode("\n", $values), 'carriers' => $values];
}

/** @return list<string> */
function shopShippingCarriers(): array
{
    $default = implode("\n", shopDefaultShippingCarriers());
    $parsed = shopNormalizeCarrierConfig((string) config('shop_shipping_carriers', $default));
    return $parsed['ok'] ? $parsed['carriers'] : shopDefaultShippingCarriers();
}

/** 发货输入门禁。返回空字符串表示合法，否则返回语言键。 */
function shopValidateTracking(string $company, string $trackingNo): string
{
    $company = trim($company);
    $trackingNo = trim($trackingNo);
    if ($company !== '' && !in_array($company, shopShippingCarriers(), true)) {
        return 'shop_err_tracking_company';
    }
    if ($trackingNo !== '' && $company === '') {
        return 'shop_err_tracking_company';
    }
    if ($trackingNo !== '' && (mb_strlen($trackingNo) > 64 || preg_match('/^[A-Za-z0-9-]+$/', $trackingNo) !== 1)) {
        return 'shop_err_tracking';
    }
    return '';
}
