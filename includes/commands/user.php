<?php
/**
 * 命令组：user
 *   user:list                列出所有管理员
 *   user:reset-pwd <username> [--password=xxx]   重置密码（不传 --password 走交互输入）
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

CLI::register('user:list', '列出所有后台管理员', function (array $args, array $opts): int {
    $rows = db()->fetchAll(
        "SELECT id, username, nickname, email, role_id, status, last_login_time FROM " . DB_PREFIX . "users ORDER BY id ASC"
    );
    if (empty($rows)) {
        CLI::info('暂无管理员账号');
        return 0;
    }
    printf("%-4s %-20s %-16s %-30s %-7s %-7s %s\n", 'ID', 'USERNAME', 'NICKNAME', 'EMAIL', 'ROLE', 'STATUS', 'LAST LOGIN');
    printf("%-4s %-20s %-16s %-30s %-7s %-7s %s\n", '---', '--------', '--------', '-----', '----', '------', '----------');
    foreach ($rows as $r) {
        $last = (int)($r['last_login_time'] ?? 0);
        $lastStr = $last > 0 ? date('Y-m-d H:i', $last) : '-';
        printf("%-4d %-20s %-16s %-30s %-7d %-7s %s\n",
            (int)$r['id'],
            mb_substr((string)$r['username'], 0, 20),
            mb_substr((string)$r['nickname'], 0, 16),
            mb_substr((string)$r['email'], 0, 30),
            (int)$r['role_id'],
            ((int)$r['status'] === 1 ? 'active' : 'off'),
            $lastStr
        );
    }
    return 0;
}, ['usage' => 'user:list']);

CLI::register('user:reset-pwd', '重置管理员密码', function (array $args, array $opts): int {
    $username = $args[0] ?? '';
    if ($username === '') {
        CLI::err('请指定用户名，例如：bin/yikai user:reset-pwd admin');
        return 1;
    }
    $user = userModel()->findByUsername($username);
    if (!$user) {
        CLI::err("未找到用户：{$username}");
        return 1;
    }

    // 密码来源：--password=xxx 优先；否则隐藏输入
    $pwd = isset($opts['password']) && is_string($opts['password']) ? $opts['password'] : '';
    if ($pwd === '') {
        $pwd  = CLI::promptHidden("为 {$username} 输入新密码（不回显）：");
        $pwd2 = CLI::promptHidden("再输入一次以确认：");
        if ($pwd !== $pwd2) {
            CLI::err('两次密码不一致');
            return 1;
        }
    }
    if (strlen($pwd) < 6) {
        CLI::err('密码长度至少 6 位');
        return 1;
    }

    userModel()->setPassword((int)$user['id'], $pwd);
    CLI::ok("已重置 {$username}（ID={$user['id']}）的密码");
    return 0;
}, ['usage' => 'user:reset-pwd <username> [--password=xxx]']);
