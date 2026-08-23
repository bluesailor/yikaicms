<?php

declare(strict_types=1);

namespace Yikai\Tests\Models;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/member_auth.php';

final class MemberSessionRevocationTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL,
                nickname TEXT NOT NULL DEFAULT \'\',
                email TEXT NOT NULL DEFAULT \'\',
                avatar TEXT NOT NULL DEFAULT \'\',
                status INTEGER NOT NULL DEFAULT 1,
                deleted_at INTEGER NULL
            )',
        ];
    }

    protected function tearDown(): void
    {
        \doMemberLogout();
        parent::tearDown();
    }

    public function testDisabledMemberSessionIsRevokedOnNextRequestCheck(): void
    {
        $id = (int) db()->insert('members', [
            'username' => 'alice', 'nickname' => 'Alice', 'email' => 'a@example.test',
            'avatar' => '', 'status' => 1, 'deleted_at' => null,
        ]);
        $_SESSION['member_id'] = $id;
        self::assertNotNull(\refreshMemberIdentity(true));

        db()->update('members', ['status' => 0], 'id = ?', [$id]);
        self::assertNull(\refreshMemberIdentity(true));
        self::assertFalse(\isMemberLoggedIn());
        self::assertArrayNotHasKey('member_id', $_SESSION);
    }

    public function testDeletedMemberSessionIsRevoked(): void
    {
        $id = (int) db()->insert('members', [
            'username' => 'bob', 'nickname' => '', 'email' => 'b@example.test',
            'avatar' => '', 'status' => 1, 'deleted_at' => null,
        ]);
        $_SESSION['member_id'] = $id;
        db()->delete('members', 'id = ?', [$id]);

        self::assertNull(\refreshMemberIdentity(true));
        self::assertArrayNotHasKey('member_id', $_SESSION);
    }
}
