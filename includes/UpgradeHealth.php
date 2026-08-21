<?php
/**
 * 升级后健康自检：关键入口文件语法可解析 + version.php 可读出版本号。
 *
 * 共享主机不能保证 exec/php -l，用 token_get_all(TOKEN_PARSE) 做纯 PHP 语法检查——
 * 升级中断形成「旧代码 + 新代码混合状态」时，最常见的表现就是某个核心文件解析不了
 * 或 version.php 缺失。upgrade_online.php 在 finalize/rollback 后调用，
 * 自检失败即提示一键回滚。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit('Access Denied');

final class UpgradeHealth
{
    /** 语法自检覆盖的关键文件（相对站点根） */
    public const CORE_FILES = [
        'index.php',
        'includes/functions.php',
        'includes/init.php',
        'admin/index.php',
        'config/version.php',
    ];

    /**
     * @param string $rootPath 站点根（测试可指向临时目录）
     * @param list<string>|null $files 覆盖默认关键文件清单（测试用）
     * @return array{ok:bool,version:string,checks:list<array{file:string,ok:bool}>}
     */
    public static function check(string $rootPath, ?array $files = null): array
    {
        $checks = [];
        $allOk = true;
        foreach ($files ?? self::CORE_FILES as $rel) {
            $ok = false;
            $p = rtrim($rootPath, '/') . '/' . $rel;
            if (is_file($p)) {
                try {
                    /** @psalm-suppress UnusedFunctionCall 只取 TOKEN_PARSE 的 ParseError 副作用做语法检查 */
                    token_get_all((string) file_get_contents($p), TOKEN_PARSE);
                    $ok = true;
                } catch (Throwable) {
                    $ok = false;
                }
            }
            $checks[] = ['file' => $rel, 'ok' => $ok];
            $allOk = $allOk && $ok;
        }

        $version = '';
        $vf = @file_get_contents(rtrim($rootPath, '/') . '/config/version.php');
        if ($vf !== false && preg_match("/CMS_VERSION'\\s*,\\s*'([^']+)'/", $vf, $m)) {
            $version = $m[1];
        }

        return ['ok' => $allOk && $version !== '', 'version' => $version, 'checks' => $checks];
    }
}
