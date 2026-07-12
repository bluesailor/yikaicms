<?php
/**
 * 回归测试：Migrator::loadAll() 是迁移集合的唯一来源。
 *
 * 背景（v1.9.x P1 加固）：历史上后台「数据库升级」跑「admin/upgrade.php 内联 21 条 +
 * migrations/*.php」的合集，而 CLI `migrate:run` 只跑 Migrator::loadAll()（仅 migrations/ 文件）。
 * 凡以内联形式新增的 schema 变更，CLI 永不执行 → CLI 与 Web 打出不同的库结构。
 *
 * 修复：内联迁移抽到 migrations/_inline_upgrades.php，Migrator::loadAll() 合并它 + 独立文件，
 * 后台与 CLI 都只调 loadAll。本测试锁定该行为，防止有人把新迁移又写回 admin/upgrade.php 内联。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MigratorLoadAllTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('CMS_VERSION')) {
            define('CMS_VERSION', 'test');
        }
        require_once ROOT_PATH . '/includes/Migrator.php';
    }

    public function testLoadAllIncludesLegacyInlineMigrations(): void
    {
        $ids = array_column(\Migrator::loadAll(), 'id');

        // 抽到共享包的历史内联迁移，现在必须出现在唯一来源里 —— 这正是 CLI 之前看不到的部分。
        $this->assertContains('20260220_banner_groups', $ids, 'CLI/loadAll 应能看到内联迁移（修复前看不到）');
        $this->assertContains('contact_map_settings', $ids);
    }

    public function testInlineBundleIsFullySubsumedByLoadAll(): void
    {
        $bundle = require ROOT_PATH . '/migrations/_inline_upgrades.php';
        $bundleIds = array_column($bundle, 'id');
        $allIds = array_column(\Migrator::loadAll(), 'id');

        foreach ($bundleIds as $id) {
            $this->assertContains($id, $allIds, "内联迁移 {$id} 必须包含在 loadAll 单一来源中");
        }
    }

    public function testMigrationIdsAreUnique(): void
    {
        // 合并（inline + 文件，同 id 文件覆盖）后不应有重复 id，否则会重复执行/状态错乱。
        $ids = array_column(\Migrator::loadAll(), 'id');
        $this->assertSame(count($ids), count(array_unique($ids)), 'loadAll 合并后 id 必须唯一');
    }

    public function testEveryMigrationHasRequiredKeys(): void
    {
        foreach (\Migrator::loadAll() as $m) {
            $this->assertNotEmpty($m['id'] ?? '', '迁移必须有 id');
            $this->assertTrue(is_callable($m['check'] ?? null), "迁移 {$m['id']} 必须有可调用的 check");
            $this->assertNotEmpty($m['_file'] ?? '', "迁移 {$m['id']} 应带 _file 来源标记");
        }
    }

    public function testInlineBundleFileIsNotLoadedAsSingleMigration(): void
    {
        // _inline_upgrades.php 返回的是「迁移列表」，不是单条迁移；loadAll 的文件循环必须跳过它，
        // 否则它会被当成缺 id 的坏迁移。断言：没有任何一条迁移的 id 恰好等于整包（即未被误当单条）。
        $all = \Migrator::loadAll();
        foreach ($all as $m) {
            $this->assertIsString($m['id']);
        }
        // 且来自内联包的条目 _file 标记应为 _inline_upgrades.php
        $fromBundle = array_filter($all, fn ($m) => ($m['_file'] ?? '') === '_inline_upgrades.php');
        $this->assertNotEmpty($fromBundle, '应有条目来自 _inline_upgrades.php 整包');
    }
}
