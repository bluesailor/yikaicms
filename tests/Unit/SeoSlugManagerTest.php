<?php
/**
 * SEO 助手 - URL 别名管理的行为契约。
 *
 * 重点不是"能改名"，而是三条安全/一致性边界：
 *   1. 表名只能来自白名单（入参来自请求，绝不能拼进 SQL）；
 *   2. 净化复用核心 normalizeSlugInput()，插件不得自成一套（漂移即事故重演）；
 *   3. 合法性口径与 Dispatcher 的路由正则一致，否则"改完仍是 404"。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/plugins/seo/slugs.php';

final class SeoSlugManagerTest extends TestCase
{
    public function testValidityMatchesTheRouterCharacterClass(): void
    {
        foreach (['about', 'about-us', 'news2024', 'a-1-b'] as $ok) {
            self::assertTrue(\seo_slug_is_valid($ok), $ok . ' 应判为合法');
        }
        // 这些在伪静态下都匹配不到路由 → 404
        foreach (['商业保险', 'About', 'about us', 'a/b', 'a_b', '', 'a.b'] as $bad) {
            self::assertFalse(\seo_slug_is_valid($bad), $bad . ' 应判为非法');
        }
    }

    public function testTableWhitelistIsClosedAndUsesBareNames(): void
    {
        $tables = \seo_slug_tables();
        self::assertSame(['channels', 'contents', 'products', 'product_categories'], array_keys($tables));
        foreach ($tables as $table => $meta) {
            self::assertStringNotContainsString(DB_PREFIX === '' ? 'yikai_' : DB_PREFIX, $table,
                'db() 包装会自动加前缀，白名单必须是裸表名');
            self::assertArrayHasKey('label_column', $meta);
            self::assertMatchesRegularExpression('/^[a-z_]+$/', (string) $meta['label_column'],
                '标题列会直接进 SQL，必须是纯标识符');
        }
    }

    /** 白名单外的表名不得通过（改名与占用检查两个入口都要挡）。 */
    public function testUnknownTablesAreRejected(): void
    {
        [$ok, , ] = \seo_slug_rename('users', 1, 'hacked', false);
        self::assertFalse($ok, '白名单外的表必须拒绝');
        [$ok2, , ] = \seo_slug_rename('channels; DROP TABLE users', 1, 'x', false);
        self::assertFalse($ok2, '注入形态的表名必须拒绝');
        self::assertTrue(\seo_slug_taken('users', 'anything', 0),
            '未知表按"已占用"处理——fail-closed，绝不放行写入');
    }

    /**
     * 插件声明的 requires_cms 必须覆盖它真正依赖的核心能力。
     * slugs.php 用核心 normalizeSlugInput()（CMS 1.20.1 起才有 includes/Slug.php），
     * requires_cms 落后就会让旧站从市场装上这个插件然后白屏。
     */
    public function testPluginDeclaresTheCoreVersionItActuallyNeeds(): void
    {
        $meta = json_decode((string) file_get_contents(ROOT_PATH . '/plugins/seo/plugin.json'), true);
        self::assertIsArray($meta);
        self::assertTrue(version_compare((string) $meta['requires_cms'], '1.20.1', '>='),
            'slugs.php 依赖 includes/Slug.php（1.20.1 引入），requires_cms 不得低于它');
        self::assertTrue(\seo_slug_available(), '测试进程已加载核心 Slug.php，可用性判定应为真');
    }

    /** 非法别名提交时走核心净化，而不是被插件放行或删空。 */
    public function testRenameSanitizesThroughTheCoreHelper(): void
    {
        require_once ROOT_PATH . '/includes/Slug.php';
        // 与核心口径完全一致：中文转拼音，而不是删光
        self::assertSame('shang-ye-bao-xian', \normalizeSlugInput('商业保险'));
        // 纯符号无法转写 → 净化后为空 → 改名必须被拒（不能落一个空别名）
        [$ok, , ] = \seo_slug_rename('channels', 1, '///', false);
        self::assertFalse($ok, '净化后为空的别名不得落库');
    }
}
