<?php
/** 微信 API v3 / 支付宝 RSA2 网关的密码学与双 URL 模式契约。 */

declare(strict_types=1);

use Yikai\Tests\TestCase;

final class ShopPaymentGatewayTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/plugins/shop/lib/gateways.php';
        require_once ROOT_PATH . '/plugins/shop/lib/payments.php';
    }

    /** @return array{private:string,certificate:string} */
    private function certificatePair(): array
    {
        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'digest_alg' => 'sha256',
        ];
        if (getenv('OPENSSL_CONF') === false && is_file(dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf')) {
            $options['config'] = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
        }
        $private = openssl_pkey_new($options);
        self::assertNotFalse($private);
        $csr = openssl_csr_new(['commonName' => 'shop-gateway-test'], $private, $options);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $private, 7, $options);
        self::assertNotFalse($certificate);
        self::assertTrue(openssl_pkey_export($private, $privatePem, null, $options));
        self::assertTrue(openssl_x509_export($certificate, $certificatePem));
        return ['private' => $privatePem, 'certificate' => $certificatePem];
    }

    public function testWechatSignatureRequiresCurrentTimestampAndUntamperedBody(): void
    {
        $pair = $this->certificatePair();
        $timestamp = (string) time();
        $nonce = 'nonce-for-unit-test';
        $body = '{"event_type":"TRANSACTION.SUCCESS"}';
        self::assertTrue(openssl_sign(
            $timestamp . "\n" . $nonce . "\n" . $body . "\n",
            $signature,
            $pair['private'],
            OPENSSL_ALGO_SHA256
        ));
        $encoded = base64_encode($signature);

        self::assertTrue(shopWechatVerifySignature(
            $timestamp,
            $nonce,
            $body,
            $encoded,
            $pair['certificate']
        ));
        self::assertFalse(shopWechatVerifySignature(
            $timestamp,
            $nonce,
            $body . 'x',
            $encoded,
            $pair['certificate']
        ));
        self::assertFalse(shopWechatVerifySignature(
            (string) (time() - 301),
            $nonce,
            $body,
            $encoded,
            $pair['certificate']
        ));
        self::assertFalse(shopWechatVerifySignature(
            $timestamp,
            $nonce,
            $body,
            'WECHATPAY/SIGNTEST/' . $encoded,
            $pair['certificate']
        ));
    }

    public function testWechatAesGcmResourceAuthenticationFailsClosed(): void
    {
        $key = '0123456789abcdef0123456789abcdef';
        $nonce = '0123456789ab';
        $associated = 'transaction';
        $plain = '{"mchid":"1900000001","trade_state":"SUCCESS"}';
        $ciphertext = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $associated
        );
        self::assertIsString($ciphertext);
        $resource = [
            'algorithm' => 'AEAD_AES_256_GCM',
            'ciphertext' => base64_encode($ciphertext . $tag),
            'nonce' => $nonce,
            'associated_data' => $associated,
        ];

        self::assertSame(json_decode($plain, true), shopWechatDecryptResource($resource, $key));
        $resource['associated_data'] = 'tampered';
        self::assertNull(shopWechatDecryptResource($resource, $key));
    }

    public function testAlipayRsa2SigningAndCertificateSerials(): void
    {
        $pair = $this->certificatePair();
        $params = [
            'method' => 'alipay.trade.page.pay',
            'app_id' => '2026000000000001',
            'sign_type' => 'RSA2',
            'biz_content' => '{"out_trade_no":"ORDER1"}',
        ];
        $content = shopAlipaySignContent($params);
        self::assertSame(
            'app_id=2026000000000001&biz_content={"out_trade_no":"ORDER1"}&method=alipay.trade.page.pay&sign_type=RSA2',
            $content
        );
        $signature = shopAlipayRsa2Sign($content, $pair['private']);
        self::assertNotSame('', $signature);
        self::assertSame(1, openssl_verify(
            $content,
            base64_decode($signature, true),
            $pair['certificate'],
            OPENSSL_ALGO_SHA256
        ));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', shopAlipayCertificateSn($pair['certificate']));
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', shopAlipayRootCertificateSn($pair['certificate']));
        self::assertTrue(shopGatewayPrivateKeyMatchesCertificate($pair['private'], $pair['certificate']));
    }

    public function testCallbackUrlSupportsRewriteAndQueryModes(): void
    {
        self::assertSame(
            'https://shop.example/shop/payment-notify/wechat_pay',
            shopPaymentNotifyUrl('wechat_pay', 'https://shop.example/', 'pretty')
        );
        self::assertSame(
            'https://shop.example/plugins/shop/front/payment-notify.php?gateway=alipay',
            shopPaymentNotifyUrl('alipay', 'https://shop.example/', 'query')
        );
    }

    public function testWechatNotificationHashIncludesSignatureHeaders(): void
    {
        $body = '{"id":"same-body"}';
        $first = shopPaymentNotifyHash('wechat_pay', $body, ['headers' => [
            'wechatpay-serial' => 'A',
            'wechatpay-timestamp' => '1',
            'wechatpay-nonce' => 'N',
            'wechatpay-signature' => 'S1',
        ]]);
        $second = shopPaymentNotifyHash('wechat_pay', $body, ['headers' => [
            'wechatpay-serial' => 'A',
            'wechatpay-timestamp' => '2',
            'wechatpay-nonce' => 'N',
            'wechatpay-signature' => 'S2',
        ]]);
        self::assertNotSame($first, $second);
        self::assertSame(
            shopPaymentNotifyHash('alipay', $body),
            shopPaymentNotifyHash('alipay', $body, ['headers' => ['ignored' => 'yes']])
        );
    }
}
