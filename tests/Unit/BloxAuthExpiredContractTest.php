<?php
/**
 * 登录过期判定契约（发版收尾任务 4）。
 *
 * 后台真实响应（本套件先钉死，编辑器判定必须与之对齐）：
 *   - 未登录 / 账号失效：AJAX 走 error(__('auth_login_required'|'auth_account_invalid'), 401)，
 *     非 AJAX redirect('/admin/login.php')（fetch 表现为 redirected 且落在登录页）。
 *   - 权限不足 requirePermission：error(__('perm_denied'), 403) —— 重新登录救不了。
 *   - CSRF 拒绝 verifyCsrf：error(__('admin_illegal_request'), 403) —— 同上，不是会话过期。
 * 因此编辑器的 authExpiredResponse 只认 401 与「跳转到登录页」，403 必须走
 * 普通失败分支（保留 failedAction 重试与恢复稿，并把服务器 msg 报给用户）。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxAuthExpiredContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/tests/editor-source.php';
    }

    /** 后台三类拒绝的真实状态码与出口：401=要重新登录；403=权限/CSRF，且都带 JSON msg。 */
    public function testBackendDistinguishesLoginExpiryFromPermissionAndCsrfDenials(): void
    {
        $auth = (string) file_get_contents(ROOT_PATH . '/admin/includes/auth.php');
        self::assertStringContainsString("error(__('auth_login_required'), 401)", $auth);
        self::assertStringContainsString("error(__('auth_account_invalid'), 401)", $auth);
        self::assertStringContainsString("redirect('/admin/login.php')", $auth);
        self::assertStringContainsString("error(__('perm_denied'), 403)", $auth, '权限拒绝是 403，不是 401');

        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString("error(__('admin_illegal_request'), 403)", $functions, 'CSRF 拒绝是 403');

        // error() 的 4xx 码原样成为 HTTP 状态（真实行为，不是字面量）
        require_once ROOT_PATH . '/includes/http_response.php';
        self::assertSame(401, applicationErrorHttpStatus(401));
        self::assertSame(403, applicationErrorHttpStatus(403));
        self::assertSame(200, applicationErrorHttpStatus(1), '业务错误码仍是 HTTP 200，与登录过期无关');
    }

    /** 编辑器判定：401 或跳到登录页 = 过期；403 不算。 */
    public function testEditorAuthExpiredPredicateOnlyMatches401AndLoginRedirect(): void
    {
        $source = bloxEditorSourceForTest();
        self::assertSame(1, preg_match('/authExpiredResponse\(r\)\s*\{(.*?)\n            \},/s', $source, $m), '找不到 authExpiredResponse 定义');
        $body = $m[1];

        self::assertStringContainsString('r.status === 401', $body);
        self::assertStringContainsString('r.redirected', $body);
        self::assertStringContainsString('/admin/login.php', $body, '重定向必须落在登录页才算会话过期');
        self::assertStringNotContainsString('403', $body, '403 是权限/CSRF 拒绝，不是登录过期');
    }

    /** 403 的保存响应要读出服务器 msg 走失败分支（保留重试与恢复稿），而不是被当成网络错误或登录过期。 */
    public function testSaveHandlerRoutes403IntoTheFailureBranchWithServerMessage(): void
    {
        $source = bloxEditorSourceForTest();
        self::assertSame(1, preg_match('/body\.set\("action", "save_draft"\).*?\.finally\(function\(\) \{ self\.saving = false; \}\);/s', $source, $m), '找不到保存请求链');
        $save = $m[0];

        self::assertStringContainsString('if (r.status === 403) return r.json()', $save);
        // 失败分支仍在：状态置 failed、动作记为 save（重试按钮）、服务器 msg 报给用户
        self::assertStringContainsString('self.failedAction = "save";', $save);
        self::assertStringContainsString('self.uiText.saveFailedMsg.replace(":msg"', $save);
    }
}
