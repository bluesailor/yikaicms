<?php
/**
 * 前台硬编码中文扫描器。
 *
 * 用法：
 *   php tools/check_frontend_i18n.php            # 扫描并报告
 *   php tools/check_frontend_i18n.php --verbose  # 同时列出每处上下文
 *
 * 退出码：0 干净 / 1 有硬编码 / 2 用法错误
 *
 * ── 为什么单独做一个 ──
 * tools/scan_admin_i18n.php 靠「渲染页面再看有没有汉字」，只覆盖 /admin/ 下的页面；
 * 前台从来没有等价的检查，于是这些漏网之鱼只能靠客户在自己的英文站上撞见：
 *   - 首页「关于」标题把站名粘在后面（客户报）
 *   - 登录页语言切换器列出未启用的语言（客户报）
 *   - 产品详情页的「相关产品 / 上一个产品 / 下一个产品 / ✎ 编辑产品」（客户报）
 * 每次都是「中文站看不出来，英文站才炸」。
 *
 * ── 判定方式 ──
 * 用 token_get_all 解析，只看两类真正会输出到页面的 token：
 *   T_INLINE_HTML                  ——  ?> 之外的裸 HTML
 *   T_CONSTANT_ENCAPSED_STRING     ——  引号字符串
 * 注释（T_COMMENT / T_DOC_COMMENT）天然被排除，所以文件头的中文说明不会误报。
 *
 * 白名单只放「汉字不是给访客看的」的情况：语言包本身、写死中文的数据键名对照等。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$ROOT = dirname(__DIR__);
$verbose = in_array('--verbose', array_slice($argv, 1), true);

// 前台会渲染到访客眼前的文件
$targets = [
    'index.php', 'list.php', 'detail.php', 'article.php', 'news.php', 'product.php',
    'page.php', 'search.php', 'contact.php', 'history.php', 'job_detail.php',
    'sitemap.php', 'rss.php', '404.php',
];
$dirs = ['themes', 'includes/blocks', 'includes/layouts', 'includes/pages'];

// 这些文件里的中文不面向访客
$skipFiles = [
    'includes/content_model_presets.php',   // 预置数据自带三语字段，中文是 zh-CN 那一份
    'includes/blocks/timeline.php',         // 见下方逐条核对
];
// 允许出现的中文串（都不是给访客看的文案）
$allowText = [
    'zh-CN', '简体中文', '繁體中文',        // 语言选择器的自称，必须是本语言写法
    '中文', '日本語', 'English',
    // 快手/小红书图标是 SVG <text> 里的单字字形，是品牌标识不是文案，不可翻译
    '快', '红',
];

/** 字符串里是否含汉字 */
function hasCjk(string $s): bool
{
    return (bool) preg_match('/[\x{4e00}-\x{9fff}]/u', $s);
}

$files = [];
foreach ($targets as $t) {
    if (is_file("$ROOT/$t")) $files[] = $t;
}
foreach ($dirs as $d) {
    if (!is_dir("$ROOT/$d")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$ROOT/$d", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $files[] = ltrim(str_replace(str_replace('\\', '/', $ROOT), '', str_replace('\\', '/', $f->getPathname())), '/');
    }
}
sort($files);

$hits = [];
foreach ($files as $rel) {
    if (in_array($rel, $skipFiles, true)) continue;
    $src = (string) @file_get_contents("$ROOT/$rel");
    if ($src === '') continue;

    foreach (token_get_all($src) as $tok) {
        if (!is_array($tok)) continue;
        [$id, $text, $line] = $tok;
        if ($id !== T_INLINE_HTML && $id !== T_CONSTANT_ENCAPSED_STRING) continue;

        // 注释不显示给访客，一律剔掉，否则真问题会被淹掉（首轮 166 处里绝大多数是注释）：
        //   <!-- 版块说明 -->        模板里的分节注释
        //   /* fade 效果下… */       内联 <style> 里的 CSS 注释
        //   // 更新按钮状态          内联 <script> 里的 JS 注释
        // `//` 前面是冒号的不动，否则 https:// 会被吃掉半截。
        $text = (string) preg_replace('/<!--.*?-->/s', '', $text);
        $text = (string) preg_replace('#/\*.*?\*/#s', '', $text);
        $text = (string) preg_replace('#(?<!:)//[^\r\n]*#', '', $text);
        if (!hasCjk($text)) continue;

        // 白名单串整体剔除后再判断——先剔再判，否则「数据+文案同串」会漏
        $probe = str_replace($allowText, '', $text);
        if (!hasCjk($probe)) continue;

        preg_match_all('/[\x{4e00}-\x{9fff}][\x{4e00}-\x{9fff}\x{3000}-\x{303f}]*/u', $probe, $m);
        $frag = implode(' / ', array_slice(array_unique($m[0]), 0, 4));
        $hits[] = ['file' => $rel, 'line' => $line, 'frag' => $frag,
                   'ctx' => trim(preg_replace('/\s+/', ' ', mb_substr($text, 0, 100)))];
    }
}

echo '扫描前台文件 ', count($files), " 个\n";
if ($hits === []) {
    echo "✓ 未发现面向访客的硬编码中文。\n";
    exit(0);
}

$byFile = [];
foreach ($hits as $h) $byFile[$h['file']][] = $h;

echo "\n✗ 发现 ", count($hits), ' 处硬编码中文，涉及 ', count($byFile), " 个文件：\n";
foreach ($byFile as $file => $list) {
    echo "\n  {$file}（", count($list), "）\n";
    foreach ($list as $h) {
        printf("    :%-5d %s\n", $h['line'], $h['frag']);
        if ($verbose) echo '           ', $h['ctx'], "\n";
    }
}
echo "\n改法：HTML 用 <?php echo e(__('key')); ?>；JS 用 json_encode(__('key'), JSON_UNESCAPED_UNICODE)（别用 e()）。\n";
exit(1);
