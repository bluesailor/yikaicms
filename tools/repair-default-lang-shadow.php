<?php
/**
 * 修复「默认语言后缀行遮蔽」——改了设置前台不生效（kksky.ph 实病，2026-08-10）。
 *
 * 用法（放到站点根目录，浏览器访问）：
 *   /repair-default-lang-shadow.php?token=<TOKEN>          预演：只报告，不改
 *   /repair-default-lang-shadow.php?token=<TOKEN>&go=1     执行（先自动备份）
 *   用完立即删除本文件。
 *
 * ── 病因 ──
 * settings 的约定：base 行 = 默认语言内容，<key>_<lang> = 其他语言。
 * 前台 configLang 读取是**后缀优先**（对未归位的老站的兼容），后台保存写 base。
 * 若站点默认语言（如 en）还残留 <key>_en 后缀行（安装种子 / 早年切语言未归位——
 * 1.17.1/1.17.2 的切语言归位会因 ESCAPE 转义 bug 直接 500，留下一批这种站），
 * 则前台永远读后缀行：后台怎么改都不生效。kksky.ph 实测：改了 description，
 * 页面源码仍是安装种子的出厂文案。
 *
 * ── 三规则（不能一刀切删后缀行）──
 * 对每个 <key>_<默认语言> 行，对照同名 base 行：
 *   1. base 不存在或为空          → 后缀值提升为 base，删后缀行（前台显示不变）
 *   2. base 含 CJK 而后缀不含     → base 是没动过的中文种子 → 后缀提升覆盖 base，
 *                                    删后缀行（前台显示不变，英文站不露中文）
 *   3. 其余（base 是客户的编辑）  → 保留 base，删后缀行（客户的修改终于显示出来）
 * 规则 2 的 CJK 启发只对非中文默认语言的站点有效——本脚本会拒绝在 zh-CN 默认站上跑。
 *
 * 执行前把所有受影响行原值写入 storage/lang-shadow-backup-<时间>.json。
 */

declare(strict_types=1);

const REPAIR_TOKEN = '__REPAIR_TOKEN__';   // 上传前替换成随机串

header('Content-Type: text/plain; charset=utf-8');

if (!hash_equals(REPAIR_TOKEN, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit("forbidden\n");
}

define('ROOT_PATH', __DIR__);   // config.php 有 ROOT_PATH 守卫，不 define 直接 Access Denied
require __DIR__ . '/config/config.php';

$pdo = new PDO(
    (defined('DB_TYPE') && DB_TYPE === 'sqlite')
        ? 'sqlite:' . DB_NAME
        : 'mysql:host=' . DB_HOST . ';port=' . (defined('DB_PORT') ? DB_PORT : 3306) . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    defined('DB_USER') ? DB_USER : null,
    defined('DB_PASS') ? DB_PASS : null,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$T = DB_PREFIX . 'settings';

$q = $pdo->prepare("SELECT value FROM `$T` WHERE `key` = ?");
$get = static function (string $k) use ($q) {
    $q->execute([$k]);
    $v = $q->fetchColumn();
    return $v === false ? null : (string) $v;
};

$defaultLang = (string) ($get('site_lang') ?? 'zh-CN');
echo "站点默认语言：$defaultLang\n";
if ($defaultLang === 'zh-CN' || $defaultLang === '') {
    exit("默认语言是中文——规则 2 的 CJK 启发在此无效，本脚本不适用，退出。\n");
}

$suffix = '_' . $defaultLang;
$cjk = static fn(?string $s): bool => (bool) preg_match('/[\x{4e00}-\x{9fff}]/u', (string) $s);

// LIKE 转义符用 '!'——反斜杠在 MySQL 字符串字面量里本身是转义符（1.17.3 修过的同款坑）
$like = '%' . str_replace(['!', '_', '%'], ['!!', '!_', '!%'], $suffix);
$rows = $pdo->prepare("SELECT `key`, value FROM `$T` WHERE `key` LIKE ? ESCAPE '!'");
$rows->execute([$like]);
$suffixRows = $rows->fetchAll(PDO::FETCH_ASSOC);

echo '发现 ', count($suffixRows), " 个 {$suffix} 后缀行\n\n";

$plan = [];
foreach ($suffixRows as $r) {
    $sKey = (string) $r['key'];
    $sVal = (string) $r['value'];
    $baseKey = substr($sKey, 0, -strlen($suffix));
    if ($baseKey === '') continue;
    $bVal = $get($baseKey);

    if ($bVal === null || trim($bVal) === '') {
        $rule = 1; $act = '后缀提升为 base';
    } elseif ($cjk($bVal) && !$cjk($sVal)) {
        $rule = 2; $act = '后缀提升覆盖中文种子';
    } else {
        $rule = 3; $act = '保留 base（客户编辑），删后缀';
    }
    $plan[] = ['key' => $baseKey, 'suffixKey' => $sKey, 'rule' => $rule, 'act' => $act,
               'base' => $bVal, 'suffixVal' => $sVal];
    printf("  R%d %-32s %s\n      base=%s\n      sufx=%s\n",
        $rule, $baseKey, $act,
        $bVal === null ? '(无)' : mb_substr($bVal, 0, 60),
        mb_substr($sVal, 0, 60));
}

if (($_GET['go'] ?? '') !== '1') {
    exit("\n（预演。确认无误后加 &go=1 执行；执行前自动备份到 storage/）\n");
}

// ── 备份 ──
$bakFile = __DIR__ . '/storage/lang-shadow-backup-' . date('Ymd-His') . '.json';
@file_put_contents($bakFile, json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "\n备份 → ", basename($bakFile), "\n";

$upd = $pdo->prepare("UPDATE `$T` SET value = ? WHERE `key` = ?");
$del = $pdo->prepare("DELETE FROM `$T` WHERE `key` = ?");
$n = 0;
foreach ($plan as $p) {
    if ($p['rule'] === 1 || $p['rule'] === 2) {
        if ($p['base'] === null) {
            // base 行不存在：把后缀行改名成 base 行最稳（保留 group/type 等元数据）
            $pdo->prepare("UPDATE `$T` SET `key` = ? WHERE `key` = ?")->execute([$p['key'], $p['suffixKey']]);
            $n++;
            continue;
        }
        $upd->execute([$p['suffixVal'], $p['key']]);
    }
    $del->execute([$p['suffixKey']]);
    $n++;
}
echo "已处理 $n 个键\n";

// 清缓存：HTML 页缓存 + 数据缓存
foreach ((array) glob(__DIR__ . '/storage/cache/html/*') as $f) @unlink($f);
foreach ((array) glob(__DIR__ . '/storage/cache/*.cache') as $f) @unlink($f);
echo "缓存已清\n";

$left = $pdo->prepare("SELECT COUNT(*) FROM `$T` WHERE `key` LIKE ? ESCAPE '!'");
$left->execute([$like]);
echo "\n── 自检 ──\n剩余 {$suffix} 后缀行：", (int) $left->fetchColumn(), "（应为 0）\n";
echo "完成。请立即删除本文件。\n";
