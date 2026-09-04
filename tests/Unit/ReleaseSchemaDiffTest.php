<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/tools/release-schema-diff.php';

final class ReleaseSchemaDiffTest extends TestCase
{
    public function testDetectsColumnAddedInsideExistingCreateTable(): void
    {
        $before = "CREATE TABLE `media` (\n  `id` int NOT NULL\n) ENGINE=InnoDB;\n";
        $after = "CREATE TABLE `media` (\n  `id` int NOT NULL,\n  `media_type` varchar(10) NOT NULL DEFAULT 'image'\n) ENGINE=InnoDB;\n";

        self::assertSame(['media'], releaseSchemaChangedTables($before, $after));
    }

    public function testIgnoresSeedDataChanges(): void
    {
        $schema = "CREATE TABLE `settings` (\n  `id` int NOT NULL\n) ENGINE=InnoDB;\n";
        $before = $schema . "INSERT INTO `settings` VALUES (1);\n";
        $after = $schema . "INSERT INTO `settings` VALUES (2);\n";

        self::assertSame([], releaseSchemaChangedTables($before, $after));
    }

    public function testDetectsAddedAndRemovedTables(): void
    {
        $before = "CREATE TABLE `old_table` (`id` int) ENGINE=InnoDB;\n";
        $after = "CREATE TABLE `new_table` (`id` int) ENGINE=InnoDB;\n";

        self::assertSame(['new_table', 'old_table'], releaseSchemaChangedTables($before, $after));
    }
}
