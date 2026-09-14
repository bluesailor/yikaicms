<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) exit('Access Denied');

require_once __DIR__ . '/compatibility.php';
if (!DoLoginCompatibility::currentSiteSupported()) return;

add_action('admin_login_request', static function (): void {
    if (($_GET['dologin'] ?? '') !== '1') return;
    require __DIR__ . '/login.php';
    exit;
});
