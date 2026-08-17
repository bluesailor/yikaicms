<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LogoMakerDefaultInstallTest extends TestCase
{
    public function testFreshInstallSeedsLogoMakerAsEnabled(): void
    {
        $mysql = file_get_contents(ROOT_PATH . '/install/sql/mysql.sql');
        $sqlite = file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql');

        self::assertIsString($mysql);
        self::assertIsString($sqlite);
        self::assertStringContainsString("('logo-maker', 1, 0, 0)", $mysql);
        self::assertStringContainsString("('logo-maker', 1, 0, 0)", $sqlite);
    }

    public function testUpgradeMigrationRegistersMissingPluginWithoutOverridingExistingState(): void
    {
        $migration = require ROOT_PATH . '/migrations/20260817_enable_logo_maker_by_default.php';

        self::assertSame('20260817_enable_logo_maker_by_default', $migration['id']);
        self::assertStringContainsString("findBySlug('logo-maker')", (string) file_get_contents(
            ROOT_PATH . '/migrations/20260817_enable_logo_maker_by_default.php'
        ));
        self::assertStringContainsString("activate('logo-maker')", (string) file_get_contents(
            ROOT_PATH . '/migrations/20260817_enable_logo_maker_by_default.php'
        ));
    }
}
