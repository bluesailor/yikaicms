<?php
/**
 * 翻译工作中心（2.3.0 CORE-02）：状态页扩到文章、单页、产品分类；每格给「下一步」；
 * 审阅状态与是否发布分开——已审的草稿仍是「未发布」，下一步是发布，不能算已上线。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use TranslationCoverage;
use TranslationState;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/TranslationCoverage.php';

final class TranslationWorkCenterTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT, translation_group_id INTEGER DEFAULT 0, parent_id INTEGER DEFAULT 0, name TEXT, description TEXT DEFAULT '', content TEXT DEFAULT '', type TEXT DEFAULT 'list', status INTEGER DEFAULT 1)",
            "CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT, translation_group_id INTEGER DEFAULT 0, channel_id INTEGER DEFAULT 0, type TEXT DEFAULT 'article', title TEXT, summary TEXT DEFAULT '', content TEXT DEFAULT '', status INTEGER DEFAULT 1, deleted_at INTEGER DEFAULT NULL)",
            "CREATE TABLE product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER DEFAULT 0, lang TEXT, translation_group_id INTEGER DEFAULT 0, name TEXT, description TEXT DEFAULT '', status INTEGER DEFAULT 1)",
            "CREATE TABLE translation_links (id INTEGER PRIMARY KEY AUTOINCREMENT, table_name TEXT NOT NULL, target_id INTEGER NOT NULL, source_id INTEGER NOT NULL, lang TEXT NOT NULL,
                source_fp TEXT NOT NULL DEFAULT '', target_fp TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT 'machine', reviewed_by INTEGER NOT NULL DEFAULT 0, reviewed_at INTEGER NOT NULL DEFAULT 0,
                proposal_json TEXT, proposal_source_fp TEXT NOT NULL DEFAULT '', proposal_target_fp TEXT NOT NULL DEFAULT '', proposal_at INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
                UNIQUE (table_name, target_id))",
        ];
    }

    /** @return array<string,array{title:string,live:bool,cells:array<string,array<string,mixed>>,flags:array<string,bool>}> 按原文标题 */
    private function scan(string $kind): array
    {
        $rows = [];
        (new TranslationCoverage(db()))->scan('zh-CN', ['en'], static function (array $source, array $cells, array $flags) use (&$rows): void {
            $rows[$source['_title']] = ['title' => $source['_title'], 'live' => (int) $source['status'] === 1, 'cells' => $cells, 'flags' => $flags];
        }, '', false, $kind);
        return $rows;
    }

    /** 给一条译文记来历：$state = machine / reviewed；$sourceRow 传当时的原文 */
    private function track(string $table, array $source, array $target, string $state, string $title = 'title', string $summary = 'summary'): void
    {
        db()->insert('translation_links', [
            'table_name' => $table, 'target_id' => (int) $target['id'], 'source_id' => (int) $source['id'], 'lang' => 'en',
            'source_fp' => TranslationState::rowFingerprint($source, $title, $summary), 'target_fp' => TranslationState::rowFingerprint($target, $title, $summary),
            'state' => $state, 'created_at' => 1, 'updated_at' => 1,
        ]);
    }

    public function testNextActionPutsCorrectnessBeforePublishing(): void
    {
        $status = static fn (array $over = []): array => $over + ['state' => 'current', 'outdated' => false, 'edited' => false, 'reviewed' => false, 'proposal' => false, 'protected' => false];
        $cell = static fn (?array $status, bool $draft, int $id = 5): array => ['id' => $id, 'status' => $status, 'draft' => $draft];

        self::assertSame('translate', TranslationCoverage::nextAction($cell(null, false, 0)));
        self::assertSame('review', TranslationCoverage::nextAction($cell($status(['proposal' => true, 'outdated' => true]), true)), '有待审译文先审');
        self::assertSame('update', TranslationCoverage::nextAction($cell($status(['state' => 'outdated', 'outdated' => true]), true)), '原文改过：先更新，再发布');
        self::assertSame('publish', TranslationCoverage::nextAction($cell($status(['reviewed' => true]), true)), '已审的草稿：下一步是发布');
        self::assertSame('publish', TranslationCoverage::nextAction($cell($status(['state' => 'untracked']), true)));
        self::assertSame('check', TranslationCoverage::nextAction($cell($status(['state' => 'untracked']), false)), '已发布但来历不明：核对');
        self::assertSame('', TranslationCoverage::nextAction($cell($status(['reviewed' => true]), false)), '已审且已发布：没有要做的');
        self::assertSame('', TranslationCoverage::nextAction($cell($status(), false)));
        foreach (['translate', 'review', 'update', 'publish', 'check'] as $action) self::assertContains($action, TranslationCoverage::ACTIONS);
    }

    public function testArticlesKeepReviewAndPublicationSeparate(): void
    {
        db()->execute("INSERT INTO channels (id, lang, name, type) VALUES (1, 'zh-CN', '新闻', 'list')");
        db()->execute("INSERT INTO contents (id, lang, translation_group_id, channel_id, type, title, summary, status) VALUES
            (1, 'zh-CN', 1, 1, 'article', '已审未发布', '摘要', 1), (2, 'en', 1, 1, 'article', 'Reviewed draft', 'Summary', 0),
            (3, 'zh-CN', 3, 1, 'article', '已审已发布', '摘要', 1), (4, 'en', 3, 1, 'article', 'Reviewed live', 'Summary', 1),
            (5, 'zh-CN', 5, 1, 'article', '原文改过', '摘要', 1), (6, 'en', 5, 1, 'article', 'Outdated live', 'Summary', 1),
            (7, 'zh-CN', 7, 1, 'article', '没有译文', '摘要', 1),
            (8, 'zh-CN', 8, 1, 'article', '手工译文', '摘要', 1), (9, 'en', 8, 1, 'article', 'Hand made', 'Summary', 1),
            (10, 'zh-CN', 10, 1, 'page', '单页正文行', '摘要', 1)");
        $row = static fn (int $id): array => (array) db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'contents WHERE id = ?', [$id]);
        $this->track('contents', $row(1), $row(2), 'reviewed');
        $this->track('contents', $row(3), $row(4), 'reviewed');
        $this->track('contents', $row(5), $row(6), 'machine');
        db()->execute("UPDATE contents SET title = '原文改过（新）' WHERE id = 5");

        $rows = $this->scan('contents');
        self::assertArrayNotHasKey('单页正文行', $rows, '单页的正文行不算文章');

        $draft = $rows['已审未发布']['cells']['en'];
        self::assertTrue($draft['status']['reviewed'], '审阅状态照实显示');
        self::assertTrue($draft['draft'], '已审不等于已发布');
        self::assertSame('publish', TranslationCoverage::nextAction($draft));
        self::assertTrue($rows['已审未发布']['flags']['draft']);

        $live = $rows['已审已发布']['cells']['en'];
        self::assertSame([true, false, ''], [$live['status']['reviewed'], $live['draft'], TranslationCoverage::nextAction($live)]);

        $outdated = $rows['原文改过（新）']['cells']['en'];
        self::assertSame([true, false, 'update'], [$outdated['status']['outdated'], $outdated['draft'], TranslationCoverage::nextAction($outdated)], '已发布的过期译文：访客仍看得到，下一步是更新');

        self::assertSame('translate', TranslationCoverage::nextAction($rows['没有译文']['cells']['en']));
        self::assertTrue($rows['没有译文']['flags']['missing']);
        self::assertSame('check', TranslationCoverage::nextAction($rows['手工译文']['cells']['en']));
    }

    public function testStandalonePagesAreListedWithoutOtherChannels(): void
    {
        db()->execute("INSERT INTO channels (id, lang, translation_group_id, name, description, type, status) VALUES
            (1, 'zh-CN', 1, '关于我们', '', 'page', 1), (2, 'en', 1, 'About', '', 'page', 0),
            (3, 'zh-CN', 3, '新闻', '', 'list', 1), (4, 'en', 3, 'News', '', 'list', 1),
            (5, 'zh-CN', 5, '联系', '', 'page', 0)");
        // 单页的正文在栏目下的正文行里：指纹要把它算进去
        db()->execute("INSERT INTO contents (id, lang, channel_id, type, title, content, status) VALUES (1, 'zh-CN', 1, 'page', '关于我们', '<p>正文</p>', 1), (2, 'en', 2, 'page', 'About', '<p>Body</p>', 1)");
        $channel = static fn (int $id): array => (array) db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'channels WHERE id = ?', [$id]);
        $body = static fn (int $id): array => (array) db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'contents WHERE channel_id = ?', [$id]);
        db()->insert('translation_links', [
            'table_name' => 'channels', 'target_id' => 2, 'source_id' => 1, 'lang' => 'en',
            'source_fp' => TranslationState::rowFingerprint($channel(1), 'name', 'description', $body(1)),
            'target_fp' => TranslationState::rowFingerprint($channel(2), 'name', 'description', $body(2)),
            'state' => 'reviewed', 'created_at' => 1, 'updated_at' => 1,
        ]);

        $rows = $this->scan('pages');
        self::assertSame(['联系', '关于我们'], array_keys($rows), '只列单页；列表栏目不在这一类里');
        $about = $rows['关于我们']['cells']['en'];
        self::assertSame([true, false, true, 'publish'], [$about['status']['reviewed'], $about['status']['outdated'], $about['draft'], TranslationCoverage::nextAction($about)],
            '英文单页已审但栏目未启用：访客看不到，下一步是发布');
        self::assertFalse($rows['联系']['live'], '原文自己没启用也标出来');

        db()->execute("UPDATE contents SET content = '<p>正文改了</p>' WHERE id = 1");
        self::assertSame('update', TranslationCoverage::nextAction($this->scan('pages')['关于我们']['cells']['en']), '只改单页正文也算原文已改');
        self::assertCount(3, $this->scan('channels'), '交付清单用的「栏目」口径不变：单页与列表栏目都在');
    }

    public function testProductCategoriesAreCovered(): void
    {
        db()->execute("INSERT INTO product_categories (id, lang, translation_group_id, name, description, status) VALUES
            (1, 'zh-CN', 1, '泵', '各类泵', 1), (2, 'en', 1, 'Pumps', 'All pumps', 1),
            (3, 'zh-CN', 3, '阀', '', 1), (4, 'en', 3, 'Valves', '', 0),
            (5, 'zh-CN', 5, '电机', '', 1)");
        $row = static fn (int $id): array => (array) db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'product_categories WHERE id = ?', [$id]);
        $this->track('product_categories', $row(1), $row(2), 'machine', 'name', 'description');

        $rows = $this->scan('categories');
        self::assertSame(['电机', '阀', '泵'], array_keys($rows));
        self::assertSame('', TranslationCoverage::nextAction($rows['泵']['cells']['en']), '机器译文未改、已启用：没有要做的');
        self::assertSame([true, 'publish'], [$rows['阀']['cells']['en']['draft'], TranslationCoverage::nextAction($rows['阀']['cells']['en'])], '英文分类未启用');
        self::assertSame('translate', TranslationCoverage::nextAction($rows['电机']['cells']['en']));

        db()->execute("UPDATE product_categories SET description = '各类工业泵' WHERE id = 1");
        self::assertSame('update', TranslationCoverage::nextAction($this->scan('categories')['泵']['cells']['en']), '改分类描述也算原文已改');
    }

    /**
     * 大站翻页（2.3.0）：状态页走「内容校验值 + 指纹缓存」的轻量扫描，结果必须与整行扫描逐项一致；
     * 内容一改（哪怕 updated_at 没动）就要重新算，不能读到旧缓存。
     */
    public function testLightScanMatchesFullScanAndNoticesEdits(): void
    {
        db()->execute("INSERT INTO channels (id, lang, name, type) VALUES (1, 'zh-CN', '新闻', 'list')");
        $row = static fn (int $id): array => (array) db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'contents WHERE id = ?', [$id]);
        for ($i = 1; $i <= 30; $i++) {
            $source = $i * 2 - 1;
            db()->execute("INSERT INTO contents (id, lang, translation_group_id, channel_id, type, title, summary, content, status) VALUES (?, 'zh-CN', ?, 1, 'article', ?, '摘要', ?, ?)",
                [$source, $source, "文章 {$i}", "<p>正文 {$i}</p>", $i % 7 === 0 ? 0 : 1]);
            if ($i % 4 === 0) continue;   // 缺译文
            db()->execute("INSERT INTO contents (id, lang, translation_group_id, channel_id, type, title, summary, content, status) VALUES (?, 'en', ?, 1, 'article', ?, 'Summary', ?, ?)",
                [$source + 1, $source, "Article {$i}", "<p>Body {$i}</p>", $i % 3 === 0 ? 0 : 1]);
            if ($i % 5 !== 0) $this->track('contents', $row($source), $row($source + 1), $i % 2 === 0 ? 'reviewed' : 'machine');   // 其余未追踪
        }
        db()->execute("UPDATE contents SET title = title || '（改）' WHERE id IN (1, 13)");   // 两篇原文之后改过

        $view = static fn (bool $light, string $filter, int $page): array => (new TranslationCoverage(db(), $light))->page('zh-CN', ['en'], 'contents', $filter, '', $page, 10);
        foreach ([['all', 1], ['all', 3], ['missing', 1], ['draft', 1], ['outdated', 1], ['untracked', 1]] as [$filter, $page]) {
            self::assertSame($view(false, $filter, $page), $view(true, $filter, $page), "{$filter} 第 {$page} 页：两种扫描结果一致");
        }
        $totals = $view(true, 'all', 1)['totals'];
        self::assertSame([30, 7, 2], [$totals['all'], $totals['missing'], $totals['outdated']]);
        self::assertSame($totals, $view(true, 'all', 3)['totals'], '计数覆盖全部原文，与翻到第几页无关');
        self::assertCount(10, $view(true, 'all', 3)['rows']);
        self::assertSame(1, $view(true, 'outdated', 1)['matched'] - 1, '筛选后的总数');

        // 第二次（指纹已缓存）后改一篇原文的正文，不动别的列：下一次就要算成「原文已改」
        db()->execute("UPDATE contents SET content = '<p>正文改了</p>' WHERE id = 3");
        self::assertSame(3, $view(true, 'all', 1)['totals']['outdated']);
        self::assertSame($view(false, 'outdated', 1), $view(true, 'outdated', 1));
        // 改回去：又回到最新
        db()->execute("UPDATE contents SET content = '<p>正文 2</p>' WHERE id = 3");
        self::assertSame(2, $view(true, 'all', 1)['totals']['outdated']);
        // 搜索与发布状态照常
        self::assertSame(1, (new TranslationCoverage(db()))->page('zh-CN', ['en'], 'contents', 'all', '文章 30', 1, 10)['matched']);
    }
}
