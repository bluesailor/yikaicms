<?php
/**
 * 硬编码货币符号门禁（CLI）。
 *
 * 背景：产品价格的货币符号应随站点语言（formatPrice() 读 lang 键
 * currency_symbol / currency_decimals）。3083bb6 换掉了主题里的 14 处硬编码
 * &yen;，却漏了后台产品列表、detail.php、product-carousel 插件三处——
 * 客户站（英文站）后台价格列一直显示 ¥1,539.00 才被发现。
 *
 * 为什么 i18n 渲染态扫描器抓不到它：那个扫描器的判据是 CJK 统一表意文字段
 * U+4E00–U+9FFF，而 ¥ 是 U+00A5（Latin-1 补充区）、$ 是 ASCII——
 * **原理上就不在它的视野里**。所以这里单独立一道静态门禁。
 *
 * 用法：
 *   php tools/check_currency_symbols.php          # 违规则退出码 1
 *
 * 判定：在 PHP 渲染代码里，货币符号紧邻价格输出（number_format / price 变量）
 * 即视为硬编码。示例文案、语言包、注释不算。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$ROOT = dirname(__DIR__);

// 扫描范围：会渲染价格的产品代码。tests/tools/vendor 与语言包不在内。
$dirs = ['admin', 'includes', 'themes', 'plugins', 'controllers', 'views'];
$rootFiles = ['index.php', 'list.php', 'detail.php', 'product.php', 'article.php', 'news.php', 'page.php', 'search.php'];

/** 货币符号：实体与字面量都要认 */
$symbols = ['&yen;', '&#165;', '&#xA5;', '¥', '￥', '&dollar;', '＄'];

/**
 * 白名单：这些位置的符号是「示例内容」而非渲染逻辑——用户插入后自行编辑。
 * 收窄到具体文件，避免整目录豁免掩盖真问题。
 */
$allowFiles = [
    'includes/builder/presets.php',   // 价格表预设的 ¥99/¥299/¥999 示例文案
];

$files = [];
foreach ($dirs as $d) {
    $abs = $ROOT . '/' . $d;
    if (!is_dir($abs)) {
        continue;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}
foreach ($rootFiles as $f) {
    if (is_file($ROOT . '/' . $f)) {
        $files[] = $ROOT . '/' . $f;
    }
}

$violations = [];
foreach ($files as $file) {
    $rel = str_replace('\\', '/', substr($file, strlen($ROOT) + 1));
    if (in_array($rel, $allowFiles, true)) {
        continue;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($lines as $i => $line) {
        $trimmed = ltrim($line);
        // 注释行不算
        if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $hit = null;
        foreach ($symbols as $sym) {
            if (str_contains($line, $sym)) {
                $hit = $sym;
                break;
            }
        }
        if ($hit === null) {
            continue;
        }
        // 只有「紧邻价格输出」才判违规：光有符号可能是别的用途（如日元文案说明）
        if (!preg_match('/number_format|\bprice\b|\$p\[|market_price/i', $line)) {
            continue;
        }
        $violations[] = [$rel, $i + 1, $hit, trim($line)];
    }
}

if ($violations === []) {
    echo "✓ 未发现硬编码货币符号（价格一律走 formatPrice()）\n";
    exit(0);
}

echo "✗ 发现 " . count($violations) . " 处硬编码货币符号——价格渲染必须走 formatPrice()：\n\n";
foreach ($violations as [$rel, $line, $sym, $src]) {
    echo "  {$rel}:{$line}  [{$sym}]\n";
    echo '    ' . mb_substr($src, 0, 110) . "\n";
}
echo "\n修法：<?php echo formatPrice(\$价格); ?>（符号与小数位随站点语言）。\n";
echo "确属示例文案而非渲染逻辑，把文件加进本工具的 \$allowFiles 并说明理由。\n";
exit(1);
