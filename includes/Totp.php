<?php
/**
 * YikaiCMS — TOTP 两步验证（RFC 6238，SHA-1 / 6 位 / 30 秒周期）。
 *
 * 纯 PHP 实现，无外部依赖，兼容 Google Authenticator / Microsoft Authenticator /
 * 1Password / 阿里云 App 等所有标准 TOTP 客户端。
 *
 * 用法：
 *   $secret = Totp::generateSecret();                    // 绑定时生成（base32）
 *   $uri    = Totp::otpauthUri($secret, $user, $issuer); // 二维码 / 点击拉起验证器
 *   Totp::verify($secret, $code);                        // 登录时校验（含前后一个时间窗）
 */

declare(strict_types=1);

final class Totp
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 生成新密钥（base32，默认 160 bit —— RFC 4226 推荐长度） */
    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes(max(16, $bytes)));
    }

    /**
     * 校验用户输入的 6 位验证码。
     * $window=1 容忍前后各一个 30 秒窗（手机时钟小幅漂移），恒定时间比较。
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $now = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return false;
        }
        $key = self::base32Decode($secret);
        if ($key === '') {
            return false;
        }
        $slice = intdiv($now ?? time(), self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::codeAt($key, $slice + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** 生成某个时间窗的验证码（测试与校验共用） */
    public static function codeAt(string $binaryKey, int $timeSlice): string
    {
        $msg = pack('N2', ($timeSlice >> 32) & 0xFFFFFFFF, $timeSlice & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $msg, $binaryKey, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) & 0xFF) << 16
            | (ord($hash[$offset + 2]) & 0xFF) << 8
            | (ord($hash[$offset + 3]) & 0xFF);
        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** otpauth:// URI（喂给二维码或直接点击拉起验证器 App） */
    public static function otpauthUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(string $bin): string
    {
        if ($bin === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32_ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
        if ($b32 === '') {
            return '';
        }
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos(self::B32_ALPHABET, $c);
            if ($pos === false) {
                return '';
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }
        return $out;
    }
}
