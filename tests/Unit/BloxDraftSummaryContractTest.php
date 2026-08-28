<?php
/** Editor wiring contract for the published-baseline draft summary. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxDraftSummaryContractTest extends TestCase
{
    public function testEditorLoadsPublishedBaselineAndSummaryEngine(): void
    {
        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');

        self::assertStringContainsString('published_document_json', $editor);
        self::assertStringContainsString('HomeBloxDocument::loadPublished()', $editor);
        self::assertStringContainsString('$publishedDocumentSource =', $editor);
        self::assertStringContainsString('/assets/js/blox-draft-summary.js', $editor);
        self::assertStringContainsString('publishedDocument:', $editor);
        self::assertStringContainsString('draftSummary()', $editor);
        self::assertStringContainsString('locateDraftChange(item)', $editor);
    }

    public function testSuccessfulPublishMovesTheComparisonBaseline(): void
    {
        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');

        self::assertGreaterThanOrEqual(3, substr_count($editor, 'acceptPublishedDocument(payload);'));
        self::assertStringContainsString('this.publishedDocument = document;', $editor);
    }

    public function testSummaryEntryAndPanelRemainVisibleTextControls(): void
    {
        $header = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/header.php');
        $overlays = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/overlays.php');

        self::assertStringContainsString('data-testid="blox-draft-summary-open"', $header);
        self::assertStringContainsString('draftSummaryCountText()', $header);
        self::assertStringContainsString('data-testid="blox-draft-summary-panel"', $overlays);
        self::assertStringContainsString('data-testid="blox-draft-summary-locate"', $overlays);
        self::assertStringContainsString('draftSummaryText.removedHint', $overlays);
    }
}
