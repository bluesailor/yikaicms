<?php
/**
 * 回归测试：install SQL 必须与 migrations/ 同步（防「全新安装缺列」类 500）。
 *
 * 背景（v1.11.0）：软删除回收站 / 相册展示模式 / 单页头图 / 2FA / 联系页地图 等 6 条迁移
 * 只写进 migrations/，没回填 install/sql/*.sql；而安装器导入 install SQL 后若未完成迁移收尾，
 * 于是全新安装缺 deleted_at 等列，首页读 contents/products 时 `WHERE deleted_at IS NULL`
 * → Unknown column → 500。
 *
 * 本测试把 install/sql/sqlite.sql 灌进内存库，再逐条跑真实迁移的 check()，断言 0 待跑。
 * 以后谁再新增迁移却忘了回填 install SQL，这里立刻变红。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

final class InstallSqlMigrationParityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('CMS_VERSION')) {
            define('CMS_VERSION', 'test');
        }
        require_once ROOT_PATH . '/includes/Migrator.php';
    }

    /**
     * 全新导入 install SQL 后，所有迁移都应已「applied」（0 待跑）。
     */
    public function testFreshSqliteInstallHasNoPendingMigrations(): void
    {
        // 载入 install schema —— 把硬编码前缀 yikai_ 换成空（匹配测试 DB_PREFIX=''），
        // 与安装器 install/index.php 的 str_replace('yikai_', $prefix, $sql) 行为一致。
        $sql = file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql');
        $sql = str_replace('yikai_', '', $sql);
        db()->getPdo()->exec($sql);

        // settingModel() 是进程级单例、带行缓存：前面的用例可能已把它暖成旧站状态
        //（executionOrder="defects" 还会轮换用例顺序，暖不暖因此不稳定 → 本测试薛定谔红绿）。
        // 导入全新 install SQL 后必须失效该缓存，否则迁移 check() 读到的是上个用例的设置。
        $cacheProp = new \ReflectionProperty(\SettingModel::class, 'cache');
        $cacheProp->setValue(settingModel(), null);

        $pending = [];
        foreach (\Migrator::loadAll() as $m) {
            if (!\Migrator::isApplied($m)) {
                $pending[] = $m['id'];
            }
        }
        sort($pending);

        $this->assertSame(
            [],
            $pending,
            "install/sql/sqlite.sql 落后于 migrations/，全新安装缺以下迁移的 schema/种子"
            . "（会致前台/后台报错，如首页 500）：\n  " . implode("\n  ", $pending)
            . "\n修法：把这些迁移的列/索引/种子回填进 install/sql/mysql.sql 和 sqlite.sql。"
        );
    }

    /**
     * 两个 install SQL 文件不能各自漂移：软删除索引必须在两边都存在
     *（sqlite 版由上面的迁移 check 覆盖，这里专门守住 mysql.sql）。
     */
    public function testMysqlAndSqliteInstallAgreeOnSoftDeleteTables(): void
    {
        $mysql  = file_get_contents(ROOT_PATH . '/install/sql/mysql.sql');
        $sqlite = file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql');

        foreach (['contents', 'products', 'downloads', 'jobs', 'albums'] as $t) {
            $idx = "idx_{$t}_deleted";
            $this->assertStringContainsString($idx, $mysql, "mysql.sql 缺软删除索引 {$idx}");
            $this->assertStringContainsString($idx, $sqlite, "sqlite.sql 缺软删除索引 {$idx}");
        }
    }

    public function testInstallerFinalizesConfigurationDependentMigrationsBeforeLocking(): void
    {
        $installer = file_get_contents(ROOT_PATH . '/install/index.php');
        self::assertIsString($installer);

        self::assertStringContainsString('function finalizeFreshInstallMigrations(): void', $installer);
        self::assertStringContainsString('Migrator::loadAll()', $installer);
        self::assertStringContainsString('Migrator::runOne($migration)', $installer);

        $configWrite = strpos($installer, "file_put_contents(ROOT_PATH . '/config/config.php'");
        $finalize = strpos($installer, 'finalizeFreshInstallMigrations();', (int) $configWrite);
        $lockWrite = strpos($installer, "file_put_contents(ROOT_PATH . '/installed.lock'", (int) $finalize);
        self::assertIsInt($configWrite);
        self::assertIsInt($finalize);
        self::assertIsInt($lockWrite);
        self::assertLessThan($finalize, $configWrite);
        self::assertLessThan($lockWrite, $finalize);
    }
}
