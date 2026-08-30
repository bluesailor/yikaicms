<?php
/**
 * HtmlCache 回归测试（cile.cn 30GB 缓存目录膨胀事故防回归）：
 *   - isCacheable() 查询参数白名单：utm_* / 未知参数 / 搜索参数不落缓存
 *   - invalidate() 只删 *.html，不碰 .gitkeep 等其它文件
 *   - pruneExpired() 只删过期文件并遵守 $limit
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HtmlCache;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once ROOT_PATH . '/includes/hooks.php';
require_once ROOT_PATH . '/includes/HtmlCache.php';

final class HtmlCacheTest extends TestCase
{
    private string $tmpDir = '';
    private array $savedGet = [];
    private array $savedServer = [];

    protected function setUp(): void
    {
        $this->savedGet = $_GET;
        $this->savedServer = $_SERVER;
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SERVER['HTTP_X_STATIC_GEN']);
    }

    protected function tearDown(): void
    {
        $_GET = $this->savedGet;
        $_SERVER = $this->savedServer;
        HtmlCache::setDir(null);
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach (scandir($this->tmpDir) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                @unlink($this->tmpDir . '/' . $f);
            }
            @rmdir($this->tmpDir);
        }
    }

    private function isCacheable(): bool
    {
        $m = new ReflectionMethod(HtmlCache::class, 'isCacheable');
        $m->setAccessible(true);
        return (bool) $m->invoke(null);
    }

    private function makeCacheDir(): string
    {
        $this->tmpDir = sys_get_temp_dir() . '/yk_htmlcache_' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0755, true);
        HtmlCache::setDir($this->tmpDir);
        return $this->tmpDir;
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testBothSettingEventHooksKeepRuntimeCacheButInvalidateContent(): void
    {
        $dir = $this->makeCacheDir();
        $checked = 0;
        foreach (['data_changed', 'setting_saved'] as $hook) {
            foreach ($GLOBALS['ik_actions'][$hook] ?? [] as $callbacks) {
                foreach ($callbacks as $callback) {
                    if (!$callback instanceof \Closure) continue;
                    $source = (new \ReflectionFunction($callback))->getFileName();
                    if ($source !== realpath(ROOT_PATH . '/includes/HtmlCache.php')) continue;
                    file_put_contents($dir . '/page.html', 'cached');
                    $runtime = ['sched_sweep_at' => '123'];
                    $content = ['site_name' => 'new'];
                    $hook === 'data_changed' ? $callback('settings', 0, $runtime) : $callback($runtime);
                    self::assertFileExists($dir . '/page.html');
                    $hook === 'data_changed' ? $callback('settings', 0, $content) : $callback($content);
                    self::assertFileDoesNotExist($dir . '/page.html');
                    $checked++;
                }
            }
        }
        self::assertSame(2, $checked);
    }

    // ---- isCacheable：查询参数白名单 ----

    public function testPlainGetIsCacheable(): void
    {
        $this->assertTrue($this->isCacheable());
    }

    public function testAllowedQueryKeysRemainCacheable(): void
    {
        $_GET = ['slug' => 'abc', 'page' => '2', 'cat' => 'news', 'sort' => 'newest', 'parent' => 'about'];
        $this->assertTrue($this->isCacheable());
    }

    public function testInvalidOrStructuredAllowedValuesAreNotCacheable(): void
    {
        foreach ([
            ['page' => '0'],
            ['page' => '10001'],
            ['page' => ['2']],
            ['sort' => 'hot'],
            ['slug' => str_repeat('a', 101)],
            ['cat' => '../private'],
        ] as $query) {
            $_GET = $query;
            $this->assertFalse($this->isCacheable());
        }
    }

    public function testCacheKeyCanonicalizesQueryOrderAndPageNumber(): void
    {
        $method = new ReflectionMethod(HtmlCache::class, 'buildKey');
        $method->setAccessible(true);
        $_SERVER['REQUEST_URI'] = '/products.html?page=02&sort=newest';
        $_GET = ['page' => '02', 'sort' => 'newest'];
        $first = $method->invoke(null);

        $_SERVER['REQUEST_URI'] = '/products.html?sort=newest&page=2';
        $_GET = ['sort' => 'newest', 'page' => '2'];
        self::assertSame($first, $method->invoke(null));
    }

    public function testUnknownQueryKeyIsNotCacheable(): void
    {
        $_GET = ['utm_source' => 'x'];
        $this->assertFalse($this->isCacheable());
    }

    public function testUnknownKeyMixedWithAllowedIsNotCacheable(): void
    {
        $_GET = ['slug' => 'abc', 'from' => 'weibo'];
        $this->assertFalse($this->isCacheable());
    }

    public function testSearchParamsAreNotCacheable(): void
    {
        foreach (['keyword', 'q', 's'] as $key) {
            $_GET = [$key => 'test'];
            $this->assertFalse($this->isCacheable(), "?{$key}= 不应进入 HTML 缓存");
        }
    }

    public function testTokenAndCsrfStillExcluded(): void
    {
        $_GET = ['token' => 'x'];
        $this->assertFalse($this->isCacheable());
        $_GET = ['csrf' => 'y'];
        $this->assertFalse($this->isCacheable());
    }

    public function testPostIsNotCacheable(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->assertFalse($this->isCacheable());
    }

    public function testStaticGenRequestIsNotCacheable(): void
    {
        $_SERVER['HTTP_X_STATIC_GEN'] = '1';
        $this->assertFalse($this->isCacheable());
    }

    // ---- invalidate：只删 *.html ----

    public function testInvalidateDeletesOnlyHtmlFiles(): void
    {
        $dir = $this->makeCacheDir();
        file_put_contents($dir . '/aaa.html', 'x');
        file_put_contents($dir . '/bbb.html', 'x');
        file_put_contents($dir . '/.gitkeep', '');
        file_put_contents($dir . '/note.txt', 'keep');

        $deleted = HtmlCache::invalidate();

        $this->assertSame(2, $deleted);
        $this->assertFileDoesNotExist($dir . '/aaa.html');
        $this->assertFileExists($dir . '/.gitkeep');
        $this->assertFileExists($dir . '/note.txt');
    }

    public function testInvalidateWithPrefixOnlyMatchesPrefix(): void
    {
        $dir = $this->makeCacheDir();
        file_put_contents($dir . '/prod_1.html', 'x');
        file_put_contents($dir . '/news_1.html', 'x');

        $deleted = HtmlCache::invalidate('prod');

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($dir . '/prod_1.html');
        $this->assertFileExists($dir . '/news_1.html');
    }

    // ---- pruneExpired ----

    public function testPruneExpiredDeletesOnlyExpiredFiles(): void
    {
        $dir = $this->makeCacheDir();
        file_put_contents($dir . '/old.html', 'x');
        touch($dir . '/old.html', time() - 3600);
        file_put_contents($dir . '/fresh.html', 'x');

        $deleted = HtmlCache::pruneExpired(300);

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($dir . '/old.html');
        $this->assertFileExists($dir . '/fresh.html');
    }

    public function testPruneExpiredRespectsLimit(): void
    {
        $dir = $this->makeCacheDir();
        for ($i = 0; $i < 5; $i++) {
            $f = $dir . '/old' . $i . '.html';
            file_put_contents($f, 'x');
            touch($f, time() - 3600);
        }

        $deleted = HtmlCache::pruneExpired(300, 2);

        $this->assertSame(2, $deleted);
        $remaining = count(glob($dir . '/*.html') ?: []);
        $this->assertSame(3, $remaining);
    }

    public function testPruneExpiredOnMissingDirReturnsZero(): void
    {
        HtmlCache::setDir(sys_get_temp_dir() . '/yk_htmlcache_missing_' . bin2hex(random_bytes(4)));
        $this->assertSame(0, HtmlCache::pruneExpired(300));
    }
}
