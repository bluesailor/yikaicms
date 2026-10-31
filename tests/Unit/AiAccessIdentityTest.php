<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiAccessAuthContext;
use AiAccessIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/ai-access/AiAccessIdentity.php';

final class AiAccessIdentityTest extends TestCase
{
    private const NOW = 1800000000;
    private AiAccessIdentity $identity;
    private array $token;

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role_id INTEGER, status INTEGER)',
            'CREATE TABLE roles (id INTEGER PRIMARY KEY, permissions TEXT, status INTEGER)',
            'CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        db()->execute('INSERT INTO users VALUES (7, ?, 2, 1)', ['audit-editor']);
        db()->execute('INSERT INTO roles VALUES (2, ?, 1)', ['["ai_access","edit_article"]']);
        db()->execute('INSERT INTO settings VALUES (?, ?)', ['ai_access_enabled', '1']);
        $this->token = ['id' => 9, 'admin_id' => 7, 'token_hash' => hash('sha256', str_repeat('a', 64)),
            'expires_at' => self::NOW + 60, 'revoked_at' => null, 'scopes' => json_encode(AiAccessAuthContext::TOOLS)];
        $this->identity = new AiAccessIdentity(db());
    }

    private function authenticate(): AiAccessAuthContext
    {
        return $this->identity->authenticate('Bearer ' . str_repeat('a', 64), fn(string $digest): array => $this->token, self::NOW);
    }

    private function assertDenied(callable $action, int $status, string $code): void
    {
        try {
            $action();
            self::fail('Expected an identity rejection');
        } catch (RuntimeException $error) {
            self::assertSame($status, $error->getCode());
            self::assertSame($code, $error->getMessage());
        }
    }

    public function testIdentityUsesServerTokenAndLookupReceivesOnlyDigest(): void
    {
        $saved = [$_SESSION ?? null, $_COOKIE, $_GET];
        try {
            $_SESSION = ['admin_id' => 1, 'admin_permissions' => ['*']];
            $_COOKIE = ['IK_fixture' => 'fixture-admin-cookie'];
            $_GET = ['admin_id' => 1, 'key' => 'fixture-key'];
            $context = $this->identity->authenticate('Bearer ' . str_repeat('a', 64), function (string $digest): array {
                self::assertSame(hash('sha256', str_repeat('a', 64)), $digest);
                return $this->token;
            }, self::NOW);
            self::assertSame(7, $context->actorId);
            self::assertSame(9, $context->tokenId);
            self::assertTrue($context->allowsTool('article_read'));
            self::assertSame(['admin_id' => 1, 'admin_permissions' => ['*']], $_SESSION);
            self::assertSame(['IK_fixture' => 'fixture-admin-cookie'], $_COOKIE);
            self::assertSame(['admin_id' => 1, 'key' => 'fixture-key'], $_GET);
        } finally {
            [$_SESSION, $_COOKIE, $_GET] = $saved;
        }
    }

    #[DataProvider('invalidHeaders')]
    public function testInvalidHeaderNeverFallsBackOrCallsRepository(string $header): void
    {
        $this->assertDenied(fn() => $this->identity->authenticate($header, static function (): never {
            self::fail('Repository must not be called for an invalid header');
        }, self::NOW), 401, 'ai_access_invalid_token');
    }

    public static function invalidHeaders(): array
    {
        return [[''], ['Basic fixture'], ['Bearer short'], ['Bearer ' . str_repeat('a', 64) . "\r\nX-Test: yes"], ['Bearer ' . str_repeat('a', 130)], ['Bearer ' . str_repeat('a', 64) . ', another']];
    }

    #[DataProvider('invalidTokens')]
    public function testMalformedRevokedAndExpiredTokensAreRejected(array $changes): void
    {
        $this->token = array_replace($this->token, $changes);
        $this->assertDenied(fn() => $this->authenticate(), 401, 'ai_access_invalid_token');
    }

    public static function invalidTokens(): array
    {
        return [
            [['token_hash' => str_repeat('0', 64)]], [['id' => 0]], [['admin_id' => -7]],
            [['revoked_at' => self::NOW]], [['expires_at' => self::NOW]], [['expires_at' => 'invalid']],
            [['scopes' => '["*"]']], [['scopes' => '["cms_publish_content"]']], [['scopes' => '{"x":"site_info"}']], [['scopes' => 'not json']],
        ];
    }

    public function testUnknownTokenAndRepositoryFailureHaveDistinctSafeErrors(): void
    {
        $header = 'Bearer ' . str_repeat('a', 64);
        $this->assertDenied(fn() => $this->identity->authenticate($header, fn(): null => null, self::NOW), 401, 'ai_access_invalid_token');
        $this->assertDenied(fn() => $this->identity->authenticate($header, static function (): never {
            throw new RuntimeException('fixture internal secret');
        }, self::NOW), 503, 'ai_access_unavailable');
    }

    public function testSuperAdminCannotExpandScopeOrCallInternalAbility(): void
    {
        db()->execute('UPDATE roles SET permissions = ?', ['["*"]']);
        $this->token['scopes'] = '["site_info"]';
        $context = $this->authenticate();
        self::assertTrue($context->allowsTool('site_info'));
        foreach (array_diff(AiAccessAuthContext::TOOLS, ['site_info']) as $tool) self::assertFalse($context->allowsTool($tool));
        self::assertFalse($context->allowsTool('cms_publish_content'));
        self::assertFalse($context->allowsTool('unknown'));
    }

    public function testCurrentRoleAndUserStateAreReadAgainWithoutLoginSnapshot(): void
    {
        self::assertTrue($this->authenticate()->allowsTool('article_read'));
        db()->execute('UPDATE roles SET permissions = ?', ['["ai_access","edit_product"]']);
        self::assertFalse($this->authenticate()->allowsTool('article_read'));
        self::assertTrue($this->authenticate()->allowsTool('site_info'));
        db()->execute('UPDATE roles SET permissions = ?', ['["edit_article"]']);
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_permission_denied');
        db()->execute('UPDATE roles SET permissions = ?, status = 0', ['["*"]']);
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_role_invalid');
        db()->execute('DELETE FROM roles');
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_role_invalid');
        db()->execute('UPDATE users SET status = 0');
        $this->assertDenied(fn() => $this->authenticate(), 401, 'ai_access_identity_invalid');
        db()->execute('DELETE FROM users');
        $this->assertDenied(fn() => $this->authenticate(), 401, 'ai_access_identity_invalid');
    }

    public function testRevocationAndRoleReassignmentTakeEffectOnNextResolution(): void
    {
        self::assertTrue($this->authenticate()->allowsTool('article_read'));
        $this->token['revoked_at'] = self::NOW;
        $this->assertDenied(fn() => $this->authenticate(), 401, 'ai_access_invalid_token');
        $this->token['revoked_at'] = null;
        db()->execute('UPDATE users SET role_id = 999');
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_role_invalid');
    }

    public function testDisabledApiDemoAndSupportCannotAuthenticate(): void
    {
        db()->execute('UPDATE settings SET `value` = ?', ['0']);
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_disabled');
        db()->execute('DELETE FROM settings');
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_disabled');
        db()->execute('INSERT INTO settings VALUES (?, ?)', ['ai_access_enabled', '1']);
        db()->execute('INSERT INTO settings VALUES (?, ?)', ['demo_mode', '2']);
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_disabled');
        db()->execute('DELETE FROM settings WHERE `key` = ?', ['demo_mode']);
        $this->identity = new AiAccessIdentity(db(), true);
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_disabled');
        $this->identity = new AiAccessIdentity(db());
        db()->execute('UPDATE users SET username = ?', ['yikai-support']);
        db()->execute('UPDATE roles SET permissions = ?', ['["*"]']);
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_support_denied');
        db()->execute('UPDATE users SET username = ?', ['renamed-support']);
        db()->execute('INSERT INTO settings VALUES (?, ?)', ['support_access_user_id', '7']);
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_support_denied');
    }

    public function testOverridesAndBrokenPolicyStorageFailClosed(): void
    {
        $this->identity = new AiAccessIdentity(db(), false, ['ai_access_enabled' => '0']);
        $this->assertDenied(fn() => $this->authenticate(), 403, 'ai_access_disabled');
        $this->identity = new AiAccessIdentity(db());
        db()->execute('DROP TABLE settings');
        $this->assertDenied(fn() => $this->authenticate(), 503, 'ai_access_unavailable');
    }

    public function testAuthenticationDoesNotWriteBusinessOrLoginState(): void
    {
        $before = [];
        foreach (['users', 'roles', 'settings'] as $table) $before[$table] = db()->fetchAll('SELECT * FROM ' . $table);
        $this->authenticate();
        foreach ($before as $table => $rows) self::assertSame($rows, db()->fetchAll('SELECT * FROM ' . $table));
    }
}
