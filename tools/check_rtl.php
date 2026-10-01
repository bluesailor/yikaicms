<?php
/**
 * RTL（阿拉伯语等从右到左语言）体检：统计访客可见界面里的「物理方向」写法。
 *
 * 从右到左页面里，ml-4 / padding-left / text-align:left 这些写死左右的样式不会自动镜像，
 * 应改成逻辑方向：ms-4 / padding-inline-start / text-align:start（Tailwind v4 自带
 * ms- me- ps- pe- start- end- text-start text-end rounded-s rounded-e border-s border-e）。
 * 确实只能用物理方向的，加 rtl: 变体补一份（如 left-0 rtl:right-0 rtl:left-auto）。
 *
 * 用法：
 *   php tools/check_rtl.php              按文件列出数量（多的在前）与合计
 *   php tools/check_rtl.php --check      与 tools/rtl-baseline.json 比：任何文件数量变多即失败（只许减少）
 *   php tools/check_rtl.php --update     把当前数量写回基线（改造完一批后执行，数量只会下降）
 *   php tools/check_rtl.php --file=PATH  列出某个文件的每一处（行号 + 片段），供逐处改造
 *
 * 不算问题的写法：left-1/2 / right-1/2（配合 translate 居中，左右对称）、inset-x-*、
 * 同一个 class 串里已带对应 rtl: 变体的、行内注释含 rtl-ok 的行。
 */

declare(strict_types=1);

const RTL_ROOT = __DIR__ . '/..';
const RTL_BASELINE = __DIR__ . '/rtl-baseline.json';

/** 访客能看到的界面：前台模板、主题、Blox 元素渲染、前台样式与脚本。后台界面暂不在范围内。 */
function rtl_targets(): array
{
    $files = [];
    $add = static function (string $pattern) use (&$files): void {
        foreach (glob(RTL_ROOT . '/' . $pattern, GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) $files[] = $f;
        }
    };
    $walk = static function (string $dir, array $exts) use (&$files): void {
        if (!is_dir(RTL_ROOT . '/' . $dir)) return;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(RTL_ROOT . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && in_array(strtolower($f->getExtension()), $exts, true)
                && !preg_match('#/(?:node_modules|vendor)/#', str_replace('\\', '/', $f->getPathname()))
                && !str_ends_with($f->getFilename(), '.min.css') && !str_ends_with($f->getFilename(), '.min.js')) {
                $files[] = $f->getPathname();
            }
        }
    };
    $add('*.php');                       // 根目录前台入口（news.php、contact.php…）
    $add('includes/{header,footer,contact_parts,blocks_render}.php');
    $walk('includes/blocks', ['php']);
    $walk('includes/builder/elements', ['php']);
    $add('includes/builder/*.php');      // Blox 渲染核心（样式生成、区块外壳）
    $walk('themes', ['php', 'css', 'js']);
    $walk('marketplace/themes', ['php', 'css', 'js']);
    $walk('views', ['php']);
    $walk('templates', ['php', 'html']);
    $add('assets/css/{style,blox-*}.css');
    $add('assets/js/blox-{carousel,tabs,lightbox,popup,banner,collapse,dot-nav,overlay,org-chart}.js');
    $files = array_values(array_unique(array_map(static fn(string $f): string => str_replace('\\', '/', (string) realpath($f)), $files)));
    sort($files);
    return $files;
}

/** @return list<array{line:int, kind:string, text:string}> */
function rtl_scan(string $content, string $ext): array
{
    $hits = [];
    $variants = '(?:[a-z0-9-]+:)*';
    $classPattern = '/(?<=^|[\s"\'`])' . $variants . '(?<tok>-?(?:m[lr]|p[lr]|scroll-[mp][lr])-[\w.\/\[\]%-]+|-?(?:left|right)-[\w.\/\[\]%-]+|text-(?:left|right)|float-(?:left|right)|clear-(?:left|right)'
        . '|rounded-(?:[lr]|tl|tr|bl|br)(?:-[\w\[\]\/.]+)?|border-[lr](?:-[\w\[\]\/.]+)?|origin-(?:left|right|top-left|top-right|bottom-left|bottom-right))(?=$|[\s"\'`])/m';
    $cssPattern = '/(?<![\w-])(?<tok>(?:margin|padding|border)-(?:left|right)(?:-[a-z]+)?\s*:|(?:left|right)\s*:\s*(?!auto\b)[^;}{]+|text-align\s*:\s*(?:left|right)\b|float\s*:\s*(?:left|right)\b|border-(?:top|bottom)-(?:left|right)-radius\s*:)/';
    $lines = preg_split('/\r?\n/', $content) ?: [];
    foreach ($lines as $i => $line) {
        if (str_contains($line, 'rtl-ok')) continue;
        if ($ext !== 'css' && preg_match_all($classPattern, $line, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m['tok'] as [$tok, $offset]) {
                if (preg_match('/^-?(?:left|right)-1\/2$/', $tok)) continue;               // 居中：左右对称
                // 同一个 class 串（前后最近的引号之间）里已补 rtl: 变体的，算已处理
                $start = max((int) strrpos(substr($line, 0, $offset), '"'), (int) strrpos(substr($line, 0, $offset), "'"));
                $endCandidates = array_filter([strpos($line, '"', $offset), strpos($line, "'", $offset)], 'is_int');
                $end = $endCandidates === [] ? strlen($line) : min($endCandidates);
                if (str_contains(substr($line, $start, $end - $start), 'rtl:')) continue;
                $hits[] = ['line' => $i + 1, 'kind' => 'class', 'text' => $tok];
            }
        }
        // CSS 属性：.css 文件、PHP/JS 里的 style 与样式字符串
        if ($ext === 'css' || preg_match('/style|css|\.style\./i', $line)) {
            if (preg_match_all($cssPattern, $line, $m)) {
                foreach ($m['tok'] as $tok) {
                    if ($ext !== 'css' && preg_match('/^(?:left|right)\s*:/', $tok) && !preg_match('/style\s*=|\.style\.|cssText|<style/i', $line)) continue;
                    $hits[] = ['line' => $i + 1, 'kind' => 'css', 'text' => trim(substr($tok, 0, 40))];
                }
            }
        }
    }
    return $hits;
}

/** @return array<string,int> 相对路径 → 数量 */
function rtl_counts(): array
{
    $out = [];
    foreach (rtl_targets() as $file) {
        $rel = ltrim(substr($file, strlen(str_replace('\\', '/', (string) realpath(RTL_ROOT)))), '/');
        $n = count(rtl_scan((string) file_get_contents($file), strtolower(pathinfo($file, PATHINFO_EXTENSION))));
        if ($n > 0) $out[$rel] = $n;
    }
    arsort($out);
    return $out;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $args = array_slice($argv, 1);
    $fileArg = null;
    foreach ($args as $a) if (str_starts_with($a, '--file=')) $fileArg = substr($a, 7);
    if ($fileArg !== null) {
        $path = RTL_ROOT . '/' . ltrim($fileArg, '/');
        foreach (rtl_scan((string) file_get_contents($path), strtolower(pathinfo($path, PATHINFO_EXTENSION))) as $hit) {
            echo str_pad((string) $hit['line'], 6, ' ', STR_PAD_LEFT) . '  ' . str_pad($hit['kind'], 5) . '  ' . $hit['text'] . "\n";
        }
        exit(0);
    }
    $counts = rtl_counts();
    if (in_array('--update', $args, true)) {
        ksort($counts);
        file_put_contents(RTL_BASELINE, json_encode($counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        echo 'baseline updated: ' . array_sum($counts) . ' in ' . count($counts) . " files\n";
        exit(0);
    }
    if (in_array('--check', $args, true)) {
        $base = is_file(RTL_BASELINE) ? (array) json_decode((string) file_get_contents(RTL_BASELINE), true) : [];
        $worse = [];
        foreach ($counts as $rel => $n) {
            if ($n > (int) ($base[$rel] ?? 0)) $worse[] = "$rel: $n (baseline " . (int) ($base[$rel] ?? 0) . ')';
        }
        if ($worse !== []) {
            echo "✗ RTL：以下文件新增了写死左右方向的样式（请改用 ms-/me-/ps-/pe-/start-/end-/text-start 等逻辑写法，或加 rtl: 变体）：\n  " . implode("\n  ", $worse) . "\n";
            echo "  逐处查看：php tools/check_rtl.php --file=<路径>\n";
            exit(1);
        }
        $total = array_sum($counts);
        $baseTotal = array_sum(array_map('intval', $base));
        echo "✓ RTL：没有新增物理方向写法（当前 {$total}，基线 {$baseTotal}" . ($total < $baseTotal ? '，可执行 --update 收紧基线' : '') . "）\n";
        exit(0);
    }
    foreach ($counts as $rel => $n) echo str_pad((string) $n, 6, ' ', STR_PAD_LEFT) . '  ' . $rel . "\n";
    echo 'total ' . array_sum($counts) . ' in ' . count($counts) . " files\n";
}
