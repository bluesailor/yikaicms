<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/includes/SessionStorage.php';
require_once ROOT_PATH . '/includes/LoginDiagnostics.php';

/**
 * 后台登录诊断（2.0.2）：登录页把「会话丢了」分成四种原因；CLI site:login-check 用两次取登录页判断会话是否被保存。
 */
final class LoginDiagnosticsTest extends TestCase
{
    public function testClassifySeparatesBrowserServerAndCacheSides(): void
    {
        $name = 'IK_TEST';
        self::assertSame(LoginDiagnostics::NO_COOKIE, LoginDiagnostics::classify([], $name, false, true));
        self::assertSame(LoginDiagnostics::NO_COOKIE, LoginDiagnostics::classify([$name => ''], $name, true, false), 'an empty cookie counts as not sent');
        self::assertSame(LoginDiagnostics::PAGE_NOT_FROM_SESSION, LoginDiagnostics::classify([$name => 'abc'], $name, true, true));
        self::assertSame(LoginDiagnostics::STORE_UNWRITABLE, LoginDiagnostics::classify([$name => 'abc'], $name, false, false));
        self::assertSame(LoginDiagnostics::SESSION_GONE, LoginDiagnostics::classify([$name => 'abc'], $name, false, true));
        self::assertSame(LoginDiagnostics::SESSION_GONE, LoginDiagnostics::classify([$name => 'abc'], $name, false, null), 'unknown store (redis…) stays generic');
    }

    public function testEveryCodeHasAHintInEveryLanguage(): void
    {
        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            foreach ([LoginDiagnostics::NO_COOKIE, LoginDiagnostics::SESSION_GONE, LoginDiagnostics::STORE_UNWRITABLE, LoginDiagnostics::PAGE_NOT_FROM_SESSION] as $code) {
                self::assertNotSame('', trim((string) ($strings[LoginDiagnostics::hintKey($code)] ?? '')), "$lang $code");
            }
            self::assertStringContainsString(':code', (string) $strings['login_diag_code'], $lang);
            self::assertStringContainsString('site:login-check', (string) $strings['login_diag_code'], $lang);
        }
    }

    public function testStoreDirFollowsPhpSavePathFormats(): void
    {
        self::assertSame('/var/lib/php/sessions', LoginDiagnostics::storeDir('files', '/var/lib/php/sessions', '/tmp'));
        self::assertSame('/srv/sess', LoginDiagnostics::storeDir('files', '2;/srv/sess', '/tmp'));
        self::assertSame('/srv/sess', LoginDiagnostics::storeDir('files', '2;600;/srv/sess', '/tmp'));
        self::assertSame('/tmp', LoginDiagnostics::storeDir('files', '', '/tmp'), 'empty save_path means the system temp dir');
        self::assertNull(LoginDiagnostics::storeDir('redis', 'tcp://127.0.0.1:6379', '/tmp'));
    }

    public function testParseSetCookieAndCacheHeaders(): void
    {
        $c = LoginDiagnostics::parseSetCookie('IK_A=v123; path=/; domain=.example.com; secure; HttpOnly; SameSite=Lax');
        self::assertSame(['IK_A', 'v123', true, true, '.example.com', '/', 'Lax'], [$c['name'], $c['value'], $c['secure'], $c['httponly'], $c['domain'], $c['path'], $c['samesite']]);
        self::assertSame('cf-cache-status: HIT', LoginDiagnostics::cacheHit(['cf-cache-status' => ['HIT']]));
        self::assertSame('age: 120', LoginDiagnostics::cacheHit(['age' => ['120']]));
        self::assertSame('', LoginDiagnostics::cacheHit(['x-cache' => ['MISS'], 'age' => ['0']]));
        self::assertSame(str_repeat('a', 64), LoginDiagnostics::tokenFrom('<input type="hidden" name="_token" value="' . str_repeat('a', 64) . '">', '_token'));
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

    public function testAnalyzePassesWhenTheSessionIsKept(): void
    {
        $t = str_repeat('b', 64);
        $first = $this->page('https://example.com/admin/login.php', ['IK_A=one; path=/; secure; HttpOnly'], $t);
        $second = $this->page('https://example.com/admin/login.php', [], $t);
        self::assertSame([], $this->failed(LoginDiagnostics::analyze($first, $second, 'IK_A', '_token')));
    }

    public function testAnalyzeCatchesEachKnownCause(): void
    {
        $t = str_repeat('c', 64);
        $cached = $this->page('https://example.com/admin/login.php', [], $t, ['x-cache' => ['HIT from cdn']]);
        self::assertSame(['cache', 'cookie'], $this->failed(LoginDiagnostics::analyze($cached, null, 'IK_A', '_token')));

        $secureOnHttp = $this->page('http://example.com/admin/login.php', ['IK_A=one; secure'], $t);
        self::assertSame(['secure'], $this->failed(LoginDiagnostics::analyze($secureOnHttp, $this->page('http://example.com/admin/login.php', [], $t), 'IK_A', '_token')));

        $otherDomain = $this->page('https://www.example.com/admin/login.php', ['IK_A=one; domain=example.org'], $t);
        self::assertContains('domain', $this->failed(LoginDiagnostics::analyze($otherDomain, null, 'IK_A', '_token')));

        $first = $this->page('https://example.com/admin/login.php', ['IK_A=one'], $t);
        $renewed = $this->page('https://example.com/admin/login.php', ['IK_A=two'], str_repeat('d', 64));
        self::assertSame(['persist'], $this->failed(LoginDiagnostics::analyze($first, $renewed, 'IK_A', '_token')), 'strict mode issued a new id: session not kept');
        $tokenChanged = $this->page('https://example.com/admin/login.php', [], str_repeat('d', 64));
        self::assertSame(['persist'], $this->failed(LoginDiagnostics::analyze($first, $tokenChanged, 'IK_A', '_token')));
    }

    public function testLoginPageWiring(): void
    {
        $login = (string) file_get_contents(ROOT_PATH . '/admin/login.php');
        $snapshot = strpos($login, '$loginSessionHadData = !empty($_SESSION);');
        self::assertIsInt($snapshot);
        self::assertLessThan(strpos($login, "\$_SESSION['login_lang'] = "), $snapshot, 'snapshot before language detection writes to the session');
        self::assertStringContainsString('LoginDiagnostics::classify($_COOKIE, session_name(), $loginSessionHadData, LoginDiagnostics::storeWritable())', $login);
        self::assertStringContainsString('ErrorHandler::log(', $login, 'server details go to the site log');
        self::assertStringContainsString('data-testid="login-diagnosis"', $login);
        self::assertStringNotContainsString('storeDir(', $login, 'no server paths are rendered to visitors');
        $runtime = require ROOT_PATH . '/config/release-runtime.php';
        self::assertContains('includes/LoginDiagnostics.php', $runtime['required_files']);
    }
}
