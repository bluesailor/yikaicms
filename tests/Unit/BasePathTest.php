<?php
/**
 * 子目录部署挂载点的契约。
 *
 * 最要紧的一条放在最前面：**装在根目录时一切都是空操作**。
 * 这个类挂在每个前台请求的最前面，根目录站点只要有一个字节的差异就是全站回归。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BasePath;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/BasePath.php';

final class BasePathTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/yk-basepath-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/site/admin', 0777, true);
        touch($this->tmp . '/site/index.php');
        touch($this->tmp . '/site/admin/setting.php');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp . '/site/admin/setting.php');
        @unlink($this->tmp . '/site/index.php');
        @rmdir($this->tmp . '/site/admin');
        @rmdir($this->tmp . '/site');
        @rmdir($this->tmp);
    }

    /** @return array<string,string> */
    private function server(string $scriptName, string $relativeFile): array
    {
        return ['SCRIPT_NAME' => $scriptName, 'SCRIPT_FILENAME' => $this->tmp . '/site' . $relativeFile];
    }

    // ── 根目录：一切为空操作 ─────────────────────────────────────────

    public function testRootInstallDetectsNoPrefix(): void
    {
        self::assertSame('', BasePath::detect($this->server('/index.php', '/index.php'), $this->tmp . '/site'));
        self::assertSame('', BasePath::detect($this->server('/admin/setting.php', '/admin/setting.php'), $this->tmp . '/site'));
    }

    public function testRootInstallLeavesHtmlByteIdentical(): void
    {
        $html = '<html><head><title>x</title></head><body><a href="/product.html">p</a>'
            . '<img src="/uploads/a.jpg" srcset="/uploads/a.jpg 1x, /uploads/b.jpg 2x">'
            . '<div style="background:url(/uploads/bg.jpg)"></div></body></html>';
        self::assertSame($html, BasePath::rewriteHtml($html, ''), '根目录下出口改写必须逐字节不变');
        self::assertSame('/uploads/a.jpg', BasePath::prefixLocation('/uploads/a.jpg', ''));
        self::assertSame('/x?q=1', BasePath::strip('/x?q=1', ''));
    }

    // ── 自动推导 ─────────────────────────────────────────────────

    public function testSubdirectoryIsDetectedFromAnyEntryScript(): void
    {
        $root = $this->tmp . '/site';
        self::assertSame('/sub', BasePath::detect($this->server('/sub/index.php', '/index.php'), $root));
        self::assertSame('/sub', BasePath::detect($this->server('/sub/admin/setting.php', '/admin/setting.php'), $root),
            '后台入口推出的前缀必须与前台一致');
        self::assertSame('/a/b.c', BasePath::detect($this->server('/a/b.c/index.php', '/index.php'), $root), '多级子目录');
    }

    /** 对不上就按根目录处理：宁可不改，不可改错。 */
    public function testMismatchedServerParamsFallBackToRoot(): void
    {
        $root = $this->tmp . '/site';
        self::assertSame('', BasePath::detect($this->server('/sub/other.php', '/index.php'), $root), '脚本名尾段对不上文件');
        self::assertSame('', BasePath::detect([], $root), '缺参数');
        self::assertSame('', BasePath::detect(['SCRIPT_NAME' => '/sub/index.php', 'SCRIPT_FILENAME' => '/nonexistent/index.php'], $root), '文件不存在');
        self::assertSame('', BasePath::detect(['SCRIPT_NAME' => '/x.php', 'SCRIPT_FILENAME' => __FILE__], $root), '入口不在安装目录内');
    }

    // ── 规范化：前缀要写进 HTML 与 JS，字符集必须收紧 ──────────────────

    public function testNormalizeKeepsOnlySafePrefixes(): void
    {
        foreach (['/sub' => '/sub', 'sub' => '/sub', '/sub/' => '/sub', '/a/b/' => '/a/b', ' /x ' => '/x',
            'yikai-zhuangshi.yikai' => '/yikai-zhuangshi.yikai'] as $in => $out) {
            self::assertSame($out, BasePath::normalize($in), var_export($in, true));
        }
        // 首尾空白（含换行）按配置书写习惯剪掉是安全的；夹在中间的控制字符必须拒绝
        self::assertSame('/sub', BasePath::normalize("/sub\n"));
        foreach (['', '/', '/sub"', '/sub<x>', '/s b', "/su\nb", "/su\tb", '/a/../b', '/./a', '/中文', '/a?b', '/a#b'] as $bad) {
            self::assertSame('', BasePath::normalize($bad), var_export($bad, true) . ' 必须按根目录处理');
        }
    }

    // ── 入口剥前缀 ─────────────────────────────────────────────────

    public function testStripRemovesOnlyAWholeLeadingSegment(): void
    {
        self::assertSame('/', BasePath::strip('/sub', '/sub'));
        self::assertSame('/', BasePath::strip('/sub/', '/sub'));
        self::assertSame('/product.html', BasePath::strip('/sub/product.html', '/sub'));
        self::assertSame('/?yk_route=product', BasePath::strip('/sub?yk_route=product', '/sub'));
        self::assertSame('/en/x.html?a=1', BasePath::strip('/sub/en/x.html?a=1', '/sub'));
        self::assertSame('/subway/x', BasePath::strip('/subway/x', '/sub'), '前缀必须按整段匹配，/subway 不属于 /sub');
        self::assertSame('/other/x', BasePath::strip('/other/x', '/sub'));
    }

    // ── 出口补前缀 ─────────────────────────────────────────────────

    public function testRootRelativeUrlsGainThePrefixOnce(): void
    {
        $out = BasePath::rewriteHtml(
            '<a href="/p.html">a</a><img src="/u/a.jpg"><form action="/form_submit.php"></form>'
            . '<link rel="stylesheet" href="/assets/c.css"><script src="/assets/j.js"></script>'
            . '<button formaction="/x.php"></button><video poster="/u/p.jpg"></video>',
            '/sub'
        );
        foreach (['href="/sub/p.html"', 'src="/sub/u/a.jpg"', 'action="/sub/form_submit.php"',
            'href="/sub/assets/c.css"', 'src="/sub/assets/j.js"', 'formaction="/sub/x.php"', 'poster="/sub/u/p.jpg"'] as $expected) {
            self::assertStringContainsString($expected, $out);
        }
        self::assertStringNotContainsString('/sub/sub/', $out);
    }

    public function testNonRootRelativeUrlsAreUntouched(): void
    {
        $html = '<a href="https://ex.com/x">1</a><a href="//cdn.ex.com/x">2</a><a href="#top">3</a>'
            . '<a href="mailto:a@b.c">4</a><a href="tel:123">5</a><a href="relative/x">6</a><a href="/\\evil">7</a>'
            . '<img src="data:image/gif;base64,R0lGOD">';
        self::assertSame($html, BasePath::rewriteHtml($html, '/sub'));
    }

    /** 同一个值恰好以挂载目录名开头时也要补——栏目别名与挂载目录同名是合法的。 */
    public function testPathsThatHappenToStartWithTheMountNameStillGainThePrefix(): void
    {
        self::assertStringContainsString('href="/shop/shop/item.html"',
            BasePath::rewriteHtml('<a href="/shop/item.html">x</a>', '/shop'));
    }

    public function testSrcsetStyleAndMetaAreRewritten(): void
    {
        $out = BasePath::rewriteHtml(
            '<img srcset="/a.jpg 1x, /b.jpg 2x, https://cdn/c.jpg 3x">'
            . '<div style="background:url(/bg.jpg);mask:url(\'/m.svg\')"></div>'
            . '<meta property="og:image" content="/og.jpg"><meta name="viewport" content="width=device-width">',
            '/sub'
        );
        self::assertStringContainsString('srcset="/sub/a.jpg 1x, /sub/b.jpg 2x, https://cdn/c.jpg 3x"', $out);
        self::assertStringContainsString('url(/sub/bg.jpg)', $out);
        // 写回属性时改写器按既有约定转义引号（' → &#039;），浏览器先解码实体再解析 CSS，语义不变
        self::assertStringContainsString("url('/sub/m.svg')", html_entity_decode($out, ENT_QUOTES));
        self::assertStringContainsString('content="/sub/og.jpg"', $out);
        self::assertStringContainsString('content="width=device-width"', $out);
    }

    /** script/style 的原始文本不是属性，改写器整体跳过；内联脚本在生成处用 url() 自己补。 */
    public function testScriptBodiesAreNotRewritten(): void
    {
        $out = BasePath::rewriteHtml('<script>fetch("/api/x")</script><a href="/y">y</a>', '/sub');
        self::assertStringContainsString('fetch("/api/x")', $out);
        self::assertStringContainsString('href="/sub/y"', $out);
    }

    /** 实体编码必须对称：读出来解码、写回去重新转义，不能把 &amp; 变成 &。 */
    public function testEntityEncodedQueryStringsSurvive(): void
    {
        $out = BasePath::rewriteHtml('<a href="/s.php?a=1&amp;b=2">x</a>', '/sub');
        self::assertStringContainsString('href="/sub/s.php?a=1&amp;b=2"', $out);
    }

    public function testBaseVariableIsInjectedForStaticScripts(): void
    {
        $out = BasePath::rewriteHtml('<!doctype html><html><head lang="zh"><meta charset="utf-8"></head><body></body></html>', '/sub');
        self::assertStringContainsString('<head lang="zh"><script>window.YK_BASE="/sub";', $out, '紧跟 <head>，早于所有脚本');
        self::assertStringContainsString('</script><meta charset="utf-8">', $out);
        // 内容数据里的图片按约定不带前缀，显示端兜底重试；只认程序自有目录，且带防循环标记
        self::assertStringContainsString('(?:uploads|assets|plugins|themes)', $out);
        self::assertStringContainsString('data-yk-base', $out);
        self::assertStringNotContainsString('YK_BASE', BasePath::rewriteHtml('<div>fragment</div>', '/sub'), '无 <head> 的片段不注入');
    }

    public function testRedirectTargets(): void
    {
        self::assertSame('/sub/about/company.html', BasePath::prefixLocation('/about/company.html', '/sub'));
        self::assertSame('https://ex.com/x', BasePath::prefixLocation('https://ex.com/x', '/sub'));
        self::assertSame('//ex.com/x', BasePath::prefixLocation('//ex.com/x', '/sub'));
        self::assertSame('relative.html', BasePath::prefixLocation('relative.html', '/sub'));
    }

    public function testUrlHelperFollowsTheSameRule(): void
    {
        // url() 读的是本进程的挂载点；CLI 下恒为根目录，所以任何输入都原样返回
        self::assertSame('/form_submit.php', BasePath::url('/form_submit.php'));
    }

    /**
     * 输出缓冲只改静态属性；Alpine 绑定和内联事件里的地址在浏览器里才拼出来，
     * 写死根路径就会丢掉挂载点（编辑器"管理菜单内容"曾指向站点根的 /admin/nav_menu.php）。
     */
    public function testBrowserEvaluatedAttributesCarryTheMountPrefix(): void
    {
        $attr = '/\s(?::[a-z-]+|x-bind:[a-z-]+|@[a-z.-]+|x-on:[a-z.-]+|x-init|x-data|on[a-z]+)="([^"]*)"/';
        $offenders = [];
        foreach (['admin', 'includes', 'member', 'plugins', 'themes', 'views'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT_PATH . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                preg_match_all($attr, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($matches as $m) {
                    // :placeholder 只是提示文字，不会被请求
                    if (str_starts_with(ltrim($m[0][0]), ':placeholder')) {
                        continue;
                    }
                    $js = str_replace("\\'", "'", $m[1][0]);
                    // 形如 '/admin/…'、'/captcha.php' 的路径；正则字面量 /'/g 之类不算
                    if (preg_match("/['`]\/[a-z_][a-z0-9_-]*[\/.?]/i", $js) === 1 && !str_contains($js, 'YK_BASE')) {
                        $line = substr_count($source, "\n", 0, $m[0][1]) + 1;
                        $offenders[] = substr($file->getPathname(), strlen(ROOT_PATH) + 1) . ':' . $line;
                    }
                }
            }
        }
        self::assertSame([], $offenders, '浏览器里拼出的根路径要加 (window.YK_BASE || \'\')，或复用服务端已改写的地址');
    }
}
