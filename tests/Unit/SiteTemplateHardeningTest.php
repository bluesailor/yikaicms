<?php
/**
 * 整站导入并入前的加固回归（2026-09-21 评审结论）。
 *
 * 三条都是"实现存在但会在失败路径上伤人"的问题：
 *   1. 导入失败留下 sitepack-* 孤儿目录（开发树实测残留 5 组）；
 *   2. CompatibleLinks 作为 ob_start 回调抛异常 → 输出缓冲内无法处理 → 全站白屏；
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use CompatibleLinks;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SiteTemplateService;

require_once ROOT_PATH . '/includes/CompatibleLinks.php';
require_once ROOT_PATH . '/includes/SiteTemplateService.php';

final class SiteTemplateHardeningTest extends TestCase
{
    /** 失败导入的文件必须被清走，且清理只认本类生成的别名形态。 */
    public function testFailedImportDiscardsOnlyItsOwnDirectories(): void
    {
        $root = sys_get_temp_dir() . '/yk_st_orphan_' . bin2hex(random_bytes(4));
        mkdir($root . '/themes', 0700, true);
        mkdir($root . '/uploads', 0700, true);
        mkdir($root . '/storage', 0700, true);
        $alias = 'sitepack-' . bin2hex(random_bytes(8));
        mkdir($root . '/themes/' . $alias . '/layouts', 0700, true);
        file_put_contents($root . '/themes/' . $alias . '/layouts/header.php', '<?php');
        mkdir($root . '/uploads/' . $alias, 0700, true);
        file_put_contents($root . '/uploads/' . $alias . '/a.png', 'x');
        // 用户自己的目录：名字不符合随机别名形态，绝不能被碰
        mkdir($root . '/themes/my-theme', 0700, true);
        file_put_contents($root . '/themes/my-theme/theme.json', '{}');

        try {
            $discard = new ReflectionMethod(SiteTemplateService::class, 'discardImportedFiles');
            $discard->setAccessible(true);
            $service = new SiteTemplateService($root);

            $discard->invoke($service, $alias);
            self::assertDirectoryDoesNotExist($root . '/themes/' . $alias, '失败导入的主题目录应被清走');
            self::assertDirectoryDoesNotExist($root . '/uploads/' . $alias, '失败导入的媒体目录应被清走');

            // 非随机别名形态一律不处理
            $discard->invoke($service, 'my-theme');
            $discard->invoke($service, '../../etc');
            self::assertDirectoryExists($root . '/themes/my-theme', '用户主题不得被清理逻辑碰到');
        } finally {
            foreach (['/themes/my-theme/theme.json'] as $file) @unlink($root . $file);
            foreach (['/themes/my-theme', '/themes', '/uploads', '/storage', ''] as $dir) @rmdir($root . $dir);
        }
    }

    /**
     * 输出过滤器必须 fail-open：它是 query 模式下每个请求的 ob_start 回调，
     * 在回调内抛异常 PHP 会升级为 fatal，代价是全站白屏而不是单页出错。
     */
    public function testOutputFilterNeverThrows(): void
    {
        $saved = $_SERVER['REQUEST_URI'] ?? null;
        try {
            // 畸形 REQUEST_URI + 无站点配置：内部任何一步出错都不得逸出
            $_SERVER['REQUEST_URI'] = "\x00://[bad";
            $html = '<html><body><a href="/about.html">x</a></body></html>';
            self::assertIsString(CompatibleLinks::output($html));
            self::assertIsString(CompatibleLinks::output(''));
            self::assertIsString(CompatibleLinks::output('not html at all'));
        } finally {
            if ($saved === null) unset($_SERVER['REQUEST_URI']);
            else $_SERVER['REQUEST_URI'] = $saved;
        }
    }

}
