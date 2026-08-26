<?php
/** Home Blox P0 document and legacy migration contracts. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeBloxDocument;
use HomeBlockElement;
use HomeLayoutDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
if (!function_exists('do_action')) {
    require_once ROOT_PATH . '/includes/hooks.php';
}

final class HomeBloxDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_test_config'] = [];
    }

    public function testLegacyHomeBlocksBecomeSortableBloxSections(): void
    {
        $GLOBALS['_test_config']['home_blocks_config'] = json_encode([
            ['type' => 'banner', 'enabled' => true],
            ['type' => 'channel:7', 'enabled' => false],
        ], JSON_UNESCAPED_UNICODE);

        $document = HomeBloxDocument::load();

        $this->assertSame('legacy', $document['source']);
        $this->assertCount(3, $document['sections']);
        $this->assertSame('home-block', $document['sections'][0]['columns'][0]['elements'][0]['type']);
        $this->assertSame('banner', $document['sections'][0]['columns'][0]['elements'][0]['data']['block_type']);
        $this->assertFalse($document['sections'][1]['columns'][0]['elements'][0]['data']['enabled']);
        $this->assertSame('partners', $document['sections'][2]['columns'][0]['elements'][0]['data']['block_type']);
        $this->assertFalse($document['sections'][2]['columns'][0]['elements'][0]['data']['enabled']);
        $this->assertSame('none', $document['sections'][0]['settings']['container_gutter']);
    }

    public function testImplicitClassicPartnersBlockKeepsItsLegacyVisibility(): void
    {
        $GLOBALS['_test_config'] += [
            'home_blocks_config' => json_encode([
                ['type' => 'banner', 'enabled' => true],
            ], JSON_THROW_ON_ERROR),
            'home_show_links' => '1',
        ];

        $document = HomeBloxDocument::load();
        $partners = $document['sections'][1]['columns'][0]['elements'][0]['data'];

        $this->assertSame('partners', $partners['block_type']);
        $this->assertTrue($partners['enabled']);
    }

    public function testLegacyAggregateChannelsExpandIntoConcreteHomepageChannels(): void
    {
        $channelIds = [];
        $createdTable = !db()->tableExists('channels');
        try {
            if ($createdTable) {
                db()->execute(
                    'CREATE TABLE channels ('
                    . 'id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT DEFAULT \'zh-CN\', '
                    . 'translation_group_id INTEGER DEFAULT 0, parent_id INTEGER DEFAULT 0, '
                    . 'name TEXT NOT NULL, slug TEXT NOT NULL, type TEXT DEFAULT \'list\', '
                    . 'is_nav INTEGER DEFAULT 0, is_home INTEGER DEFAULT 0, status INTEGER DEFAULT 1, '
                    . 'sort_order INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0)'
                );
            }
            foreach ([
                ['name' => 'Migration Products', 'slug' => 'migration-products', 'type' => 'product', 'sort_order' => 901],
                ['name' => 'Migration News', 'slug' => 'migration-news', 'type' => 'list', 'sort_order' => 902],
            ] as $channel) {
                $channelIds[] = db()->insert('channels', $channel + [
                    'lang' => 'zh-CN',
                    'translation_group_id' => 0,
                    'parent_id' => 0,
                    'is_nav' => 0,
                    'is_home' => 1,
                    'status' => 1,
                    'created_at' => time(),
                ]);
            }

            $GLOBALS['_test_config']['home_blocks_config'] = json_encode([
                ['type' => 'banner', 'enabled' => true],
                ['type' => 'channels', 'enabled' => true, 'limit' => 8, 'per_row' => 4],
                ['type' => 'cta', 'enabled' => true],
            ], JSON_THROW_ON_ERROR);

            $document = HomeBloxDocument::load();
            $homeBlocks = array_map(
                static fn (array $section): array => $section['columns'][0]['elements'][0]['data'],
                $document['sections']
            );
            $byType = [];
            foreach ($homeBlocks as $block) {
                $byType[(string) $block['block_type']] = $block;
            }

            $this->assertArrayNotHasKey('channels', $byType);
            foreach ($channelIds as $channelId) {
                $type = 'channel:' . $channelId;
                $this->assertArrayHasKey($type, $byType);
                $this->assertSame(8, $byType[$type]['limit']);
                $this->assertSame(4, $byType[$type]['per_row']);
            }
        } finally {
            foreach ($channelIds as $channelId) {
                db()->delete('channels', 'id = ?', [$channelId]);
            }
            if ($createdTable) {
                db()->execute('DROP TABLE channels');
            }
        }
    }

    public function testLegacyStatsAndAdvantagesKeepTheSiteSpecificContent(): void
    {
        $GLOBALS['_test_config'] += [
            'home_blocks_config' => json_encode([
                ['type' => 'stats', 'enabled' => true],
                ['type' => 'advantage', 'enabled' => true],
            ], JSON_THROW_ON_ERROR),
            'home_stat_1_num' => '70+',
            'home_stat_1_text' => '年技术传承',
            'home_adv_1_title' => '十五年的行业经验',
            'home_adv_1_desc' => '保留原站优势说明',
        ];

        $document = HomeBloxDocument::load();
        $stats = $document['sections'][0]['columns'][0]['elements'][0]['data'];
        $advantage = $document['sections'][1]['columns'][0]['elements'][0]['data'];

        $this->assertSame('70+', $stats['stats_items'][0]['number']);
        $this->assertSame('年技术传承', $stats['stats_items'][0]['label']);
        $this->assertSame('十五年的行业经验', $advantage['advantage_items'][0]['title']);
        $this->assertSame('保留原站优势说明', $advantage['advantage_items'][0]['description']);
    }

    public function testLegacyCustomSectionsUseTheCurrentSiteLanguage(): void
    {
        $custom = static fn (string $title): string => json_encode([
            'title' => $title,
            'blocks' => [[
                'id' => 'pricing',
                'settings' => ['title' => $title],
                'columns' => [[
                    'id' => 'pricing-column',
                    'elements' => [[
                        'id' => 'pricing-heading',
                        'type' => 'heading',
                        'data' => ['text' => $title],
                    ]],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $GLOBALS['_test_config'] += [
            'home_blocks_config' => json_encode([
                ['type' => 'custom:1', 'enabled' => true],
            ], JSON_THROW_ON_ERROR),
            'home_custom_1' => $custom('价格方案'),
            'home_custom_1_en' => $custom('Pricing Plans'),
            'home_custom_1_ja' => $custom('料金プラン'),
        ];

        foreach (['zh-CN' => '价格方案', 'en' => 'Pricing Plans', 'ja' => '料金プラン'] as $lang => $title) {
            $GLOBALS['_test_config']['site_lang'] = $lang;
            $section = HomeBloxDocument::load()['sections'][0];

            $this->assertSame($title, $section['name']);
            $this->assertSame($title, $section['columns'][0]['elements'][0]['data']['text']);
        }
    }

    public function testOnlyUntouchedUnpublishedLegacyImportCanBeRefreshed(): void
    {
        $legacyImport = json_encode([
            'schema' => 1,
            'source' => 'legacy-import',
            'sections' => [],
        ], JSON_THROW_ON_ERROR);
        $GLOBALS['_test_config'] += [
            HomeBloxDocument::DATA_KEY => $legacyImport,
            HomeBloxDocument::ACTIVE_KEY => '0',
            HomeBloxDocument::PUBLISHED_KEY => '',
        ];

        $this->assertTrue(HomeBloxDocument::canRefreshLegacyImportDraft());

        $GLOBALS['_test_config'][HomeBloxDocument::DATA_KEY] = str_replace(
            'legacy-import',
            'legacy-import-complete',
            $legacyImport
        );
        $this->assertTrue(HomeBloxDocument::canRefreshLegacyImportDraft());

        $GLOBALS['_test_config'][HomeBloxDocument::ACTIVE_KEY] = '1';
        $this->assertFalse(HomeBloxDocument::canRefreshLegacyImportDraft());

        $GLOBALS['_test_config'][HomeBloxDocument::ACTIVE_KEY] = '0';
        $GLOBALS['_test_config'][HomeBloxDocument::DATA_KEY] = str_replace('legacy-import', 'blox', $legacyImport);
        $this->assertFalse(HomeBloxDocument::canRefreshLegacyImportDraft());

        $GLOBALS['_test_config'][HomeBloxDocument::DATA_KEY] = $legacyImport;
        $GLOBALS['_test_config'][HomeBloxDocument::PUBLISHED_KEY] = $legacyImport;
        $this->assertFalse(HomeBloxDocument::canRefreshLegacyImportDraft());
    }

    public function testRefreshingLegacyImportMarksTheMigrationComplete(): void
    {
        db()->execute(
            'CREATE TABLE IF NOT EXISTS settings ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', '
            . '"key" TEXT UNIQUE, value TEXT, type TEXT DEFAULT \'text\', '
            . 'name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)'
        );
        foreach ([HomeBloxDocument::DATA_KEY, HomeBloxDocument::PUBLISHED_KEY, HomeBloxDocument::ACTIVE_KEY] as $key) {
            db()->execute('DELETE FROM settings WHERE "key" = ?', [$key]);
        }

        $GLOBALS['_test_config'] += [
            'home_blocks_config' => json_encode([['type' => 'banner', 'enabled' => true]], JSON_THROW_ON_ERROR),
            HomeBloxDocument::DATA_KEY => json_encode([
                'schema' => 1,
                'source' => 'legacy-import',
                'sections' => [],
            ], JSON_THROW_ON_ERROR),
            HomeBloxDocument::PUBLISHED_KEY => '',
            HomeBloxDocument::ACTIVE_KEY => '0',
        ];

        $document = HomeBloxDocument::refreshLegacyImportDraft();

        $this->assertNotNull($document);
        $this->assertSame('legacy-import-complete-v2', $document['source']);
        $stored = (string) db()->fetchColumn(
            'SELECT value FROM settings WHERE "key" = ?',
            [HomeBloxDocument::DATA_KEY]
        );
        $this->assertStringContainsString('"source":"legacy-import-complete-v2"', $stored);

        $GLOBALS['_test_config'][HomeBloxDocument::DATA_KEY] = $stored;
        $this->assertFalse(HomeBloxDocument::canRefreshLegacyImportDraft());
    }

    public function testSavedEmptyDocumentDoesNotFallbackToLegacy(): void
    {
        $GLOBALS['_test_config']['home_blox_data'] = json_encode([
            'version' => 1,
            'source' => 'blox',
            'sections' => [],
        ], JSON_UNESCAPED_UNICODE);

        $document = HomeBloxDocument::load();

        $this->assertSame(1, $document['schema']);
        $this->assertSame([], $document['settings']);
        $this->assertSame('blox', $document['source']);
        $this->assertSame([], $document['sections']);
    }

    public function testClassicHomepageCreatesANonPublishedDraftWithoutOverwritingIt(): void
    {
        db()->execute(
            'CREATE TABLE IF NOT EXISTS settings ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', '
            . '"key" TEXT UNIQUE, value TEXT, type TEXT DEFAULT \'text\', '
            . 'name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)'
        );
        foreach ([
            HomeBloxDocument::DATA_KEY,
            HomeBloxDocument::PUBLISHED_KEY,
            HomeBloxDocument::ACTIVE_KEY,
        ] as $key) {
            db()->execute('DELETE FROM settings WHERE "key" = ?', [$key]);
        }
        $classicConfig = json_encode([
            ['type' => 'banner', 'enabled' => true],
            ['type' => 'channel:12', 'enabled' => false],
        ], JSON_THROW_ON_ERROR);
        $GLOBALS['_test_config']['home_blocks_config'] = $classicConfig;

        $document = HomeBloxDocument::createDraftFromLegacy();
        $stored = (string) db()->fetchColumn(
            'SELECT value FROM settings WHERE "key" = ?',
            [HomeBloxDocument::DATA_KEY]
        );

        $this->assertSame('legacy-import', $document['source']);
        $this->assertFalse($document['active']);
        $this->assertCount(3, $document['sections']);
        $this->assertSame('banner', $document['sections'][0]['columns'][0]['elements'][0]['data']['block_type']);
        $this->assertNotSame('', $stored);
        $this->assertFalse((bool) db()->fetchColumn(
            'SELECT COUNT(*) FROM settings WHERE "key" IN (?, ?)',
            [HomeBloxDocument::PUBLISHED_KEY, HomeBloxDocument::ACTIVE_KEY]
        ));
        $this->assertSame($classicConfig, $GLOBALS['_test_config']['home_blocks_config']);

        // 模拟下一请求读取已落库草稿；再次转换必须继续原草稿，不能按新经典配置覆盖。
        $GLOBALS['_test_config']['home_blox_data'] = $stored;
        $GLOBALS['_test_config']['home_blocks_config'] = json_encode([
            ['type' => 'cta', 'enabled' => true],
        ], JSON_THROW_ON_ERROR);
        $existing = HomeBloxDocument::createDraftFromLegacy();

        $this->assertSame($document['sections'], $existing['sections']);
        $this->assertSame($stored, db()->fetchColumn(
            'SELECT value FROM settings WHERE "key" = ?',
            [HomeBloxDocument::DATA_KEY]
        ));
    }

    public function testHomeDocumentsLoadStandardEnvelopeAndPreserveSettings(): void
    {
        $envelope = json_encode([
            'schema' => 1,
            'settings' => ['sticky' => true],
            'sections' => [[
                'id' => 'responsive-section',
                'settings' => ['padding' => ['d' => 'lg', 'm' => 'sm']],
                'columns' => [[
                    'id' => 'responsive-column',
                    'span' => ['d' => 8, 't' => 6],
                    'elements' => [],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $GLOBALS['_test_config']['home_blox_data'] = $envelope;
        $GLOBALS['_test_config']['home_layout_data'] = $envelope;

        $blox = HomeBloxDocument::load();
        $layout = HomeLayoutDocument::load();
        $this->assertSame(['sticky' => true], $blox['settings']);
        $this->assertSame(['sticky' => true], $layout['settings']);
        $this->assertSame(['d' => 'lg', 'm' => 'sm'], $blox['sections'][0]['settings']['padding']);
        $this->assertSame(['d' => 8, 't' => 6], $blox['sections'][0]['columns'][0]['span']);
        $this->assertSame(['d' => 'lg', 'm' => 'sm'], $layout['sections'][0]['settings']['padding']);
        $this->assertSame(['d' => 8, 't' => 6], $layout['sections'][0]['columns'][0]['span']);
    }

    public function testHomeDocumentRejectsFutureSchema(): void
    {
        $GLOBALS['_test_config']['home_blox_data'] = '{"schema":99,"sections":[]}';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_doc_schema_too_new');
        HomeBloxDocument::load();
    }

    public function testHomeDraftStatusIgnoresAutomaticImportAndEquivalentPublication(): void
    {
        $draft = [
            'schema' => 1,
            'settings' => [],
            'version' => 1,
            'source' => 'legacy-import',
            'updated_at' => 10,
            'sections' => [['id' => 'home-status', 'type' => 'section', 'settings' => [], 'columns' => []]],
        ];
        $GLOBALS['_test_config'][HomeBloxDocument::DATA_KEY] = json_encode($draft, JSON_THROW_ON_ERROR);
        $GLOBALS['_test_config'][HomeBloxDocument::PUBLISHED_KEY] = '';
        self::assertSame([], \BloxPublicationStatus::query(['/admin/blox_editor.php?home=1'], '/'));

        $draft['source'] = 'blox';
        $GLOBALS['_test_config'][HomeBloxDocument::DATA_KEY] = json_encode($draft, JSON_THROW_ON_ERROR);
        $items = \BloxPublicationStatus::query(['/admin/blox_editor.php?home=1'], '/?probe=1');
        self::assertCount(1, $items);
        self::assertSame('home', $items[0]['kind']);
        self::assertSame('/?probe=1&preview=draft&blox_draft=home', $items[0]['preview_url']);

        $published = $draft;
        $published['updated_at'] = 99;
        $GLOBALS['_test_config'][HomeBloxDocument::PUBLISHED_KEY] = json_encode($published, JSON_THROW_ON_ERROR);
        self::assertSame([], \BloxPublicationStatus::query(['/admin/blox_editor.php?home=1'], '/'));

        $draft['sections'][0]['id'] = 'home-status-changed';
        $GLOBALS['_test_config'][HomeBloxDocument::DATA_KEY] = json_encode($draft, JSON_THROW_ON_ERROR);
        self::assertCount(1, \BloxPublicationStatus::query(['/admin/blox_editor.php?home=1'], '/'));
    }

    public function testSaveAndPublishStoresTheSameValidatedDocumentAtomically(): void
    {
        db()->execute(
            'CREATE TABLE IF NOT EXISTS settings ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', '
            . '"key" TEXT UNIQUE, value TEXT, type TEXT DEFAULT \'text\', '
            . 'name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)'
        );
        $keys = [
            HomeBloxDocument::DATA_KEY,
            HomeBloxDocument::PUBLISHED_KEY,
            HomeBloxDocument::HISTORY_KEY,
            HomeBloxDocument::ACTIVE_KEY,
            HomeLayoutDocument::ACTIVE_KEY,
        ];
        foreach ($keys as $key) {
            db()->execute('DELETE FROM settings WHERE "key" = ?', [$key]);
        }

        $payload = json_encode([
            'schema' => 1,
            'settings' => [],
            'sections' => [[
                'id' => 'publish-section',
                'settings' => [],
                'columns' => [['id' => 'publish-column', 'span' => 12, 'elements' => []]],
            ]],
        ], JSON_THROW_ON_ERROR);

        $result = HomeBloxDocument::saveAndPublish($payload);
        $draft = (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [HomeBloxDocument::DATA_KEY]);
        $published = (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [HomeBloxDocument::PUBLISHED_KEY]);

        $this->assertSame($draft, $published);
        $this->assertSame('1', db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [HomeBloxDocument::ACTIVE_KEY]));
        $this->assertSame('0', db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [HomeLayoutDocument::ACTIVE_KEY]));
        $this->assertTrue($result['active']);
        $this->assertSame(1, $result['sections']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['base_revision']);
    }

    public function testHomeBlockPreviewEscapesLegacyTypeAndShowsDraftState(): void
    {
        $html = (new HomeBlockElement())->render([
            'block_type' => 'custom:<script>',
            'label' => '首页 <区块>',
            'enabled' => false,
        ]);

        $this->assertStringContainsString('data-home-block="custom:&lt;script&gt;"', $html);
        $this->assertStringContainsString('首页 &lt;区块&gt;', $html);
        $this->assertStringContainsString('blox_home_disabled', $html);
    }
}
