<?php
/**
 * Yikai CMS - CLI 框架
 *
 * 命令注册 / 分发 / 输出辅助。设计原则：
 *   - 零依赖：不引入 symfony/console，保持 "shared hosting friendly"
 *   - 命令文件即模块：includes/commands/{group}.php 一个文件可注册多个 group:* 命令
 *   - 输出带颜色：TTY 检测后才上色，重定向到文件不会带 ANSI
 *
 * 一个命令的注册示例（在 includes/commands/foo.php 里）：
 *
 *   CLI::register('foo:bar', '描述', function (array $args, array $opts) {
 *       CLI::ok("done");
 *       return 0;  // exit code; 0 = 成功
 *   }, [
 *       'usage' => 'foo:bar <name> [--flag]',
 *   ]);
 */
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

class CLI
{
    /** @var array<string, array{desc:string, run:callable, usage:string}> */
    private static array $commands = [];

    private static bool $useColor = true;

    public static function register(string $name, string $description, callable $handler, array $meta = []): void
    {
        self::$commands[$name] = [
            'desc'  => $description,
            'run'   => $handler,
            'usage' => $meta['usage'] ?? $name,
        ];
    }

    /**
     * 主调度器。返回 exit code。
     * @param array $argv $_SERVER['argv']
     */
    public static function dispatch(array $argv): int
    {
        // TTY 检测：非 TTY（如管道重定向）不上色
        self::$useColor = function_exists('stream_isatty') && @stream_isatty(STDOUT);

        array_shift($argv); // 移除脚本路径

        $cmd = $argv[0] ?? '';
        if ($cmd === '' || in_array($cmd, ['help', '--help', '-h'], true)) {
            self::printHelp($argv[1] ?? null);
            return 0;
        }
        if (in_array($cmd, ['version', '--version', '-V'], true)) {
            $v = defined('CMS_VERSION') ? CMS_VERSION : 'unknown';
            self::out("Yikai CMS v{$v}");
            return 0;
        }

        if (!isset(self::$commands[$cmd])) {
            self::err("未知命令：{$cmd}");
            self::out("");
            self::printHelp();
            return 1;
        }

        // 解析剩余 args：分离 --options（前缀 --）和位置参数
        array_shift($argv);
        $opts = [];
        $args = [];
        foreach ($argv as $a) {
            if (str_starts_with($a, '--')) {
                $kv = substr($a, 2);
                if (str_contains($kv, '=')) {
                    [$k, $v] = explode('=', $kv, 2);
                    $opts[$k] = $v;
                } else {
                    $opts[$kv] = true;
                }
            } else {
                $args[] = $a;
            }
        }

        try {
            $code = (int)(call_user_func(self::$commands[$cmd]['run'], $args, $opts) ?? 0);
            return $code;
        } catch (\Throwable $e) {
            self::err("命令异常：" . $e->getMessage());
            if (!empty($opts['debug'])) {
                self::out($e->getTraceAsString());
            }
            return 2;
        }
    }

    private static function printHelp(?string $only = null): void
    {
        $v = defined('CMS_VERSION') ? CMS_VERSION : 'dev';
        self::out("Yikai CMS CLI v{$v}");
        self::out("");
        self::out("Usage: bin/yikai <command> [options] [args]");
        self::out("");

        if ($only !== null && isset(self::$commands[$only])) {
            $c = self::$commands[$only];
            self::out("  {$only}");
            self::out("    " . $c['desc']);
            self::out("    Usage: " . $c['usage']);
            return;
        }

        // 按命令分组（冒号前缀）
        ksort(self::$commands);
        $groups = [];
        foreach (self::$commands as $name => $info) {
            $g = str_contains($name, ':') ? substr($name, 0, strpos($name, ':')) : 'misc';
            $groups[$g][$name] = $info;
        }
        ksort($groups);

        self::out("Commands:");
        foreach ($groups as $g => $cmds) {
            foreach ($cmds as $name => $c) {
                self::out(sprintf("  %-26s %s", $name, $c['desc']));
            }
        }
        self::out("");
        self::out("Helpers:");
        self::out("  help [command]             显示帮助（或单个命令的详细用法）");
        self::out("  version                    显示 CMS 版本");
        self::out("");
        self::out("Global options:");
        self::out("  --debug                    出错时打印堆栈");
    }

    // ─── 输出辅助 ──────────────────────────────────────────
    public static function out(string $msg): void
    {
        echo $msg . "\n";
    }

    public static function ok(string $msg): void
    {
        echo self::color('✓ ', '32') . $msg . "\n";
    }

    public static function err(string $msg): void
    {
        fwrite(STDERR, self::color('✗ ', '31') . $msg . "\n");
    }

    public static function info(string $msg): void
    {
        echo self::color('ℹ ', '36') . $msg . "\n";
    }

    public static function warn(string $msg): void
    {
        fwrite(STDERR, self::color('⚠ ', '33') . $msg . "\n");
    }

    public static function prompt(string $question, ?string $default = null): string
    {
        $hint = $default !== null ? " [{$default}]" : '';
        echo $question . $hint . ' ';
        $line = fgets(STDIN);
        if ($line === false) return $default ?? '';
        $line = trim($line);
        return $line === '' ? ($default ?? '') : $line;
    }

    public static function confirm(string $question, bool $default = false): bool
    {
        $hint = $default ? 'Y/n' : 'y/N';
        $ans = self::prompt("{$question} [{$hint}]");
        $ans = strtolower($ans);
        if ($ans === '') return $default;
        return in_array($ans, ['y', 'yes', '1', 'true'], true);
    }

    /**
     * 在 TTY 下显示密码输入（无回显）。回退到普通 fgets。
     */
    public static function promptHidden(string $question): string
    {
        echo $question . ' ';
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
            // stty -echo（仅 unix-like）
            @shell_exec('stty -echo 2>/dev/null');
            $line = fgets(STDIN);
            @shell_exec('stty echo 2>/dev/null');
            echo "\n";
            return $line === false ? '' : trim($line);
        }
        $line = fgets(STDIN);
        return $line === false ? '' : trim($line);
    }

    private static function color(string $text, string $code): string
    {
        if (!self::$useColor) return $text;
        return "\033[{$code}m{$text}\033[0m";
    }
}
