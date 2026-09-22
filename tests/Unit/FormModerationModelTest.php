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
        return ['CREATE TABLE forms (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT, extra TEXT DEFAULT \'\')',
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE, value TEXT, `group` TEXT, name TEXT, type TEXT, tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)'];
    }

    private function add(string $ip, string $extra = ''): int { return (int) db()->insert('forms', ['ip' => $ip, 'extra' => $extra]); }

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

    public function testIpBulkDeleteUsesTheSameAttachmentLifecycle(): void
    {
        require_once ROOT_PATH . '/includes/FormUploadService.php';
        $tmp = tempnam(sys_get_temp_dir(), 'yk-form');
        file_put_contents($tmp, "%PDF-1.4\n%%EOF");
        $service = new \FormUploadService(null,
            static fn(string $path): bool => is_file($path),
            static fn(string $from, string $to): bool => rename($from, $to));
        $total = 0;
        $stored = $service->store(['name' => 'proof.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK],
            ['accept' => ['pdf'], 'max_size' => 1], $total);
        $path = $service->pathForReference($stored['reference']);
        self::assertNotNull($path);
        $id = $this->add('192.0.2.9', json_encode(['proof' => $stored['reference']], JSON_THROW_ON_ERROR));

        self::assertSame(1, (new FormModerationModel())->blockFromForm($id, true, 1, 1)['deleted']);
        self::assertFileDoesNotExist((string) $path);
    }

    public function testFailedBulkDeleteRestoresStagedAttachment(): void
    {
        require_once ROOT_PATH . '/includes/FormUploadService.php';
        $tmp = tempnam(sys_get_temp_dir(), 'yk-form');
        file_put_contents($tmp, "%PDF-1.4\n%%EOF");
        $service = new \FormUploadService(null,
            static fn(string $path): bool => is_file($path),
            static fn(string $from, string $to): bool => rename($from, $to));
        $total = 0;
        $stored = $service->store(['name' => 'proof.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK],
            ['accept' => ['pdf'], 'max_size' => 1], $total);
        $path = $service->pathForReference($stored['reference']);
        $id = $this->add('192.0.2.10', json_encode(['proof' => $stored['reference']], JSON_THROW_ON_ERROR));
        db()->execute("CREATE TRIGGER deny_delete_restore BEFORE DELETE ON forms BEGIN SELECT RAISE(ABORT, 'simulated restore'); END");
        try {
            (new FormModerationModel())->blockFromForm($id, true, 1, 1);
            self::fail('Expected delete failure');
        } catch (\PDOException $error) {
            self::assertStringContainsString('simulated restore', $error->getMessage());
        }
        self::assertFileExists((string) $path);
        $service->remove($stored['reference']);
    }

    public function testOuterTransactionIsRejectedBeforeAttachmentStaging(): void
    {
        require_once ROOT_PATH . '/includes/FormUploadService.php';
        $tmp = tempnam(sys_get_temp_dir(), 'yk-form');
        file_put_contents($tmp, "%PDF-1.4\n%%EOF");
        $service = new \FormUploadService(null,
            static fn(string $path): bool => is_file($path),
            static fn(string $from, string $to): bool => rename($from, $to));
        $total = 0;
        $stored = $service->store(['name' => 'proof.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK],
            ['accept' => ['pdf'], 'max_size' => 1], $total);
        $path = $service->pathForReference($stored['reference']);
        $id = $this->add('192.0.2.11', json_encode(['proof' => $stored['reference']], JSON_THROW_ON_ERROR));

        db()->beginTransaction();
        try {
            (new FormModerationModel())->blockFromForm($id, true, 1, 1);
            self::fail('Nested transaction accepted');
        } catch (RuntimeException $error) {
            self::assertSame('form_delete_transaction_active', $error->getMessage());
        } finally {
            if (db()->getPdo()->inTransaction()) db()->rollback();
        }
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM forms WHERE id = ?', [$id]));
        self::assertFileExists((string) $path);
        $service->remove($stored['reference']);
    }
}
