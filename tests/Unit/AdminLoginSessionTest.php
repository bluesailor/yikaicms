<?php
/**
 * 刚装好就登不进后台：{"code":403,"msg":"非法请求"}（2026-09-27 用户转来的客户问题，本机也偶发）。
 *
 * 根因：登录提交时会话里的 CSRF 令牌不在了——服务器默认会话目录不可写（每次都是新会话）、
 * 登录页放太久被回收（gc_maxlifetime 1800），或 Cookie 被拦。修复三层：
 *   1. SessionStorage::recover()：config 里 session_start() 失败时改用 storage/sessions 再开；
 *   2. 登录页校验失败不再吐 JSON，留在登录页说清原因（仍然不做任何登录处理）；
 *   3. 登录页禁止缓存。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/SessionStorage.php';
if (!defined('CSRF_TOKEN_NAME')) {
    define('CSRF_TOKEN_NAME', '_token');  // 与 config.sample.php 一致；单测环境不加载 config.php
}
// 单测环境不加载完整的 functions.php（依赖太多）；从源码里取出真实的 csrfTokenValid() 加载，测的就是生产代码
if (!function_exists('csrfTokenValid')) {
    $source = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
    if (preg_match('/^function csrfTokenValid\(\): bool\n\{.*?\n\}\n/ms', str_replace("\r\n", "\n", $source), $match) === 1) {
        eval($match[0]);
    }
}

final class AdminLoginSessionTest extends TestCase
{
    private array $savedPost = [];
    private array $savedSession = [];

    protected function setUp(): void
    {
        $this->savedPost = $_POST;
        $this->savedSession = $_SESSION ?? [];
    }

    protected function tearDown(): void
    {
        $_POST = $this->savedPost;
        $_SESSION = $this->savedSession;
        unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    public function testCsrfTokenValidChecksWithoutAborting(): void
    {
        $_SESSION['csrf_token'] = 'abc';
        $_POST[CSRF_TOKEN_NAME] = 'abc';
        self::assertTrue(csrfTokenValid());
        $_POST[CSRF_TOKEN_NAME] = 'stale';
        self::assertFalse(csrfTokenValid());
        $_POST[CSRF_TOKEN_NAME] = ['abc'];
        self::assertFalse(csrfTokenValid(), 'array input is rejected, not a TypeError');
        unset($_SESSION['csrf_token']);
        $_POST[CSRF_TOKEN_NAME] = '';
        self::assertFalse(csrfTokenValid(), 'empty session token never matches an empty field');
        $_SESSION['csrf_token'] = 'abc';
        unset($_POST[CSRF_TOKEN_NAME]);
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'abc';
        self::assertTrue(csrfTokenValid(), 'header token still accepted');
    }

    public function testVerifyCsrfStillRejectsEverywhereElse(): void
    {
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertMatchesRegularExpression("/function verifyCsrf\\(\\): bool\\s*\\{\\s*if \\(!csrfTokenValid\\(\\)\\) \\{\\s*error\\(__\\('admin_illegal_request'\\), 403\\);/", $functions);
    }

    public function testLoginPageExplainsInsteadOfDumpingJson(): void
    {
        $login = (string) file_get_contents(ROOT_PATH . '/admin/login.php');
        self::assertStringNotContainsString('verifyCsrf();', $login, 'login must not answer with a bare JSON 403');
        $post = strpos($login, "if (\$_SERVER['REQUEST_METHOD'] === 'POST') {");
        self::assertIsInt($post);
        $block = substr($login, $post, 2400);  // 2.0.2 起令牌分支里多了登录诊断
        self::assertStringContainsString('if (!csrfTokenValid()) {', $block);
        self::assertStringContainsString("'login_session_lost' : 'login_page_expired'", $block);
        // 令牌无效时直接落到页面渲染，doLogin / doTotpLogin 只在 elseif 分支里
        self::assertLessThan(strpos($block, 'doTotpLogin'), strpos($block, 'if (!csrfTokenValid()) {'));
        self::assertStringContainsString("header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');", $login);
        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            self::assertNotSame('', trim((string) ($strings['login_session_lost'] ?? '')), $lang);
            self::assertNotSame('', trim((string) ($strings['login_page_expired'] ?? '')), $lang);
        }
    }

    public function testSessionFallbackRunsBeforeAnythingElse(): void
    {
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        $recover = strpos($functions, 'SessionStorage::recover(ROOT_PATH);');
        self::assertIsInt($recover);
        self::assertLessThan(strpos($functions, 'BasePath::bootstrap();'), $recover, 'recover before any other bootstrap');
        $storage = (string) file_get_contents(ROOT_PATH . '/includes/SessionStorage.php');
        self::assertStringContainsString("\$root . '/storage/sessions'", $storage, 'fallback stays inside the web-denied storage/ tree');
        self::assertStringContainsString('session_status() !== PHP_SESSION_NONE', $storage, 'only acts when config\'s session_start() failed');
    }

    public function testRecoverIsANoOpInCliAndWhenASessionIsActive(): void
    {
        $before = session_status();
        SessionStorage::recover(sys_get_temp_dir() . '/yk-should-not-exist-' . bin2hex(random_bytes(4)));
        self::assertSame($before, session_status());
    }

    public function testHttpsDetectionMatchesConfig(): void
    {
        self::assertTrue(SessionStorage::isHttps(['HTTPS' => 'on']));
        self::assertFalse(SessionStorage::isHttps(['HTTPS' => 'off']));
        self::assertTrue(SessionStorage::isHttps(['SERVER_PORT' => '443']));
        self::assertTrue(SessionStorage::isHttps(['HTTP_X_FORWARDED_PROTO' => 'HTTPS']));
        self::assertFalse(SessionStorage::isHttps(['SERVER_PORT' => '80']));
    }
}
