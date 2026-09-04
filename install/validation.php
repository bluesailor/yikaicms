<?php

declare(strict_types=1);

/**
 * 管理员密码服务端底线。HTML required/minlength 只能改善交互，不能作为安装安全边界。
 */
function installerAdminPasswordValid(string $password): bool
{
    $length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
    return $length >= 6;
}
