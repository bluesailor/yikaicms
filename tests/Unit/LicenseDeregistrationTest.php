<?php
/**
 * 自助注销本站授权（2.0.3）。完整流程（申请验证码 → 授权服务器回访首页 → 解绑 → 本站清掉授权码）
 * 已用一次性站点 + 本地授权服务器端到端验证；这里钉住安全约束。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LicenseDeregistrationTest extends TestCase
{
    private function src(string $file): string
    {
        return (string) file_get_contents(ROOT_PATH . '/' . $file);
    }

    public function testChallengeIsAnsweredOnlyOnTheHomeEntryBeforeCaching(): void
    {
        $index = $this->src('index.php');
        $hook = strpos($index, "if (\$__isIndexRequest && isset(\$_GET['yk_license_challenge'])) {");
        self::assertIsInt($hook);
        self::assertGreaterThan(strpos($index, "require_once __DIR__ . '/includes/init.php';"), $hook, 'needs the database for the pending secret');
        self::assertLessThan(strpos($index, 'HtmlCache::start('), $hook, 'answered before the page cache');
    }

    public function testRespondRevealsTheSecretOnlyForTheMatchingUnexpiredChallenge(): void
    {
        $src = $this->src('includes/LicenseDeregistration.php');
        self::assertStringContainsString("hash_equals((string) (\$pending['challenge'] ?? ''), \$challenge)", $src);
        self::assertStringContainsString("(int) (\$pending['expires'] ?? 0) >= time()", $src);
        self::assertStringContainsString('http_response_code(404);', $src);
        self::assertStringContainsString("header('Cache-Control: no-store, max-age=0');", $src);
        self::assertStringNotContainsString('): never', $src, 'PHP 8.0 has no never return type');
    }

    public function testTheSecretIsShortLivedAndClearedAfterConfirming(): void
    {
        $src = $this->src('includes/LicenseDeregistration.php');
        self::assertStringContainsString("'expires' => min((int) (\$start['expires'] ?? 0), time() + 600)", $src);
        self::assertMatchesRegularExpression('/finally \{\s*settingModel\(\)->set\(self::CHALLENGE, \'\', \'system\'\);/', $src);
        // 只有服务器签名确认解绑后才清本站授权码
        self::assertStringContainsString('license_verify($j[\'data\'], (string) ($j[\'sig\'] ?? \'\'))', $src);
        self::assertSame(2, substr_count($src, 'self::forgetLicense();'));
    }

    public function testLicenseRequestsAcceptALongerTimeoutForTheCallback(): void
    {
        $license = $this->src('includes/License.php');
        self::assertStringContainsString('function license_http(string $url, array $fields, int $timeout = 6): ?string', $license);
        self::assertStringContainsString("self::call(['action' => 'confirm', 'key' => \$key, 'domain' => \$domain], 40)", $this->src('includes/LicenseDeregistration.php'));
    }

    public function testOnlyFullAdministratorsCanRelease(): void
    {
        $page = $this->src('admin/license.php');
        self::assertLessThan(strpos($page, "if (\$act === 'deregister') {"), strpos($page, "requirePermission('*');"));
        self::assertStringContainsString("adminLog('license', 'deregister'", $page);
    }
}
