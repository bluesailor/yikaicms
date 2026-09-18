<?php
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use FormModerationModel;
use RuntimeException;
use Yikai\Tests\TestCase;

final class FormModerationModelTest extends TestCase
{
    protected function tearDown(): void
    {
        // Do not leak this reduced schema into tests sharing the in-memory DB.
        $this->resetDatabase();
        parent::tearDown();
    }

    protected function schemaSql(): array
    {
        return ['CREATE TABLE forms (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT)',
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE, value TEXT, `group` TEXT, name TEXT, type TEXT)'];
    }

    private function add(string $ip): int { return (int) db()->insert('forms', ['ip' => $ip]); }

    public function testCanonicalAddressesShareTheSameBlock(): void
    {
        self::assertSame('2001:db8::1', FormModerationModel::normalizeIp('2001:0DB8:0:0:0:0:0:1'));
        self::assertSame('192.0.2.1', FormModerationModel::normalizeIp('::ffff:192.0.2.1'));
        self::assertSame('', FormModerationModel::normalizeIp("1.2.3.4' OR 1=1"));
        $m = new FormModerationModel();
        $id = $this->add('::ffff:192.0.2.1');
        $this->add('192.0.2.1');
        self::assertFalse($m->isBlocked('192.0.2.1'));
        self::assertSame(2, $m->inspect($id)['count']);
        $m->blockFromForm($id, false, 2, 1);
        self::assertTrue($m->isBlocked('192.0.2.1'));
        self::assertTrue($m->isBlocked('::ffff:192.0.2.1'));
        self::assertFalse($m->isBlocked('192.0.2.2'));
        self::assertSame(2, (int) db()->fetchColumn('SELECT COUNT(*) FROM forms'));
        $m->unblock('192.0.2.1');
        self::assertFalse($m->isBlocked('192.0.2.1'));
    }

    public function testDeleteIncludesAllEquivalentIpRecordsButNotOtherIps(): void
    {
        $m = new FormModerationModel();
        $id = $this->add('2001:db8::1');
        $this->add('2001:0db8:0:0:0:0:0:1');
        $other = $this->add('2001:db8::2');
        self::assertSame(['ip' => '2001:db8::1', 'deleted' => 2], $m->blockFromForm($id, true, 2, 1));
        self::assertSame($other, (int) db()->fetchColumn('SELECT id FROM forms'));
        self::assertCount(1, $m->blockedIps());
        $m->unblock('2001:db8::1');
        self::assertSame([], $m->blockedIps());
    }

    public function testChangedCountRequiresAnotherConfirmationWithoutBlocking(): void
    {
        $m = new FormModerationModel();
        $id = $this->add('192.0.2.1');
        $this->add('192.0.2.1');
        try { $m->blockFromForm($id, true, 1, 1); self::fail('Stale confirmation accepted'); }
        catch (RuntimeException $e) { self::assertSame('form_ip_changed', $e->getMessage()); }
        self::assertFalse($m->isBlocked('192.0.2.1'));
        self::assertSame(2, (int) db()->fetchColumn('SELECT COUNT(*) FROM forms'));
    }

    public function testDeleteFailureRollsBackTheBlock(): void
    {
        $m = new FormModerationModel();
        $id = $this->add('192.0.2.1');
        db()->execute("CREATE TRIGGER deny_delete BEFORE DELETE ON forms BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        try { $m->blockFromForm($id, true, 1, 1); self::fail('Expected failure'); }
        catch (\PDOException $e) { self::assertStringContainsString('simulated failure', $e->getMessage()); }
        self::assertFalse($m->isBlocked('192.0.2.1'));
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM forms'));
    }

    public function testRepeatedBlockIsIdempotentAndInvalidIpIsRejected(): void
    {
        $m = new FormModerationModel();
        $id = $this->add('192.0.2.1');
        $m->blockFromForm($id, false, 1, 1);
        $m->blockFromForm($id, false, 1, 1);
        self::assertCount(1, $m->blockedIps());
        $bad = $this->add('unknown');
        $this->expectExceptionMessage('form_ip_invalid');
        $m->blockFromForm($bad, true, 1, 1);
    }
}
