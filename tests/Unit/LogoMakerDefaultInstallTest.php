<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LogoMakerDefaultInstallTest extends TestCase
{
    /** 不随核心包的插件不得在安装种子里登记：新装站点会留下没有目录的启用记录。 */
    public function testFreshInstallSeedsNoPluginOutsideTheCorePackage(): void
    {
        $mysql = file_get_contents(ROOT_PATH . '/install/sql/mysql.sql');
        $sqlite = file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql');

        self::assertIsString($mysql);
        self::assertIsString($sqlite);
        foreach (['logo-maker', 'product-carousel', 'yikai-builder'] as $slug) {
            self::assertStringNotContainsString("('" . $slug . "', 1, 0, 0)", $mysql);
            self::assertStringNotContainsString("('" . $slug . "', 1, 0, 0)", $sqlite);
        }
    }

    /** logo-maker 改走插件市场后，历史迁移已退役：不再登记或启用插件。 */
    public function testRetiredUpgradeMigrationNoLongerRegistersThePlugin(): void
    {
        $migration = require ROOT_PATH . '/migrations/20260817_enable_logo_maker_by_default.php';

        self::assertSame('20260817_enable_logo_maker_by_default', $migration['id']);
        self::assertTrue(($migration['check'])());
        self::assertStringNotContainsString("activate('logo-maker')", (string) file_get_contents(
            ROOT_PATH . '/migrations/20260817_enable_logo_maker_by_default.php'
        ));
    }
}
