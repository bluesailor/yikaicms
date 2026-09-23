<?php
/**
 * 发行代码里不能出现的东西：客户站点名、内部开发代理名、基础设施供应商、开发机路径。
 *
 * 代码注释会随发行包交给每个客户（tests/、.github/ 则进公开仓库）。2026-09-23 清理时
 * 在注释里找到了客户域名（事故复盘写成「某某站白天升级把 PHP-FPM 拖死」）、
 * 「某代理审计 P0-1」这类内部来源、更新服务器所在主机商，以及随 deploy/ 一起发出去的
 * 内部交接文档。复盘照样写，只是不点名：「曾有站点」「外部审计 P0-1」。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShippedSourceHygieneTest extends TestCase
{
    /** 点名客户或内部来源——发行代码与公开仓库都不允许 */
    private const NAMED = [
        '/cile\.cn|cile_shopex|xcidcn|kksky/i' => '客户站点名',
        '/codex\s*审计|GLM\s*整改|claude\s*审计/i' => '内部开发代理名',
    ];

    /** 只约束发行包：基础设施与开发机信息 */
    private const SHIPPED_ONLY = [
        '/SiteGround/i' => '更新服务器所在主机商',
        '~phpstudy_pro|/mnt/d/|[A-Z]:\\\\phpstudy~i' => '开发机本地路径',
    ];

    private const SHIPPED_DIRS = ['admin', 'api', 'assets/js', 'config', 'deploy', 'includes', 'install', 'member',
        'migrations', 'overrides', 'plugins', 'themes', 'views'];

    /** build.sh 已排除、不进发行包的内部记录 */
    private const NOT_SHIPPED = ['plugins/dologin/VERIFICATION.md'];

    /** @return list<string> */
    private static function files(array $dirs, bool $withRoot): array
    {
        $out = [];
        if ($withRoot) {
            foreach (glob(ROOT_PATH . '/*.{php,md,txt}', GLOB_BRACE) ?: [] as $f) {
                if (!in_array(basename($f), ['AGENTS.md', 'CLAUDE.md'], true)) $out[] = basename($f);
            }
        }
        foreach ($dirs as $dir) {
            if (!is_dir(ROOT_PATH . '/' . $dir)) continue;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT_PATH . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(ROOT_PATH) + 1));
                if (!$f->isFile() || !preg_match('/\.(php|js|cjs|md|conf|txt|json|yml|sh|sql|htaccess)$/', $rel)
                    || str_ends_with($rel, '.min.js') || in_array($rel, self::NOT_SHIPPED, true)) continue;
                $out[] = $rel;
            }
        }
        return $out;
    }

    /** @param array<string,string> $rules @param list<string> $files @return list<string> */
    private static function offenders(array $rules, array $files): array
    {
        $hits = [];
        foreach ($files as $rel) {
            if ($rel === 'tests/Unit/ShippedSourceHygieneTest.php') continue;
            $text = (string) @file_get_contents(ROOT_PATH . '/' . $rel);
            foreach ($rules as $pattern => $what) {
                if (preg_match($pattern, $text, $m) === 1) $hits[] = "{$rel}（{$what}：{$m[0]}）";
            }
        }
        return $hits;
    }

    public function testShippedCodeNamesNoCustomerAgentHostOrLocalPath(): void
    {
        $files = self::files(self::SHIPPED_DIRS, true);
        self::assertGreaterThan(500, count($files), '扫描范围异常');
        self::assertSame([], self::offenders(self::NAMED + self::SHIPPED_ONLY, $files));
    }

    public function testPublicRepoTestsAndCiNameNoCustomerOrAgent(): void
    {
        self::assertSame([], self::offenders(self::NAMED, self::files(['tests', '.github'], false)));
    }
}
