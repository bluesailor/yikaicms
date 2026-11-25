<?php
/** 交付检查清单（评审 T09）：三类分开计、修复入口、检查摘要、人工确认的记录与撤销、缺译统计。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use DeliveryChecklist;
use InvalidArgumentException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/DeliveryChecklist.php';

final class DeliveryChecklistTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, \"group\" TEXT DEFAULT 'basic', \"key\" TEXT UNIQUE, value TEXT, type TEXT DEFAULT 'text', name TEXT DEFAULT '', tip TEXT DEFAULT '', options TEXT, sort_order INT DEFAULT 0)",
            "CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT, translation_group_id INTEGER DEFAULT 0, title TEXT, summary TEXT DEFAULT '', content TEXT DEFAULT '', status INTEGER DEFAULT 1, deleted_at INTEGER DEFAULT NULL)",
        ];
    }

    private function createTranslationLinks(): void
    {
        db()->execute("CREATE TABLE translation_links (id INTEGER PRIMARY KEY AUTOINCREMENT, table_name TEXT NOT NULL, target_id INTEGER NOT NULL, source_id INTEGER NOT NULL, lang TEXT NOT NULL,
            source_fp TEXT NOT NULL DEFAULT '', target_fp TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT 'machine', reviewed_by INTEGER NOT NULL DEFAULT 0, reviewed_at INTEGER NOT NULL DEFAULT 0,
            proposal_json TEXT, proposal_source_fp TEXT NOT NULL DEFAULT '', proposal_target_fp TEXT NOT NULL DEFAULT '', proposal_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL)");
    }

    /** @param array<string,array{missing:int,draft:int,outdated:int}> $missing @return array<string,mixed> */
    private static function facts(bool $multilingual = false, array $missing = ['en' => ['missing' => 0, 'draft' => 0, 'outdated' => 0]]): array
    {
        return [
            'setup' => [
                ['label' => 'setup_brand', 'url' => '/admin/setting.php?tab=basic', 'done' => true],
                ['label' => 'setup_contact', 'url' => '/admin/setting_contact.php', 'done' => false],
            ],
            'description' => false,
            'languages' => $multilingual ? ['en' => 'English'] : [],
            'translation_gaps' => $multilingual ? $missing : [],
            'latest_inquiry' => 0,
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private static function byKey(array $rows): array
    {
        return array_column($rows, null, 'key');
    }

    public function testFreshSiteSeparatesPresentDataChecksAndConfirmations(): void
    {
        $list = DeliveryChecklist::evaluate(self::facts(), ['accept' => [], 'run' => []]);
        $data = self::byKey($list['data']);
        self::assertSame('done', $data['setup_brand']['status']);
        self::assertSame('todo', $data['setup_contact']['status']);
        self::assertSame('/admin/setting_contact.php', $data['setup_contact']['url'], '每项带修复入口');
        self::assertSame('todo', $data['description']['status']);
        self::assertArrayNotHasKey('translations', $data, '单语言站没有译文项');
        foreach ($list['check'] as $row) {
            self::assertSame('unknown', $row['status'], '没运行过检查就是「未检查」，不是通过');
            self::assertSame('dlv_not_run', $row['detail']);
        }
        self::assertSame(['inquiry_test', 'mobile_review', 'handover'], array_column($list['accept'], 'key'), '单语言站没有多语言浏览项');
        self::assertSame('dlv_no_inquiry', self::byKey($list['accept'])['inquiry_test']['detail']);
        self::assertSame(6, $list['open'], '缺联系方式、缺描述、两项未检查、两项未确认；交付本身不计');
    }

    public function testRunSummaryDrivesCheckStatuses(): void
    {
        $health = [['id' => 'debug', 'title' => 'Debug mode', 'status' => 'critical', 'action_url' => '/admin/setting.php'],
            ['id' => 'https', 'title' => 'HTTPS', 'status' => 'recommended'], ['id' => 'php', 'title' => 'PHP', 'status' => 'good'], ['id' => 'x', 'status' => 'weird']];
        for ($i = 0; $i < 12; $i++) $health[] = ['id' => 'c' . $i, 'title' => 'C' . $i, 'status' => 'critical'];
        $content = ['issues' => [['level' => 'error'], ['level' => 'review'], ['level' => 'review']], 'scanned' => 40, 'limited' => false];
        $run = DeliveryChecklist::summarizeRun($health, $content, 1800000000);
        self::assertSame(['critical' => 13, 'recommended' => 1, 'good' => 1, 'unknown' => 1], array_intersect_key($run['health'], array_flip(['critical', 'recommended', 'good', 'unknown'])));
        self::assertCount(10, $run['health']['issues'], '严重项只留前 10 条');
        self::assertSame(['id' => 'debug', 'title' => 'Debug mode', 'url' => '/admin/setting.php'], $run['health']['issues'][0]);
        self::assertSame(['error' => 1, 'review' => 2, 'scanned' => 40, 'limited' => false], $run['content']);

        $check = self::byKey(DeliveryChecklist::evaluate(self::facts(), ['accept' => [], 'run' => $run])['check']);
        self::assertSame('fail', $check['health']['status']);
        self::assertSame(['critical' => '13', 'recommended' => '1'], $check['health']['params']);
        self::assertSame('fail', $check['content']['status']);

        $clean = DeliveryChecklist::summarizeRun([['id' => 'https', 'status' => 'recommended']], ['issues' => [['level' => 'review']], 'scanned' => 3], 1800000000);
        $check = self::byKey(DeliveryChecklist::evaluate(self::facts(), ['accept' => [], 'run' => $clean])['check']);
        self::assertSame(['warn', 'warn'], [$check['health']['status'], $check['content']['status']], '只有建议 / 待看项：提示但不算失败');
        $pass = DeliveryChecklist::summarizeRun([['id' => 'php', 'status' => 'good']], ['issues' => [], 'scanned' => 3], 1800000000);
        $check = self::byKey(DeliveryChecklist::evaluate(self::facts(), ['accept' => [], 'run' => $pass])['check']);
        self::assertSame(['done', 'done'], [$check['health']['status'], $check['content']['status']]);
    }

    public function testConfirmationsAreRecordedRevokedAndRestricted(): void
    {
        DeliveryChecklist::accept('inquiry_test', 7, 'alice', 1800000000);
        DeliveryChecklist::saveRun(['at' => 1800000100, 'health' => ['critical' => 0], 'content' => ['error' => 0]]);
        $state = DeliveryChecklist::state();
        self::assertSame(['admin_id' => 7, 'name' => 'alice', 'at' => 1800000000], $state['accept']['inquiry_test']);
        self::assertSame(1800000100, $state['run']['at'], '保存检查结果不丢已有确认');
        $accept = self::byKey(DeliveryChecklist::evaluate(self::facts(), $state)['accept']);
        self::assertSame('done', $accept['inquiry_test']['status']);
        self::assertSame('alice', $accept['inquiry_test']['by']['name']);

        DeliveryChecklist::revoke('inquiry_test');
        self::assertArrayNotHasKey('inquiry_test', DeliveryChecklist::state()['accept']);
        self::assertSame(1800000100, DeliveryChecklist::state()['run']['at']);

        $this->expectException(InvalidArgumentException::class);
        DeliveryChecklist::accept('publish_everything', 7, 'alice', 1800000000);
    }

    public function testHandoverCanBeConfirmedButDoesNotHideOpenItems(): void
    {
        $state = ['accept' => ['handover' => ['admin_id' => 1, 'name' => 'admin', 'at' => 1800000000]], 'run' => []];
        $list = DeliveryChecklist::evaluate(self::facts(), $state);
        self::assertSame('done', self::byKey($list['accept'])['handover']['status']);
        self::assertSame(6, $list['open'], '交付已确认，其他未完成项照样计数');
    }

    public function testMultilingualSitesCountMissingUnpublishedAndOutdatedTranslations(): void
    {
        db()->execute("INSERT INTO products (id, lang, translation_group_id, title, status) VALUES (1, 'zh-CN', 0, 'A', 1), (2, 'zh-CN', 2, 'B', 1), (3, 'zh-CN', 0, 'Draft', 0)");
        db()->execute("INSERT INTO products (id, lang, translation_group_id, title, status) VALUES (4, 'en', 1, 'A en', 0), (5, 'ja', 2, 'B ja', 1), (6, 'ja', 1, 'A ja (deleted)', 1)");
        db()->execute('UPDATE products SET deleted_at = 1 WHERE id = 6');
        // 程序已更新、数据库还没升级（没有来源追踪表）：照常算缺失与未发布
        self::assertSame([
            'en' => ['missing' => 1, 'draft' => 1, 'outdated' => 0],
            'ja' => ['missing' => 1, 'draft' => 0, 'outdated' => 0],
        ], DeliveryChecklist::translationGaps('zh-CN', ['en', 'ja']),
            '只看已发布的源产品；草稿译文前台看不到，不算就绪（路线图 CORE-02）；回收站里的译文不算');

        // 原文在机器翻译之后改过：已发布的译文算过期；来历不明的旧译文不算
        $this->createTranslationLinks();
        (new \TranslationState(db()))->recordMachine('products', 5, 2, 'ja', 'fingerprint-of-an-older-source',
            \TranslationState::rowFingerprint(productModel()->find(5), 'title', 'summary'), 1800000000);
        self::assertSame(['missing' => 1, 'draft' => 0, 'outdated' => 1], DeliveryChecklist::translationGaps('zh-CN', ['ja'])['ja']);

        $facts = self::facts(true);
        self::assertSame('done', self::byKey(DeliveryChecklist::evaluate($facts, ['accept' => [], 'run' => []])['data'])['translations']['status']);
        $facts['translation_gaps'] = ['en' => ['missing' => 0, 'draft' => 2, 'outdated' => 1]];
        $list = DeliveryChecklist::evaluate($facts, ['accept' => [], 'run' => []]);
        $row = self::byKey($list['data'])['translations'];
        self::assertSame(['todo', '/admin/translation_status.php?filter=draft', 'dlv_translations_gaps', 'English 0/2/1'],
            [$row['status'], $row['url'], $row['detail'], $row['params']['list']], '只有草稿和过期也不算完成；入口指向最先要处理的一类');
        $facts['translation_gaps'] = ['en' => ['missing' => 3, 'draft' => 2, 'outdated' => 0]];
        $row = self::byKey(DeliveryChecklist::evaluate($facts, ['accept' => [], 'run' => []])['data'])['translations'];
        self::assertSame('/admin/translation_status.php?filter=missing', $row['url']);
        self::assertContains('language_review', array_column($list['accept'], 'key'));
    }

    /** 2.3.0：文章等内容、栏目与单页的译文也计入，单页的正文行不单独算一条内容 */
    public function testArticlesAndChannelsAreCountedSeparatelyFromProducts(): void
    {
        db()->execute("CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT, translation_group_id INTEGER DEFAULT 0, parent_id INTEGER DEFAULT 0, name TEXT, description TEXT DEFAULT '', content TEXT DEFAULT '', type TEXT DEFAULT 'list', status INTEGER DEFAULT 1)");
        db()->execute("CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT, translation_group_id INTEGER DEFAULT 0, channel_id INTEGER DEFAULT 0, type TEXT DEFAULT 'article', title TEXT, summary TEXT DEFAULT '', content TEXT DEFAULT '', status INTEGER DEFAULT 1, deleted_at INTEGER DEFAULT NULL)");
        try {
            // 栏目：「关于」单页有已发布的英文版；「新闻」没有英文版
            db()->execute("INSERT INTO channels (id, lang, translation_group_id, name, type, status) VALUES (1, 'zh-CN', 0, '关于', 'page', 1), (2, 'zh-CN', 0, '新闻', 'list', 1), (3, 'en', 1, 'About', 'page', 1), (4, 'zh-CN', 0, '未启用', 'list', 0)");
            // 内容：单页正文行（不算内容）；一篇已发布文章，英文版是草稿；一篇回收站里的文章
            db()->execute("INSERT INTO contents (id, lang, translation_group_id, channel_id, type, title, status) VALUES (1, 'zh-CN', 0, 1, 'page', '关于正文', 1), (2, 'en', 1, 3, 'page', 'About body', 1), (3, 'zh-CN', 0, 2, 'article', '开业', 1), (4, 'en', 3, 2, 'article', 'Opening', 0), (5, 'zh-CN', 0, 2, 'article', '已删', 1)");
            db()->execute('UPDATE contents SET deleted_at = 1 WHERE id = 5');

            self::assertSame(['en' => ['missing' => 0, 'draft' => 1, 'outdated' => 0]], DeliveryChecklist::translationGaps('zh-CN', ['en'], 'contents'),
                '单页正文行与回收站里的文章不算；草稿译文算未发布');
            self::assertSame(['en' => ['missing' => 1, 'draft' => 0, 'outdated' => 0]], DeliveryChecklist::translationGaps('zh-CN', ['en'], 'channels'),
                '「新闻」缺英文版；未启用的栏目不需要译文');
        } finally {
            db()->execute('DROP TABLE channels');
            db()->execute('DROP TABLE contents');
        }

        $facts = self::facts(true) + [
            'content_translation_gaps' => ['en' => ['missing' => 0, 'draft' => 1, 'outdated' => 0]],
            'page_translation_gaps' => ['en' => ['missing' => 0, 'draft' => 0, 'outdated' => 0]],
            'section_translation_gaps' => ['en' => ['missing' => 0, 'draft' => 0, 'outdated' => 0]],
            'section_first_gap' => 0,
            'category_translation_gaps' => ['en' => ['missing' => 0, 'draft' => 0, 'outdated' => 0]],
        ];
        $data = self::byKey(DeliveryChecklist::evaluate($facts, ['accept' => [], 'run' => []])['data']);
        self::assertSame('done', $data['translations']['status']);
        self::assertSame(['todo', '/admin/translation_status.php?kind=contents&filter=draft', 'English 0/1/0'],
            [$data['translations_contents']['status'], $data['translations_contents']['url'], $data['translations_contents']['params']['list']],
            '产品译文齐了、文章译文还是草稿：清单不能显示就绪；入口进工作中心的文章一栏、筛出草稿（2.3.1）');
        self::assertSame(['done', 'done', 'done'], [$data['translations_pages']['status'], $data['translations_sections']['status'], $data['translations_categories']['status']]);
    }

    /**
     * 2.3.1（11-26 评审）：产品分类单列——日文产品已发布、日文分类缺失时清单要报出来；
     * 每类问题的入口直达要改的地方：工作中心对应一栏按最先要处理的一类筛选，普通栏目进第一个要处理的栏目。
     */
    public function testCategoryGapsAndDirectLinksToTheRecordsToFix(): void
    {
        db()->execute("CREATE TABLE product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT, translation_group_id INTEGER DEFAULT 0, parent_id INTEGER DEFAULT 0, name TEXT, description TEXT DEFAULT '', status INTEGER DEFAULT 1)");
        db()->execute("CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT, translation_group_id INTEGER DEFAULT 0, parent_id INTEGER DEFAULT 0, name TEXT, description TEXT DEFAULT '', content TEXT DEFAULT '', type TEXT DEFAULT 'list', status INTEGER DEFAULT 1)");
        db()->execute("CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT, translation_group_id INTEGER DEFAULT 0, channel_id INTEGER DEFAULT 0, type TEXT DEFAULT 'article', title TEXT, summary TEXT DEFAULT '', content TEXT DEFAULT '', status INTEGER DEFAULT 1, deleted_at INTEGER DEFAULT NULL)");
        try {
            // 产品：日文版已发布，产品译文齐了
            db()->execute("INSERT INTO products (id, lang, translation_group_id, title, status) VALUES (1, 'zh-CN', 1, '回转支承', 1), (2, 'ja', 1, '旋回輪', 1)");
            // 分类：「泵」没有日文版；「阀」的日文版是草稿；未启用的分类不需要译文
            db()->execute("INSERT INTO product_categories (id, lang, translation_group_id, name, status) VALUES (1, 'zh-CN', 0, '泵', 1), (2, 'zh-CN', 2, '阀', 1), (3, 'ja', 2, 'バルブ', 0), (4, 'zh-CN', 0, '停用', 0)");
            // 栏目：「关于」单页有日文版；普通栏目「新闻」「案例」都缺日文版
            db()->execute("INSERT INTO channels (id, lang, translation_group_id, name, type, status) VALUES (5, 'zh-CN', 0, '关于', 'page', 1), (6, 'ja', 5, '会社概要', 'page', 1), (7, 'zh-CN', 0, '新闻', 'list', 1), (8, 'zh-CN', 0, '案例', 'list', 1)");

            self::assertSame(['ja' => ['missing' => 0, 'draft' => 0, 'outdated' => 0]], DeliveryChecklist::translationGaps('zh-CN', ['ja']), '产品译文齐了');
            self::assertSame(['ja' => ['missing' => 1, 'draft' => 1, 'outdated' => 0]], DeliveryChecklist::translationGaps('zh-CN', ['ja'], 'categories'),
                '「泵」缺日文分类、「阀」的日文分类未发布；停用的分类不算');
            self::assertSame(['ja' => ['missing' => 0, 'draft' => 0, 'outdated' => 0]], DeliveryChecklist::translationGaps('zh-CN', ['ja'], 'pages'));
            self::assertSame(['ja' => ['missing' => 2, 'draft' => 0, 'outdated' => 0]], DeliveryChecklist::translationGaps('zh-CN', ['ja'], 'sections'), '普通栏目不含单页');

            $sections = (new \TranslationCoverage(db()))->gaps('zh-CN', ['ja'], 'sections');
            self::assertSame(8, $sections['first'], '第一个要处理的普通栏目：与翻译工作中心同一顺序，新建的在前');
            $facts = self::facts(true) + [
                'content_translation_gaps' => DeliveryChecklist::translationGaps('zh-CN', ['ja'], 'contents'),
                'page_translation_gaps' => DeliveryChecklist::translationGaps('zh-CN', ['ja'], 'pages'),
                'category_translation_gaps' => DeliveryChecklist::translationGaps('zh-CN', ['ja'], 'categories'),
                'section_translation_gaps' => $sections['gaps'],
                'section_first_gap' => $sections['first'],
            ];
            $facts['translation_gaps'] = DeliveryChecklist::translationGaps('zh-CN', ['ja']);
        } finally {
            foreach (['product_categories', 'channels', 'contents'] as $table) db()->execute('DROP TABLE ' . $table);
        }

        $facts['languages'] = ['ja' => '日本語'];
        $data = self::byKey(DeliveryChecklist::evaluate($facts, ['accept' => [], 'run' => []])['data']);
        self::assertSame('done', $data['translations']['status']);
        self::assertSame(['todo', '/admin/translation_status.php?kind=categories&filter=missing', '日本語 1/1/0'],
            [$data['translations_categories']['status'], $data['translations_categories']['url'], $data['translations_categories']['params']['list']],
            '产品都译了、分类缺译：清单报出分类缺口，入口进工作中心的分类一栏');
        self::assertSame(['todo', '/admin/channel.php?edit=8&tab=main'], [$data['translations_sections']['status'], $data['translations_sections']['url']],
            '普通栏目的问题进那个栏目的设置页');
        self::assertSame('done', $data['translations_pages']['status']);
        self::assertArrayNotHasKey('translations_channels', $data, '「栏目与单页」一条已拆成单页与栏目两条');

        $facts['page_translation_gaps'] = ['ja' => ['missing' => 0, 'draft' => 0, 'outdated' => 2]];
        $data = self::byKey(DeliveryChecklist::evaluate($facts, ['accept' => [], 'run' => []])['data']);
        self::assertSame('/admin/translation_status.php?kind=pages&filter=outdated', $data['translations_pages']['url'], '只有过期的单页：筛出过期');
    }
}
