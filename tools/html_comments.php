<?php
/**
 * 模板里的 HTML 注释（<!-- … -->）会原样发给每个访客，「查看源代码」即可看到——
 * 开发说明、页面结构、中文备注都不该出现在那里。本工具把它们改写成 PHP 注释
 * （<?php /* … *\/ ?>）：维护者在源码里照样看得到，浏览器一个字节也收不到。
 *
 *   php tools/html_comments.php            列出仍会输出的 HTML 注释（有则退出码 1）
 *   php tools/html_comments.php --fix      原地改写
 *
 * 只动真正处于 HTML 位置的注释（T_INLINE_HTML）：PHP 字符串里拼的、<script>/<style>/
 * <pre>/<textarea> 里的、条件注释 <!--[if …]>、跨越 PHP 代码块的注释一律不碰。
 * marketplace/ 下的市场主题有独立的版本与签名纪律，不在扫描范围内。
 */

declare(strict_types=1);

final class HtmlCommentScanner
{
    /** 扫描范围：发行包里会渲染 HTML 的源码目录与根目录入口 */
    public const ROOTS = ['admin', 'includes', 'themes', 'plugins', 'member', 'views', 'install', 'api'];

    /**
     * @return array{source:string, found:list<array{line:int,text:string}>, skipped:list<array{line:int,reason:string}>}
     */
    public static function process(string $source): array
    {
        $tokens = token_get_all($source);
        $out = '';
        $found = [];
        $skipped = [];
        $raw = '';      // 当前处于哪个原样文本元素里（script/style/pre/textarea），跨 token 保持
        $line = 1;

        foreach ($tokens as $token) {
            if (!is_array($token)) {
                $out .= $token;
                $line += substr_count($token, "\n");
                continue;
            }
            [$id, $text] = $token;
            if ($id !== T_INLINE_HTML) {
                $out .= $text;
                $line += substr_count($text, "\n");
                continue;
            }
            $out .= self::rewriteSegment($text, $line, $raw, $found, $skipped);
            $line += substr_count($text, "\n");
        }

        return ['source' => $out, 'found' => $found, 'skipped' => $skipped];
    }

    /**
     * @param list<array{line:int,text:string}> $found
     * @param list<array{line:int,reason:string}> $skipped
     */
    private static function rewriteSegment(string $html, int $line, string &$raw, array &$found, array &$skipped): string
    {
        $result = '';
        $pos = 0;
        $len = strlen($html);
        while ($pos < $len) {
            if ($raw !== '') {
                $close = stripos($html, '</' . $raw, $pos);
                if ($close === false) {
                    $result .= substr($html, $pos);
                    return $result;
                }
                $result .= substr($html, $pos, $close - $pos);
                $pos = $close;
                $raw = '';
                continue;
            }
            $comment = strpos($html, '<!--', $pos);
            $open = preg_match('~<(script|style|pre|textarea)\b~i', $html, $m, PREG_OFFSET_CAPTURE, $pos) === 1 ? $m[0][1] : false;
            if ($open !== false && ($comment === false || $open < $comment)) {
                $tagEnd = strpos($html, '>', $open);
                $stop = $tagEnd === false ? $len : $tagEnd + 1;
                $result .= substr($html, $pos, $stop - $pos);
                $pos = $stop;
                $raw = strtolower($m[1][0]);
                continue;
            }
            if ($comment === false) {
                $result .= substr($html, $pos);
                return $result;
            }
            $result .= substr($html, $pos, $comment - $pos);
            $at = $line + substr_count($html, "\n", 0, $comment);
            $end = strpos($html, '-->', $comment + 4);
            if ($end === false) {
                $skipped[] = ['line' => $at, 'reason' => '注释跨越 PHP 代码块'];
                $result .= substr($html, $comment);
                return $result;
            }
            $full = substr($html, $comment, $end + 3 - $comment);
            if (preg_match('~^<!--\s*\[(if|endif)~i', $full) === 1 || str_starts_with($full, '<!--<![endif]')) {
                $skipped[] = ['line' => $at, 'reason' => '条件注释'];
                $result .= $full;
                $pos = $end + 3;
                continue;
            }
            $body = trim(substr($full, 4, -3));
            $found[] = ['line' => $at, 'text' => $body];
            $result .= '<?php /* ' . str_replace('*/', '* /', $body) . ' */ ?>';
            $pos = $end + 3;
            // PHP 的结束标记会吞掉紧跟的一个换行。注释两侧原本若只靠这个换行隔开（前面紧贴内容、
            // 后面下一行顶格），吞掉后两段内容会粘连——inline 元素之间的空隙就没了。补回去。
            if ($pos < $len && $html[$pos] === "\n") {
                // 片段开头的注释紧跟在 PHP 代码块之后，前面输出了什么未知，按「非空白」保守处理
                $before = $comment > 0 ? $html[$comment - 1] : 'x';
                $after = $pos + 1 < $len ? $html[$pos + 1] : "\n";
                if (!ctype_space($before) && !ctype_space($after)) {
                    $result .= "\n";
                }
            }
        }
        return $result;
    }

    /** @return list<string> 相对仓库根的 PHP 文件 */
    public static function files(string $root): array
    {
        $files = [];
        foreach (glob($root . '/*.php') ?: [] as $f) {
            $files[] = basename($f);
        }
        foreach (self::ROOTS as $dir) {
            if (!is_dir($root . '/' . $dir)) continue;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
                }
            }
        }
        sort($files);
        return $files;
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $root = dirname(__DIR__);
    $fix = in_array('--fix', $argv, true);
    $total = 0;
    $changed = 0;
    foreach (HtmlCommentScanner::files($root) as $rel) {
        $source = (string) file_get_contents($root . '/' . $rel);
        if (!str_contains($source, '<!--')) continue;
        $r = HtmlCommentScanner::process($source);
        foreach ($r['skipped'] as $s) {
            fwrite(STDERR, "  · 跳过 {$rel}:{$s['line']}（{$s['reason']}）\n");
        }
        if ($r['found'] === []) continue;
        $total += count($r['found']);
        $changed++;
        if ($fix) {
            file_put_contents($root . '/' . $rel, $r['source']);
        } else {
            foreach ($r['found'] as $c) {
                echo "{$rel}:{$c['line']}  <!-- " . mb_strimwidth($c['text'], 0, 60, '…') . " -->\n";
            }
        }
    }
    if ($fix) {
        echo "已改写 {$total} 处，涉及 {$changed} 个文件\n";
        exit(0);
    }
    echo $total === 0 ? "✓ 模板里没有会输出到页面的 HTML 注释\n" : "✗ {$total} 处 HTML 注释会原样输出到页面（{$changed} 个文件），运行 --fix 改写\n";
    exit($total === 0 ? 0 : 1);
}
