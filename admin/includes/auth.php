<?php
/**
 * YikaiCMS - 后台认证模块
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// 防止直接访问
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 加载 Model 层
require_once ROOT_PATH . '/includes/models/autoload.php';

// 加载后台公共 helper：adminLangView / adminFilterLangSuffixes / adminRemapLangKeys
// 以及 renderAdminLangSwitcher / loadTransStatus / renderTransPills
// 让 admin 页面顶部即可调用，无需各页自行 require
require_once ROOT_PATH . '/admin/includes/trans_pills.php';

// 初始化语言
initLang();

// 加载钩子系统与插件
require_once ROOT_PATH . '/includes/hooks.php';
require_once ROOT_PATH . '/includes/Compatibility.php';
Compatibility::bootstrap();
require_once ROOT_PATH . '/includes/AiService.php';
require_once ROOT_PATH . '/includes/Abilities.php';
require_once ROOT_PATH . '/includes/abilities/cms_basics.php';
require_once ROOT_PATH . '/includes/abilities/cms_admin.php';
require_once ROOT_PATH . '/includes/plugin.php';
require_once ROOT_PATH . '/includes/License.php';
// 静态 HTML：让后台页可调用 StaticHtml 类，并注册内容变更后清空静态文件的失效钩子
require_once ROOT_PATH . '/includes/StaticHtml.php';

/**
 * 检查登录状态
 */
function checkLogin(): void
{
    if (empty($_SESSION['admin_id'])) {
        if (isAjax()) {
            error('请先登录', 401);
        }
        redirect('/admin/login.php');
    }

    // 每次请求重新解析身份：会话里的角色与权限是登录那一刻的快照，
    // 不刷新的话「停用某人」「收紧某个角色」对已登录的人都不生效。
    refreshAdminIdentity();

    // 自动校验 CSRF：所有 POST 请求必须携带 _token
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();

        // 演示模式：拦截写操作（升级、演示开关页除外）
        if (defined('DEMO_MODE') && DEMO_MODE) {
            $demoAllowPages = ['upgrade.php', 'setting_demo.php'];
            $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
            if (!in_array($currentPage, $demoAllowPages)) {
                error('演示模式下不允许修改操作');
            }
        }
    }
}

/**
 * 按当前请求重新解析登录者的身份（账号状态、所属角色、角色权限）。
 *
 * 为什么必须每请求做：`$_SESSION['admin_permissions']` 原本只在登录时写一次，
 * 之后再不更新，于是
 *   - 改角色权限 → 已登录的人沿用旧权限，**收紧权限不生效**
 *   - 把用户改到别的角色 → 同样不生效
 *   - **账号被禁用（status=0）或删除 → 现存会话照常可用**，「停用某人」停不掉
 * 最后一条比权限那条严重：它意味着离职、误操作、被盗号之后，
 * 管理员在后台点「禁用」其实什么也没发生，只能等对方自己退出。
 *
 * 代价是每个后台请求两次主键查询（用户 + 角色），按请求缓存一次。
 * 后台页面本就有几十次查询，这点开销换「权限即时生效」是值得的。
 */
function refreshAdminIdentity(): void
{
    static $done = false;
    if ($done) {
        return;   // 有的页面会调两次 checkLogin()
    }
    $done = true;

    $uid = (int) ($_SESSION['admin_id'] ?? 0);
    if ($uid <= 0) {
        return;
    }

    $user = userModel()->find($uid);
    if (!$user || (int) ($user['status'] ?? 0) !== 1) {
        // 账号已被禁用或删除：当场失效，不等对方自己退出
        doLogout();
        if (isAjax()) {
            error('账号已失效，请重新登录', 401);
        }
        redirect('/admin/login.php');
    }

    $roleId = (int) ($user['role_id'] ?? 0);
    $role   = $roleId > 0 ? roleModel()->find($roleId) : null;
    // 角色被停用或删除 → 权限清空（不踢出，让人能看到「没有操作权限」而不是莫名被登出）
    $perms  = ($role && (int) ($role['status'] ?? 1) === 1)
        ? (json_decode((string) ($role['permissions'] ?? '[]'), true) ?: [])
        : [];

    $_SESSION['admin_role_id']     = $roleId;
    $_SESSION['admin_permissions'] = is_array($perms) ? $perms : [];
}

/**
 * 判断是否AJAX请求
 */
function isAjax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * 执行登录
 */
function doLogin(string $username, string $password): array
{
    // 暴力破解防护：检查登录失败次数
    $lockout = checkLoginThrottle();
    if ($lockout > 0) {
        return ['success' => false, 'message' => __('login_throttle_locked', ['minutes' => $lockout])];
    }

    $user = userModel()->findWhere(['username' => $username, 'status' => 1]);

    // 恒定时间：用户不存在时也跑一次 bcrypt 校验，避免用响应快慢枚举用户名。
    // 占位 hash 是一条真实 bcrypt（cost 10），verify 永远失败但耗时与真校验一致。
    $hash = $user['password'] ?? '$2y$10$CvKLPJwopxGfViQwogwiguMCWdL1haPC5o2trR8N8swtjmQcLQZI6';
    $passOk = password_verify($password, $hash);
    if (!$user || !$passOk) {
        recordLoginFailure();
        // 记录登录失败日志（不依赖 session）
        adminLogModel()->log([
            'admin_id'     => 0,
            'admin_name'   => $username,
            'module'       => 'auth',
            'action'       => 'login_fail',
            'description'  => '登录失败：用户名或密码错误',
            'url'          => $_SERVER['REQUEST_URI'] ?? '',
            'method'       => 'POST',
            'request_data' => json_encode(['username' => $username], JSON_UNESCAPED_UNICODE),
            'ip'           => getClientIp(),
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at'   => time(),
        ]);
        return ['success' => false, 'message' => __('login_invalid')];
    }

    // 登录成功，清除失败记录
    clearLoginFailure();

    // 两步验证：已绑定 TOTP 的账号先进入待验证态，验证码通过后才建立会话
    if (!empty($user['totp_secret'])) {
        session_regenerate_id(true);
        $_SESSION['2fa_pending'] = ['user_id' => (int) $user['id'], 'expires' => time() + 300];
        return ['success' => false, 'need_2fa' => true, 'message' => ''];
    }

    completeAdminLogin($user);
    return ['success' => true, 'message' => __('login_success')];
}

/**
 * 密码（及两步验证）全部通过后建立后台会话。
 */
function completeAdminLogin(array $user): void
{
    // 防止 Session Fixation 攻击
    session_regenerate_id(true);
    unset($_SESSION['2fa_pending']);

    // 更新登录信息
    userModel()->updateById($user['id'], [
        'last_login_time' => time(),
        'last_login_ip' => getClientIp(),
        'login_count' => $user['login_count'] + 1
    ]);

    // 设置Session
    $_SESSION['admin_id'] = $user['id'];
    $_SESSION['admin_username'] = $user['username'];
    $_SESSION['admin_nickname'] = $user['nickname'] ?: $user['username'];
    $_SESSION['admin_role_id'] = $user['role_id'];
    $_SESSION['admin_avatar'] = $user['avatar'];

    // 获取角色权限
    $role = roleModel()->find($user['role_id']);
    $_SESSION['admin_permissions'] = $role ? json_decode($role['permissions'] ?? '[]', true) : [];

    // 记录日志
    adminLog('auth', 'login', '登录成功');
}

/**
 * 两步验证第二步：校验验证器 6 位码，通过则建立会话。
 * 复用登录限流：连续输错验证码与输错密码共享同一锁定计数。
 */
function doTotpLogin(string $code): array
{
    $pending = $_SESSION['2fa_pending'] ?? null;
    if (!$pending || ($pending['expires'] ?? 0) < time()) {
        unset($_SESSION['2fa_pending']);
        return ['success' => false, 'message' => __('login_2fa_expired'), 'expired' => true];
    }

    $lockout = checkLoginThrottle();
    if ($lockout > 0) {
        unset($_SESSION['2fa_pending']);
        return ['success' => false, 'message' => __('login_throttle_locked', ['minutes' => $lockout]), 'expired' => true];
    }

    $user = userModel()->findWhere(['id' => (int) $pending['user_id'], 'status' => 1]);
    require_once ROOT_PATH . '/includes/Totp.php';
    if (!$user || empty($user['totp_secret']) || !Totp::verify((string) $user['totp_secret'], $code)) {
        recordLoginFailure();
        adminLogModel()->log([
            'admin_id'     => 0,
            'admin_name'   => (string) ($user['username'] ?? ''),
            'module'       => 'auth',
            'action'       => 'login_fail',
            'description'  => '登录失败：两步验证码错误',
            'url'          => $_SERVER['REQUEST_URI'] ?? '',
            'method'       => 'POST',
            'request_data' => '{}',
            'ip'           => getClientIp(),
            'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at'   => time(),
        ]);
        return ['success' => false, 'message' => __('login_2fa_invalid')];
    }

    clearLoginFailure();
    completeAdminLogin($user);
    return ['success' => true, 'message' => __('login_success')];
}

/**
 * 登录限流：检查是否被锁定，返回剩余锁定分钟数（0=未锁定）
 */
function checkLoginThrottle(): int
{
    $file = _loginThrottleFile();
    if (!file_exists($file)) return 0;

    $data = json_decode(file_get_contents($file), true);
    if (!$data) return 0;

    $maxAttempts = (int)config('login_max_attempts', 5);
    $lockMinutes = (int)config('login_lock_minutes', 15);

    if (($data['count'] ?? 0) >= $maxAttempts) {
        $elapsed = time() - ($data['last'] ?? 0);
        $lockSeconds = $lockMinutes * 60;
        if ($elapsed < $lockSeconds) {
            return (int)ceil(($lockSeconds - $elapsed) / 60);
        }
        // 锁定已过期，清除
        @unlink($file);
    }

    return 0;
}

/**
 * 记录一次登录失败
 */
function recordLoginFailure(): void
{
    $file = _loginThrottleFile();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $handle = fopen($file, 'c+');
    if (!$handle) return;
    flock($handle, LOCK_EX);

    $content = stream_get_contents($handle);
    $data = $content ? (json_decode($content, true) ?: ['count' => 0, 'last' => 0]) : ['count' => 0, 'last' => 0];

    // 超过15分钟的旧记录重新计数
    if (time() - ($data['last'] ?? 0) > 900) {
        $data = ['count' => 0, 'last' => 0];
    }

    $data['count'] = ($data['count'] ?? 0) + 1;
    $data['last'] = time();

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}

/**
 * 登录成功后清除失败记录
 */
function clearLoginFailure(): void
{
    $file = _loginThrottleFile();
    if (file_exists($file)) @unlink($file);
}

/**
 * 获取当前 IP 对应的限流文件路径
 */
function _loginThrottleFile(): string
{
    $ip = preg_replace('/[^a-zA-Z0-9._\-]/', '_', getClientIp());
    return ROOT_PATH . '/storage/login_throttle/' . $ip . '.json';
}

/**
 * 执行登出
 */
function doLogout(): void
{
    adminLog('auth', 'logout', '退出登录');

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/**
 * 获取当前管理员信息
 */
function getAdminInfo(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }

    return [
        'id' => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'],
        'nickname' => $_SESSION['admin_nickname'],
        'role_id' => $_SESSION['admin_role_id'],
        'avatar' => $_SESSION['admin_avatar'] ?? '',
        'permissions' => $_SESSION['admin_permissions'] ?? []
    ];
}

/**
 * 获取当前登录管理员 ID（未登录返回 0）
 * job_edit.php / download_edit.php 发布新内容时用它写 admin_id。
 */
function getAdminId(): int
{
    return (int)($_SESSION['admin_id'] ?? 0);
}

/**
 * 检查是否超级管理员
 */
function isSuperAdmin(): bool
{
    $permissions = $_SESSION['admin_permissions'] ?? [];
    return in_array('*', $permissions);
}

/**
 * 检查权限
 */
function hasPermission(string $permission): bool
{
    if (isSuperAdmin()) {
        return true;
    }

    $permissions = $_SESSION['admin_permissions'] ?? [];
    return in_array('*', $permissions) || in_array($permission, $permissions);
}

/**
 * 要求权限
 */
function requirePermission(string $permission): void
{
    if (!hasPermission($permission)) {
        if (isAjax()) {
            error(__('perm_denied'), 403);
        }
        die('<div style="padding:50px;text-align:center;"><h2>' . e(__('perm_denied')) . '</h2><a href="/admin/">返回首页</a></div>');
    }
}
