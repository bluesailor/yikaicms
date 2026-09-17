<?php

declare(strict_types=1);

/**
 * 管理员密码服务端底线。HTML required/minlength 只能改善交互，不能作为安装安全边界。
 *
 * 登录页读参数走 post()，它对用户名和密码都做 trim，再用 empty() 判空。安装器若原样
 * 收下首尾带空格、或纯空格的密码，装完就登不进去（2026-09-18 复审 R04）。这里按登录端
 * 的语义校验：先 trim 再量长度，且不接受被 trim 改变的值。
 */
function installerAdminPasswordValid(string $password): bool
{
    if ($password !== trim($password)) {
        return false;   // 首尾空格会被登录端吃掉，bcrypt 校验必然失败
    }
    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    return $length >= 6;
}

/**
 * 管理员用户名服务端底线。
 *
 * 登录端 empty() 会把空串、纯空格和字符串 "0" 一并判为空，安装器必须提前拒绝这些
 * 「存得进、登不上」的取值；首尾空格同理（登录端 trim 之后就对不上了）。
 */
function installerAdminUsernameValid(string $username): bool
{
    if ($username !== trim($username) || $username === '' || $username === '0') {
        return false;
    }
    $length = function_exists('mb_strlen') ? mb_strlen($username, 'UTF-8') : strlen($username);
    if ($length < 2 || $length > 50) {
        return false;
    }
    // 控制字符会让日志、会话与后台显示出现难以排查的怪状
    return preg_match('/[\x00-\x1F\x7F]/u', $username) !== 1;
}
