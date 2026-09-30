<?php
/**
 * YikaiCMS —— 自助注销本站授权（2.0.3）。
 *
 * 客户换域名、换服务器时，在后台「授权」页把授权码从本站解绑，再拿到新站上用。
 * 正式域名要证明本站确实控制着这个域名（与授权服务器约定）：
 *   1. 向授权服务器申请一次性验证码（start），存在本站设置里（10 分钟有效）；
 *   2. 授权服务器回访 {本站}/index.php?yk_license_challenge=<编号>，本站出示验证码（respond）；
 *   3. 对得上才解绑（confirm）。本机 / 测试站不经回访，直接解绑。
 * 成功后清掉本站保存的授权码与授权缓存。所有服务器响应都验签。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class LicenseDeregistration
{
    public const URL = 'https://update.yikaicms.com/api/license/deregister.php';
    private const CHALLENGE = 'license_dereg_challenge';

    /** @return array{ok: bool, reason: string, role: string} */
    public static function run(): array
    {
        require_once __DIR__ . '/License.php';
        require_once __DIR__ . '/BasePath.php';
        $key = license_key();
        if ($key === '') {
            return ['ok' => false, 'reason' => 'no_key', 'role' => ''];
        }
        $domain = license_domain();
        $start = self::call(['action' => 'start', 'key' => $key, 'domain' => $domain, 'base' => BasePath::get()], 10);
        if ($start === null) {
            return ['ok' => false, 'reason' => 'unreachable', 'role' => ''];
        }
        if (!empty($start['done'])) {
            self::forgetLicense();
            return ['ok' => true, 'reason' => '', 'role' => (string) ($start['role'] ?? '')];
        }
        $challenge = (string) ($start['challenge'] ?? '');
        $secret = (string) ($start['secret'] ?? '');
        if (preg_match('/^[a-f0-9]{16,64}$/', $challenge) !== 1 || preg_match('/^[a-f0-9]{16,128}$/', $secret) !== 1) {
            return ['ok' => false, 'reason' => (string) ($start['reason'] ?? 'unknown'), 'role' => ''];
        }
        settingModel()->set(self::CHALLENGE, (string) json_encode([
            'challenge' => $challenge,
            'secret' => $secret,
            'expires' => min((int) ($start['expires'] ?? 0), time() + 600),
        ]), 'system');
        try {
            // 服务器要回访本站（最多试 https/http × 带/不带 www），给足时间
            $confirm = self::call(['action' => 'confirm', 'key' => $key, 'domain' => $domain], 40);
        } finally {
            settingModel()->set(self::CHALLENGE, '', 'system');
        }
        if ($confirm !== null && !empty($confirm['done'])) {
            self::forgetLicense();
            return ['ok' => true, 'reason' => '', 'role' => (string) ($confirm['role'] ?? 'main')];
        }
        return ['ok' => false, 'reason' => (string) ($confirm['reason'] ?? 'unreachable'), 'role' => ''];
    }

    /**
     * 首页入口调用：授权服务器回访时出示验证码。编号不符、已过期或没有进行中的注销一律 404，
     * 不透露任何信息。只输出纯文本验证码，不缓存。
     */
    public static function respond(string $challenge): void
    {
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex');
        $pending = json_decode((string) settingModel()->get(self::CHALLENGE, ''), true);
        if (is_array($pending)
            && preg_match('/^[a-f0-9]{16,64}$/', $challenge) === 1
            && hash_equals((string) ($pending['challenge'] ?? ''), $challenge)
            && (int) ($pending['expires'] ?? 0) >= time()
            && is_string($pending['secret'] ?? null)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $pending['secret'];
            exit;
        }
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit;
    }

    /** @param array<string, string> $fields @return array<string, mixed>|null 验签通过的 data 段 */
    private static function call(array $fields, int $timeout): ?array
    {
        $resp = license_http(self::URL, $fields, $timeout);
        $j = $resp !== null ? json_decode($resp, true) : null;
        if (!is_array($j) || !is_array($j['data'] ?? null) || !license_verify($j['data'], (string) ($j['sig'] ?? ''))) {
            return null;
        }
        return $j['data'];
    }

    private static function forgetLicense(): void
    {
        settingModel()->saveBatch(['license_key' => '', 'license_state' => '']);
    }
}
