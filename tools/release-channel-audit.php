<?php

/**
 * 发布渠道核对 CLI（R1）。
 *
 *   php tools/release-channel-audit.php 1.19.8 --candidate
 *   php tools/release-channel-audit.php 1.19.8 --post-release --online
 *   php tools/release-channel-audit.php 1.19.8 --post-release --online --json=out.json
 *
 * 退出码：0 = 本模式下全部达标；1 = 有渠道失败或（发布后模式）有渠道未执行；2 = 用法错误。
 *
 * `--online` 才会真的发请求。不加就把线上子项记「未执行」——发布后模式因此会如实失败，
 * 而不是悄悄放行。这是有意的：发布后核对不看线上就没有意义。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/ReleaseChannelAudit.php';

$projectRoot = dirname(__DIR__);
$workspace = dirname($projectRoot);

$version = '';
$mode = ReleaseChannelAudit::MODE_CANDIDATE;
$online = false;
$jsonOut = '';
$configPath = $projectRoot . '/config/release-channels.php';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--candidate') {
        $mode = ReleaseChannelAudit::MODE_CANDIDATE;
    } elseif ($arg === '--post-release') {
        $mode = ReleaseChannelAudit::MODE_POST_RELEASE;
    } elseif ($arg === '--online') {
        $online = true;
    } elseif (str_starts_with($arg, '--json=')) {
        $jsonOut = substr($arg, 7);
    } elseif (str_starts_with($arg, '--config=')) {
        $configPath = substr($arg, 9);
    } elseif (str_starts_with($arg, '--workspace=')) {
        $workspace = substr($arg, 12);
    } elseif (str_starts_with($arg, '-')) {
        fwrite(STDERR, "未知参数: {$arg}\n");
        exit(2);
    } elseif ($version === '') {
        $version = ltrim($arg, 'v');
    } else {
        fwrite(STDERR, "只能指定一个版本号\n");
        exit(2);
    }
}

if ($version === '') {
    fwrite(STDERR, "用法: php tools/release-channel-audit.php <version> [--candidate|--post-release] [--online] [--json=<path>]\n");
    exit(2);
}
if (!is_file($configPath)) {
    fwrite(STDERR, "渠道配置缺失: {$configPath}\n");
    exit(2);
}

/**
 * 真实网络取数：只做 GET，绝不碰 install/upgrade 类写操作路径。
 *
 * $wantBody 为真时才把正文落到临时文件再读回来——演示站要靠正文里的资源版本
 * 查询串判断版本，而主题包动辄百 KB 起，没必要一律读回内存。
 */
$fetcher = static function (string $url, bool $wantBody = false): array {
    $bodyFile = $wantBody ? tempnam(sys_get_temp_dir(), 'yk-fetch-') : null;
    $sink = $bodyFile ?? (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
    $command = [
        'curl', '-sS', '-L', '--max-time', '30',
        '-o', $sink,
        '-w', '%{http_code}|%{content_type}|%{size_download}',
        $url,
    ];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return ['status' => 0, 'type' => '', 'bytes' => 0, 'error' => 'cannot start curl'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    $body = '';
    if ($bodyFile !== null) {
        // 只读回前 512 KB：版本探针在 <head>/资源引用里，不需要整页。
        $handle = @fopen($bodyFile, 'rb');
        if (is_resource($handle)) {
            $body = (string) fread($handle, 512 * 1024);
            fclose($handle);
        }
        @unlink($bodyFile);
    }

    $parts = array_pad(explode('|', trim($stdout)), 3, '');
    if ($code !== 0) {
        // curl 自己没跑成：这是本机/网络问题，不是线上资源坏了，也不许自动归因为 WAF。
        return [
            'status' => (int) $parts[0],
            'type' => $parts[1],
            'bytes' => (int) $parts[2],
            'error' => 'curl exit ' . $code . ' ' . preg_replace('/\s+/', ' ', trim($stderr)),
            'body' => $body,
        ];
    }
    return [
        'status' => (int) $parts[0], 'type' => $parts[1], 'bytes' => (int) $parts[2],
        'error' => '', 'body' => $body,
    ];
};

$config = require $configPath;
if (!is_array($config)) {
    fwrite(STDERR, "渠道配置必须返回数组: {$configPath}\n");
    exit(2);
}

try {
    $report = ReleaseChannelAudit::run($config, $version, $mode, $workspace, $online ? $fetcher : null);
} catch (Throwable $e) {
    fwrite(STDERR, 'CHANNEL AUDIT ERROR: ' . $e->getMessage() . "\n");
    exit(2);
}

$label = [
    ReleaseChannelAudit::VERIFIED => '已验证',
    ReleaseChannelAudit::FAILED => '失败',
    ReleaseChannelAudit::SKIPPED => '未执行',
];

$modeName = $mode === ReleaseChannelAudit::MODE_CANDIDATE ? '候选阶段' : '发布后核对';
fwrite(STDOUT, "发布渠道核对 — v{$version}（{$modeName}" . ($online ? '，含线上请求' : '，未请求线上') . ")\n");
fwrite(STDOUT, "观测时刻: {$report['observed_at']}    workspace: {$report['workspace']}\n\n");

foreach ($report['channels'] as $key => $channel) {
    $status = (string) $channel['status'];
    $mark = $status === ReleaseChannelAudit::VERIFIED ? '✓' : ($status === ReleaseChannelAudit::FAILED ? '✗' : '·');
    fwrite(STDOUT, "{$mark} [{$key}] {$channel['label']} — {$label[$status]}\n");
    if (isset($channel['note'])) {
        fwrite(STDOUT, "    注: {$channel['note']}\n");
    }
    foreach ($channel['checks'] as $check) {
        $sub = $check['ok'] === true ? '✓' : ($check['ok'] === false ? '✗' : '·');
        fwrite(STDOUT, "    {$sub} {$check['name']}: {$check['detail']}\n");
        $evidence = $check['evidence'] ?? null;
        if (is_array($evidence) && isset($evidence['mtime'])) {
            // 官网这类活动目录，mtime 是判断「我看到的是不是别人刚改过的」的唯一依据。
            fwrite(STDOUT, "        证据: {$evidence['path']}  mtime={$evidence['mtime']}\n");
        }
    }
    fwrite(STDOUT, "\n");
}

if ($jsonOut !== '') {
    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || file_put_contents($jsonOut, $encoded . "\n") === false) {
        fwrite(STDERR, "无法写出 JSON 报告: {$jsonOut}\n");
        exit(2);
    }
    fwrite(STDOUT, "JSON 报告: {$jsonOut}\n");
}

if ($report['ok'] === true) {
    fwrite(STDOUT, "渠道核对通过：本模式下所有必需渠道均已验证。\n");
    exit(0);
}

$bad = [];
foreach ($report['channels'] as $key => $channel) {
    if ($channel['status'] !== ReleaseChannelAudit::VERIFIED) {
        $bad[] = $key . '=' . $label[(string) $channel['status']];
    }
}
fwrite(STDERR, '渠道核对未通过：' . implode('，', $bad) . "\n");
if ($mode === ReleaseChannelAudit::MODE_POST_RELEASE) {
    fwrite(STDERR, "发布后核对里「未执行」不能当作通过——补齐后重跑，不要在文档里记为已完成。\n");
}
exit(1);
