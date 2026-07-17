<?php
/**
 * Tests for ContentModelModel — 自定义内容模型定义（key 校验、keys、contentCount）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use Yikai\Tests\TestCase;

final class ContentModelModelTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE content_models (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                model_key TEXT NOT NULL, name TEXT NOT NULL,
                name_en TEXT DEFAULT '', name_ja TEXT DEFAULT '',
                icon TEXT DEFAULT '', url_prefix TEXT DEFAULT '',
                list_template TEXT DEFAULT '', detail_template TEXT DEFAULT '',
                has_detail INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0,
                status INTEGER DEFAULT 1, created_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT DEFAULT 'article', title TEXT, status INTEGER DEFAULT 1,
                deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    public function testKeyValidation(): void
    {
        $m = \contentModelModel();
        $this->assertTrue($m->isKeyValid('team'));
        $this->assertTrue($m->isKeyValid('solution_2'));
        // 格式非法
        $this->assertFalse($m->isKeyValid('2team'));      // 数字开头
        $this->assertFalse($m->isKeyValid('Team'));       // 大写
        $this->assertFalse($m->isKeyValid('a'));          // 太短
        $this->assertFalse($m->isKeyValid('team-x'));     // 连字符
        // 保留字
        $this->assertFalse($m->isKeyValid('product'));
        $this->assertFalse($m->isKeyValid('page'));
        $this->assertFalse($m->isKeyValid('content'));
    }

    public function testKeyUniqueness(): void
    {
        $m = \contentModelModel();
        $m->create(['model_key' => 'team', 'name' => '团队', 'created_at' => 100]);
        $this->assertFalse($m->isKeyValid('team')); // 已占用
        $this->assertNotNull($m->getByKey('team'));
        $this->assertNull($m->getByKey('nope'));
    }

    public function testKeysReturnsOnlyActive(): void
    {
        $m = \contentModelModel();
        $m->create(['model_key' => 'team', 'name' => '团队', 'status' => 1, 'created_at' => 100]);
        $m->create(['model_key' => 'faq', 'name' => 'FAQ', 'status' => 0, 'created_at' => 100]);
        $keys = $m->keys();
        $this->assertContains('team', $keys);
        $this->assertNotContains('faq', $keys); // 停用不出
    }

    public function testContentCountExcludesTrashed(): void
    {
        $m = \contentModelModel();
        db()->insert('contents', ['type' => 'team', 'title' => 'A']);
        db()->insert('contents', ['type' => 'team', 'title' => 'B', 'deleted_at' => 123]); // 回收站
        db()->insert('contents', ['type' => 'article', 'title' => 'C']);
        $this->assertSame(1, $m->contentCount('team')); // 只数未删的 team
    }

    public function testGetByUrlPrefix(): void
    {
        $m = \contentModelModel();
        $m->create(['model_key' => 'team', 'name' => '团队', 'url_prefix' => 'staff', 'status' => 1, 'created_at' => 100]);
        // 按 url_prefix 命中
        $this->assertSame('team', $m->getByUrlPrefix('staff')['model_key']);
        // 回退按 model_key 命中（url_prefix 与 key 不同也能用 key 访问）
        $this->assertSame('team', $m->getByUrlPrefix('team')['model_key']);
        // 不存在
        $this->assertNull($m->getByUrlPrefix('nope'));
    }
}
