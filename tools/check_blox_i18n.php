<?php
/**
 * Blox 三语化门禁：防止已清零的硬编码中文回潮。
 *
 * 规则（范围：admin/blox_*.php + includes/builder/**.php + BloxTemplateModel）：
 *   1. 禁止 \uXXXX 形式的 CJK 转义（历史上 120 处，2026-08 清零）。
 *   2. PHP 字符串 token 中禁止 CJK（token_get_all 解析，注释天然豁免；
 *      纯 CJK 标点如 implode('、') 的分隔符豁免；lang key 本身无 CJK）。
 *   3. 内联 HTML/JS 中禁止 CJK（先剥离 <!-- --> 与 /* *​/ 与行级 // 注释）。
 *
 * 白名单：存量数据兼容哨兵（如「首页区块」旧文档标签比较），见 $sentinels。
 * CI 干净 checkout 缺付费源码文件时自动跳过对应文件（guard 同款语义：跳过≠失败）。
 * 用法：php tools/check_blox_i18n.php [--allow-element-labels]   退出码 0=通过 1=违规
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$scope = array_merge(
    glob($root . '/admin/blox_*.php') ?: [],
    glob($root . '/includes/builder/*.php') ?: [],
    glob($root . '/includes/builder/elements/*.php') ?: [],
    [$root . '/includes/models/BloxTemplateModel.php']
);

/** 存量数据兼容哨兵：比较旧文档里已存储的标签，不是 UI 文案，本地化会破坏旧数据识别。 */
$sentinels = ['首页区块'];

/**
 * 元素/Schema 标签层的过渡豁免（--allow-element-labels）：
 * label()/controls()/预设名等存量中文属「元素层 i18n」专项，清零后从此列表删除对应文件收紧。
 * 注意用 CLI 参数而非环境变量——本仓库常在 WSL 下调 Windows php.exe，env 不透传。
 */
$elementFileGrace = in_array('--allow-element-labels', $argv ?? [], true);
$graceFiles = [
    '/includes/builder/elements/',
    '/includes/builder/AbstractElement.php',
    '/includes/builder/presets.php',
    '/includes/builder/BlocksLibrary.php',
    '/includes/builder/DynamicListItemSchema.php',
    '/includes/builder/HomeBloxBlockSchema.php',
    '/includes/builder/HomeBloxRenderContext.php',
    '/includes/builder/HomeBloxDocument.php',
    '/includes/builder/HomeLayoutDocument.php',
    '/includes/builder/BloxDocumentValidator.php',
    '/includes/builder/DynamicLoopTemplateRenderer.php',
];

$cjk = '/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}]/u';
$cjkPunctOnly = '/^[\x{3000}-\x{303F}\x{FF00}-\x{FFEF}\s]+$/u'; // 、。「」（）等纯标点

$violations = [];
$checked = 0;

foreach ($scope as $file) {
    if (!is_file($file)) {
        continue; // CI 干净 checkout 缺付费源码：跳过，不失败
    }
    $checked++;
    $rel = str_replace($root . '/', '', $file);
    $src = (string) file_get_contents($file);

    // 规则 1：\uXXXX CJK 转义
    if (preg_match_all('/\\\\u(4[eE][0-9a-fA-F]{2}|[5-9][0-9a-fA-F]{3})/', $src, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as [$hit, $off]) {
            $line = substr_count(substr($src, 0, $off), "\n") + 1;
            $violations[] = "$rel:$line \\uXXXX 转义「{$hit}」——请改用 lang key（\$jt/ctxText/uiText 模式）";
        }
    }

    $isElementFile = false;
    foreach ($graceFiles as $graceMark) {
        if (str_contains(str_replace('\\', '/', $file), $graceMark)) {
            $isElementFile = true;
            break;
        }
    }

    // adminLog() 运营日志按 CMS 惯例中文书写，不属 UI 文案：豁免其调用行±6 行内的字符串。
    $adminLogLines = [];
    foreach (explode("\n", $src) as $i => $srcLine) {
        if (str_contains($srcLine, 'adminLog(')) {
            for ($d = 0; $d <= 6; $d++) {
                $adminLogLines[$i + 1 + $d] = true;
            }
        }
    }

    foreach (token_get_all($src) as $token) {
        if (!is_array($token)) {
            continue;
        }
        [$id, $text, $line] = $token;

        // 规则 2：PHP 字符串
        if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
            $inner = trim($text, "'\"");
            if (preg_match($cjk, $inner) !== 1) {
                continue;
            }
            if (isset($adminLogLines[$line])) {
                continue; // adminLog 运营日志
            }
            if (preg_match($cjkPunctOnly, $inner) === 1) {
                continue; // 纯 CJK 标点（分隔符）
            }
            if (in_array($inner, $sentinels, true)) {
                continue;
            }
            if ($isElementFile && $elementFileGrace) {
                continue; // 元素/Schema 标签存量：过渡期豁免（--allow-element-labels）
            }
            $violations[] = "$rel:$line PHP 字符串含中文「" . mb_substr($inner, 0, 24) . "」——请走 __() + 三语 lang key";
        }

        // 规则 3：内联 HTML/JS（剥注释后扫）
        if ($id === T_INLINE_HTML) {
            $html = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;
            $html = preg_replace('#/\*.*?\*/#s', '', $html) ?? $html;
            $offsetLine = $line;
            foreach (explode("\n", $html) as $i => $htmlLine) {
                $stripped = preg_replace('#(^|\s)//.*$#', '', $htmlLine) ?? $htmlLine;
                if (preg_match($cjk, $stripped) !== 1) {
                    continue;
                }
                $skip = false;
                foreach ($sentinels as $sentinel) {
                    if (str_contains($stripped, $sentinel)) {
                        $skip = true;
                        break;
                    }
                }
                if ($skip) {
                    continue;
                }
                $violations[] = "$rel:" . ($offsetLine + $i) . ' 内联 HTML/JS 含中文「'
                    . mb_substr(trim($stripped), 0, 40) . "」——请走 <?= __() ?> / \$jt()";
            }
        }
    }
}

echo "Blox i18n 门禁：扫描 {$checked} 个文件\n";
if ($violations !== []) {
    echo "✗ 发现 " . count($violations) . " 处硬编码中文：\n";
    foreach (array_slice($violations, 0, 50) as $v) {
        echo "  - $v\n";
    }
    if (count($violations) > 50) {
        echo '  …及另外 ' . (count($violations) - 50) . " 处\n";
    }
    exit(1);
}
echo "✓ 无硬编码中文回潮。\n";
