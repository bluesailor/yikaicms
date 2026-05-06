<?php
/**
 * 翻译 themes/* 和 admin/* 的可见注释 (HTML/JS):
 *   <!-- 中文 -->        → <!-- English -->
 *   // 中文 (JS 行内)    → // English (仅在 <script> 块内或 .js 文件)
 *
 * PHP 内部注释 (/* * /, //, #) 不动 - 开发者注释不影响 UX
 *
 * 调 DeepSeek 走 D:/tmp_ds_cache.json 缓存
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit("CLI only\n");

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/AiService.php';

$pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
    DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$apiKey = AiService::decryptKey((string)$pdo->query("SELECT value FROM " . DB_PREFIX . "settings WHERE `key`='ai_api_key'")->fetchColumn());
$model = (string)$pdo->query("SELECT value FROM " . DB_PREFIX . "settings WHERE `key`='ai_model'")->fetchColumn() ?: 'deepseek-v4-flash';
if (!$apiKey) exit("[!] AI key 未配置\n");

const CACHE = 'D:/tmp_ds_cache.json';
$cache = file_exists(CACHE) ? (json_decode(file_get_contents(CACHE), true) ?: []) : [];

function ds_translate(string $cn, string $apiKey, string $model, array &$cache): string {
    $cn = trim($cn);
    if ($cn === '' || !preg_match('/[\x{4e00}-\x{9fff}]/u', $cn)) return $cn;
    if (isset($cache[$cn])) return $cache[$cn];
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'Translate Chinese text to natural English. Return ONLY the translation, no explanation, no quotes.'],
            ['role' => 'user', 'content' => $cn],
        ],
        'temperature' => 0.2,
        'max_tokens' => 500,
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
    if ($code !== 200) return $cn;
    $en = trim(json_decode($resp, true)['choices'][0]['message']['content'] ?? '');
    $en = trim($en, "\"' \t\n");
    if ($en === '') return $cn;
    $cache[$cn] = $en;
    file_put_contents(CACHE, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return $en;
}

// 扫描目标目录
$dirs = [
    ROOT_PATH . '/themes/default',
    ROOT_PATH . '/themes/business',
    ROOT_PATH . '/themes/minimal',
];
$files = [];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($rii as $f) {
        if ($f->isDir()) continue;
        $ext = strtolower($f->getExtension());
        if (in_array($ext, ['php', 'html', 'js'], true)) $files[] = (string)$f;
    }
}

$totalFiles = $totalReplaced = 0;
foreach ($files as $file) {
    $src = file_get_contents($file);
    $orig = $src;

    // 1) HTML 注释 <!-- 中文 -->
    $src = preg_replace_callback('/<!--\s*(.+?)\s*-->/u', function ($m) use ($apiKey, $model, &$cache) {
        $text = $m[1];
        if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) return $m[0];
        $en = ds_translate($text, $apiKey, $model, $cache);
        return '<!-- ' . $en . ' -->';
    }, $src);

    if ($src !== $orig) {
        file_put_contents($file, $src);
        $count = substr_count($orig, '<!-- ') - substr_count($src, '<!--' . $orig); // 粗估
        $totalReplaced++;
        echo "  ✓ " . str_replace(ROOT_PATH . '/', '', $file) . "\n";
    }
    $totalFiles++;
}

echo "\n✓ 扫了 $totalFiles 文件, 改了 $totalReplaced 个\n";
echo "  缓存条目: " . count($cache) . "\n";
