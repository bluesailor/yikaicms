<?php
/**
 * 商城支付方式扩展点与人工收款资料。
 *
 * 在线网关只保留稳定标识；真正的下单、签名、回调验签必须由独立适配器完成。
 * 人工收款资料允许配置多组个人/企业账户或二维码，只在已验证的待付款订单页展示。
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once ROOT_PATH . '/includes/UrlPolicy.php';

/** @return list<string> 为独立适配器预留的规范网关标识。 */
function shopReservedPaymentGatewayIds(): array
{
    return ['offline', 'alipay', 'wechat_pay', 'stripe', 'paypal', 'wise'];
}

/** 收款资料只允许换行，不允许其它控制字符。 */
function shopPaymentTextHasControlChars(string $value): bool
{
    return preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1;
}

/**
 * 清洗后台提交的人工收款方式；最多 10 项。
 *
 * @return array{ok:bool,error:string,value:string,methods:list<array<string,mixed>>}
 */
function shopNormalizeManualPaymentMethods(mixed $input): array
{
    if (!is_array($input)) {
        return ['ok' => false, 'error' => 'shop_err_payment_methods', 'value' => '[]', 'methods' => []];
    }

    $methods = [];
    foreach ($input as $row) {
        if (!is_array($row)) {
            return ['ok' => false, 'error' => 'shop_err_payment_methods', 'value' => '[]', 'methods' => []];
        }
        if ((string) ($row['remove'] ?? '') === '1') {
            continue;
        }

        $rawText = [
            (string) ($row['subject_type'] ?? 'personal'),
            (string) ($row['label'] ?? ''),
            (string) ($row['payee'] ?? ''),
            (string) ($row['institution'] ?? ''),
            (string) ($row['account'] ?? ''),
            (string) ($row['instructions'] ?? ''),
        ];
        foreach ($rawText as $rawValue) {
            if (shopPaymentTextHasControlChars($rawValue)) {
                return ['ok' => false, 'error' => 'shop_err_payment_methods', 'value' => '[]', 'methods' => []];
            }
        }

        $subjectType = trim($rawText[0]);
        $label = trim($rawText[1]);
        $payee = trim($rawText[2]);
        $institution = trim($rawText[3]);
        $account = trim($rawText[4]);
        $qrRaw = trim((string) ($row['qr_image'] ?? ''));
        $instructions = trim(str_replace(["\r\n", "\r"], "\n", $rawText[5]));

        if ($label === '' && $payee === '' && $institution === '' && $account === ''
            && $qrRaw === '' && $instructions === '') {
            continue;
        }
        if (count($methods) >= 10 || !in_array($subjectType, ['personal', 'company'], true)
            || $label === '' || mb_strlen($label) > 50
            || mb_strlen($payee) > 100 || mb_strlen($institution) > 100
            || mb_strlen($account) > 200 || mb_strlen($instructions) > 500
            || shopPaymentTextHasControlChars($label) || shopPaymentTextHasControlChars($payee)
            || shopPaymentTextHasControlChars($institution) || shopPaymentTextHasControlChars($account)
            || shopPaymentTextHasControlChars($instructions)) {
            return ['ok' => false, 'error' => 'shop_err_payment_methods', 'value' => '[]', 'methods' => []];
        }

        $qrImage = $qrRaw === '' ? '' : UrlPolicy::image($qrRaw);
        if ($qrRaw !== '' && $qrImage === '') {
            return ['ok' => false, 'error' => 'shop_err_payment_methods', 'value' => '[]', 'methods' => []];
        }
        if ($payee === '' && $institution === '' && $account === '' && $qrImage === '' && $instructions === '') {
            return ['ok' => false, 'error' => 'shop_err_payment_methods', 'value' => '[]', 'methods' => []];
        }

        $methods[] = [
            'enabled' => (string) ($row['enabled'] ?? '') === '1' ? 1 : 0,
            'subject_type' => $subjectType,
            'label' => $label,
            'payee' => $payee,
            'institution' => $institution,
            'account' => $account,
            'qr_image' => $qrImage,
            'instructions' => $instructions,
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'value' => json_encode($methods, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        'methods' => $methods,
    ];
}

/** @return list<array<string,mixed>> */
function shopManualPaymentMethods(bool $includeDisabled = false): array
{
    $decoded = json_decode((string) config('shop_manual_payment_methods', '[]'), true);
    $normalized = shopNormalizeManualPaymentMethods(is_array($decoded) ? $decoded : []);
    if (!$normalized['ok']) {
        return [];
    }
    if ($includeDisabled) {
        return $normalized['methods'];
    }
    return array_values(array_filter(
        $normalized['methods'],
        static fn(array $method): bool => (int) ($method['enabled'] ?? 0) === 1
    ));
}
