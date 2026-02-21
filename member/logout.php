<?php
/**
 * Yikai CMS - 会员登出
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

doMemberLogout();
redirect('/');
