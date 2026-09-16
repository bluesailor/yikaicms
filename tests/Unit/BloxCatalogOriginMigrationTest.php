<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Migrator;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/Migrator.php';

final class BloxCatalogOriginMigrationTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE blox_remote_template_states (template_id INTEGER PRIMARY KEY, installed_version TEXT)',
            'CREATE TABLE blox_import_reviews (id TEXT PRIMARY KEY, package_json TEXT)',
        ];
    }

    public function testMigrationPreservesLegacyRecordsAndCanRunTwice(): void
    {
        db()->insert('blox_remote_template_states', ['template_id' => 12, 'installed_version' => '1.0.0']);
        db()->insert('blox_import_reviews', ['id' => 'legacy', 'package_json' => '{}']);
        $migration = require ROOT_PATH . '/migrations/20260915_blox_catalog_origin.php';
        $this->assertContains($migration['id'], array_column(Migrator::loadAll(), 'id'));
        $this->assertFalse(($migration['check'])());
        $this->assertTrue(Migrator::runOne($migration)['ok']);
        $this->assertTrue(($migration['check'])());
        $this->assertTrue(Migrator::runOne($migration)['ok']);
        $this->assertSame(['template_id' => 12, 'installed_version' => '1.0.0', 'catalog_origin' => ''],
            db()->fetchOne('SELECT * FROM blox_remote_template_states WHERE template_id = ?', [12]));
        $this->assertSame(['id' => 'legacy', 'package_json' => '{}', 'catalog_origin' => ''],
            db()->fetchOne('SELECT * FROM blox_import_reviews WHERE id = ?', ['legacy']));
    }

    public function testPartialMigrationKeepsAlreadyBoundOrigin(): void
    {
        db()->execute("ALTER TABLE blox_remote_template_states ADD COLUMN catalog_origin VARCHAR(16) NOT NULL DEFAULT ''");
        db()->insert('blox_remote_template_states', [
            'template_id' => 12, 'installed_version' => '1.0.0', 'catalog_origin' => 'community',
        ]);
        $migration = require ROOT_PATH . '/migrations/20260915_blox_catalog_origin.php';
        $this->assertFalse(($migration['check'])());
        $this->assertTrue(Migrator::runOne($migration)['ok']);
        $this->assertTrue(($migration['check'])());
        $this->assertSame('community', db()->fetchColumn(
            'SELECT catalog_origin FROM blox_remote_template_states WHERE template_id = ?', [12]
        ));
    }

    public function testReviewTableCreationPrecedesOriginMigration(): void
    {
        db()->execute('DROP TABLE ' . DB_PREFIX . 'blox_import_reviews');
        $create = require ROOT_PATH . '/migrations/20260914_add_blox_import_reviews.php';
        $this->assertFalse(($create['check'])());
        $result = Migrator::runOne($create);
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertTrue(($create['check'])());
        $this->assertTrue(Migrator::runOne($create)['ok']);
        $origin = require ROOT_PATH . '/migrations/20260915_blox_catalog_origin.php';
        $this->assertTrue(Migrator::runOne($origin)['ok']);
        $this->assertTrue(($origin['check'])());
    }
}
