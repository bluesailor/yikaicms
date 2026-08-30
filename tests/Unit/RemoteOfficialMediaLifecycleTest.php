<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/RemoteOfficialMedia.php';

final class RemoteOfficialMediaLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        db()->execute('CREATE TABLE IF NOT EXISTS media (id INTEGER PRIMARY KEY, name TEXT)');
        db()->execute(
            'CREATE TABLE IF NOT EXISTS media_remote_imports (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                media_id INTEGER NOT NULL,
                provider TEXT NOT NULL,
                asset_id TEXT NOT NULL,
                asset_version TEXT NOT NULL,
                UNIQUE(provider, asset_id, asset_version)
            )'
        );
        db()->execute('DELETE FROM media_remote_imports WHERE asset_id LIKE ?', ['test-lifecycle-%']);
        db()->execute('DELETE FROM media WHERE id >= ?', [990000]);
    }

    public function testMediaDeletionAlsoDeletesRemoteImportMapping(): void
    {
        db()->execute('INSERT INTO media (id, name) VALUES (?, ?)', [990001, 'remote.jpg']);
        db()->execute(
            'INSERT INTO media_remote_imports (media_id, provider, asset_id, asset_version) VALUES (?, ?, ?, ?)',
            [990001, 'update.yikaicms.com', 'test-lifecycle-delete', 'v1']
        );

        (new MediaModel())->deleteById(990001);

        self::assertSame(0, (int) db()->fetchColumn(
            'SELECT COUNT(*) FROM media_remote_imports WHERE asset_id = ?',
            ['test-lifecycle-delete']
        ));
    }

    public function testStaleMappingSelfHealsBeforeReimport(): void
    {
        db()->execute(
            'INSERT INTO media_remote_imports (media_id, provider, asset_id, asset_version) VALUES (?, ?, ?, ?)',
            [990002, 'update.yikaicms.com', 'test-lifecycle-stale', 'v1']
        );
        $method = new ReflectionMethod(RemoteOfficialMedia::class, 'findExisting');
        $method->setAccessible(true);

        self::assertNull($method->invoke(null, 'test-lifecycle-stale', 'v1'));
        self::assertSame(0, (int) db()->fetchColumn(
            'SELECT COUNT(*) FROM media_remote_imports WHERE asset_id = ?',
            ['test-lifecycle-stale']
        ));
    }

    public function testApiBaseRequiresOfficialHttpsOrExplicitLocalDevelopment(): void
    {
        $method = new ReflectionMethod(RemoteOfficialMedia::class, 'apiBaseAllowed');
        $method->setAccessible(true);

        self::assertTrue($method->invoke(null, 'https://update.yikaicms.com/api/media'));
        self::assertFalse($method->invoke(null, 'http://update.yikaicms.com/api/media'));
        self::assertFalse($method->invoke(null, 'https://evil.example/api/media'));
        self::assertFalse($method->invoke(null, 'https://update.yikaicms.com:443/api/media'));
        self::assertFalse($method->invoke(null, 'https://user@example.com/api/media'));

        putenv('YIKAI_OFFICIAL_MEDIA_ALLOW_LOCAL=1');
        try {
            self::assertTrue($method->invoke(null, 'http://127.0.0.1:8080/api/media'));
        } finally {
            putenv('YIKAI_OFFICIAL_MEDIA_ALLOW_LOCAL');
        }
    }
}
