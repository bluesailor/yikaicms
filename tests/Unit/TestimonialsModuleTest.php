<?php
/** 客户评价模块（v2.1.0）：迁移建表 + 导入首页评价与译文 + 补权限；按语言取、回退默认语言；评价轮播「来自客户评价」。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use TestimonialCarouselElement;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class TestimonialsModuleTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, \"group\" TEXT DEFAULT 'basic', \"key\" TEXT, value TEXT, type TEXT DEFAULT 'text', name TEXT DEFAULT '', tip TEXT DEFAULT '', options TEXT, sort_order INTEGER DEFAULT 0, UNIQUE(\"key\"))",
            'CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, permissions TEXT)',
        ];
    }

    private function homeDoc(): string
    {
        $items = [
            ['avatar' => '/a1.svg', 'name' => '陈思远', 'role' => '采购总监', 'content' => '三周交付', 'rating' => '5'],
            ['avatar' => '/a2.svg', 'name' => '林晓雯', 'role' => '运营经理', 'content' => '盘点减半', 'rating' => '4'],
        ];
        $binding = ['lang' => 'zh-CN', 'source' => ['items' => $items], 'translations' => [
            'en' => ['items' => [['name' => 'Siyuan Chen', 'role' => 'Procurement Director', 'content' => 'Three weeks'], ['name' => 'Xiaowen Lin', 'role' => 'Ops', 'content' => 'Half the time']]],
            'ja' => ['items' => [['name' => '陳', 'role' => '購買部長', 'content' => '3週間']]],
        ]];
        return (string) json_encode(['sections' => [['columns' => [['elements' => [
            ['type' => 'testimonial-carousel', 'data' => ['items' => $items, '_home_testimonials_i18n' => $binding]],
        ]]]]]], JSON_UNESCAPED_UNICODE);
    }

    public function testMigrationCreatesTableImportsHomeTestimonialsAndGrantsPermissions(): void
    {
        db()->execute('INSERT INTO settings ("key", value) VALUES (?, ?)', ['home_blox_published', $this->homeDoc()]);
        db()->execute('INSERT INTO roles (name, permissions) VALUES (?, ?)', ['编辑', '["edit_timeline","delete_timeline"]']);
        db()->execute('INSERT INTO roles (name, permissions) VALUES (?, ?)', ['超管', '["*"]']);
        $migration = require ROOT_PATH . '/migrations/20261012_testimonials.php';
        self::assertFalse(($migration['check'])());

        self::assertSame('imported 5, roles 1', ($migration['php'])());
        self::assertTrue(($migration['check'])());
        $rows = db()->fetchAll('SELECT lang, translation_group_id, name, rating, sort_order, avatar FROM testimonials ORDER BY id');
        self::assertSame(['zh-CN', 'en', 'ja', 'zh-CN', 'en'], array_column($rows, 'lang'));
        self::assertSame($rows[0]['translation_group_id'], $rows[1]['translation_group_id'], '同一条评价的各语言同组');
        self::assertSame($rows[0]['translation_group_id'], $rows[2]['translation_group_id']);
        self::assertNotSame($rows[0]['translation_group_id'], $rows[3]['translation_group_id']);
        self::assertSame('Siyuan Chen', $rows[1]['name']);
        self::assertSame('/a1.svg', $rows[1]['avatar'], '译文沿用原文的头像与评分');
        self::assertSame(4, (int) $rows[3]['rating']);
        self::assertSame(['edit_timeline', 'delete_timeline', 'edit_testimonial', 'delete_testimonial'], json_decode((string) db()->fetchColumn('SELECT permissions FROM roles WHERE id = 1'), true));

        self::assertSame('imported 0, roles 0', ($migration['php'])(), '表里已有评价不再导入');
    }

    public function testActiveTestimonialsFollowTheLanguageAndFallBack(): void
    {
        $migration = require ROOT_PATH . '/migrations/20261012_testimonials.php';
        ($migration['php'])();   // 没有首页评价：只建表
        $insert = 'INSERT INTO testimonials (lang, translation_group_id, name, content, rating, sort_order, status) VALUES (?, 0, ?, ?, 5, ?, ?)';
        db()->execute($insert, ['zh-CN', '乙', '内容乙', 2, 1]);
        db()->execute($insert, ['zh-CN', '甲', '内容甲', 1, 1]);
        db()->execute($insert, ['zh-CN', '隐藏', '不显示', 0, 0]);
        db()->execute($insert, ['en', 'Ann', 'Great', 0, 1]);

        self::assertSame(['甲', '乙'], array_column(testimonialModel()->getActive('zh-CN'), 'name'), '按排序、只取显示的');
        self::assertSame(['Ann'], array_column(testimonialModel()->getActive('en'), 'name'));
        self::assertSame(['甲', '乙'], array_column(testimonialModel()->getActive('ja'), 'name'), '这一语言没有就用默认语言');
        self::assertSame(['甲'], array_column(testimonialModel()->getActive('zh-CN', 1), 'name'));

        $GLOBALS['_test_config']['site_lang'] = 'zh-CN';
        try {
            self::assertSame(['甲'], array_column(TestimonialCarouselElement::libraryItems(1), 'name'));
            $html = (new TestimonialCarouselElement())->render(['source' => 'library', 'items' => [['name' => '元素里的', 'content' => 'x']]]);
            self::assertStringContainsString('内容甲', $html, '「来自客户评价」不用元素里填的条目');
            self::assertStringNotContainsString('元素里的', $html);
        } finally {
            unset($GLOBALS['_test_config']['site_lang']);
        }
    }
}
