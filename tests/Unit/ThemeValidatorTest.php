<?php
/**
 * 主题包校验器测试（规范见 yikaicms-docs/theme-schema.md）。
 *
 * 核心包只内置 default；可选主题源码放 marketplace/themes，由市场签名分发。
 * 两处都必须通过同一套主题包校验，避免“移到市场后就不测”的质量降级。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2));
}
require_once ROOT_PATH . '/config/version.php';
require_once ROOT_PATH . '/includes/ThemeValidator.php';

final class ThemeValidatorTest extends TestCase
{
    /** @return array<string,mixed> 一份合规的 v1 元数据 */
    private function validMeta(array $override = []): array
    {
        return array_merge([
            'schema_version'   => 1,
            'name'             => '测试主题',
            'name_en'          => 'Test',
            'name_ja'          => 'テスト',
            'description'      => '描述',
            'description_en'   => 'Desc',
            'description_ja'   => '説明',
            'version'          => '1.0.0',
            'author'           => 'Yikai',
            'category'         => 'general',
            'requires_cms'     => '>=1.0',
            'requires_php'     => '>=8.0',
            'required_plugins' => [],
        ], $override);
    }

    // ── 内置主题（最重要的一条）────────────────────────────

    public function testBundledThemesAllValid(): void
    {
        $dirs = glob(ROOT_PATH . '/themes/*', GLOB_ONLYDIR) ?: [];
        $this->assertSame(['default'], array_map('basename', $dirs), '核心包运行时只能内置 default 主题');

        foreach ($dirs as $dir) {
            $slug = basename($dir);
            $r = ThemeValidator::validateDir($dir, $slug);
            $this->assertSame([], $r['errors'], "内置主题 {$slug} 校验不通过：" . implode('；', $r['errors']));
            $this->assertSame([], $r['warnings'], "内置主题 {$slug} 有警告：" . implode('；', $r['warnings']));
        }
    }

    public function testMarketplaceThemeSourcesAllValid(): void
    {
        $dirs = glob(ROOT_PATH . '/marketplace/themes/*', GLOB_ONLYDIR) ?: [];
        $this->assertSame(
            ['aurora', 'business', 'minimal', 'trade'],
            array_map('basename', $dirs),
            '市场主题源码清单发生变化时必须同步调整签名发布清单'
        );
        foreach ($dirs as $dir) {
            $slug = basename($dir);
            $r = ThemeValidator::validateDir($dir, $slug);
            $this->assertSame([], $r['errors'], "市场主题 {$slug} 校验不通过：" . implode('；', $r['errors']));
            $this->assertSame([], $r['warnings'], "市场主题 {$slug} 有警告：" . implode('；', $r['warnings']));
        }
    }

    // ── 必填与格式 ────────────────────────────────────────

    public function testMissingNameIsError(): void
    {
        $r = ThemeValidator::validateMeta($this->validMeta(['name' => '']));
        $this->assertNotEmpty($r['errors']);
    }

    public function testV1MissingVersionIsError(): void
    {
        $m = $this->validMeta();
        unset($m['version']);
        $this->assertNotEmpty(ThemeValidator::validateMeta($m)['errors']);
    }

    public function testNonSemverVersionIsError(): void
    {
        $r = ThemeValidator::validateMeta($this->validMeta(['version' => '1.0']));
        $this->assertNotEmpty($r['errors']);
    }

    public function testBadSlugIsError(): void
    {
        $r = ThemeValidator::validateMeta($this->validMeta(), 'Bad_Slug');
        $this->assertNotEmpty($r['errors']);
    }

    public function testFutureSchemaVersionIsError(): void
    {
        $r = ThemeValidator::validateMeta($this->validMeta(['schema_version' => 99]));
        $this->assertNotEmpty($r['errors'], '声明了本站不支持的 schema_version 应拒绝');
    }

    // ── 兼容性 ────────────────────────────────────────────

    public function testUnsatisfiedCmsRequirementIsError(): void
    {
        $r = ThemeValidator::validateMeta($this->validMeta(['requires_cms' => '>=99.0']));
        $this->assertNotEmpty($r['errors'], 'CMS 版本不满足必须拒绝——装上去也是坏的');
    }

    public function testUnsatisfiedPhpRequirementIsError(): void
    {
        $r = ThemeValidator::validateMeta($this->validMeta(['requires_php' => '>=99.0']));
        $this->assertNotEmpty($r['errors']);
    }

    /** @dataProvider constraintProvider */
    public function testSatisfies(string $actual, string $constraint, bool $expected): void
    {
        $this->assertSame($expected, ThemeValidator::satisfies($actual, $constraint));
    }

    /** @return array<string, array{0:string,1:string,2:bool}> */
    public static function constraintProvider(): array
    {
        return [
            '>= 满足'      => ['1.14.0', '>=1.14', true],
            '>= 不满足'    => ['1.13.3', '>=1.14', false],
            '裸版本号视作 >=' => ['1.14.0', '1.14', true],
            '^ 视作 >='    => ['1.14.0', '^1.0', true],
            '> 边界'       => ['1.14.0', '>1.14', false],
            '空约束放行'    => ['1.0.0', '', true],
            // 认不出的写法不拦：主题作者不是 composer 用户，写法五花八门，
            // 与其解析失败就拒绝，不如放行
            '看不懂的放行'  => ['1.14.0', '1.x || 2.x', true],
        ];
    }

    // ── 旧包宽松（向后兼容，不能让存量主题失效）──────────────

    public function testLegacyThemeWithoutSchemaVersionIsAccepted(): void
    {
        $r = ThemeValidator::validateMeta(['name' => '老主题']);
        $this->assertSame([], $r['errors'], 'v1 之前的主题包不能因为新规范而装不上');
        $this->assertNotEmpty($r['warnings'], '但应给出补齐建议');
    }

    public function testLegacyThemeStillRejectedWhenIncompatible(): void
    {
        // 宽松不等于不管：明确声明了不满足的兼容性要求，照样拒绝
        $r = ThemeValidator::validateMeta(['name' => '老主题', 'requires_php' => '>=99.0']);
        $this->assertNotEmpty($r['errors']);
    }

    // ── 已废弃字段 ────────────────────────────────────────

    public function testDeprecatedFieldsWarn(): void
    {
        $r = ThemeValidator::validateMeta($this->validMeta([
            'supports' => ['banner'],
            'colors'   => ['primary' => '#000'],
            'locales'  => ['zh-CN'],      // 已废弃：主题不承载演示内容
        ]));
        $this->assertSame([], $r['errors'], '废弃字段只警告，不拒绝');
        $this->assertCount(3, array_filter($r['warnings'], static fn($w) => str_contains($w, '已废弃') || str_contains($w, '已移出')));
    }

    public function testUnknownCategoryWarnsButPasses(): void
    {
        $r = ThemeValidator::validateMeta($this->validMeta(['category' => 'metaverse']));
        $this->assertSame([], $r['errors']);
        $this->assertNotEmpty($r['warnings']);
    }

    // ── 区块覆盖由文件系统推导 ────────────────────────────

    public function testBlockCoverageIsDerivedFromFilesystem(): void
    {
        // default 主题实现了 partners 与 product_categories，却从未在 supports 里声明过——
        // 正是这类不一致（五套里三套）促成了「不信声明、只看文件」的决定。
        $cov = themeBlockCoverage('default');
        $this->assertContains('partners', $cov['own']);
        $this->assertContains('product_categories', $cov['own']);

        $this->assertContains('timeline', $cov['fallback']);
        $this->assertContains('banner', $cov['own']);
    }

    public function testBlockCoveragePartitionsAllCoreBlocks(): void
    {
        $cov = themeBlockCoverage('default');
        $all = array_merge($cov['own'], $cov['fallback']);
        sort($all);
        $core = array_map(
            static fn(string $f): string => basename($f, '.php'),
            glob(ROOT_PATH . '/includes/blocks/*.php') ?: []
        );
        // 全集是「核心 ∪ 主题自带」：主题可以提供核心没有的区块
        $expect = array_values(array_unique(array_merge($core, $cov['own'])));
        sort($expect);
        $this->assertSame($expect, $all, 'own + fallback 应不重不漏地覆盖全集');
        $this->assertSame([], array_intersect($cov['own'], $cov['fallback']), 'own 与 fallback 不应重叠');
    }
}
