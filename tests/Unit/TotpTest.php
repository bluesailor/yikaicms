<?php
/**
 * Tests for includes/Totp.php — RFC 6238 官方测试向量（SHA-1 组）+ 校验语义。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Totp;

require_once ROOT_PATH . '/includes/Totp.php';

final class TotpTest extends TestCase
{
    /** RFC 6238 附录 B：密钥 ASCII "12345678901234567890"（SHA-1 组），8 位码的后 6 位即 6 位码 */
    private const RFC_KEY = '12345678901234567890';

    /** @return array<string, array{int, string}> */
    public static function rfcVectors(): array
    {
        // [unix 时间, 6 位期望码]（RFC 表格给的是 8 位，TOTP 截断规则下 6 位 = 后 6 位）
        return [
            't=59'          => [59, '287082'],
            't=1111111109'  => [1111111109, '081804'],
            't=1111111111'  => [1111111111, '050471'],
            't=1234567890'  => [1234567890, '005924'],
            't=2000000000'  => [2000000000, '279037'],
        ];
    }

    /** @dataProvider rfcVectors */
    public function testRfc6238Vectors(int $time, string $expected): void
    {
        $code = Totp::codeAt(self::RFC_KEY, intdiv($time, 30));
        $this->assertSame($expected, $code);
    }

    public function testVerifyAcceptsCurrentAndAdjacentWindow(): void
    {
        $secret = Totp::base32Encode(self::RFC_KEY);
        $now = 1111111111;
        $code = Totp::codeAt(self::RFC_KEY, intdiv($now, 30));
        $this->assertTrue(Totp::verify($secret, $code, 1, $now));
        // 前一个窗（30 秒前生成的码）也应通过
        $prev = Totp::codeAt(self::RFC_KEY, intdiv($now, 30) - 1);
        $this->assertTrue(Totp::verify($secret, $prev, 1, $now));
        // 两个窗之外拒绝
        $old = Totp::codeAt(self::RFC_KEY, intdiv($now, 30) - 2);
        $this->assertFalse(Totp::verify($secret, $old, 1, $now));
    }

    public function testVerifyRejectsMalformedCode(): void
    {
        $secret = Totp::generateSecret();
        $this->assertFalse(Totp::verify($secret, ''));
        $this->assertFalse(Totp::verify($secret, 'abcdef'));
        $this->assertFalse(Totp::verify($secret, '12345'));
        $this->assertFalse(Totp::verify('', '123456'));
    }

    public function testVerifyToleratesSpacesInCode(): void
    {
        $secret = Totp::base32Encode(self::RFC_KEY);
        $now = 1111111111;
        $code = Totp::codeAt(self::RFC_KEY, intdiv($now, 30));
        $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);
        $this->assertTrue(Totp::verify($secret, $spaced, 1, $now));
    }

    public function testBase32RoundTrip(): void
    {
        $bin = random_bytes(20);
        $this->assertSame($bin, Totp::base32Decode(Totp::base32Encode($bin)));
        // RFC 4648 已知值
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        $this->assertSame('foobar', Totp::base32Decode('MZXW6YTBOI'));
    }

    public function testGenerateSecretIsBase32AndLongEnough(): void
    {
        $secret = Totp::generateSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThanOrEqual(20, strlen(Totp::base32Decode($secret)));
    }

    public function testOtpauthUriEncodesIssuerAndAccount(): void
    {
        $uri = Totp::otpauthUri('ABC234', 'admin', '易凯 CMS');
        $this->assertStringStartsWith('otpauth://totp/%E6%98%93%E5%87%AF%20CMS:admin?', $uri);
        $this->assertStringContainsString('secret=ABC234', $uri);
        $this->assertStringContainsString('issuer=%E6%98%93%E5%87%AF%20CMS', $uri);
    }
}
