<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * storage/ 存放 SQLite 数据库、日志与备份。随包的 storage/.htaccess 拒绝一切网页访问（Apache），
 * 升级器不写 storage/，老站由迁移补齐——两处内容必须一致，且迁移不得覆盖站点已有文件。
 */
final class StorageDenyHtaccessTest extends TestCase
{
    public function testBundledFileDeniesAllWebAccessOnApache24And22(): void
    {
        $file = (string) file_get_contents(ROOT_PATH . '/storage/.htaccess');
        self::assertStringContainsString('<IfModule mod_authz_core.c>', $file);
        self::assertStringContainsString('Require all denied', $file);
        self::assertStringContainsString('Deny from all', $file);
    }

    public function testMigrationWritesTheSameContentAsTheBundledFile(): void
    {
        $migration = require ROOT_PATH . '/migrations/20260919_storage_deny_htaccess.php';
        self::assertSame('20260919_storage_deny_htaccess', $migration['id']);
        self::assertSame((string) file_get_contents(ROOT_PATH . '/storage/.htaccess'), $migration['htaccess']);
        // 迁移会被反复加载：再 require 一次不能因重复声明而出错。
        $again = require ROOT_PATH . '/migrations/20260919_storage_deny_htaccess.php';
        self::assertSame($migration['htaccess'], $again['htaccess']);
    }

    public function testMigrationNeverOverwritesAnExistingFile(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/migrations/20260919_storage_deny_htaccess.php');
        self::assertMatchesRegularExpression('/if \(is_file\(\$file\)\) \{\s*return /', $source);
        self::assertStringContainsString("is_file(ROOT_PATH . '/storage/.htaccess')", $source);
    }

    public function testBuildRequiresTheFileInThePackage(): void
    {
        self::assertStringContainsString('"storage/.htaccess"', (string) file_get_contents(ROOT_PATH . '/build.sh'));
    }
}
