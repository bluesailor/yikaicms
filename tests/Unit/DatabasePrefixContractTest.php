<?php
/**
 * DB_PREFIX 口径合同（2026-09-21 带前缀安装事故防回归）。
 *
 * Database::insert/update/delete/insertBatch 会自己拼 DB_PREFIX，调用方必须传裸表名；
 * 传 DB_PREFIX.'表名' 会双前缀（yikai_yikai_*），在带前缀安装上所有写入直接报表不存在。
 * v1.23/v1.25 的全局类与全局查询即因此在带前缀站上完全不可用，而单测/冒烟站前缀为空、
 * 门禁全绿——只有源码合同能兜住这类"测试环境测不出"的口径错误。
 * data_changed 钩子的监听方（HtmlCache/StaticHtml 的 skip 表、settings 过滤）比较的
 * 也是裸表名，do_action 侧带前缀会让过滤失效。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class DatabasePrefixContractTest extends TestCase
{
    private const FORBIDDEN = [
        'db()->insert(DB_PREFIX',
        'db()->update(DB_PREFIX',
        'db()->delete(DB_PREFIX',
        'db()->insertBatch(DB_PREFIX',
        "do_action('data_changed', DB_PREFIX",
        'do_action("data_changed", DB_PREFIX',
    ];

    private const SCAN_DIRS = ['includes', 'admin', 'api', 'plugins', 'controllers', 'migrations', 'bin'];

    public function testWrapperCallsAndDataChangedHooksUseBareTableNames(): void
    {
        $violations = [];
        $roots = array_map(static fn (string $dir): string => ROOT_PATH . '/' . $dir, self::SCAN_DIRS);
        foreach (glob(ROOT_PATH . '/*.php') ?: [] as $rootFile) {
            $this->scanFile($rootFile, $violations);
        }
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $this->scanFile($file->getPathname(), $violations);
            }
        }
        self::assertSame([], $violations,
            "以下调用把 DB_PREFIX 拼进了会自动加前缀的入口（wrapper 双前缀 / data_changed 监听方比较裸表名）：\n"
            . implode("\n", $violations));
    }

    /** @param list<string> $violations */
    private function scanFile(string $path, array &$violations): void
    {
        $source = file_get_contents($path);
        if ($source === false) {
            return;
        }
        foreach (self::FORBIDDEN as $pattern) {
            if (str_contains($source, $pattern)) {
                $violations[] = str_replace(ROOT_PATH . '/', '', $path) . ' → ' . $pattern;
            }
        }
    }
}
