<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * deploy/ 下的备用服务器配置与根目录 .htaccess、Dispatcher 路由保持同步。
 * 由来（2026-09-25 真实 Nginx / Apache 实测）：备用配置各改各的，漂移了几个月没人发现——
 * 宝塔版缺 index.php 兜底（商城路由与支付回调全 404）、完整版把 /search.html 送进单页、
 * /vendor/*.php 可执行、阿里云两份缺下载分类与 zh-TW、最小版不支持子目录。
 */
final class DeployConfigSyncTest extends TestCase
{
    private static function read(string $path): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/' . $path));
    }

    public function testAliyunVhostCopyIsTheRootHtaccessVerbatim(): void
    {
        // 头部说明之后逐字等于根目录 .htaccess：改根目录规则时照抄一份即可，不必逐条对齐
        self::assertStringEndsWith(self::read('.htaccess'), self::read('deploy/aliyun-vhost.htaccess'));
    }

    public function testReadmeAliyunSnippetIsTheMinimalFileRules(): void
    {
        // README 里给阿里云用户直接粘贴的片段曾比 deploy 文件少 4 条（含上传目录 PHP 禁执行）
        $rules = static fn(string $text): array => array_values(array_filter(explode("\n", $text),
            static fn(string $line): bool => trim($line) !== '' && !str_starts_with(ltrim($line), '#')));
        $readme = self::read('README.md');
        $block = substr($readme, (int) strpos($readme, "```nginx\n", (int) strpos($readme, '#### 阿里云')) + 9);
        $block = substr($block, 0, (int) strpos($block, "```\n"));
        self::assertSame($rules(self::read('deploy/aliyun-nginx-minimal.txt')), $rules($block));
    }

    public function testBaotaIncludeFallsBackToTheDispatcher(): void
    {
        $baota = self::read('deploy/nginx-baota.conf');
        self::assertStringContainsString('rewrite ^/sitemap\.xml$ /sitemap.php last;', $baota);
        // 兜底必须排在最后：前面的具体规则仍直接派发到入口文件
        $fallback = strpos($baota, "if (!-e \$request_filename) {\n    rewrite ^ /index.php last;\n}");
        self::assertIsInt($fallback, '宝塔版缺少 index.php 兜底，插件路由会 404');
        self::assertGreaterThan((int) strrpos($baota, 'rewrite ^/([a-z0-9_-]+)\.html$'), $fallback);
        self::assertMatchesRegularExpression('/\\\\\.\(md\|sql\|bak\|example\|dist\|dist\\\\\.php\|conf\|/', $baota);
    }

    public function testFullNginxConfigMatchesTheRootProtectionsAndRoutes(): void
    {
        $full = self::read('deploy/nginx-server.conf');
        self::assertStringContainsString('location ~ ^/(vendor|includes|bin|migrations|recipes)/ {', $full);
        // ^~ /install/ 挡住了外层 .sql 正则，初始 SQL 必须在块内单独封
        $install = substr($full, (int) strpos($full, "\nlocation ^~ /install/ {\n"));
        $install = substr($install, 0, (int) strpos($install, "\n}\n"));
        self::assertStringContainsString('location ^~ /install/sql/ {', $install);
        // 搜索在通用单页规则之前
        $dynamic = substr($full, (int) strpos($full, 'location @yikai_dynamic {'));
        self::assertLessThan(strpos($dynamic, 'rewrite ^/([a-z0-9_-]+)\.html$'), strpos($dynamic, 'rewrite ^/search\.html$ /search.php last;'));
        // 静态直出只给无查询串的 GET/HEAD（与根目录 .htaccess 同一条件）：首页、语言页、普通 .html 三处
        self::assertSame(3, substr_count($full, 'if ($args != "") { rewrite ^ /index.php last; }'));
        self::assertSame(3, substr_count($full, 'if ($request_method !~ ^(GET|HEAD)$) { rewrite ^ /index.php last; }'));
    }

    public function testRestrictedHostConfigsKeepRouteAndSubdirectoryParity(): void
    {
        $aliyun = self::read('deploy/aliyun-nginx.htaccess');
        self::assertStringContainsString('^/(ja|en|zh-CN|zh-TW)/', $aliyun);
        $download = strpos($aliyun, 'rewrite ^/download/([a-z0-9_-]+)\.html$ /list.php?slug=download&cat=$1 last;');
        self::assertIsInt($download);
        self::assertLessThan(strpos($aliyun, 'rewrite ^/([a-z0-9_-]+)/([a-z0-9_-]+)\.html$'), $download);

        $minimal = self::read('deploy/htaccess-minimal.txt');
        self::assertStringNotContainsString('RewriteBase /', $minimal);
        self::assertStringContainsString('RewriteRule . %{ENV:YK_BASE}index.php [L]', $minimal);
        self::assertStringContainsString('%{DOCUMENT_ROOT}%{ENV:YK_BASE}installed.lock -f', $minimal);

        // 单站子目录写法：安装目录的 ^~ 挡住了外层正则，初始 SQL 在块内单独封
        $subdir = self::read('deploy/SUBDIRECTORY.md');
        $install = substr($subdir, (int) strpos($subdir, 'location ^~ /sub/install/ {'));
        self::assertStringContainsString('location ^~ /sub/install/sql/ { deny all; }', substr($install, 0, 400));
    }
}
