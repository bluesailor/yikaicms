<?php
/**
 * URL 别名净化合同（2026-09-21 客户站中文 URL 事故防回归）。
 *
 * 事故：客户站出现 /商业保险.html（浏览器显示为 /%E5%95%86%E4%B8%9A%E4%BF%9D%E9%99%A9.html）。
 * 根因是 admin/channel.php 留空别名时 `$data['slug'] = $data['name']`，把中文栏目名
 * 原样当别名；其余编辑页早已走 resolveSlug，唯独栏目管理与另外三个入口漏掉。
 *
 * 教训：净化口径靠"每个入口自觉调用"必然漏——加一层源码合同，任何新写入点
 * 想绕过 normalizeSlugInput/resolveSlug 都会在这里失败。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SlugSanitizationContractTest extends TestCase
{
    /**
     * 会把 slug 落库的后台入口 => 该文件里必须出现的净化调用之一。
     * 新增写 slug 的入口时，一并在此登记（登记即自查：它真的净化了吗）。
     */
    private const SLUG_WRITERS = [
        'admin/channel.php'           => ['normalizeSlugInput(', 'resolveSlug('],
        'admin/content_edit.php'      => ['resolveSlug('],
        'admin/article_edit.php'      => ['resolveSlug('],
        'admin/product_edit.php'      => ['resolveSlug('],
        'admin/product_category.php'  => ['resolveSlug('],
        'admin/product_brand.php'     => ['normalizeSlugInput(', 'resolveSlug('],
        'admin/product_tag.php'       => ['normalizeSlugInput(', 'resolveSlug('],
        'admin/album_edit.php'        => ['resolveSlug('],
        'admin/case_category.php'     => ['resolveSlug('],
        'admin/download_category.php' => ['resolveSlug('],
        'admin/page_edit.php'         => ['resolveSlug('],
        'admin/page_edit_advance.php' => ['resolveSlug('],
    ];

    public function testEverySlugWriterGoesThroughTheSanitizer(): void
    {
        foreach (self::SLUG_WRITERS as $path => $sanitizers) {
            $source = file_get_contents(ROOT_PATH . '/' . $path);
            self::assertIsString($source, $path . ' 不可读');
            $hit = false;
            foreach ($sanitizers as $needle) {
                if (str_contains($source, $needle)) {
                    $hit = true;
                    break;
                }
            }
            self::assertTrue($hit, $path . ' 落库 slug 却没走净化口径（'
                . implode(' / ', $sanitizers) . '）——中文别名会生成百分号编码 URL');
        }
    }

    /** 别名原样取自标题/名称的写法是事故本身，源码里不得再出现。 */
    public function testNoWriterAssignsATitleOrNameStraightToSlug(): void
    {
        $forbidden = [
            "\$data['slug'] = \$data['name']",
            "\$data['slug'] = \$data['title']",
            "'slug' => \$data['name']",
            "'slug' => \$data['title']",
            "'slug' => post('name')",
            "'slug' => post('title')",
        ];
        foreach (array_keys(self::SLUG_WRITERS) as $path) {
            $source = (string) file_get_contents(ROOT_PATH . '/' . $path);
            foreach ($forbidden as $pattern) {
                self::assertStringNotContainsString($pattern, $source,
                    $path . ' 把标题/名称原样当 slug（中文站即中文 URL）');
            }
        }
    }
}
