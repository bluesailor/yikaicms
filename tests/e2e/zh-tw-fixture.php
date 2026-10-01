<?php
/**
 * CLI-only for the disposable e2e site: turn Traditional Chinese on (prepare) / restore it,
 * and report Simplified leftovers in captured zh-TW output (check, JSON on stdin).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Disposable runner site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/i18n/S2T.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}
$action = $argv[1] ?? '';
$backup = ROOT_PATH . '/storage/zh-tw-fixture-backup.json';
$keys = ['enabled_languages', 'show_lang_switcher', 'html_cache_enabled'];

if ($action === 'prepare') {
    if (is_file($backup)) throw new RuntimeException('Unrestored fixture');
    $rows = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'settings WHERE `key` IN (?, ?, ?)', $keys);
    file_put_contents($backup, json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    settingModel()->saveBatch(['enabled_languages' => '["zh-CN","en","ja","zh-TW"]', 'show_lang_switcher' => '1', 'html_cache_enabled' => '0']);
    exit;
}
if ($action === 'restore') {
    if (!is_file($backup)) exit;
    foreach ($keys as $key) db()->delete('settings', '`key` = ?', [$key]);
    foreach (json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR) as $row) db()->insert('settings', $row);
    unlink($backup);
    exit;
}
if ($action === 'check') {
    // 只认「简体专用字」：简→繁单字映射里会变、且本身不是任何繁体写法的字。
    // 不能拿整段再转一遍比对——台湾用词那一趟对繁体文本再跑会改词（文件→檔案），全是误报。
    $maps = require ROOT_PATH . '/includes/i18n/s2t_maps.php';
    $traditional = [];
    foreach ($maps['p1'] as $to) {
        foreach (mb_str_split($to) as $char) $traditional[$char] = true;
    }
    $simplifiedOnly = [];
    foreach ($maps['p1'] as $from => $to) {
        if (mb_strlen($from) === 1 && $from !== $to && !isset($traditional[$from])) $simplifiedOnly[$from] = true;
    }
    $segments = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $found = [];
    foreach ($segments as $seg) {
        $text = (string) $seg['text'];
        if (str_contains((string) $seg['where'], '[script')) {
            // 注释访客看不到，不算
            $text = (string) preg_replace(['#/\*.*?\*/#s', '#(^|[\s;{}])//[^\n]*#'], ['', '$1'], $text);
        }
        // JSON 里转义成 \uXXXX 的汉字也要看
        $text = (string) preg_replace_callback('/(?:\\\\u[0-9a-fA-F]{4})+/', static fn(array $m): string => (string) json_decode('"' . $m[0] . '"'), $text);
        $chars = mb_str_split($text);
        $samples = [];
        foreach ($chars as $i => $char) {
            if (isset($simplifiedOnly[$char])) {
                $samples[] = implode('', array_slice($chars, max(0, $i - 6), 14));
                if (count($samples) >= 3) break;
            }
        }
        if ($samples !== []) $found[] = ['where' => $seg['where'], 'samples' => $samples];
    }
    echo json_encode($found, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
throw new RuntimeException('Expected prepare, restore or check');
