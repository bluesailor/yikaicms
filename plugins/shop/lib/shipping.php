<?php
/**
 * 中国大陆收货地址、配送范围与快递公司配置。
 *
 * 地址按省级地区 / 市 / 区县（或镇街）结构化保存；服务区规则按路径前缀匹配，
 * 例如「海南省/三沙市」会排除三沙市下全部区级地址。
 */

declare(strict_types=1);

/** @return list<string> 中国大陆 31 个省级行政区（不含港澳台）。 */
function shopMainlandProvinces(): array
{
    return [
        '北京市', '天津市', '河北省', '山西省', '内蒙古自治区', '辽宁省', '吉林省', '黑龙江省',
        '上海市', '江苏省', '浙江省', '安徽省', '福建省', '江西省', '山东省', '河南省',
        '湖北省', '湖南省', '广东省', '广西壮族自治区', '海南省', '重庆市', '四川省',
        '贵州省', '云南省', '西藏自治区', '陕西省', '甘肃省', '青海省', '宁夏回族自治区',
        '新疆维吾尔自治区',
    ];
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

/**
 * 结构化并验证大陆地址。
 *
 * @param array<string,mixed> $input
 * @return array{ok:bool,error:string,address?:array{country:string,province:string,city:string,district:string,region:string,address:string}}
 */
function shopNormalizeMainlandAddress(array $input): array
{
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

    $region = implode(' ', shopShippingCollapsePath([$province, $city, $district]));
    return [
        'ok' => true,
        'error' => '',
        'address' => [
            'country' => 'CN',
            'province' => $province,
            'city' => $city,
            'district' => $district,
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
        $parts = shopShippingCollapsePath(preg_split('/[\/>＞]+/u', $line) ?: []);
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
 * @return array{ok:bool,error:string,address?:array{country:string,province:string,city:string,district:string,region:string,address:string}}
 */
function shopValidateShippingAddress(array $input): array
{
    $normalized = shopNormalizeMainlandAddress($input);
    if (!$normalized['ok']) {
        return $normalized;
    }
    $address = $normalized['address'];
    $path = shopShippingCollapsePath([$address['province'], $address['city'], $address['district']]);
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
