<?php
/**
 * Yikai CMS - 在线升级检测接口
 *
 * 部署到 www.yikaicms.com 服务器
 * 访问地址：https://www.yikaicms.com/api/update/check?version=1.0.0
 *
 * 客户端（后台升级页面）请求此接口，传入当前版本号，
 * 服务端比较后返回是否有新版本及更新信息。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// 允许跨域（CMS后台从不同域名请求）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Content-Type: application/json; charset=utf-8');

// ============================================================
// 最新版本配置（发布新版时只需修改这里）
// ============================================================
$latestVersion  = '1.0.0';           // 最新版本号
$releaseDate    = '2026-02-15';      // 发布日期
$changelog      = '初始发布版本';     // 更新说明
$downloadUrl    = '';                 // 更新包下载地址，空表示暂无
$minPhpVersion  = '8.0.0';          // 最低 PHP 版本要求
$minMysqlVersion = '5.7.0';         // 最低 MySQL 版本要求

// ============================================================
// 处理请求
// ============================================================
$clientVersion = trim($_GET['version'] ?? '');

// 参数校验
if ($clientVersion === '') {
    echo json_encode([
        'code' => 1,
        'msg'  => '缺少 version 参数',
        'data' => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 版本号格式校验（x.y.z）
if (!preg_match('/^\d+\.\d+\.\d+$/', $clientVersion)) {
    echo json_encode([
        'code' => 1,
        'msg'  => '版本号格式错误，应为 x.y.z',
        'data' => null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 版本比较：version_compare 返回 -1/0/1
$hasUpdate = version_compare($clientVersion, $latestVersion, '<');

echo json_encode([
    'code' => 0,
    'msg'  => 'ok',
    'data' => [
        'has_update'       => $hasUpdate,
        'current_version'  => $clientVersion,
        'latest_version'   => $latestVersion,
        'release_date'     => $releaseDate,
        'changelog'        => $hasUpdate ? $changelog : '',
        'download_url'     => $hasUpdate ? $downloadUrl : '',
        'min_php_version'  => $minPhpVersion,
        'min_mysql_version' => $minMysqlVersion,
    ],
], JSON_UNESCAPED_UNICODE);
