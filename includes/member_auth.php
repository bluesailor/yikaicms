<?php
/**
 * Yikai CMS - 前台会员认证
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/**
 * 从数据库刷新当前会员身份。每次请求初始化时强制检查一次，后续调用复用结果。
 * 账号被禁用、软删除或删除后，旧 Session 在下一次请求立即失效。
 */
function refreshMemberIdentity(bool $force = false): ?array
{
    static $checkedId = null;
    static $identity = null;

    $memberId = (int) ($_SESSION['member_id'] ?? 0);
    if ($memberId <= 0) {
        $checkedId = null;
        $identity = null;
        return null;
    }
    if (!$force && $checkedId === $memberId) {
        return $identity;
    }

    $checkedId = $memberId;
    $member = memberModel()->find($memberId);
    if (!is_array($member) || empty($member['status'])) {
        doMemberLogout();
        $identity = null;
        return null;
    }

    $_SESSION['member_username'] = (string) ($member['username'] ?? '');
    $_SESSION['member_nickname'] = (string) (($member['nickname'] ?? '') ?: ($member['username'] ?? ''));
    $_SESSION['member_email'] = (string) ($member['email'] ?? '');
    $_SESSION['member_avatar'] = (string) ($member['avatar'] ?? '');
    $identity = [
        'id' => $memberId,
        'username' => $_SESSION['member_username'],
        'nickname' => $_SESSION['member_nickname'],
        'email' => $_SESSION['member_email'],
        'avatar' => $_SESSION['member_avatar'],
    ];
    return $identity;
}

/**
 * 获取当前会员信息
 */
function getMemberInfo(): ?array
{
    return refreshMemberIdentity();
}

/**
 * 是否已登录
 */
function isMemberLoggedIn(): bool
{
    return refreshMemberIdentity() !== null;
}

/**
 * 会员登录
 */
function doMemberLogin(string $username, string $password): array
{
    if (empty($username) || empty($password)) {
        return ['success' => false, 'message' => '请输入用户名和密码'];
    }

    // 暴力破解防护：与后台登录共用限流实现，作用域隔离
    $lockout = loginThrottleRemaining('member');
    if ($lockout > 0) {
        return ['success' => false, 'message' => "登录失败次数过多，请于 {$lockout} 分钟后重试"];
    }

    $member = memberModel()->findByUsername($username);
    // 恒定时间：账号不存在也跑一次 bcrypt，避免用响应快慢枚举会员账号
    $hash = $member['password'] ?? '$2y$10$CvKLPJwopxGfViQwogwiguMCWdL1haPC5o2trR8N8swtjmQcLQZI6';
    if (!password_verify($password, $hash) || !$member) {
        loginThrottleRecordFailure('member');
        return ['success' => false, 'message' => '用户名或密码错误'];
    }

    if (!$member['status']) {
        return ['success' => false, 'message' => '账号已被禁用，请联系管理员'];
    }

    // 登录成功，清除失败计数
    loginThrottleClear('member');

    // 防止 Session Fixation
    session_regenerate_id(true);

    // 更新登录信息
    memberModel()->updateById($member['id'], [
        'last_login_time' => time(),
        'last_login_ip'   => getClientIp(),
        'login_count'     => $member['login_count'] + 1,
    ]);

    // 设置 Session
    $_SESSION['member_id']       = $member['id'];
    $_SESSION['member_username'] = $member['username'];
    $_SESSION['member_nickname'] = $member['nickname'] ?: $member['username'];
    $_SESSION['member_email']    = $member['email'];
    $_SESSION['member_avatar']   = $member['avatar'];

    return ['success' => true, 'message' => '登录成功'];
}

/**
 * 会员注册
 */
function doMemberRegister(string $username, string $email, string $password, string $passwordConfirm): array
{
    // 检查注册开关
    if (config('allow_member_register') !== '1') {
        return ['success' => false, 'message' => '暂未开放注册'];
    }

    // 验证用户名
    $username = trim($username);
    if (strlen($username) < 3 || strlen($username) > 20) {
        return ['success' => false, 'message' => '用户名长度为3-20个字符'];
    }
    if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
        return ['success' => false, 'message' => '用户名只能包含字母、数字、下划线或中文'];
    }

    // 验证邮箱
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => '邮箱格式不正确'];
    }

    // 验证密码
    if (strlen($password) < 8) {
        return ['success' => false, 'message' => '密码至少8位'];
    }
    if ($password !== $passwordConfirm) {
        return ['success' => false, 'message' => '两次密码不一致'];
    }

    // 唯一性检查
    if (!memberModel()->isUsernameUnique($username)) {
        return ['success' => false, 'message' => '用户名已被注册'];
    }
    if (!memberModel()->isEmailUnique($email)) {
        return ['success' => false, 'message' => '邮箱已被注册'];
    }

    // 创建会员
    $id = memberModel()->create([
        'username'   => $username,
        'password'   => password_hash($password, PASSWORD_DEFAULT),
        'email'      => $email,
        'nickname'   => $username,
        'status'     => 1,
        'created_at' => time(),
    ]);

    // 自动登录
    session_regenerate_id(true);
    $_SESSION['member_id']       = (int)$id;
    $_SESSION['member_username'] = $username;
    $_SESSION['member_nickname'] = $username;
    $_SESSION['member_email']    = $email;
    $_SESSION['member_avatar']   = '';

    return ['success' => true, 'message' => '注册成功'];
}

/**
 * 会员登出
 */
function doMemberLogout(): void
{
    unset(
        $_SESSION['member_id'],
        $_SESSION['member_username'],
        $_SESSION['member_nickname'],
        $_SESSION['member_email'],
        $_SESSION['member_avatar']
    );
}
