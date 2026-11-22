<?php
/**
 * YikaiCMS — 定时任务入口。
 *
 * 由系统 crontab / 宝塔计划任务定时请求（建议每 5 分钟一次）：
 *   *\/5 * * * * curl -s "https://你的域名/cron.php?token=<token>" >/dev/null
 * token 在后台「系统 → 定时任务」页查看。也可用 CLI：php bin/yikai.php cron:run
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

header('Content-Type: text/plain; charset=utf-8');

$token = (string) ($_GET['token'] ?? '');
if ($token === '' || !hash_equals(Cron::token(), $token)) {
    http_response_code(403);
    exit("forbidden\n");
}

// ?task=<name>：只跑指定任务且无视间隔（如 task=demo_reset 作为站长一键重置链接收藏）
$task = (string) ($_GET['task'] ?? '');
// 建批后的立即触发（Cron::kick）发完请求就断开：断开后也要把任务跑完
ignore_user_abort(true);
if ($task !== '') {
    if (!isset($_GET['kick'])) Cron::beat();   // 立即触发不是计划任务在调用，不算心跳
    $r = Cron::runOne($task);
    if (!$r['ran']) {
        http_response_code(404);
        exit("- {$r['name']}: {$r['msg']}\n");
    }
    $tag = $r['ok'] ? 'OK' : 'FAIL';
    echo "* {$r['name']}: {$tag} ({$r['ms']}ms) {$r['msg']}\ndone\n";
    exit;
}

$force = isset($_GET['force']);
$results = Cron::runDue($force);

foreach ($results as $r) {
    if (!$r['ran']) {
        echo "- {$r['name']}: {$r['msg']}\n";
        continue;
    }
    $tag = $r['ok'] ? 'OK' : 'FAIL';
    echo "* {$r['name']}: {$tag} ({$r['ms']}ms) {$r['msg']}\n";
}
echo "done\n";
