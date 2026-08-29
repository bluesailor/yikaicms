<?php
/** Multilingual Header/Footer draft lifecycle. */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use BloxAreaLanguageManager;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxHeaderStates.php';
require_once ROOT_PATH . '/includes/builder/BloxDocumentPipeline.php';
require_once ROOT_PATH . '/includes/builder/BloxAreaDocument.php';
require_once ROOT_PATH . '/includes/builder/BloxAreaResolver.php';
require_once ROOT_PATH . '/includes/builder/BloxAreaLanguageManager.php';

final class BloxAreaLanguageManagerTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT 'user',
                source_ref TEXT NOT NULL DEFAULT '',
                schema_version INTEGER NOT NULL DEFAULT 1,
                draft_data TEXT NOT NULL,
                published_data TEXT,
                requirements TEXT,
                metadata TEXT,
                conditions TEXT,
                thumbnail TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 0,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testCopyCreatesOneUnpublishedLanguageDraftAndReusesIt(): void
    {
        $sourceId = bloxTemplateModel()->createDraft(
            'header',
            'Corporate header',
            '{"schema":1,"settings":{},"sections":[]}',
            'builtin',
            1,
            ['elements' => ['site-logo']],
            '/header.png',
            3,
            'corporate-header',
            ['purpose' => 'general']
        );
        bloxTemplateModel()->publishDraft($sourceId);

        $created = BloxAreaLanguageManager::createLanguageDraft(
            $sourceId,
            'header',
            'en',
            ['zh-CN' => '中文', 'en' => 'English'],
            7
        );
        $row = bloxTemplateModel()->findForExport($created['id']);

        self::assertFalse($created['reused']);
        self::assertSame('Corporate header · English', $row['name'] ?? null);
        self::assertSame(0, (int) ($row['status'] ?? -1));
        self::assertSame('user', $row['source'] ?? null);
        self::assertSame(
            [['main' => 'any', 'ids' => [], 'langs' => ['en'], 'exclude' => false]],
            json_decode((string) ($row['conditions'] ?? ''), true)
        );
        self::assertSame(
            ['elements' => ['site-logo'], 'plugins' => [], 'design_tokens' => [], 'design_styles' => []],
            json_decode((string) ($row['requirements'] ?? ''), true)
        );

        $reused = BloxAreaLanguageManager::createLanguageDraft(
            $sourceId,
            'header',
            'en',
            ['zh-CN' => '中文', 'en' => 'English'],
            9
        );
        self::assertTrue($reused['reused']);
        self::assertSame($created['id'], $reused['id']);
    }

    public function testRestoreInheritanceUnpublishesOnlyManagedSingleLanguageTemplate(): void
    {
        $managedId = bloxTemplateModel()->createDraft('footer', 'English footer', '{"schema":1,"settings":{},"sections":[]}');
        bloxTemplateModel()->saveConditions($managedId, [['main' => 'any', 'ids' => [], 'langs' => ['en'], 'exclude' => false]]);
        bloxTemplateModel()->publishDraft($managedId);

        $advancedId = bloxTemplateModel()->createDraft('footer', 'English pages', '{"schema":1,"settings":{},"sections":[]}');
        bloxTemplateModel()->saveConditions($advancedId, [['main' => 'home', 'ids' => [], 'langs' => ['en'], 'exclude' => false]]);
        bloxTemplateModel()->publishDraft($advancedId);

        self::assertSame(
            [$managedId],
            BloxAreaLanguageManager::restoreInheritance('footer', 'en', ['en' => 'English'])
        );
        self::assertSame(0, (int) (bloxTemplateModel()->find($managedId)['status'] ?? -1));
        self::assertSame(1, (int) (bloxTemplateModel()->find($advancedId)['status'] ?? -1));
    }
}
