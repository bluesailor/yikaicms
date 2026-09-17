<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * 用临时 RSA 密钥签出真实缓存，走 license_cache() 全链路：
 * 签名正确但来自别的域名、或 checked_at 被改到未来的缓存都不能延续授权。
 * 公钥常量必须在加载 License.php 之前定义，所以放在独立进程里。
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LicenseCacheSignedReplayTest extends TestCase
{
    /** @var OpenSSLAsymmetricKey */
    private $key;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key, 'openssl_pkey_new 失败：本机需设置 OPENSSL_CONF');
        $this->key = $key;
        $details = openssl_pkey_get_details($key);
        define('LICENSE_PUBKEY_B64', preg_replace('/-----[^-]+-----|\s/', '', $details['key']));
        require_once ROOT_PATH . '/includes/License.php';
    }

    /** @param array<string,mixed> $data */
    private function signedState(array $data, int $checkedAt): string
    {
        ksort($data);
        self::assertTrue(openssl_sign(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $signature,
            $this->key,
            OPENSSL_ALGO_SHA256
        ));
        return (string) json_encode(['data' => $data, 'sig' => base64_encode($signature), 'checked_at' => $checkedAt]);
    }

    public function testSignedCacheOnlyWorksOnTheDomainItWasIssuedFor(): void
    {
        $data = ['valid' => true, 'reason' => 'ok', 'plan' => 'pro', 'modules' => ['seo-pro'], 'expires_at' => '2099-01-01',
            'expired' => false, 'domain' => 'site-a.example', 'ts' => date('Y-m-d H:i:s')];
        $GLOBALS['_test_config']['license_key'] = 'KEY-A';
        $GLOBALS['_test_config']['license_state'] = $this->signedState($data, time());

        $_SERVER['HTTP_HOST'] = 'www.site-a.example';
        self::assertSame(['seo-pro'], license_cache()['data']['modules']);

        $_SERVER['HTTP_HOST'] = 'site-b.example';
        self::assertSame([], license_cache(), '复制到别的域名的缓存必须作废');
    }

    public function testForgedFutureCheckedAtIsCappedBySignedTimestamp(): void
    {
        $issued = time() - 40 * 86400;
        $data = ['valid' => true, 'reason' => 'ok', 'plan' => 'pro', 'modules' => ['seo-pro'], 'expires_at' => '2099-01-01',
            'expired' => false, 'domain' => 'site-a.example', 'ts' => date('Y-m-d H:i:s', $issued)];
        $GLOBALS['_test_config']['license_key'] = 'KEY-A';
        $GLOBALS['_test_config']['license_state'] = $this->signedState($data, time() + 5 * 365 * 86400);
        $_SERVER['HTTP_HOST'] = 'site-a.example';

        $cache = license_cache();
        self::assertNotSame([], $cache);
        self::assertSame(0, $cache['checked_at'], '未来的 checked_at 视为从未校验');
        self::assertGreaterThanOrEqual(LICENSE_GRACE, time() - $cache['checked_at'], '被改过的缓存超出宽限期，必须重新校验');

        // 未改过的 checked_at 也不能晚于签名时间 + 一天
        $GLOBALS['_test_config']['license_state'] = $this->signedState($data, time() - 60);
        self::assertSame($issued + LICENSE_TS_SLACK, license_cache()['checked_at']);
    }
}
