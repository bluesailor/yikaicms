<?php
/**
 * SQL 可移植性静态检查：抓那些「SQLite 通过、MySQL 报错」的写法。
 *
 * 用法：php tools/check_sql_portability.php
 * 退出码：0 干净 / 1 有命中 / 2 用法错误
 *
 * ── 为什么需要它 ──
 * 单元测试与 CI 全部跑在 SQLite 上（workflow 里只装了 pdo_sqlite），而客户站几乎都是
 * MySQL。两者对**字符串字面量里的反斜杠**处理相反：
 *   SQLite：反斜杠就是普通字符，'\' 是合法的单字符字符串；
 *   MySQL ：反斜杠是转义符，'\' 里的 \' 被当成转义引号 → 字符串不闭合 → 1064。
 * 于是这类 bug 在测试里全绿、一上线就 500，而且测试再多也照不到。
 *
 * 2026-08-10 实例：SettingModel 里的
 *     "... WHERE `key` LIKE ? ESCAPE '\\'"
 * PHP 双引号串解析后只剩一个反斜杠，SQL 收到 ESCAPE '\' —— 后果是
 * **切换站点默认语言必 500**，随 1.17.x 发出去了。
 *
 * 规则刻意只收「确凿会炸」的模式，不做启发式猜测：误报会让人把整个工具关掉。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

$ROOT = dirname(__DIR__);
$ROOT_N = str_replace('\\', '/', $ROOT);

$rules = [
    [
        'name' => "ESCAPE '\\' —— MySQL 下字符串不闭合",
        // 匹配 SQL 文本里的 ESCAPE '\'（PHP 源码中表现为一个或两个反斜杠）
        'regex' => "/ESCAPE\\s*'\\\\{1,2}'/",
        'fix'   => "改用不参与转义的字符，如 ESCAPE '!'，并把 pattern 里的 ! _ % 一并转义",
    ],
    [
        'name' => 'LIKE 拼接了未转义的 _ 或 %（通配符会误伤）',
        // 只报「LIKE '...%$var...'」这种把变量直接插进 LIKE 字面量的写法
        'regex' => '/LIKE\\s+[\'"]%?\\{?\\$[A-Za-z_]/',
        'fix'   => '改用占位符 ? 传参，并对 _ % 做转义后再拼 %',
    ],
];

$dirs = ['includes', 'admin', 'migrations', 'api', 'bin'];
$files = [];
foreach ($dirs as $d) {
    if (!is_dir("$ROOT/$d")) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$ROOT/$d", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() === 'php') $files[] = $f->getPathname();
    }
}
foreach (glob("$ROOT/*.php") as $f) {
    $files[] = $f;
}
sort($files);

$hits = [];
foreach ($files as $path) {
    $rel = str_replace($ROOT_N . '/', '', str_replace('\\', '/', $path));
    if (str_starts_with($rel, 'tools/')) continue;          // 本工具自身的说明文字里就有反面例子
    foreach (file($path) as $i => $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '#')) {
            continue;                                        // 注释里的反面例子不算
        }
        foreach ($rules as $r) {
            if (preg_match($r['regex'], $line)) {
                $hits[] = ['file' => $rel, 'line' => $i + 1, 'rule' => $r['name'],
                           'fix' => $r['fix'], 'code' => trim($line)];
            }
        }
    }
}

echo '扫描 ', count($files), " 个 PHP 文件\n";
if ($hits === []) {
    echo "✓ 未发现 MySQL/SQLite 不兼容的 SQL 写法。\n";
    exit(0);
}

echo "\n✗ 发现 ", count($hits), " 处（SQLite 会通过、MySQL 会报错）：\n";
foreach ($hits as $h) {
    printf("\n  %s:%d\n    规则：%s\n    代码：%s\n    改法：%s\n",
        $h['file'], $h['line'], $h['rule'], mb_substr($h['code'], 0, 110), $h['fix']);
}
echo "\n注意：CI 只跑 SQLite，这类问题测试照不到，必须靠本检查拦住。\n";
exit(1);
