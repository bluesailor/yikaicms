<?php
/**
 * 商城真实支付网关：微信支付 API v3 Native 与支付宝电脑网站支付（证书模式）。
 *
 * 密钥和证书只保存在 storage/shop/payment，数据库仅保存非敏感商户标识和启用态。
 * 网关未完整配置时不出现在买家端；验签、金额、币种和商户身份任一不符均拒绝。
 */

declare(strict_types=1);

require_once __DIR__ . '/money.php';

/** @return array<string,string> */
function shopGatewayCredentialFiles(): array
{
    return [
        'wechat_private_key' => 'wechat-private-key.pem',
        'wechat_merchant_cert' => 'wechat-merchant-cert.pem',
        'wechat_platform_cert' => 'wechat-platform-cert.pem',
        'wechat_api_v3_key' => 'wechat-api-v3-key.txt',
        'alipay_private_key' => 'alipay-private-key.pem',
        'alipay_app_cert' => 'alipay-app-cert.crt',
        'alipay_public_cert' => 'alipay-public-cert.crt',
        'alipay_root_cert' => 'alipay-root-cert.crt',
    ];
}

function shopGatewayCredentialDir(): string
{
    return STORAGE_PATH . '/shop/payment';
}

function shopGatewayCredentialPath(string $name): string
{
    $files = shopGatewayCredentialFiles();
    if (!isset($files[$name])) {
        throw new InvalidArgumentException('shop: unknown payment credential');
    }
    return shopGatewayCredentialDir() . '/' . $files[$name];
}

function shopGatewayCredentialRead(string $name): string
{
    $path = shopGatewayCredentialPath($name);
    if (!is_file($path) || filesize($path) === false || filesize($path) > 524288) {
        return '';
    }
    $content = file_get_contents($path);
    return is_string($content) ? trim($content) : '';
}

function shopGatewayCredentialWrite(string $name, string $content): bool
{
    $content = trim($content);
    if ($content === '' || strlen($content) > 524288) {
        return false;
    }
    $dir = shopGatewayCredentialDir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    @chmod($dir, 0700);
    $path = shopGatewayCredentialPath($name);
    try {
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(6));
    } catch (Throwable $e) {
        return false;
    }
    if (file_put_contents($tmp, $content . "\n", LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0600);
    return true;
}

/** @return array<string,mixed>|null */
function shopGatewayCertificateInfo(string $certificate): ?array
{
    if ($certificate === '') {
        return null;
    }
    $parsed = openssl_x509_parse($certificate);
    return is_array($parsed) ? $parsed : null;
}

function shopGatewayCertificateCurrent(array $info, ?int $now = null): bool
{
    $now ??= time();
    return (int) ($info['validFrom_time_t'] ?? PHP_INT_MAX) <= $now
        && (int) ($info['validTo_time_t'] ?? 0) >= $now;
}

function shopGatewayPrivateKeyMatchesCertificate(string $privateKey, string $certificate): bool
{
    $private = openssl_pkey_get_private($privateKey);
    $public = openssl_pkey_get_public($certificate);
    if ($private === false || $public === false) {
        return false;
    }
    $privateDetails = openssl_pkey_get_details($private);
    $publicDetails = openssl_pkey_get_details($public);
    if (!is_array($privateDetails) || !is_array($publicDetails)
        || !is_string($privateDetails['key'] ?? null) || !is_string($publicDetails['key'] ?? null)) {
        return false;
    }
    return hash_equals($privateDetails['key'], $publicDetails['key']);
}

function shopWechatCertificateSerial(string $certificate): string
{
    $info = shopGatewayCertificateInfo($certificate);
    $serial = is_array($info) ? strtoupper((string) ($info['serialNumberHex'] ?? '')) : '';
    $serial = preg_replace('/^0X/', '', $serial) ?? '';
    return preg_match('/^[0-9A-F]{8,80}$/D', $serial) === 1 ? ltrim($serial, '0') : '';
}

/** 十六进制大整数转十进制字符串，不依赖 bcmath。 */
function shopGatewayHexToDecimal(string $hex): string
{
    $hex = strtoupper(preg_replace('/^0X/', '', trim($hex)) ?? '');
    if ($hex === '' || preg_match('/^[0-9A-F]+$/D', $hex) !== 1) {
        return '';
    }
    $decimal = '0';
    foreach (str_split($hex) as $char) {
        $carry = hexdec($char);
        $next = '';
        for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
            $value = ((int) $decimal[$i]) * 16 + $carry;
            $next = (string) ($value % 10) . $next;
            $carry = intdiv($value, 10);
        }
        while ($carry > 0) {
            $next = (string) ($carry % 10) . $next;
            $carry = intdiv($carry, 10);
        }
        $decimal = ltrim($next, '0');
        if ($decimal === '') {
            $decimal = '0';
        }
    }
    return $decimal;
}

/** @param array<string,mixed> $dn */
function shopAlipayDnString(array $dn): string
{
    $parts = [];
    foreach ($dn as $key => $value) {
        if (is_scalar($value)) {
            $parts[] = (string) $key . '=' . (string) $value;
        }
    }
    return implode(',', $parts);
}

function shopAlipayCertificateSn(string $certificate): string
{
    $info = shopGatewayCertificateInfo($certificate);
    if ($info === null || !is_array($info['issuer'] ?? null)) {
        return '';
    }
    $serial = (string) ($info['serialNumber'] ?? '');
    if (str_starts_with(strtolower($serial), '0x')) {
        $serial = shopGatewayHexToDecimal((string) ($info['serialNumberHex'] ?? $serial));
    }
    if ($serial === '') {
        $serial = shopGatewayHexToDecimal((string) ($info['serialNumberHex'] ?? ''));
    }
    return $serial === '' ? '' : md5(shopAlipayDnString(array_reverse($info['issuer'])) . $serial);
}

function shopAlipayRootCertificateSn(string $certificateChain): string
{
    $serials = [];
    $chunks = explode('-----END CERTIFICATE-----', $certificateChain);
    foreach ($chunks as $chunk) {
        if (trim($chunk) === '') {
            continue;
        }
        $certificate = trim($chunk) . "\n-----END CERTIFICATE-----";
        $info = shopGatewayCertificateInfo($certificate);
        if ($info === null || !in_array((string) ($info['signatureTypeLN'] ?? ''), [
            'sha1WithRSAEncryption', 'sha256WithRSAEncryption',
        ], true)) {
            continue;
        }
        $serial = shopAlipayCertificateSn($certificate);
        if ($serial !== '') {
            $serials[] = $serial;
        }
    }
    return implode('_', $serials);
}

/** @return array{ready:bool,error:string,merchant_serial:string,platform_serial:string} */
function shopWechatGatewayStatus(): array
{
    $appId = trim((string) config('shop_wechat_app_id', ''));
    $merchantId = trim((string) config('shop_wechat_merchant_id', ''));
    $privateKey = shopGatewayCredentialRead('wechat_private_key');
    $merchantCert = shopGatewayCredentialRead('wechat_merchant_cert');
    $platformCert = shopGatewayCredentialRead('wechat_platform_cert');
    $apiV3Key = shopGatewayCredentialRead('wechat_api_v3_key');
    $merchantInfo = shopGatewayCertificateInfo($merchantCert);
    $platformInfo = shopGatewayCertificateInfo($platformCert);
    $merchantSerial = shopWechatCertificateSerial($merchantCert);
    $platformSerial = shopWechatCertificateSerial($platformCert);

    $ready = preg_match('/^[A-Za-z0-9_-]{6,32}$/D', $appId) === 1
        && preg_match('/^[0-9]{6,32}$/D', $merchantId) === 1
        && strlen($apiV3Key) === 32
        && $merchantInfo !== null && $platformInfo !== null
        && shopGatewayCertificateCurrent($merchantInfo)
        && shopGatewayCertificateCurrent($platformInfo)
        && $merchantSerial !== '' && $platformSerial !== ''
        && shopGatewayPrivateKeyMatchesCertificate($privateKey, $merchantCert);

    return [
        'ready' => $ready,
        'error' => $ready ? '' : 'shop_err_gateway_incomplete',
        'merchant_serial' => $merchantSerial,
        'platform_serial' => $platformSerial,
    ];
}

/** @return array{ready:bool,error:string,app_cert_sn:string,alipay_cert_sn:string,root_cert_sn:string} */
function shopAlipayGatewayStatus(): array
{
    $appId = trim((string) config('shop_alipay_app_id', ''));
    $sellerId = trim((string) config('shop_alipay_seller_id', ''));
    $privateKey = shopGatewayCredentialRead('alipay_private_key');
    $appCert = shopGatewayCredentialRead('alipay_app_cert');
    $publicCert = shopGatewayCredentialRead('alipay_public_cert');
    $rootCert = shopGatewayCredentialRead('alipay_root_cert');
    $appInfo = shopGatewayCertificateInfo($appCert);
    $publicInfo = shopGatewayCertificateInfo($publicCert);
    $appSn = shopAlipayCertificateSn($appCert);
    $publicSn = shopAlipayCertificateSn($publicCert);
    $rootSn = shopAlipayRootCertificateSn($rootCert);

    $ready = preg_match('/^[0-9]{16,32}$/D', $appId) === 1
        && preg_match('/^[0-9]{16}$/D', $sellerId) === 1
        && $appInfo !== null && $publicInfo !== null
        && shopGatewayCertificateCurrent($appInfo)
        && shopGatewayCertificateCurrent($publicInfo)
        && $appSn !== '' && $publicSn !== '' && $rootSn !== ''
        && shopGatewayPrivateKeyMatchesCertificate($privateKey, $appCert);

    return [
        'ready' => $ready,
        'error' => $ready ? '' : 'shop_err_gateway_incomplete',
        'app_cert_sn' => $appSn,
        'alipay_cert_sn' => $publicSn,
        'root_cert_sn' => $rootSn,
    ];
}

/** @return list<string> */
function shopEnabledOnlinePaymentGateways(): array
{
    $gateways = [];
    if ((string) config('shop_wechat_enabled', '0') === '1' && shopWechatGatewayStatus()['ready']) {
        $gateways[] = 'wechat_pay';
    }
    if ((string) config('shop_alipay_enabled', '0') === '1' && shopAlipayGatewayStatus()['ready']) {
        $gateways[] = 'alipay';
    }
    return $gateways;
}

/**
 * 后台保存微信支付配置。空的密钥/证书字段表示保留原文件。
 * @return array{ok:bool,error:string}
 */
function shopSaveWechatGateway(array $input): array
{
    $appId = trim((string) ($input['app_id'] ?? ''));
    $merchantId = trim((string) ($input['merchant_id'] ?? ''));
    $enabled = (int) ($input['enabled'] ?? 0) === 1;
    if (($appId !== '' && preg_match('/^[A-Za-z0-9_-]{6,32}$/D', $appId) !== 1)
        || ($merchantId !== '' && preg_match('/^[0-9]{6,32}$/D', $merchantId) !== 1)) {
        return ['ok' => false, 'error' => 'shop_err_gateway_identifier'];
    }

    $names = ['wechat_private_key', 'wechat_merchant_cert', 'wechat_platform_cert', 'wechat_api_v3_key'];
    $values = [];
    foreach ($names as $name) {
        $posted = trim((string) ($input[$name] ?? ''));
        $values[$name] = $posted !== '' ? $posted : shopGatewayCredentialRead($name);
    }
    $merchantInfo = shopGatewayCertificateInfo($values['wechat_merchant_cert']);
    $platformInfo = shopGatewayCertificateInfo($values['wechat_platform_cert']);
    $complete = $appId !== '' && $merchantId !== ''
        && strlen($values['wechat_api_v3_key']) === 32
        && $merchantInfo !== null && $platformInfo !== null
        && shopGatewayCertificateCurrent($merchantInfo)
        && shopGatewayCertificateCurrent($platformInfo)
        && shopWechatCertificateSerial($values['wechat_merchant_cert']) !== ''
        && shopWechatCertificateSerial($values['wechat_platform_cert']) !== ''
        && shopGatewayPrivateKeyMatchesCertificate($values['wechat_private_key'], $values['wechat_merchant_cert']);
    if (($enabled || array_filter($values, static fn(string $value): bool => $value !== '') !== []) && !$complete) {
        return ['ok' => false, 'error' => 'shop_err_gateway_credentials'];
    }

    foreach ($names as $name) {
        if (trim((string) ($input[$name] ?? '')) !== '' && !shopGatewayCredentialWrite($name, $values[$name])) {
            return ['ok' => false, 'error' => 'shop_err_gateway_store'];
        }
    }
    settingModel()->saveBatch([
        'shop_wechat_enabled' => $enabled ? '1' : '0',
        'shop_wechat_app_id' => $appId,
        'shop_wechat_merchant_id' => $merchantId,
    ]);
    return ['ok' => true, 'error' => ''];
}

/** @return array{ok:bool,error:string} */
function shopSaveAlipayGateway(array $input): array
{
    $appId = trim((string) ($input['app_id'] ?? ''));
    $sellerId = trim((string) ($input['seller_id'] ?? ''));
    $enabled = (int) ($input['enabled'] ?? 0) === 1;
    if (($appId !== '' && preg_match('/^[0-9]{16,32}$/D', $appId) !== 1)
        || ($sellerId !== '' && preg_match('/^[0-9]{16}$/D', $sellerId) !== 1)) {
        return ['ok' => false, 'error' => 'shop_err_gateway_identifier'];
    }

    $names = ['alipay_private_key', 'alipay_app_cert', 'alipay_public_cert', 'alipay_root_cert'];
    $values = [];
    foreach ($names as $name) {
        $posted = trim((string) ($input[$name] ?? ''));
        $values[$name] = $posted !== '' ? $posted : shopGatewayCredentialRead($name);
    }
    $appInfo = shopGatewayCertificateInfo($values['alipay_app_cert']);
    $publicInfo = shopGatewayCertificateInfo($values['alipay_public_cert']);
    $complete = $appId !== '' && $sellerId !== ''
        && $appInfo !== null && $publicInfo !== null
        && shopGatewayCertificateCurrent($appInfo)
        && shopGatewayCertificateCurrent($publicInfo)
        && shopAlipayCertificateSn($values['alipay_app_cert']) !== ''
        && shopAlipayCertificateSn($values['alipay_public_cert']) !== ''
        && shopAlipayRootCertificateSn($values['alipay_root_cert']) !== ''
        && shopGatewayPrivateKeyMatchesCertificate($values['alipay_private_key'], $values['alipay_app_cert']);
    if (($enabled || array_filter($values, static fn(string $value): bool => $value !== '') !== []) && !$complete) {
        return ['ok' => false, 'error' => 'shop_err_gateway_credentials'];
    }

    foreach ($names as $name) {
        if (trim((string) ($input[$name] ?? '')) !== '' && !shopGatewayCredentialWrite($name, $values[$name])) {
            return ['ok' => false, 'error' => 'shop_err_gateway_store'];
        }
    }
    settingModel()->saveBatch([
        'shop_alipay_enabled' => $enabled ? '1' : '0',
        'shop_alipay_app_id' => $appId,
        'shop_alipay_seller_id' => $sellerId,
    ]);
    return ['ok' => true, 'error' => ''];
}

function shopPaymentNotifyUrl(string $gateway, ?string $baseUrl = null, ?string $mode = null): string
{
    if (!in_array($gateway, ['wechat_pay', 'alipay'], true)) {
        throw new InvalidArgumentException('shop: unsupported gateway');
    }
    $baseUrl = rtrim($baseUrl ?? siteBaseUrl(), '/');
    $mode = $mode ?? (function_exists('urlMode') ? urlMode() : 'pretty');
    if ($mode === 'query') {
        return $baseUrl . '/plugins/shop/front/payment-notify.php?gateway=' . rawurlencode($gateway);
    }
    return $baseUrl . '/shop/payment-notify/' . rawurlencode($gateway);
}

function shopPaymentOrderReturnUrl(string $orderNo, ?string $baseUrl = null): string
{
    return rtrim($baseUrl ?? siteBaseUrl(), '/') . '/shop/order?no=' . rawurlencode($orderNo);
}

function shopPaymentStartToken(string $gateway, string $orderNo, int $timestamp, string $secret): string
{
    return FormSubmissionToken::sign('shop_pay:' . $gateway . ':' . $orderNo, $timestamp, $secret);
}

function shopPaymentVerifyStartToken(
    string $gateway,
    string $orderNo,
    int $timestamp,
    string $signature,
    string $secret
): bool {
    return FormSubmissionToken::verify(
        'shop_pay:' . $gateway . ':' . $orderNo,
        $timestamp,
        $signature,
        $secret,
        false,
        7200
    );
}

function shopWechatVerifySignature(
    string $timestamp,
    string $nonce,
    string $body,
    string $signature,
    string $platformCertificate,
    ?int $now = null
): bool {
    if (preg_match('/^[0-9]{10}$/D', $timestamp) !== 1 || $nonce === '' || strlen($nonce) > 64
        || str_starts_with($signature, 'WECHATPAY/SIGNTEST/')) {
        return false;
    }
    $now ??= time();
    if (abs($now - (int) $timestamp) > 300) {
        return false;
    }
    $binarySignature = base64_decode($signature, true);
    $publicKey = openssl_pkey_get_public($platformCertificate);
    if ($binarySignature === false || $publicKey === false) {
        return false;
    }
    return openssl_verify($timestamp . "\n" . $nonce . "\n" . $body . "\n", $binarySignature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
}

/** @return array<string,mixed>|null */
function shopWechatDecryptResource(array $resource, string $apiV3Key): ?array
{
    if (!hash_equals('AEAD_AES_256_GCM', (string) ($resource['algorithm'] ?? ''))
        || strlen($apiV3Key) !== 32) {
        return null;
    }
    $ciphertext = base64_decode((string) ($resource['ciphertext'] ?? ''), true);
    $nonce = (string) ($resource['nonce'] ?? '');
    $associatedData = (string) ($resource['associated_data'] ?? '');
    if ($ciphertext === false || strlen($ciphertext) <= 16 || $nonce === '') {
        return null;
    }
    $tag = substr($ciphertext, -16);
    $encrypted = substr($ciphertext, 0, -16);
    $plain = openssl_decrypt(
        $encrypted,
        'aes-256-gcm',
        $apiV3Key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        $associatedData
    );
    if (!is_string($plain) || !mb_check_encoding($plain, 'UTF-8')) {
        return null;
    }
    try {
        $decoded = json_decode($plain, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return null;
    }
    return is_array($decoded) ? $decoded : null;
}

/** @return array<string,mixed> */
function shopWechatVerifyNotification(string $rawBody, array $context): array
{
    $status = shopWechatGatewayStatus();
    if (!$status['ready']) {
        return ['verified' => false];
    }
    $headers = is_array($context['headers'] ?? null) ? $context['headers'] : [];
    $timestamp = (string) ($headers['wechatpay-timestamp'] ?? '');
    $nonce = (string) ($headers['wechatpay-nonce'] ?? '');
    $signature = (string) ($headers['wechatpay-signature'] ?? '');
    $serial = strtoupper((string) ($headers['wechatpay-serial'] ?? ''));
    if ($serial === '' || !hash_equals($status['platform_serial'], ltrim($serial, '0'))) {
        return ['verified' => false];
    }
    $platformCertificate = shopGatewayCredentialRead('wechat_platform_cert');
    if (!shopWechatVerifySignature($timestamp, $nonce, $rawBody, $signature, $platformCertificate)) {
        return ['verified' => false];
    }
    try {
        $payload = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return ['verified' => false];
    }
    if (!is_array($payload) || !hash_equals('TRANSACTION.SUCCESS', (string) ($payload['event_type'] ?? ''))
        || !is_array($payload['resource'] ?? null)) {
        return ['verified' => false];
    }
    $transaction = shopWechatDecryptResource($payload['resource'], shopGatewayCredentialRead('wechat_api_v3_key'));
    if ($transaction === null
        || !hash_equals((string) config('shop_wechat_merchant_id', ''), (string) ($transaction['mchid'] ?? ''))
        || !hash_equals((string) config('shop_wechat_app_id', ''), (string) ($transaction['appid'] ?? ''))
        || !hash_equals('SUCCESS', (string) ($transaction['trade_state'] ?? ''))
        || !is_array($transaction['amount'] ?? null)
        || !hash_equals('CNY', strtoupper((string) ($transaction['amount']['currency'] ?? '')))
        || !is_int($transaction['amount']['total'] ?? null)) {
        return ['verified' => false];
    }
    return [
        'verified' => true,
        'status' => 'succeeded',
        'order_no' => (string) ($transaction['out_trade_no'] ?? ''),
        'trade_no' => (string) ($transaction['transaction_id'] ?? ''),
        'amount' => shopCentsToDecimal((int) $transaction['amount']['total']),
        'currency' => 'CNY',
    ];
}

/** @param array<string,mixed> $params */
function shopAlipaySignContent(array $params, bool $notification = false): string
{
    if ($notification) {
        unset($params['sign'], $params['sign_type']);
    }
    ksort($params);
    $parts = [];
    foreach ($params as $key => $value) {
        if (is_scalar($value) && trim((string) $value) !== '' && !str_starts_with((string) $value, '@')) {
            $parts[] = (string) $key . '=' . (string) $value;
        }
    }
    return implode('&', $parts);
}

function shopAlipayRsa2Sign(string $content, string $privateKey): string
{
    $key = openssl_pkey_get_private($privateKey);
    if ($key === false || !openssl_sign($content, $signature, $key, OPENSSL_ALGO_SHA256)) {
        return '';
    }
    return base64_encode($signature);
}

/** @return array<string,string> */
function shopAlipayPagePayParameters(array $order): array
{
    $status = shopAlipayGatewayStatus();
    if (!$status['ready']) {
        return [];
    }
    $orderNo = (string) ($order['order_no'] ?? '');
    $bizContent = json_encode([
        'out_trade_no' => $orderNo,
        'product_code' => 'FAST_INSTANT_TRADE_PAY',
        'total_amount' => (string) ($order['amount_total'] ?? ''),
        'subject' => mb_substr((string) config('site_name', 'Yikai Shop') . ' - ' . $orderNo, 0, 128),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $params = [
        'app_id' => (string) config('shop_alipay_app_id', ''),
        'method' => 'alipay.trade.page.pay',
        'format' => 'JSON',
        'return_url' => shopPaymentOrderReturnUrl($orderNo),
        'charset' => 'utf-8',
        'sign_type' => 'RSA2',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0',
        'notify_url' => shopPaymentNotifyUrl('alipay'),
        'app_cert_sn' => $status['app_cert_sn'],
        'alipay_root_cert_sn' => $status['root_cert_sn'],
        'biz_content' => $bizContent,
    ];
    $params['sign'] = shopAlipayRsa2Sign(shopAlipaySignContent($params), shopGatewayCredentialRead('alipay_private_key'));
    return $params['sign'] === '' ? [] : $params;
}

/** @return array<string,mixed> */
function shopAlipayVerifyNotification(string $rawBody, array $context = []): array
{
    // 与统一网关过滤器保持四参数签名；支付宝表单通知不使用 HTTP 请求头。
    unset($context);
    $status = shopAlipayGatewayStatus();
    if (!$status['ready'] || strlen($rawBody) > 262144) {
        return ['verified' => false];
    }
    parse_str($rawBody, $params);
    if (!is_array($params) || count($params) > 100) {
        return ['verified' => false];
    }
    foreach ($params as $value) {
        if (!is_string($value)) {
            return ['verified' => false];
        }
    }
    if (!hash_equals('RSA2', strtoupper((string) ($params['sign_type'] ?? '')))
        || !hash_equals((string) config('shop_alipay_app_id', ''), (string) ($params['app_id'] ?? ''))
        || !hash_equals((string) config('shop_alipay_seller_id', ''), (string) ($params['seller_id'] ?? ''))
        || !hash_equals($status['alipay_cert_sn'], (string) ($params['alipay_cert_sn'] ?? ''))) {
        return ['verified' => false];
    }
    $tradeStatus = (string) ($params['trade_status'] ?? '');
    if (!hash_equals('TRADE_SUCCESS', $tradeStatus) && !hash_equals('TRADE_FINISHED', $tradeStatus)) {
        return ['verified' => false];
    }
    $signature = base64_decode((string) ($params['sign'] ?? ''), true);
    $publicKey = openssl_pkey_get_public(shopGatewayCredentialRead('alipay_public_cert'));
    if ($signature === false || $publicKey === false
        || openssl_verify(shopAlipaySignContent($params, true), $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
        return ['verified' => false];
    }
    return [
        'verified' => true,
        'status' => 'succeeded',
        'order_no' => (string) ($params['out_trade_no'] ?? ''),
        'trade_no' => (string) ($params['trade_no'] ?? ''),
        'amount' => (string) ($params['total_amount'] ?? ''),
        'currency' => 'CNY',
    ];
}

/** @return array{status:int,headers:array<string,string>,body:string,error:string} */
function shopPaymentHttpJson(string $method, string $url, string $body, array $headers): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'curl unavailable'];
    }
    $handle = curl_init($url);
    if ($handle === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => 'curl init failed'];
    }
    $responseHeaders = [];
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'YikaiCMS-Shop/0.7',
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    $responseBody = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return [
        'status' => $status,
        'headers' => $responseHeaders,
        'body' => is_string($responseBody) ? $responseBody : '',
        'error' => $error,
    ];
}

function shopWechatAuthorization(string $method, string $path, string $body, string $merchantId, string $serial, string $privateKey): string
{
    $timestamp = (string) time();
    try {
        $nonce = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        return '';
    }
    $key = openssl_pkey_get_private($privateKey);
    $message = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body . "\n";
    if ($key === false || !openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256)) {
        return '';
    }
    return 'WECHATPAY2-SHA256-RSA2048 mchid="' . $merchantId . '",nonce_str="' . $nonce
        . '",signature="' . base64_encode($signature) . '",timestamp="' . $timestamp
        . '",serial_no="' . $serial . '"';
}

/** @return array{ok:bool,error:string,code_url?:string} */
function shopWechatCreateNativeOrder(array $order): array
{
    $status = shopWechatGatewayStatus();
    if (!$status['ready']) {
        return ['ok' => false, 'error' => 'shop_err_gateway_incomplete'];
    }
    try {
        $amountCents = shopMoneyToCents((string) ($order['amount_total'] ?? ''));
        $body = json_encode([
            'appid' => (string) config('shop_wechat_app_id', ''),
            'mchid' => (string) config('shop_wechat_merchant_id', ''),
            'description' => mb_substr((string) config('site_name', 'Yikai Shop') . ' - ' . (string) $order['order_no'], 0, 127),
            'out_trade_no' => (string) $order['order_no'],
            'notify_url' => shopPaymentNotifyUrl('wechat_pay'),
            'amount' => ['total' => $amountCents, 'currency' => 'CNY'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'shop_err_payment_start'];
    }
    $path = '/v3/pay/transactions/native';
    $authorization = shopWechatAuthorization(
        'POST',
        $path,
        $body,
        (string) config('shop_wechat_merchant_id', ''),
        $status['merchant_serial'],
        shopGatewayCredentialRead('wechat_private_key')
    );
    if ($authorization === '') {
        return ['ok' => false, 'error' => 'shop_err_payment_start'];
    }
    $response = shopPaymentHttpJson('POST', 'https://api.mch.weixin.qq.com' . $path, $body, [
        'Accept: application/json',
        'Content-Type: application/json; charset=utf-8',
        'Authorization: ' . $authorization,
    ]);
    if ($response['status'] !== 200) {
        error_log('[shop] WeChat Native order failed: HTTP ' . $response['status'] . ' ' . mb_substr($response['body'], 0, 500));
        return ['ok' => false, 'error' => 'shop_err_payment_start'];
    }
    $headers = $response['headers'];
    $serial = strtoupper((string) ($headers['wechatpay-serial'] ?? ''));
    if (!hash_equals($status['platform_serial'], ltrim($serial, '0'))
        || !shopWechatVerifySignature(
            (string) ($headers['wechatpay-timestamp'] ?? ''),
            (string) ($headers['wechatpay-nonce'] ?? ''),
            $response['body'],
            (string) ($headers['wechatpay-signature'] ?? ''),
            shopGatewayCredentialRead('wechat_platform_cert')
        )) {
        error_log('[shop] WeChat Native response signature rejected');
        return ['ok' => false, 'error' => 'shop_err_payment_unverified'];
    }
    try {
        $decoded = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        return ['ok' => false, 'error' => 'shop_err_payment_start'];
    }
    $codeUrl = is_array($decoded) ? (string) ($decoded['code_url'] ?? '') : '';
    if (!str_starts_with($codeUrl, 'weixin://wxpay/bizpayurl?') || strlen($codeUrl) > 2048) {
        return ['ok' => false, 'error' => 'shop_err_payment_start'];
    }
    return ['ok' => true, 'error' => '', 'code_url' => $codeUrl];
}
