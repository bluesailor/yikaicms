<?php
/** Header/Footer dedicated assignment lifecycle. */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use BloxAreaAssignmentManager;
use RuntimeException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxHeaderStates.php';
require_once ROOT_PATH . '/includes/builder/BloxDocumentPipeline.php';
require_once ROOT_PATH . '/includes/builder/BloxAreaDocument.php';
require_once ROOT_PATH . '/includes/builder/BloxAreaResolver.php';
require_once ROOT_PATH . '/includes/builder/BloxAreaAssignmentManager.php';

final class BloxAreaAssignmentManagerTest extends TestCase
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

    public function testCopyCreatesOneDedicatedDraftAndRestoreKeepsItsSource(): void
    {
        $sourceId = bloxTemplateModel()->createDraft(
            'header',
            'Default header',
            '{"schema":1,"settings":{},"sections":[]}',
            'builtin',
            1,
            ['elements' => ['site-logo']],
            '/header.png',
            3
        );
        bloxTemplateModel()->publishDraft($sourceId);
        $target = BloxAreaAssignmentManager::contextFromKey('page:8', [
            'channel' => [],
            'page' => [['id' => 8, 'label' => 'About', 'lang' => 'ja']],
        ], 'zh-CN');

        $created = BloxAreaAssignmentManager::createDedicatedDraft(
            $sourceId,
            'header',
            $target['context'],
            $target['label'],
            7
        );
        $row = bloxTemplateModel()->findForExport($created['id']);

        self::assertFalse($created['reused']);
        self::assertSame('Default header · About', $row['name'] ?? null);
        self::assertSame(0, (int) ($row['status'] ?? -1));
        self::assertSame('user', $row['source'] ?? null);
        self::assertSame(
            [['main' => 'page', 'ids' => [8], 'langs' => ['ja'], 'exclude' => false]],
            json_decode((string) ($row['conditions'] ?? ''), true)
        );

        bloxTemplateModel()->publishDraft($created['id']);
        $reused = BloxAreaAssignmentManager::createDedicatedDraft(
            $sourceId,
            'header',
            $target['context'],
            $target['label'],
            9
        );
        self::assertTrue($reused['reused']);
        self::assertSame($created['id'], $reused['id']);
        self::assertSame(
            [$created['id']],
            BloxAreaAssignmentManager::restoreInheritance('header', $target['context'])
        );
        self::assertSame(0, (int) (bloxTemplateModel()->find($created['id'])['status'] ?? -1));
        self::assertSame(1, (int) (bloxTemplateModel()->find($sourceId)['status'] ?? -1));
    }

    public function testContextKeyMustResolveToARealEntity(): void
    {
        $channel = BloxAreaAssignmentManager::contextFromKey('channel:7', [
            'channel' => [['id' => 7, 'label' => 'Products', 'lang' => 'en']],
        ], 'zh-CN');
        self::assertSame(
            [['main' => 'channel', 'ids' => [7], 'langs' => ['en'], 'exclude' => false]],
            BloxAreaAssignmentManager::conditionsFor($channel['context'])
        );

        $this->expectException(RuntimeException::class);
        BloxAreaAssignmentManager::contextFromKey('page:999', ['page' => []], 'zh-CN');
    }
}
