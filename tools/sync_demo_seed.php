<?php
/**
 * 用参照站（开发站）的外观数据回灌安装种子。
 *
 * 装完包的站点应当和开发站长得一样：同样的页头/页脚区域模板、同样的首页 Blox 文档、
 * 同样的页脚栏目与首页区块开关。这些数据平时只存在于开发站的库里，改完不会自动回到
 * install/sql/*.sql，于是每发一版新装站就越来越"素"。本工具把这段回灌固定下来。
 *
 * 用法：
 *   php tools/sync_demo_seed.php                # 参照站 = 本仓库 config/config.php 指向的库
 *   php tools/sync_demo_seed.php --dry-run      # 只报告差异，不改文件
 *   php tools/sync_demo_seed.php --ref=D:/path/to/site
 *
 * 只同步下面两份白名单，其它一概不动：
 *   1. blox_templates 中的内置区域模板（按 source_ref 认，开发过程中的草稿/试验模板不进包）
 *   2. 外观相关的 settings 键（首页文档、页脚栏目、导航开关…）
 * 运行期状态（cron_*、license_*、static_html_*、*_history…）与站点身份（site_name、
 * 备案号、favicon、密钥）永远不同步。
 *
 * mysql.sql 是唯一可信源，sqlite.sql 由 tools/mysql_to_sqlite.php 生成。
 */

declare(strict_types=1);

// 首页文档单条就有十几 KB，默认的 PCRE JIT 栈和回溯上限都不够用（会以
// "JIT stack limit exhausted" 失败，而不是安静地写错）。
ini_set('pcre.jit', '0');
ini_set('pcre.backtrack_limit', '10000000');

$repoRoot = dirname(__DIR__);

// ── 白名单 1：随包的内置区域模板（按 type + source_ref 精确匹配）────────────────
// status 由参照站决定：开发站发布了哪套页头/页脚，新装站就用哪套。
const TEMPLATE_REFS = [
    ['header', 'clean-site-header'],
    ['header', 'corporate-site-header'],
    ['footer', 'corporate-site-footer'],
    ['footer', 'clean-site-footer'],
    ['footer', 'business-site-footer'],
    ['footer', 'minimal-site-footer'],
    ['article-detail', 'classic-article-detail'],
    ['product-detail', 'classic-product-detail'],
];

// ── 白名单 2：外观 settings ───────────────────────────────────────────────────
const SETTING_KEYS = [
    // 首页 Blox 文档（前台实际渲染的那份）与旧版区块开关
    'home_blox_active',
    'home_blox_data',
    'home_blox_published',
    'home_blocks_config',
    // 页脚结构（三语）
    'footer_columns',
    'footer_columns_en',
    'footer_columns_ja',
    'footer_nav',
    'footer_nav_en',
    'footer_nav_ja',
    // 页头 / 导航
    'blox_custom_header_enabled',
    'header_scroll_opacity',
    'nav_home_text',
    'nav_home_text_en',
    'nav_home_text_ja',
    'nav_icons_enabled',
    'banner_fullscreen',
    // 主题与内页头图
    'theme_color_profiles',
    'page_hero_style_options',
    // 发展历程展示方式
    'timeline_layout',
    'timeline_sort',
];

$dryRun = in_array('--dry-run', $argv, true);
$refRoot = $repoRoot;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--ref=')) {
        $refRoot = rtrim(substr($arg, 6), '/\\');
    }
}

// ── 连接参照站 ───────────────────────────────────────────────────────────────
if (!is_file($refRoot . '/config/config.php')) {
    fwrite(STDERR, "参照站没有 config/config.php：{$refRoot}\n");
    exit(1);
}
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $refRoot);
}
require_once $refRoot . '/config/config.php';

$pdo = DB_DRIVER === 'sqlite'
    ? new PDO('sqlite:' . DB_PATH)
    : new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS
    );
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$prefix = DB_PREFIX;

/**
 * 首页文档入种子前的必要归一化。
 *
 * 开发站为了调轮播常把 banner 区块切到 items_mode=custom，那样中文文案会被烤死在
 * 文档里：英文/日文安装站首页照样显示中文（2026-08-25 两个客户站实病）。种子里必须
 * 是 inherit，让轮播按语言从 banners 表取数。防回归见 BloxSeedSanitizerStableTest。
 */
function normalizeHomeDocument(string $json): string
{
    $doc = json_decode($json, true);
    if (!is_array($doc)) {
        return $json;
    }
    $walk = static function (array &$node) use (&$walk): void {
        foreach ($node as $key => &$value) {
            if (!is_array($value)) {
                continue;
            }
            if (($value['type'] ?? '') === 'home-block' && ($value['data']['block_type'] ?? '') === 'banner') {
                $value['data']['items_mode'] = 'inherit';
                if (is_array($value['data']['children'] ?? null)) {
                    $value['data']['children'] = array_map('sanitizeBannerChild', $value['data']['children']);
                }
            }
            $walk($value);
        }
    };
    $walk($doc);
    return (string) json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * 轮播子项去掉「只对参照站有意义」的绑定字段。
 *
 * inherit 模式下前台取的是 banners 表，这些子项只是编辑器里的兜底预览；把参照站的
 * 轮播行 id、翻译组、语言烤进种子没有意义，装到别的站上还会指向不存在的行。
 *
 * @param mixed $child
 * @return mixed
 */
function sanitizeBannerChild($child)
{
    if (!is_array($child) || !is_array($child['data'] ?? null)) {
        return $child;
    }
    foreach (['source_banner_id', 'translation_group_id', 'lang'] as $siteBound) {
        unset($child['data'][$siteBound]);
    }
    return $child;
}

/** MySQL 字面量转义：与 tools/mysql_to_sqlite.php 的反向解析一一对应。 */
function mysqlLiteral(?string $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    $escaped = str_replace(
        ['\\', "'", '"', "\n", "\r", "\t", "\0"],
        ['\\\\', "\\'", '\\"', '\\n', '\\r', '\\t', '\\0'],
        $value
    );
    return "'" . $escaped . "'";
}

$sqlPath = $repoRoot . '/install/sql/mysql.sql';
$raw = (string) file_get_contents($sqlPath);
$crlf = str_contains($raw, "\r\n");
$lines = explode("\n", str_replace("\r\n", "\n", $raw));
$changes = [];

// ── 1. blox_templates ────────────────────────────────────────────────────────
$templateColumns = ['type', 'name', 'source', 'source_ref', 'schema_version', 'draft_data', 'published_data', 'requirements', 'metadata', 'thumbnail', 'status', 'published_at'];
$rows = [];
foreach (TEMPLATE_REFS as [$type, $ref]) {
    $row = $pdo->query(
        'SELECT * FROM `' . $prefix . 'blox_templates` WHERE `type` = ' . mysqlLiteral($type)
        . ' AND `source_ref` = ' . mysqlLiteral($ref) . ' LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fwrite(STDERR, "参照站缺少区域模板 {$type}/{$ref}，已跳过\n");
        continue;
    }
    $rows[] = $row;
}
if ($rows) {
    $values = [];
    foreach ($rows as $i => $row) {
        $cells = [(string) ($i + 1)];
        foreach ($templateColumns as $col) {
            $value = $row[$col] ?? null;
            $cells[] = in_array($col, ['schema_version', 'status', 'published_at'], true)
                ? (string) (int) $value
                : mysqlLiteral($value === null ? null : (string) $value);
        }
        // admin_id / created_at / updated_at 用固定值，避免每次同步都产生无意义 diff
        $cells[] = '0';
        $cells[] = '1776652898';
        $cells[] = '1776652898';
        $values[] = '(' . implode(',', $cells) . ')';
    }
    // 每行一条完整 INSERT：tools/mysql_to_sqlite.php 只转换以 INSERT 开头的行，
    // 多行 VALUES 的续行会原样落进 sqlite 种子，JSON 里的 \" 不会被还原，
    // 装出来的站点读模板时报「排版数据不是有效 JSON」，页头页脚直接不渲染。
    $columnList = '(`id`,`' . implode('`,`', $templateColumns) . '`,`admin_id`,`created_at`,`updated_at`)';
    $statements = [];
    foreach ($values as $tuple) {
        $statements[] = 'INSERT INTO `yikai_blox_templates` ' . $columnList . ' VALUES ' . $tuple . ';';
    }
    $insert = implode("\n", $statements);

    // 整段替换：从第一条 blox_templates 的 INSERT 开始，一直到最后一条语句收尾。
    // 旧种子是「一条 INSERT 多行 VALUES」，新种子是「每行一条 INSERT」，两种都要能认出来。
    $start = null;
    $end = null;
    foreach ($lines as $i => $line) {
        if (str_starts_with($line, 'INSERT INTO `yikai_blox_templates`')) {
            $start ??= $i;
            $end = $i;
            continue;
        }
        if ($start !== null && $end !== null && !str_ends_with(rtrim($lines[$end]), ';')) {
            // 上一条语句还没收尾：当前行是它的 VALUES 续行
            $end = $i;
        }
    }
    if ($start === null || $end === null) {
        fwrite(STDERR, "mysql.sql 里找不到 blox_templates 的 INSERT\n");
        exit(1);
    }
    $before = implode("\n", array_slice($lines, $start, $end - $start + 1));
    if ($before !== $insert) {
        array_splice($lines, $start, $end - $start + 1, explode("\n", $insert));
        $changes[] = 'blox_templates：' . count($rows) . ' 个区域模板（'
            . implode('、', array_map(static fn (array $r): string => $r['name'] . ($r['status'] ? '·已发布' : ''), $rows)) . '）';
    }
}

// ── 2. 栏目骨架：参照站有、种子没有的子栏目 ──────────────────────────────────
// 只按 slug 补，且必须能在种子里找到同 slug 的父栏目——不整表同步，避免把开发过程中
// 建的沙盒栏目带进包。id 一律重新分配：参照站的 id 早被种子里别的栏目占用了。
const CHANNEL_SLUGS = [
    'software-download', 'document-download', 'driver-download',
    'software-download-en', 'document-download-en', 'driver-download-en',
    'software-download-ja', 'document-download-ja', 'driver-download-ja',
];

$channelColumns = null;
$seedChannels = [];      // slug => [id, lang]
$maxChannelId = 0;
$lastChannelLine = null;
foreach ($lines as $i => $line) {
    if (!str_starts_with($line, 'INSERT INTO `yikai_channels`')) {
        continue;
    }
    if (preg_match("/VALUES \((\d+),/", $line, $m)) {
        $maxChannelId = max($maxChannelId, (int) $m[1]);
        $lastChannelLine = $i;
    }
    if (preg_match("/VALUES \((\d+),[^;]*?'((?:[^']|\\\\')*)'/", $line, $m)) {
        // slug 是第几列取决于列清单，下面用参照站的列名定位，这里先记住行
    }
    if ($channelColumns === null && preg_match('/INSERT INTO `yikai_channels` \(([^)]+)\) VALUES/', $line, $m)) {
        $channelColumns = array_map(
            static fn (string $c): string => trim($c, " `"),
            explode(',', $m[1])
        );
    }
}
if ($channelColumns !== null && $lastChannelLine !== null) {
    // 种子现有 slug@lang → id
    foreach ($lines as $line) {
        if (!str_starts_with($line, 'INSERT INTO `yikai_channels`')) {
            continue;
        }
        if (!preg_match('/VALUES \((.*)\);$/', $line, $m)) {
            continue;
        }
        $cells = str_getcsv($m[1], ',', "'");
        $row = @array_combine($channelColumns, $cells);
        if (!is_array($row)) {
            continue;
        }
        $seedChannels[$row['slug'] . '@' . $row['lang']] = $row;
    }

    $newChannelLines = [];
    $assignedIds = [];      // 参照站 id → 种子新 id
    foreach (CHANNEL_SLUGS as $slug) {
        $ref = $pdo->query('SELECT * FROM `' . $prefix . 'channels` WHERE `slug` = ' . mysqlLiteral($slug) . ' LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        if (!$ref) {
            fwrite(STDERR, "参照站没有栏目 {$slug}，已跳过\n");
            continue;
        }
        if (isset($seedChannels[$slug . '@' . $ref['lang']])) {
            continue;
        }
        $parent = $pdo->query('SELECT `slug`, `lang` FROM `' . $prefix . 'channels` WHERE `id` = ' . (int) $ref['parent_id'])
            ->fetch(PDO::FETCH_ASSOC);
        $parentKey = $parent ? $parent['slug'] . '@' . $parent['lang'] : '';
        if (!isset($seedChannels[$parentKey])) {
            fwrite(STDERR, "种子里没有 {$slug} 的父栏目（{$parentKey}），已跳过\n");
            continue;
        }
        $maxChannelId++;
        $assignedIds[(int) $ref['id']] = $maxChannelId;

        $cells = [];
        foreach ($channelColumns as $col) {
            $value = $ref[$col] ?? null;
            if ($col === 'id') {
                $cells[] = (string) $maxChannelId;
            } elseif ($col === 'parent_id') {
                $cells[] = (string) (int) $seedChannels[$parentKey]['id'];
            } elseif ($col === 'translation_group_id') {
                // 翻译组以中文源行的新 id 为准；中文行自己指向自己
                $group = (int) $ref['translation_group_id'];
                $cells[] = (string) ($assignedIds[$group] ?? $maxChannelId);
            } elseif (is_numeric($value) && !in_array($col, ['name', 'slug', 'seo_title'], true)) {
                $cells[] = (string) (int) $value;
            } else {
                $cells[] = mysqlLiteral($value === null ? null : (string) $value);
            }
        }
        $newChannelLines[] = 'INSERT INTO `yikai_channels` (`' . implode('`, `', $channelColumns) . '`) VALUES ('
            . implode(',', $cells) . ');';
        $changes[] = "channels.{$slug}（新增 #{$maxChannelId}）";
    }
    if ($newChannelLines) {
        array_splice($lines, $lastChannelLine + 1, 0, $newChannelLines);
    }
}

// ── 3. settings ──────────────────────────────────────────────────────────────
$settingLine = [];      // key => 行号
$maxId = 0;
$lastSettingLine = null;
foreach ($lines as $i => $line) {
    if (!str_starts_with($line, 'INSERT INTO `yikai_settings`')) {
        continue;
    }
    if (preg_match("/VALUES \((\d+),'((?:[^']|\\\\')*)','((?:[^']|\\\\')*)'/", $line, $m)) {
        $settingLine[stripslashes($m[3])] = $i;
        $maxId = max($maxId, (int) $m[1]);
        $lastSettingLine = $i;
    }
}

$appended = [];
foreach (SETTING_KEYS as $key) {
    $stmt = $pdo->prepare('SELECT `group`, `value`, `type`, `name`, `tip`, `options`, `sort_order` FROM `' . $prefix . 'settings` WHERE `key` = ?');
    $stmt->execute([$key]);
    $ref = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ref) {
        fwrite(STDERR, "参照站没有设置项 {$key}，已跳过\n");
        continue;
    }

    $value = (string) $ref['value'];
    if ($key === 'home_blox_data' || $key === 'home_blox_published') {
        // 新装站的首页文档（含轮播子项）完全跟随参照站；老站升级是另一条链路，
        // 由迁移 20260825 的出厂指纹负责，那份指纹是历史事实、不随种子变动。
        $value = normalizeHomeDocument($value);
    }

    if (isset($settingLine[$key])) {
        // 已有行：只换 value，保留种子里的 id / 分组 / 说明文案
        $i = $settingLine[$key];
        $line = $lines[$i];
        $pattern = "/(VALUES \(\d+,'(?:[^']|\\\\')*','" . preg_quote(addcslashes($key, "'"), '/') . "',)'(?:[^']|\\\\')*'/";
        $replaced = preg_replace($pattern, '$1' . str_replace('$', '\\$', mysqlLiteral($value)), $line, 1);
        if ($replaced === null) {
            fwrite(STDERR, "替换 {$key} 失败（PCRE: " . preg_last_error_msg() . "）\n");
            exit(1);
        }
        if ($replaced !== $line) {
            $lines[$i] = $replaced;
            $changes[] = "settings.{$key}（改值，" . strlen($value) . ' 字节）';
        }
        continue;
    }

    $maxId++;
    $appended[] = 'INSERT INTO `yikai_settings` (`id`, `group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) VALUES ('
        . $maxId . ','
        . mysqlLiteral((string) $ref['group']) . ','
        . mysqlLiteral($key) . ','
        . mysqlLiteral($value) . ','
        . mysqlLiteral((string) $ref['type']) . ','
        . mysqlLiteral((string) $ref['name']) . ','
        . mysqlLiteral((string) $ref['tip']) . ','
        . ($ref['options'] === null ? 'NULL' : mysqlLiteral((string) $ref['options'])) . ','
        . (int) $ref['sort_order'] . ');';
    $changes[] = "settings.{$key}（新增）";
}
if ($appended && $lastSettingLine !== null) {
    array_splice($lines, $lastSettingLine + 1, 0, $appended);
}

// ── 输出 ─────────────────────────────────────────────────────────────────────
if (!$changes) {
    echo "种子已与参照站一致，无需改动。\n";
    exit(0);
}

echo "将要同步：\n";
foreach ($changes as $c) {
    echo '  · ' . $c . "\n";
}
if ($dryRun) {
    echo "\n--dry-run：未改动文件。\n";
    exit(0);
}

$text = implode("\n", $lines);
file_put_contents($sqlPath, $crlf ? str_replace("\n", "\r\n", $text) : $text);
echo "\n已写入 install/sql/mysql.sql\n";

$php = PHP_BINARY;
$cmd = escapeshellarg($php) . ' ' . escapeshellarg($repoRoot . '/tools/mysql_to_sqlite.php')
    . ' ' . escapeshellarg($sqlPath) . ' ' . escapeshellarg($repoRoot . '/install/sql/sqlite.sql');
exec($cmd, $out, $exit);
if ($exit !== 0) {
    fwrite(STDERR, "sqlite.sql 生成失败：\n" . implode("\n", $out) . "\n");
    exit(1);
}
echo "已重新生成 install/sql/sqlite.sql\n";
