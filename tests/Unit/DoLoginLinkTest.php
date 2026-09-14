<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use DoLoginLinkModel;
use Migrator;
use Yikai\Tests\TestCase;

final class DoLoginLinkTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, password TEXT, role_id INTEGER, status INTEGER, totp_secret TEXT)',
            'CREATE TABLE roles (id INTEGER PRIMARY KEY, status INTEGER, permissions TEXT)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ENCRYPT_KEY')) define('ENCRYPT_KEY', 'dologin-test-key-32-characters-long');
        require_once ROOT_PATH . '/includes/Migrator.php';
        $migration = array_values(array_filter(Migrator::loadAll(), static fn(array $m): bool => $m['id'] === '20260914_dologin_links'))[0];
        self::assertTrue(Migrator::runOne($migration)['ok']);
        self::assertTrue(Migrator::isApplied($migration));
        self::assertTrue(Migrator::runOne($migration)['ok']);
        db()->insert('roles', ['id' => 1, 'status' => 1, 'permissions' => '["*"]']);
        db()->insert('roles', ['id' => 2, 'status' => 1, 'permissions' => '["content"]']);
        db()->insert('users', ['id' => 1, 'username' => 'owner', 'password' => 'owner-password-hash', 'role_id' => 1, 'status' => 1, 'totp_secret' => '']);
        db()->insert('users', ['id' => 2, 'username' => 'editor', 'password' => 'editor-password-hash', 'role_id' => 2, 'status' => 1, 'totp_secret' => '']);
    }

    public function testTokenIsStoredAsVerifierAndRedeemedOnlyOnce(): void
    {
        $model = new DoLoginLinkModel();
        $issued = $model->issue(2, 1, 60, '<script>note</script>');
        $row = $model->find($issued['id']);
        self::assertNotSame($issued['token'], $row['token_hash']);
        self::assertNotSame(hash('sha256', $issued['token']), $row['token_hash']);
        self::assertNull($model->redeem($row['token_hash'], '127.0.0.1'));
        self::assertSame(2, (int) $model->redeem($issued['token'], '2001:db8::1')['id']);
        self::assertNull($model->redeem($issued['token'], '127.0.0.1'));
        self::assertSame('2001:db8::1', $model->recent()[0]['used_ip']);
        self::assertArrayNotHasKey('token_hash', $model->recent()[0]);
    }

    public function testRevokedAndExpiredLinksCannotBeRedeemed(): void
    {
        $model = new DoLoginLinkModel();
        $revoked = $model->issue(2, 1, 15, '');
        $model->revoke($revoked['id']);
        self::assertNull($model->redeem($revoked['token'], '127.0.0.1'));
        $expired = $model->issue(2, 1, 15, '');
        db()->update('dologin_links', ['expires_at' => time()], 'id = ?', [$expired['id']]);
        self::assertNull($model->redeem($expired['token'], '127.0.0.1'));
    }

    public function testCredentialOrRoleChangeInvalidatesOutstandingLinks(): void
    {
        $model = new DoLoginLinkModel();
        foreach (['password' => 'new-hash', 'role_id' => 1, 'totp_secret' => 'NEWSECRET'] as $field => $value) {
            $issued = $model->issue(2, 1, 15, '');
            db()->update('users', [$field => $value], 'id = ?', [2]);
            self::assertNull($model->redeem($issued['token'], '127.0.0.1'), $field);
        }
    }

    public function testDisabledOrDeletedTargetCannotSignIn(): void
    {
        $model = new DoLoginLinkModel();
        $issued = $model->issue(2, 1, 15, '');
        db()->update('users', ['status' => 0], 'id = ?', [2]);
        self::assertNull($model->redeem($issued['token'], '127.0.0.1'));
        db()->delete('users', 'id = ?', [2]);
        self::assertNull($model->redeem($issued['token'], '127.0.0.1'));
    }

    public function testIssuerLosingAdminRightsInvalidatesLinks(): void
    {
        $model = new DoLoginLinkModel();
        $issued = $model->issue(2, 1, 15, '');
        db()->update('roles', ['permissions' => '["content"]'], 'id = ?', [1]);
        self::assertNull($model->redeem($issued['token'], '127.0.0.1'));
    }

    public function testDisabledTargetRoleInvalidatesLinks(): void
    {
        $model = new DoLoginLinkModel();
        $issued = $model->issue(2, 1, 15, '');
        db()->update('roles', ['status' => 0], 'id = ?', [2]);
        self::assertNull($model->redeem($issued['token'], '127.0.0.1'));
    }

    public function testNonAdminCannotIssueLinks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DoLoginLinkModel())->issue(1, 2, 15, '');
    }

    public function testInvalidTtlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DoLoginLinkModel())->issue(2, 1, 0, '');
    }

    public function testMalformedTokensAreRejected(): void
    {
        foreach (['', str_repeat('a', 65), "' OR 1=1 --", str_repeat('a', 64) . "\n", str_repeat('G', 64)] as $token) {
            self::assertNull((new DoLoginLinkModel())->redeem($token, '127.0.0.1'));
        }
    }

    public function testPluginTranslationsHaveIdenticalKeys(): void
    {
        $source = require ROOT_PATH . '/plugins/dologin/lang/zh-CN.php';
        foreach (['en', 'ja'] as $language) {
            $translated = require ROOT_PATH . '/plugins/dologin/lang/' . $language . '.php';
            self::assertSame(array_keys($source), array_keys($translated));
        }
    }
}
