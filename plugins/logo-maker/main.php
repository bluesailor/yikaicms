<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 应用完整图标包后，在前台补充 Apple、Android 和 PWA 图标声明。
add_action('ik_head', function (): void {
    if ((string) config('logo_maker_applied', '') !== '1') {
        return;
    }

    $version = preg_replace('/\D/', '', (string) config('logo_maker_applied_at', ''));
    $query = $version !== '' ? '?v=' . $version : '';
    echo "\n" . '<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png' . $query . '">' .
        "\n" . '<link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png' . $query . '">' .
        "\n" . '<link rel="manifest" href="/site.webmanifest' . $query . '">' . "\n";
});
