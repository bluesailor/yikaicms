<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class PublishedDetailLanguageTest extends TestCase
{
    private mixed $previous;

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE channels (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, type TEXT)',
            'CREATE TABLE product_categories (id INTEGER PRIMARY KEY, name TEXT, slug TEXT)',
            'CREATE TABLE contents (id INTEGER PRIMARY KEY, channel_id INTEGER, title TEXT, type TEXT, lang TEXT,
                translation_group_id INTEGER, status INTEGER, deleted_at INTEGER)',
            'CREATE TABLE products (id INTEGER PRIMARY KEY, category_id INTEGER, title TEXT, lang TEXT,
                translation_group_id INTEGER, status INTEGER, deleted_at INTEGER)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        $GLOBALS['yikai_config_runtime_overrides'] = ['site_lang' => 'en'];
        foreach (['contents', 'products'] as $table) {
            foreach (['zh-CN', 'en', 'ja'] as $i => $lang) {
                $row = ['id' => $i + 1, 'title' => $lang, 'lang' => $lang,
                    'translation_group_id' => 1, 'status' => 1, 'deleted_at' => null];
                if ($table === 'contents') $row['type'] = 'article';
                db()->insert($table, $row);
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->previous === null) unset($GLOBALS['yikai_config_runtime_overrides']);
        else $GLOBALS['yikai_config_runtime_overrides'] = $this->previous;
        parent::tearDown();
    }

    public function testNumericSourcesResolveEachLanguageButRawLookupRemainsStable(): void
    {
        foreach ([contentModel(), productModel()] as $model) {
            foreach (['zh-CN', 'en', 'ja'] as $index => $language) {
                $GLOBALS['yikai_config_runtime_overrides']['site_lang'] = $language;
                foreach ([1, 2, 3] as $sourceId) {
                    self::assertSame($index + 1, (int) $model->getPublishedForLanguage($sourceId)['id']);
                    self::assertSame($sourceId, (int) $model->getPublished($sourceId)['id']);
                }
            }
        }
    }

    public function testMissingDraftAndDeletedTranslationsKeepPublishedSource(): void
    {
        foreach (['contents' => contentModel(), 'products' => productModel()] as $table => $model) {
            foreach ([['status' => 0], ['deleted_at' => 123], ['translation_group_id' => 0]] as $change) {
                db()->update($table, $change, 'id = ?', [2]);
                self::assertSame(1, (int) $model->getPublishedForLanguage(1)['id']);
                db()->update($table, ['status' => 1, 'deleted_at' => null, 'translation_group_id' => 1], 'id = ?', [2]);
            }
            db()->update($table, ['status' => 0], 'id = ?', [1]);
            self::assertNull($model->getPublishedForLanguage(1));
            self::assertNull($model->getPublishedForLanguage(999));
        }
    }

    public function testLegacyZeroSourceGroupAndDifferentContentTypes(): void
    {
        foreach (['contents' => contentModel(), 'products' => productModel()] as $table => $model) {
            db()->update($table, ['translation_group_id' => 0], 'id = ?', [1]);
            self::assertSame(2, (int) $model->getPublishedForLanguage(1)['id']);
        }
        db()->update('contents', ['type' => 'case'], 'id = ?', [2]);
        self::assertSame(1, (int) contentModel()->getPublishedForLanguage(1)['id']);
    }
}
