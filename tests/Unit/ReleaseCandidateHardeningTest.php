<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleaseCandidateHardeningTest extends TestCase
{
    public function testBaotaRoutesCoverThePortableRouteContract(): void
    {
        $baota = (string) file_get_contents(ROOT_PATH . '/deploy/nginx-baota.conf');

        self::assertStringContainsString('^/api/v1/([a-z_]+)/?$', $baota);
        // 宝塔 server 块没有 location / 兜底：语言前缀与首页都必须直接改写到真实入口。
        // 「去前缀 + ?_lang= 再往下匹配」在 server 级 last 之后不会再跑后续规则，语言内页全 404；
        // 首页不直达入口时，默认文档里 index.html 排前面就会被单页规则当成别名 index（2026-09-23 实测）。
        self::assertStringContainsString('rewrite ^/(ja|en|zh-CN|zh-TW)/ /index.php last;', $baota);
        self::assertStringNotContainsString('?_lang=$1 last', $baota);
        self::assertStringContainsString('rewrite ^/$ /index.php last;', $baota);
        self::assertStringContainsString('rewrite ^/(admin|install)/$ /$1/index.php last;', $baota);
        self::assertStringContainsString('^/download/([a-z0-9_-]+)/page/(\\d+)\\.html$', $baota);
        self::assertStringContainsString('^/download/([a-z0-9_-]+)\\.html$', $baota);
    }

    /**
     * 默认首页不能依赖面板的 server 级 index：面板把 index.html 排前面时首页 404，
     * 没写 index 时 /admin/、/install/ 403——新站连安装器都进不去（2026-09-23 真实 Nginx 实测）。
     * location 级 index 会覆盖 server 级，所以三个会落到目录的块各自声明。
     */
    public function testNginxTemplateDeclaresItsOwnDefaultIndex(): void
    {
        $conf = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/deploy/nginx-server.conf'));
        foreach (['location / {' => 'index index.php index.html;', 'location ^~ /install/ {' => 'index index.php;',
            'location ^~ /api/v1/ {' => 'index index.php;'] as $block => $directive) {
            $start = strpos($conf, "\n" . $block . "\n");
            self::assertNotFalse($start, $block);
            $body = substr($conf, (int) $start, (int) strpos($conf, "\n}", (int) $start) - (int) $start);
            self::assertMatchesRegularExpression('/^\s+' . preg_quote($directive, '/') . '$/m', $body, $block);
        }
    }

    /**
     * 多站通用配置与单站写法必须挡同一批目录——两份各改各的，迟早有一份漏掉 storage/。
     * 另锁住两处实测才发现的坑（2026-09-23 真实 Nginx）：/site/install/ 直接交给 PHP 会回 403
     * 导致新站装不上；不存在的 .php 要在 nginx 这层判 404。
     */
    public function testMultiSiteNginxConfigMatchesSingleSiteProtection(): void
    {
        $multi = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/deploy/nginx-subdirectories.conf'));
        $single = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/deploy/SUBDIRECTORY.md'));
        $dirs = 'config|storage|deploy|vendor|includes|bin|migrations|recipes';
        self::assertStringContainsString("/sub/($dirs)/", $single);
        self::assertStringContainsString("/(?:$dirs|install/sql)/", $multi);
        foreach (['uploads/.*\.(?:php|phtml|phar|php[0-9])$', '(?:md|sql|bak|example|dist|conf|lock|yml|yaml)$',
            'installed.lock) { return 403; }', 'index index.php index.html;'] as $rule) {
            self::assertStringContainsString($rule, $multi, $rule);
        }
        self::assertStringContainsString('rewrite ^ /$yk_site/install/index.php last;', $multi);
        self::assertSame(2, substr_count($multi, 'if (!-f $request_filename) { return 404; }'));
        // 保护规则必须排在 PHP 处理之前（nginx 正则 location 先匹配先生效）
        self::assertLessThan(strpos($multi, '# ── 3. PHP'), strpos($multi, '/uploads/.*\.(?:php'));
    }

    public function testApplicationErrorsUseRealHttpErrorStatuses(): void
    {
        $helper = ROOT_PATH . '/includes/http_response.php';
        self::assertFileExists($helper);
        if (!is_file($helper)) {
            return;
        }
        require_once $helper;

        self::assertSame(200, applicationErrorHttpStatus(1));
        self::assertSame(403, applicationErrorHttpStatus(403));
        self::assertSame(409, applicationErrorHttpStatus(409));
        self::assertSame(200, applicationErrorHttpStatus(700));

        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString("['code' => \$code, 'msg'", $functions);
        self::assertStringContainsString('applicationErrorHttpStatus($code)', $functions);
    }

    public function testDisabledKnownLanguagePrefixIsA404(): void
    {
        $helper = ROOT_PATH . '/includes/language_request.php';
        self::assertFileExists($helper);
        if (!is_file($helper)) {
            return;
        }
        require_once $helper;

        $available = ['zh-CN' => 'Chinese', 'en' => 'English', 'ja' => 'Japanese'];
        self::assertFalse(languagePrefixIsDisabled('', $available, ['zh-CN', 'en']));
        self::assertFalse(languagePrefixIsDisabled('en', $available, ['zh-CN', 'en']));
        self::assertTrue(languagePrefixIsDisabled('ja', $available, ['zh-CN', 'en']));
        self::assertFalse(languagePrefixIsDisabled('xx', $available, ['zh-CN', 'en']));

        $init = (string) file_get_contents(ROOT_PATH . '/includes/init.php');
        self::assertStringContainsString('languagePrefixIsDisabled(', $init);
        self::assertStringContainsString('render404();', $init);
    }

    public function testReadmeMatchesBundledThemesAndUsesVerifiableCiEvidence(): void
    {
        $readme = (string) file_get_contents(ROOT_PATH . '/README.md');

        self::assertStringContainsString('Default（标准）、Business（深色商务风）、Minimal（极简）', $readme);
        // Aurora、Trade 已从模板市场下架（2026-09），README 不得再把它们当作可安装主题介绍。
        self::assertStringNotContainsString('Aurora', $readme);
        self::assertStringContainsString('模板市场', $readme);
        self::assertStringNotContainsString('tests-349%20passing', $readme);
        self::assertStringContainsString('actions/workflows/ci.yml/badge.svg', $readme);
    }

    public function testFreshInstallDefaultsToExpiringStrictFormSignatures(): void
    {
        $defaults = require ROOT_PATH . '/config/defaults.php';
        self::assertSame('2', $defaults['security']['form_security_version']['value']);
        self::assertGreaterThanOrEqual(1800, (int) $defaults['security']['form_signature_max_age']['value']);
        self::assertLessThanOrEqual(86400, (int) $defaults['security']['form_signature_max_age']['value']);

        foreach (['mysql.sql', 'sqlite.sql'] as $database) {
            $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/' . $database);
            self::assertMatchesRegularExpression("/form_security_version[^\\n]+,'2'/", $sql);
            self::assertMatchesRegularExpression("/form_signature_max_age[^\\n]+,'7200'/", $sql);
        }

        // Existing sites without these newer rows keep compatibility mode until an administrator opts in.
        $endpoint = (string) file_get_contents(ROOT_PATH . '/form_submit.php');
        self::assertStringContainsString("config('form_security_version', '1')", $endpoint);
        self::assertStringContainsString("config('form_signature_max_age', '0')", $endpoint);
    }

    public function testEditorDefersTheFullIconCatalogAndHasASourceBudget(): void
    {
        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        $catalog = ROOT_PATH . '/assets/icons/blox-icon-catalog.json';

        self::assertFileExists($catalog);
        self::assertStringNotContainsString("file_get_contents(ROOT_PATH . '/assets/tabler/tabler-icons.min.css')", $editor);
        self::assertStringNotContainsString('json_encode($tablerIcons)', $editor);
        self::assertStringContainsString('/assets/icons/blox-icon-catalog.json', $editor);
        self::assertLessThan(500_000, strlen($editor), '主入口超过 500 KB，应继续拆分而不是继续堆叠。');

        $data = json_decode((string) file_get_contents($catalog), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['schema']);
        self::assertGreaterThan(5000, count($data['tabler']));
        self::assertGreaterThan(2000, count($data['bootstrap']));

        $tablerCss = (string) file_get_contents(ROOT_PATH . '/assets/tabler/tabler-icons.min.css');
        $bootstrapCss = (string) file_get_contents(ROOT_PATH . '/assets/bootstrap-icons/bootstrap-icons.min.css');
        preg_match_all('/\.ti-([a-z0-9-]+):before/', $tablerCss, $tablerMatches);
        preg_match_all('/\.bi-([a-z0-9-]+)::before/', $bootstrapCss, $bootstrapMatches);
        self::assertSame(array_values(array_unique($tablerMatches[1])), $data['tabler']);
        self::assertSame(
            array_map(static fn (string $name): string => 'bi:' . $name, array_values(array_unique($bootstrapMatches[1]))),
            $data['bootstrap']
        );

        $builder = (string) file_get_contents(ROOT_PATH . '/tools/build-blox-icon-catalog.php');
        self::assertStringContainsString('blox-icon-catalog.json', $builder);
    }

    public function testBuildProducesReviewableCommitAndCiEvidence(): void
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');
        $tool = (string) file_get_contents(ROOT_PATH . '/tools/build-release-evidence.php');

        self::assertStringContainsString('build-release-evidence.php', $build);
        self::assertStringContainsString('.evidence.json', $build);
        self::assertStringContainsString("'source_commit'", $tool);
        self::assertStringContainsString("'ci_url'", $tool);
        self::assertStringContainsString("'artifact_sha256'", $tool);
        self::assertStringContainsString("'tests_in_install_package' => false", $tool);
    }
}
