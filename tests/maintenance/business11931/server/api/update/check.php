<?php
/**
 * Yikai CMS Update Server - 版本检测 API
 *
 * GET /api/update/check?version=1.0.0
 *
 * 响应格式:
 * {
 *   "code": 0,
 *   "data": {
 *     "has_update": true/false,
 *     "current_version": "1.0.0",
 *     "latest_version": "1.1.0",
 *     "release_date": "2026-03-01",
 *     "changelog": "...",
 *     "download_url": "https://update.yikaicms.com/packages/yikaicms-1.1.0.zip",
 *     "min_php": "8.0",
 *     "size": "2.5MB",
 *     "hash": "sha256:..."
 *   }
 * }
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
// 禁缓存：避免 SiteGround 边缘缓存把旧版本信息（如刚发布前的 latest）缓存住
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// 处理预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/_installs.php';
require __DIR__ . '/_channel.php';
require __DIR__ . '/_signature.php';

// 读取版本数据与中央登记表。登记表缺失或通道不一致时 fail closed，防止未占号的包被发布。
$dataFile = dirname(__DIR__, 2) . '/data/releases.json';
$registryFile = dirname(__DIR__, 2) . '/data/release-registry.json';

if (!file_exists($dataFile) || !file_exists($registryFile)) {
    echo json_encode(['code' => 1, 'msg' => '版本数据不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$releases = json_decode(file_get_contents($dataFile), true);
$registry = json_decode(file_get_contents($registryFile), true);

if (!is_array($releases) || !is_array($registry) || empty($releases['latest'])) {
    echo json_encode(['code' => 1, 'msg' => '版本数据格式错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取客户端当前版本（GET 兼容旧调用；POST 用于心跳上报）
$clientVersion = trim($_REQUEST['version'] ?? '');

// 放宽：接受 3~4 段版本号 + 可选客户后缀（如 1.7.6.2-shryphyxh）
if (!$clientVersion || !preg_match('/^\d+\.\d+(\.\d+){0,2}(-[A-Za-z0-9._\-]+)?$/', $clientVersion)) {
    echo json_encode(['code' => 1, 'msg' => '缺少 version 参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 比较用核心版本（去掉 -客户后缀）
$coreVersion = preg_replace('/-.*$/', '', $clientVersion) ?: $clientVersion;

try {
    $resolved = updateChannelResolveCatalog(
        $releases,
        $registry,
        $_REQUEST['channel'] ?? null,
        $coreVersion,
        $_REQUEST['domain'] ?? '',
        updateTargetIsManualCheck($_GET, (string) ($_SERVER['REQUEST_METHOD'] ?? ''))
    );
} catch (RuntimeException $e) {
    echo json_encode(['code' => 1, 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

$releases['latest'] = $resolved['latest'];
$releases['releases'] = $resolved['releases'];
$latestVersion = $resolved['latest'];
$hasUpdate = version_compare($latestVersion, $coreVersion, '>');

// 构建响应
$response = [
    'code' => 0,
    'data' => [
        'has_update'      => $hasUpdate,
        'current_version' => $clientVersion,
        'latest_version'  => $latestVersion,
        'channel'         => $resolved['requested_channel'],
        'release_channel' => $resolved['release_channel'],
    ],
];

// 有更新时附带详情
if ($hasUpdate) {
    $latestRelease = null;
    foreach ($releases['releases'] as $release) {
        if ($release['version'] === $latestVersion) {
            $latestRelease = $release;
            break;
        }
    }

    if ($latestRelease) {
        $signatureRequired = updateSignatureIsRequired($latestVersion);
        if (($signatureRequired || trim((string) ($latestRelease['sig'] ?? '')) !== '')
            && !updateSignatureMetadataIsValid($latestVersion, $latestRelease)) {
            echo json_encode(['code' => 1, 'msg' => '完整升级包签名无效，版本暂不可用'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $response['data']['release_date'] = $latestRelease['release_date'] ?? '';
        // 升级级别：security(关键安全)/feature(功能)/normal(常规)；1.12.8+ 客户端据此显示级别徽章
        $response['data']['level']        = $latestRelease['level'] ?? '';
        $response['data']['changelog']    = $latestRelease['changelog'] ?? '';
        $response['data']['min_php']      = $latestRelease['min_php'] ?? '8.0';
        $response['data']['size']         = $latestRelease['size'] ?? '';
        $response['data']['hash']         = $latestRelease['hash'] ?? '';
        // RSA 签名（在线升级客户端验签 "版本|hash"；v1.18.2 起强制存在）。
        $response['data']['sig']          = $latestRelease['sig'] ?? '';
        $response['data']['signature_required'] = $signatureRequired;

        // 下载地址：有 package 文件名时构建完整 URL
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'update.yikaicms.com';
        if (!empty($latestRelease['package'])) {
            $response['data']['download_url'] = $scheme . '://' . $host . '/packages/' . $latestRelease['package'];
        } else {
            $response['data']['download_url'] = '';
        }

        // 增量包（delta）：客户端「当前版本」正好等于某 delta 的 from 时，附带其小包信息。
        //   支持 delta 的客户端优先用它（下载几 KB、只覆盖变化文件）；
        //   旧客户端忽略此字段、照常走全量 download_url（向后兼容）。
        if (!empty($latestRelease['deltas']) && is_array($latestRelease['deltas'])) {
            foreach ($latestRelease['deltas'] as $delta) {
                if (($delta['from'] ?? '') === $coreVersion && !empty($delta['package'])) {
                    // 新版签名策略下，无效 delta 不下发，客户端自然回退到已签名完整包。
                    if (($signatureRequired || trim((string) ($delta['sig'] ?? '')) !== '')
                        && !updateSignatureMetadataIsValid($latestVersion, $delta)) {
                        break;
                    }
                    $response['data']['delta'] = [
                        'from'         => $delta['from'],
                        'download_url' => $scheme . '://' . $host . '/packages/' . $delta['package'],
                        'hash'         => $delta['hash'] ?? '',
                        'size'         => $delta['size'] ?? '',
                        'sig'          => $delta['sig'] ?? '',
                    ];
                    break;
                }
            }
        }
    }

    // 收集从客户端版本到最新版本之间的所有更新日志
    $changelogs = [];
    foreach ($releases['releases'] as $release) {
        if (version_compare($release['version'], $coreVersion, '>') && version_compare($release['version'], $latestVersion, '<=')) {
            $changelogs[] = [
                'version'      => $release['version'],
                'release_date' => $release['release_date'] ?? '',
                'changelog'    => $release['changelog'] ?? '',
            ];
        }
    }

    // 按版本降序
    usort($changelogs, function ($a, $b) {
        return version_compare($b['version'], $a['version']);
    });

    if (count($changelogs) > 1) {
        $response['data']['history'] = $changelogs;
    }
}

// 记录检测日志（可选）
$logDir = dirname(__DIR__, 2) . '/data/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
$ip = $_SERVER['REMOTE_ADDR'] ?? '-';
$logLine = date('Y-m-d H:i:s') . "\t" . $clientVersion . "\t" . $ip . "\t" . ($hasUpdate ? 'update_available' : 'up_to_date') . "\n";
@file_put_contents($logDir . '/' . date('Y-m') . '.log', $logLine, FILE_APPEND | LOCK_EX);

// 安装注册表：心跳上报时记录站点（域名优先取上报参数，回退 Origin/Referer）
$reportDomain = (string) ($_REQUEST['domain'] ?? '');
if ($reportDomain === '') {
    $reportDomain = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
}
$report = [
    'install_id' => (string) ($_REQUEST['install_id'] ?? ''),
    'domain'     => $reportDomain,
    'version'    => $clientVersion,
    'php'        => (string) ($_REQUEST['php'] ?? ''),
    'site_name'  => (string) ($_REQUEST['site_name'] ?? ''),
    'ip'         => $ip,
];
// 自动升级状态与站点健康摘要：**只在客户端真的带了这些参数时才传下去**，
// 否则老客户端的心跳会把已有记录覆盖成空（recordInstall 里靠 array_key_exists 判断）。
foreach (['auto', 'auto_scope', 'auto_window', 'auto_result', 'auto_at', 'auto_to', 'auto_msg',
          'health_at', 'health_crit', 'health_rec', 'health_bad'] as $optional) {
    if (array_key_exists($optional, $_REQUEST)) {
        $report[$optional] = (string) $_REQUEST[$optional];
    }
}
recordInstall($report);

echo json_encode($response, JSON_UNESCAPED_UNICODE);
