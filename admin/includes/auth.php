<?php
/**
 * Yikai CMS - 后台认证模块
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

// 初始化语言
initLang();

// 加载钩子系统与插件
require_once ROOT_PATH . '/includes/hooks.php';
require_once ROOT_PATH . '/includes/plugin.php';

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

    // 自动校验 CSRF：所有 POST 请求必须携带 _token
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();

        // 演示模式：拦截所有写操作
        if (defined('DEMO_MODE') && DEMO_MODE) {
            error('演示模式下不允许修改操作');
        }
    }
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
        return ['success' => false, 'message' => "登录失败次数过多，请 {$lockout} 分钟后重试"];
    }

    $user = userModel()->findWhere(['username' => $username, 'status' => 1]);

    if (!$user || !password_verify($password, $user['password'])) {
        recordLoginFailure();
        return ['success' => false, 'message' => '用户名或密码错误'];
    }

    // 登录成功，清除失败记录
    clearLoginFailure();

    // 防止 Session Fixation 攻击
    session_regenerate_id(true);

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

    return ['success' => true, 'message' => '登录成功'];
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

    $maxAttempts = 5;
    $lockMinutes = 15;

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
    $ip = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
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
            error('没有操作权限', 403);
        }
        die('<div style="padding:50px;text-align:center;"><h2>没有操作权限</h2><a href="/admin/">返回首页</a></div>');
    }
}
