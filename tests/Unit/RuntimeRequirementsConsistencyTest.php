<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 运行环境要求的唯一来源。
 *
 * 起因：v1.19.3 之前同一个问题有五个互相矛盾的答案——README/composer/安装器说 PHP 8.0，
 * SiteHealth 却按 8.2 判 CRITICAL；Compatibility 要求 curl/openssl 却不查 fileinfo/dom，
 * 与安装器的必需项正好错开；SiteHealth 还把核心零使用的 simplexml 列为必需项报红。
 * 后果是装得上的站打开健康检查就看到红色严重项。
 *
 * 本测试锁住：清单只能有一份，其它文件不许再写死自己的版本号或扩展列表。
 */
final class RuntimeRequirementsConsistencyTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/RuntimeRequirements.php';
    }

    public function testMinimumIsNotAboveRecommended(): void
    {
        self::assertTrue(
            version_compare(RuntimeRequirements::PHP_MINIMUM, RuntimeRequirements::PHP_RECOMMENDED, '<='),
            '硬地板不能高于建议线。'
        );
    }

    /** 2.1.3 起核心要求 8.2（所有者 2026-10-25 定）；改版本线要连同下面几条一起改 */
    public function testCoreRequiresPhp82(): void
    {
        self::assertSame('8.2.0', RuntimeRequirements::PHP_MINIMUM);
        self::assertSame('8.3.0', RuntimeRequirements::PHP_RECOMMENDED);
        self::assertStringContainsString('PHP_VERSION_ID < 80200', (string) file_get_contents(ROOT_PATH . '/includes/php_guard.php'));
    }

    /**
     * 随包插件与主题仍声明 requires_php >=8.0：它们经市场装到 2.1.2 及更早、还在 8.0 上的站，
     * 必须继续能在 8.0 上加载（CI 用 tools/check-php80-runtime.php 编译检查）。
     */
    public function testOfficialExtensionsAcceptPhp80(): void
    {
        $manifests = array_merge(
            glob(ROOT_PATH . '/plugins/*/plugin.json') ?: [],
            glob(ROOT_PATH . '/marketplace/themes/*/theme.json') ?: [],
            [ROOT_PATH . '/themes/default/theme.json']
        );
        foreach ($manifests as $manifest) {
            $data = json_decode((string) file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
            $constraint = (string) ($data['requires_php'] ?? '8.0');
            self::assertMatchesRegularExpression('/^(?:>=)?8\.0(?:\.0)?$/', $constraint, $manifest);
            $minimum = str_starts_with($constraint, '>=') ? substr($constraint, 2) : $constraint;
            self::assertTrue(version_compare('8.0.0', $minimum, '>='), $manifest . ' rejects PHP 8.0');
        }
    }

    /** 装得上的版本，健康检查就不该判 CRITICAL——这正是当初那条红色误报的成因 */
    public function testInstallableVersionIsNotReportedCritical(): void
    {
        self::assertTrue(
            RuntimeRequirements::phpMeetsMinimum(RuntimeRequirements::PHP_MINIMUM),
            '恰好等于硬地板的版本必须判为可安装。'
        );
        self::assertFalse(
            RuntimeRequirements::phpMeetsRecommended('8.2.0'),
            'PHP 8.2 应落在「可运行但建议升级」这一档（8.2 安全更新 2026 年底结束），而不是与 8.3 同级。'
        );
    }

    /** 核心真的用到才算必需：simplexml 只有 product-import 插件用，不能进必需项 */
    public function testSimplexmlIsNotACoreRequirement(): void
    {
        self::assertNotContains('simplexml', RuntimeRequirements::requiredNames());
        self::assertContains('simplexml', RuntimeRequirements::recommendedNames());
    }

    public function testRequiredAndRecommendedDoNotOverlap(): void
    {
        self::assertSame(
            [],
            array_values(array_intersect(RuntimeRequirements::requiredNames(), RuntimeRequirements::recommendedNames())),
            '同一个扩展不能既必需又只是建议。'
        );
    }

    /** 每一项都要写清「缺了会怎样」——分级的依据是后果，不是习惯 */
    public function testEveryExtensionCarriesARationale(): void
    {
        foreach (RuntimeRequirements::required() + RuntimeRequirements::recommended() as $ext => $why) {
            self::assertNotSame('', trim($why), "{$ext} 缺少「缺了会怎样」的说明。");
        }
    }

    /**
     * 安装界面显示的版本号必须是 "8.2+"。
     *
     * 这条是被 codex 复核抓出来的真 bug 的回归锁：原实现写 rtrim(PHP_MINIMUM, '.0')，
     * 而 rtrim 第二参是**字符集合**不是后缀——rtrim('8.0.0', '.0') 会一路剥到只剩 '8'，
     * 安装器于是显示 "PHP 8+"。当时 1340 项测试全绿，因为没有一条断言过这个 label。
     */
    public function testInstallerLabelShowsTheFullMinimumVersion(): void
    {
        self::assertSame('8.2+', RuntimeRequirements::phpMinimumLabel());

        // 与硬地板联动：改了 PHP_MINIMUM 而忘了 label，这条会红
        $expected = implode('.', array_slice(explode('.', RuntimeRequirements::PHP_MINIMUM), 0, 2)) . '+';
        self::assertSame($expected, RuntimeRequirements::phpMinimumLabel());
    }

    /** 契约文件本身要随包发出去，否则装到用户机器上 SiteHealth 会因缺文件 Fatal */
    public function testContractShipsInTheReleaseArtifact(): void
    {
        $runtime = require ROOT_PATH . '/config/release-runtime.php';
        self::assertContains('includes/RuntimeRequirements.php', $runtime['required_files']);
    }

    /** README / composer / CI 无法在运行期读 PHP 类，只能靠这条断言防漂移 */
    public function testDocumentationAndToolingStateTheSameContract(): void
    {
        $readme = (string) file_get_contents(ROOT_PATH . '/README.md');
        self::assertStringContainsString('| PHP | >= 8.2', $readme);
        self::assertStringNotContainsString('| PHP | >= 8.0', $readme);

        $composer = json_decode((string) file_get_contents(ROOT_PATH . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('>=8.2', $composer['require']['php']);
        foreach (RuntimeRequirements::requiredNames() as $ext) {
            self::assertArrayHasKey('ext-' . $ext, $composer['require'], "必需扩展 {$ext} 应在 composer 平台约束里。");
        }
        foreach (RuntimeRequirements::recommendedNames() as $ext) {
            self::assertArrayNotHasKey(
                'ext-' . $ext,
                $composer['require'],
                "{$ext} 只是建议项，写进 composer require 会让没装它的主机直接装不上。"
            );
        }

        // 8.2 是产品承诺的最低版，后台冒烟必须真的在 8.2 上跑；8.0 只留给随包扩展的编译检查
        $ci = (string) file_get_contents(ROOT_PATH . '/.github/workflows/ci.yml');
        self::assertStringContainsString("- php: '8.2'", $ci);
        self::assertStringNotContainsString("- php: '8.0'", $ci);
    }

    /**
     * 消费方不许再自带一份清单。这里扫的是「写死的扩展数组」和「写死的 PHP 版本比较」，
     * 它们正是当年那五个矛盾答案的来源。
     */
    public function testConsumersDoNotHardcodeTheirOwnLists(): void
    {
        $consumers = [
            'includes/SiteHealth.php',
            'includes/Compatibility.php',
            'install/index.php',
        ];
        foreach ($consumers as $relative) {
            $source = file_get_contents(ROOT_PATH . '/' . $relative);
            self::assertNotFalse($source, "读不到 {$relative}");

            self::assertDoesNotMatchRegularExpression(
                "/version_compare\(\s*PHP_VERSION\s*,\s*'\d+\.\d+/",
                $source,
                "{$relative} 又写死了 PHP 版本比较；请改用 RuntimeRequirements::phpMeetsMinimum()／phpMeetsRecommended()。"
            );
            self::assertDoesNotMatchRegularExpression(
                "/\[\s*'pdo'\s*,\s*'json'/",
                $source,
                "{$relative} 又写死了一份必需扩展清单；请改用 RuntimeRequirements::requiredNames()。"
            );
        }
    }

    /** 核心已要求 8.2，可以用 never；随包扩展仍要能在 8.0 上解析 */
    public function testBundledExtensionsDoNotUsePhp81NeverReturnTypes(): void
    {
        $roots = ['plugins', 'themes', 'marketplace/themes'];
        $violations = [];
        foreach ($roots as $relativeRoot) {
            $absoluteRoot = ROOT_PATH . '/' . $relativeRoot;
            if (!is_dir($absoluteRoot)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                $code = '';
                foreach (token_get_all($source) as $token) {
                    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= is_array($token) ? $token[1] : $token;
                }
                if (preg_match('/\)\s*:\s*never\b/i', $code) === 1) {
                    $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen(ROOT_PATH) + 1));
                }
            }
        }

        self::assertSame([], $violations, 'PHP 8.0 无法解析 never 返回类型：' . implode(', ', $violations));
    }
}
