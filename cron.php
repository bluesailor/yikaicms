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
