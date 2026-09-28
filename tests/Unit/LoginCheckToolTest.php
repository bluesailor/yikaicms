<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/tools/LoginCheck.php';

/**
 * 内部工具 tools/login-check.php：远程取两次客户站登录页，判断会话丢在哪一端。
 */
final class LoginCheckToolTest extends TestCase
{
    public function testParsesCookiesAndRecognisesSessionNames(): void
    {
        $c = LoginCheck::parseSetCookie('IK_4ED54D9802EBBEB4=v123; path=/; domain=.example.com; secure; HttpOnly; SameSite=Lax');
        self::assertSame(['IK_4ED54D9802EBBEB4', 'v123', true, true, '.example.com', '/', 'Lax'], [$c['name'], $c['value'], $c['secure'], $c['httponly'], $c['domain'], $c['path'], $c['samesite']]);
        self::assertSame('IKAICMS_SESSION', LoginCheck::sessionCookie(['theme=dark', 'IKAICMS_SESSION=a'])['name'] ?? null, 'older installs');
        self::assertNull(LoginCheck::sessionCookie(['theme=dark', 'PHPSESSID=a']));
        self::assertSame('PHPSESSID', LoginCheck::sessionCookie(['PHPSESSID=a'], 'PHPSESSID')['name'] ?? null, '--cookie overrides the naming rule');
    }

    public function testCacheHeadersAndToken(): void
    {
        self::assertSame('cf-cache-status: HIT', LoginCheck::cacheHit(['cf-cache-status' => ['HIT']]));
        self::assertSame('age: 120', LoginCheck::cacheHit(['age' => ['120']]));
        self::assertSame('', LoginCheck::cacheHit(['x-cache' => ['MISS'], 'age' => ['0']]));
        self::assertSame(str_repeat('a', 64), LoginCheck::tokenFrom('<input type="hidden" name="_token" value="' . str_repeat('a', 64) . '">', '_token'));
    }

    /** @return array{status:int,headers:array<string,list<string>>,body:string,url:string} */
    private function page(string $url, array $cookies, string $token, array $extra = []): array
    {
        return ['status' => 200, 'url' => $url, 'body' => '<form><input type="hidden" name="_token" value="' . $token . '"></form>',
            'headers' => $extra + ['set-cookie' => $cookies]];
    }

    private function failed(array $checks): array
    {
        return array_values(array_map(static fn(array $c): string => $c['code'], array_filter($checks, static fn(array $c): bool => !$c['ok'])));
    }

    public function testPassesWhenTheSessionIsKept(): void
    {
        $t = str_repeat('b', 64);
        $first = $this->page('https://example.com/admin/login.php', ['IK_ABCDEF0123456789=one; path=/; secure; HttpOnly'], $t);
        self::assertSame([], $this->failed(LoginCheck::analyze($first, $this->page('https://example.com/admin/login.php', [], $t))));
    }

    public function testCatchesEachKnownCause(): void
    {
        $t = str_repeat('c', 64);
        $cached = $this->page('https://example.com/admin/login.php', [], $t, ['x-cache' => ['HIT from cdn']]);
        self::assertSame(['cache', 'cookie'], $this->failed(LoginCheck::analyze($cached, null)));

        $secureOnHttp = $this->page('http://example.com/admin/login.php', ['IK_ABCDEF0123456789=one; secure'], $t);
        self::assertSame(['secure'], $this->failed(LoginCheck::analyze($secureOnHttp, $this->page('http://example.com/admin/login.php', [], $t))));

        $otherDomain = $this->page('https://www.example.com/admin/login.php', ['IK_ABCDEF0123456789=one; domain=example.org'], $t);
        self::assertContains('domain', $this->failed(LoginCheck::analyze($otherDomain, null)));

        $first = $this->page('https://example.com/admin/login.php', ['IK_ABCDEF0123456789=one'], $t);
        $renewed = $this->page('https://example.com/admin/login.php', ['IK_ABCDEF0123456789=two'], str_repeat('d', 64));
        self::assertSame(['persist'], $this->failed(LoginCheck::analyze($first, $renewed)), 'strict mode issued a new id: session not kept');
        $tokenChanged = $this->page('https://example.com/admin/login.php', [], str_repeat('d', 64));
        self::assertSame(['persist'], $this->failed(LoginCheck::analyze($first, $tokenChanged)));
    }

    public function testToolStaysOutOfTheInstallPackage(): void
    {
        $runtime = require ROOT_PATH . '/config/release-runtime.php';
        foreach ($runtime['required_files'] as $file) self::assertStringStartsNotWith('tools/', $file);
        self::assertStringNotContainsString('LoginCheck', (string) file_get_contents(ROOT_PATH . '/admin/login.php'), 'the product login page is unchanged');
    }
}
