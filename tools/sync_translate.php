<?php
/**
 * 增量翻译同步: yikai_* (CN) → enkai_* (EN)
 *
 * 用法:
 *   php tools/sync_translate.php              # 增量同步 (自上次)
 *   php tools/sync_translate.php --full       # 全量重译 (会覆盖)
 *   php tools/sync_translate.php --dry-run    # 仅报告需要译的行数
 *   php tools/sync_translate.php --table=channels   # 仅同步某表
 *
 * - DeepSeek key / model 从 DB settings (yikai_*) 读取并解密
 * - 跨表前缀: 源 yikai_, 目标 enkai_ (写死, 因这是 yikaicms 项目约定)
 * - 跟踪 last_sync_at 写在 enkai_settings.sync_last_run_at
 * - 缓存翻译: D:/tmp_ds_cache.json (跨 run 复用)
 *
 * 适合 cron / 计划任务: 每 5-10 分钟跑一次.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");

// ---------- 解析参数 ----------
$opts = getopt('', ['full', 'dry-run', 'table:']);
$isFull = isset($opts['full']);
$isDry  = isset($opts['dry-run']);
$onlyTbl = $opts['table'] ?? null;

// ---------- 引导 yikaicms 上下文 ----------
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/AiService.php';

// ---------- 表配置 ----------
$tables = [
    'channels'           => ['name', 'description', 'content', 'seo_title', 'seo_keywords', 'seo_description'],
    'contents'           => ['title', 'subtitle', 'summary', 'content', 'tags', 'author', 'source', 'client_name', 'industry', 'duration', 'result_metric', 'location', 'salary', 'requirements', 'seo_title', 'seo_keywords', 'seo_description'],
    'products'           => ['title', 'subtitle', 'summary', 'content', 'specs', 'tags', 'model', 'material', 'scene'],
    'product_categories' => ['name', 'description', 'seo_title', 'seo_keywords', 'seo_description'],
    'product_tags'       => ['name', 'group_name'],
    'brands'             => ['name', 'description', 'country'],
    'links'              => ['name', 'description'],
    'banners'            => ['title', 'subtitle', 'btn1_text', 'btn2_text'],
    'banner_groups'      => ['name'],
    'jobs'               => ['title', 'summary', 'requirements', 'location', 'salary'],
    'albums'             => ['name', 'description'],
    'album_photos'       => ['title', 'description'],
    'timelines'          => ['title', 'content'],
    'downloads'          => ['title', 'description', 'file_name'],
    'form_templates'     => ['name', 'success_message'],
    'settings'           => ['value'],
];
$srcPrefix = 'yikai_';
$dstPrefix = 'enkai_';

if ($onlyTbl) {
    if (!isset($tables[$onlyTbl])) exit("[!] 未知表: $onlyTbl\n");
    $tables = [$onlyTbl => $tables[$onlyTbl]];
}

// ---------- DB ----------
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// ---------- 读 AI 配置 ----------
$cfg = function (string $k) use ($pdo, $srcPrefix): string {
    $stmt = $pdo->prepare("SELECT value FROM {$srcPrefix}settings WHERE `key`=?");
    $stmt->execute([$k]);
    return (string)$stmt->fetchColumn();
};
$provider = $cfg('ai_provider') ?: 'deepseek';
$apiKey   = AiService::decryptKey($cfg('ai_api_key'));
$model    = $cfg('ai_model') ?: 'deepseek-v4-flash';
if (!$apiKey) exit("[!] AI key 未配置. 在后台 AI 服务配置里填 DeepSeek key.\n");

echo "=== sync_translate ===\n";
echo "  provider: $provider, model: $model, key: " . substr($apiKey, 0, 8) . "...\n";
echo "  mode: " . ($isFull ? 'FULL' : ($isDry ? 'DRY-RUN' : 'INCREMENTAL')) . "\n";

// ---------- last sync 时间 ----------
$lastKey = 'sync_last_run_at';
$stmt = $pdo->prepare("SELECT value FROM {$dstPrefix}settings WHERE `key`=?");
$stmt->execute([$lastKey]);
$lastSync = (int)$stmt->fetchColumn();
if ($isFull) $lastSync = 0;
echo "  last sync: " . ($lastSync ? date('Y-m-d H:i:s', $lastSync) : '(never)') . "\n\n";

// ---------- 翻译缓存 ----------
const CACHE = 'D:/tmp_ds_cache.json';
$cache = file_exists(CACHE) ? (json_decode(file_get_contents(CACHE), true) ?: []) : [];

// ---------- DeepSeek 翻译 ----------
function ds_translate(string $cn, string $apiKey, string $model, array &$cache): string {
    $cn = trim($cn);
    if ($cn === '' || !preg_match('/[\x{4e00}-\x{9fff}]/u', $cn)) return $cn;
    if (isset($cache[$cn])) return $cache[$cn];

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'You are a translator. Translate the user-provided Chinese text into natural professional English. Preserve any HTML tags, JSON structure, placeholder tokens like {{xxx}} or {year}, URLs, and code. Return ONLY the translation, no explanation, no quotes, no markdown.'],
            ['role' => 'user', 'content' => $cn],
        ],
        'temperature' => 0.2,
        'max_tokens' => 2000,
    ];
    $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        echo "  [!] DS HTTP $code\n";
        return $cn;
    }
    $en = trim(json_decode($resp, true)['choices'][0]['message']['content'] ?? '');
    $en = trim($en, "\"' \t\n");
    if ($en === '') return $cn;
    $cache[$cn] = $en;
    file_put_contents(CACHE, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $en;
}

function looks_like_json(string $s): bool {
    $t = trim($s);
    return ($t !== '' && ($t[0] === '{' || $t[0] === '[') && json_decode($t) !== null);
}

function translate_value(string $val, string $apiKey, string $model, array &$cache): string {
    if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $val)) return $val;
    if (looks_like_json($val)) {
        $arr = json_decode($val, true);
        $arr = json_walk_translate($arr, $apiKey, $model, $cache);
        return json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return ds_translate($val, $apiKey, $model, $cache);
}

function json_walk_translate($v, string $apiKey, string $model, array &$cache) {
    if (is_string($v) && preg_match('/[\x{4e00}-\x{9fff}]/u', $v)) {
        return ds_translate($v, $apiKey, $model, $cache);
    }
    if (is_array($v)) {
        foreach ($v as $k => $vv) $v[$k] = json_walk_translate($vv, $apiKey, $model, $cache);
    }
    return $v;
}

// ---------- 主同步循环 ----------
$totalScanned = $totalTranslated = $totalUpserted = 0;
foreach ($tables as $tbl => $cols) {
    $src = $srcPrefix . $tbl;
    $dst = $dstPrefix . $tbl;

    // 检查目标表是否存在
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?");
    $stmt->execute([DB_NAME, $dst]);
    if (!$stmt->fetchColumn()) { echo "  $tbl: 跳过 (无 $dst 表)\n"; continue; }

    // 取列
    $allColsRow = $pdo->query("SELECT * FROM $src LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$allColsRow) { continue; }
    $allCols = array_keys($allColsRow);

    // 增量条件
    $where = '1=1';
    $params = [];
    if (!$isFull && in_array('updated_at', $allCols, true)) {
        $where = "updated_at >= ?";
        $params = [$lastSync];
    }

    $rows = $pdo->prepare("SELECT * FROM $src WHERE $where");
    $rows->execute($params);
    $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
    $totalScanned += count($rows);
    if (!$rows) { echo "  $tbl: 0 行需要同步\n"; continue; }

    if ($isDry) { echo "  $tbl: " . count($rows) . " 行需要同步\n"; continue; }

    $colList = implode(',', array_map(fn($c) => "`$c`", $allCols));
    $placeholders = implode(',', array_fill(0, count($allCols), '?'));
    $updateSet = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $allCols));
    $upsert = $pdo->prepare("INSERT INTO $dst ($colList) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updateSet");

    $tCount = 0;
    foreach ($rows as $r) {
        foreach ($cols as $c) {
            if (!isset($r[$c])) continue;
            $cn = (string)$r[$c];
            $en = translate_value($cn, $apiKey, $model, $cache);
            if ($en !== $cn) { $r[$c] = $en; $tCount++; }
        }
        if (array_key_exists('lang', $r)) $r['lang'] = 'en';
        if (array_key_exists('translation_group_id', $r)) $r['translation_group_id'] = (int)($r['id'] ?? 0);
        $upsert->execute(array_values($r));
        $totalUpserted++;
    }
    $totalTranslated += $tCount;
    echo "  $tbl: " . count($rows) . " 行同步, $tCount 字段调译\n";
}

// ---------- 写 last_sync_at ----------
if (!$isDry) {
    $now = time();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$dstPrefix}settings WHERE `key`=?");
    $stmt->execute([$lastKey]);
    if ($stmt->fetchColumn() > 0) {
        $pdo->prepare("UPDATE {$dstPrefix}settings SET value=? WHERE `key`=?")->execute([(string)$now, $lastKey]);
    } else {
        $pdo->prepare("INSERT INTO {$dstPrefix}settings (`key`, name, type, value) VALUES (?, ?, 'text', ?)")
            ->execute([$lastKey, $lastKey, (string)$now]);
    }
}

echo "\n✓ done. scanned=$totalScanned, upserted=$totalUpserted, translated_fields=$totalTranslated\n";
