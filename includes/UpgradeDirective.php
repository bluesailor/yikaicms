<?php
/**
 * YikaiCMS —— 升级指令验签（v1.18.6）。
 *
 * 控制台批量下发升级时，服务器在 check.php 的响应里附一段指令；站点用**内置公钥**
 * （与升级包验签同一把）校验后才执行。
 *
 * 规范串：`autoupgrade|<domain>|<to>|<issued_at>|<expires_at>|<nonce>`
 *
 * 为什么值得签：升级包本身已有 SHA256 + RSA 双重校验，伪造指令最多只能让站点装上
 * **官方**包。但指令仍决定「什么时候升、升到哪个版本」——不签的话，中间人可以挑
 * 时机（业务高峰）或压着不让升。签名 + 域名绑定 + 有效期 + nonce 把这些都堵住：
 *   - 域名绑定：A 站的指令不能拿去驱动 B 站
 *   - 有效期：过期指令重放无效（默认服务器签 15 分钟）
 *   - nonce：同一条指令只认一次，防止在有效期内反复触发
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class UpgradeDirective
{
    /** 用过的 nonce 存这个设置键（JSON：nonce => 过期时间戳）。 */
    private const NONCE_KEY = 'auto_upgrade_seen_nonces';

    /**
     * nonce 保留时长。必须 ≥ 指令最长有效期（verify 里限死 86400 秒），
     * 否则「按条数滚动淘汰」会让仍在有效期内的旧 nonce 被挤掉、重放复活
     * （codex 审计 P1-2）。改为按过期时间清理，条数自然有界。
     */
    private const NONCE_TTL = 86400;

    /**
     * 校验一条指令。任何一项不合规都返回 false —— 宁可不升，也不能被牵着走。
     *
     * @param mixed  $directive check 响应里的 directive 段
     * @param string $expectedTo 本次 check 返回的最新版本；指令必须指向同一版本
     */
    public static function verify(mixed $directive, string $expectedTo): bool
    {
        if (!is_array($directive)) {
            return false;
        }
        $to = (string) ($directive['to'] ?? '');
        $issued = (int) ($directive['issued_at'] ?? 0);
        $expires = (int) ($directive['expires_at'] ?? 0);
        $nonce = (string) ($directive['nonce'] ?? '');
        $sig = (string) ($directive['sig'] ?? '');
        $domain = (string) ($directive['domain'] ?? '');

        if ($to === '' || $sig === '' || $nonce === '' || $domain === '') {
            return false;
        }
        // 指令必须指向服务器同一次响应里的最新版本，杜绝「签一个旧版本让站点降级」
        if ($expectedTo !== '' && $to !== $expectedTo) {
            return false;
        }
        $now = time();
        // 允许 5 分钟时钟偏差：共享主机时间不准很常见，但不能放任无限早签
        if ($issued > $now + 300 || $expires <= $now || $expires - $issued > 86400) {
            return false;
        }
        if (!self::domainMatches($domain)) {
            return false;
        }
        if (self::nonceSeen($nonce)) {
            return false;
        }

        if (!function_exists('license_pubkey')) {
            require_once ROOT_PATH . '/includes/License.php';
        }
        if (!function_exists('openssl_verify') || !function_exists('license_pubkey')) {
            return false;
        }
        $canonical = 'autoupgrade|' . $domain . '|' . $to . '|' . $issued . '|' . $expires . '|' . $nonce;
        $raw = base64_decode($sig, true);
        if ($raw === false || $raw === '') {
            return false;
        }
        if (openssl_verify($canonical, $raw, license_pubkey(), OPENSSL_ALGO_SHA256) !== 1) {
            return false;
        }

        self::rememberNonce($nonce);
        return true;
    }

    /**
     * 指令里的域名是否就是本站。
     *
     * 比较时剥掉协议、www. 前缀与端口：同一个站在 HTTP_HOST 与后台配置里的写法常常
     * 不一致（有无 www、带不带端口），严格字符串相等会让指令永远验不过。
     */
    private static function domainMatches(string $domain): bool
    {
        $norm = static function (string $h): string {
            $h = strtolower(trim($h));
            $h = (string) preg_replace('#^https?://#', '', $h);
            $h = explode('/', $h)[0];
            $h = explode(':', $h)[0];
            return (string) preg_replace('/^www\./', '', $h);
        };
        $target = $norm($domain);
        if ($target === '') {
            return false;
        }
        foreach ([(string) ($_SERVER['HTTP_HOST'] ?? ''), (string) config('site_url', '')] as $mine) {
            if ($mine !== '' && $norm($mine) === $target) {
                return true;
            }
        }
        return false;
    }

    /**
     * 已用 nonce 表：nonce => 过期时间戳。读取时顺手剔除过期项，条数自然有界。
     *
     * @return array<string, int>
     */
    private static function nonces(): array
    {
        $raw = (string) config(self::NONCE_KEY, '');
        if ($raw === '') {
            return [];
        }
        $l = json_decode($raw, true);
        if (!is_array($l)) {
            return [];
        }
        $now = time();
        $out = [];
        foreach ($l as $n => $exp) {
            // 兼容旧格式（纯数组）：没有过期时间的一律按「刚过期」处理，
            // 保留一个 TTL 周期后自然清空
            if (is_int($n) && is_string($exp)) {
                $out[$exp] = $now + self::NONCE_TTL;
                continue;
            }
            if (is_string($n) && is_int($exp) && $exp > $now) {
                $out[$n] = $exp;
            }
        }
        return $out;
    }

    private static function nonceSeen(string $nonce): bool
    {
        return array_key_exists($nonce, self::nonces());
    }

    private static function rememberNonce(string $nonce): void
    {
        $l = self::nonces();
        $l[$nonce] = time() + self::NONCE_TTL;
        settingModel()->set(self::NONCE_KEY, json_encode($l), 'system');
    }
}
