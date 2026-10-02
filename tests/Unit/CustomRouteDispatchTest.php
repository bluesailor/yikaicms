<?php
/**
 * 登记网址分发的纯函数部分（2.0.4，WordPress 迁移第 2 步）：
 * 语言域名与登记写法互换、分页地址、文章标签整词匹配。
 */
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use ContentModel;
use LanguageDomains;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Yikai\Tests\TestCase;

#[RunTestsInSeparateProcesses]
final class CustomRouteDispatchTest extends TestCase
{
    protected function schemaSql(): array
    {
        return ['CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, tags TEXT DEFAULT "")'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT_PATH . '/includes/i18n/LanguageDomains.php';
        require_once ROOT_PATH . '/includes/product_routes.php';
        $GLOBALS['_test_config']['site_lang'] = 'en';
    }

    protected function tearDown(): void
    {
        LanguageDomains::setForTests(null);
        parent::tearDown();
    }

    public function testLanguageDomainRequestsLookUpThePrefixedRegisteredPath(): void
    {
        LanguageDomains::setForTests(['ja' => 'ja.example.com'], 'https://www.example.com', 'ja.example.com');
        self::assertSame('/ja/slewing-bearing/', customRouteLookupPath('/slewing-bearing/'));
        self::assertSame('/slewing-bearing/', customRoutePublicPath('/ja/slewing-bearing/'));
        self::assertSame('/en-only/', customRoutePublicPath('/en-only/'), '别的语言的登记写法原样返回');
    }

    public function testMainDomainKeepsPathsUnchanged(): void
    {
        LanguageDomains::setForTests(['ja' => 'ja.example.com'], 'https://www.example.com', 'www.example.com');
        self::assertSame('/ja/slewing-bearing/', customRouteLookupPath('/ja/slewing-bearing/'));
        self::assertSame('/ja/slewing-bearing/', customRoutePublicPath('/ja/slewing-bearing/'));
        LanguageDomains::setForTests([], 'https://www.example.com', 'www.example.com');
        self::assertSame('/tag/x/', customRouteLookupPath('/tag/x/'));
    }

    #[DataProvider('pages')]
    public function testPagedUrlFollowsTheBaseStyle(string $base, int $page, string $expected): void
    {
        self::assertSame($expected, pagedUrl($base, $page));
    }

    public static function pages(): array
    {
        return [
            'WordPress 斜杠' => ['/category/news/', 2, '/category/news/page/2/'],
            '第一页不变' => ['/category/news/', 1, '/category/news/'],
            '.html' => ['/en/news.html', 3, '/en/news/page/3.html'],
            '带查询串' => ['/tag/x/?keyword=a', 2, '/tag/x/page/2/?keyword=a'],
            '无后缀' => ['/products', 2, '/products/page/2/'],
        ];
    }

    public function testTagConditionMatchesWholeTagsOnly(): void
    {
        $hit = $this->insertRow('contents', ['title' => 'A', 'tags' => 'Worm Gear, slewing ring']);
        $this->insertRow('contents', ['title' => 'B', 'tags' => 'worm gearbox']);
        $cjk = $this->insertRow('contents', ['title' => 'C', 'tags' => '回转支承，worm gear']);
        $this->insertRow('contents', ['title' => 'D', 'tags' => 'worm_gear']);
        $ids = function (string $tag): array {
            $params = [];
            $where = ContentModel::tagCondition('tags', $tag, $params);
            return array_map('intval', array_column(db()->fetchAll('SELECT id FROM ' . DB_PREFIX . "contents WHERE {$where} ORDER BY id", $params), 'id'));
        };
        self::assertSame([$hit, $cjk], $ids('worm gear'), '整词、忽略大小写、全角逗号也算分隔');
        self::assertSame([$hit], $ids('slewing ring'));
        self::assertSame([], $ids('worm%'), '% 和 _ 不当通配符');
        self::assertSame([], $ids('worm gea_'));
    }
}
