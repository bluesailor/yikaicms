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
}
