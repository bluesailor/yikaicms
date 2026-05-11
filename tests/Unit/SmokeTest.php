<?php
/**
 * Smoke test — confirms the bootstrap loaded constants, the Database
 * singleton spun up against in-memory SQLite, and PHPUnit can drive it.
 *
 * If this fails, no other test will run; treat it as the canary.
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class SmokeTest extends TestCase
{
    public function testBootstrapDefinedConstants(): void
    {
        $this->assertSame('sqlite', DB_DRIVER);
        $this->assertSame(':memory:', DB_PATH);
        $this->assertSame('', DB_PREFIX);
    }

    public function testDatabaseSingletonReturnsSqlitePdo(): void
    {
        $db = db();
        $this->assertInstanceOf(\Database::class, $db);
        $this->assertSame('sqlite', $db->getDriver());
        $this->assertTrue($db->isSqlite());
        $this->assertFalse($db->isMysql());
    }

    public function testCanCreateTableAndQueryIt(): void
    {
        db()->getPdo()->exec('CREATE TABLE smoke (id INTEGER PRIMARY KEY, val TEXT)');
        db()->insert('smoke', ['val' => 'hello']);
        $row = db()->fetchOne('SELECT * FROM smoke WHERE val = ?', ['hello']);
        $this->assertIsArray($row);
        $this->assertSame('hello', $row['val']);
    }
}
