<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once ROOT_PATH . '/includes/SiteAuthoringChecks.php';

final class SiteAuthoringChecksTest extends TestCase
{
    private function section(string $anchor = 'steps'): array
    {
        return ['type' => 'section', 'settings' => ['anchor_id' => $anchor], 'columns' => [['elements' => [
            ['type' => 'text', 'data' => ['html' => '<p>01</p>']],
            ['type' => 'container', 'data' => ['children' => [
                ['type' => 'heading', 'data' => ['text' => '02']],
                ['type' => 'text', 'data' => ['html' => '<strong>03</strong>']],
            ]]],
        ]]]];
    }

    public function testOneNumberedFlowIsNotAProblem(): void
    {
        self::assertSame([], SiteAuthoringChecks::inspect(json_encode([$this->section()], JSON_THROW_ON_ERROR))['issues']);
    }

    public function testRepeatedFlowsAndAnchorsAreSeparateHints(): void
    {
        $report = SiteAuthoringChecks::inspect(json_encode([$this->section(), $this->section()], JSON_THROW_ON_ERROR));
        self::assertSame(['sc_anchor_duplicate', 'sc_numbered_repeat'], array_column($report['issues'], 'kind'));
        self::assertFalse($report['limited']);
    }

    public function testNumbersInCopyAreNotDecorativeLabels(): void
    {
        $section = $this->section('copy');
        $section['columns'][0]['elements'] = [['type' => 'text', 'data' => ['html' => '<p>01 / 02 / 03</p>']]];
        self::assertNotContains('sc_numbered_repeat', array_column(SiteAuthoringChecks::inspect(json_encode([$this->section(), $section], JSON_THROW_ON_ERROR))['issues'], 'kind'));
    }

    public function testOnlyExplicitEmptyButtonTextIsFlagged(): void
    {
        $section = ['columns' => [['elements' => [
            ['id' => 'empty', 'type' => 'button', 'data' => ['text' => ' ']],
            ['id' => 'default', 'type' => 'button', 'data' => []],
            ['id' => 'dynamic', 'type' => 'button', 'data' => ['text' => '{{site.name}}']],
        ]]]];
        self::assertSame([['kind' => 'sc_button_label', 'detail' => 'empty']], SiteAuthoringChecks::inspect(json_encode([$section], JSON_THROW_ON_ERROR))['issues']);
    }

    public function testInvalidOrFutureDocumentsAreReportedWithoutThrowing(): void
    {
        foreach (['invalid', '{"schema":999,"sections":[]}', str_repeat(' ', 2000001)] as $json) {
            self::assertSame('sc_builder_invalid', SiteAuthoringChecks::inspect($json)['issues'][0]['kind']);
        }
    }

    public function testPublicationComparisonIgnoresEnvelopeMetadata(): void
    {
        $sections = [$this->section()];
        $draft = json_encode(['schema' => 1, 'sections' => $sections, 'updated_at' => 99], JSON_THROW_ON_ERROR);
        self::assertFalse(SiteAuthoringChecks::hasChanges($draft, json_encode($sections, JSON_THROW_ON_ERROR)));
        self::assertTrue(SiteAuthoringChecks::hasChanges($draft, '[]'));
        self::assertTrue(SiteAuthoringChecks::hasChanges($draft, ''));
        self::assertFalse(SiteAuthoringChecks::hasChanges('', $draft));
    }

    public function testSpecialDocumentSettingsLoadWithoutRendererBootstrap(): void
    {
        $json = json_encode(['schema' => 1, 'sections' => [], 'settings' => [
            'product_template' => ['mode' => 'all'], 'detail_template' => ['kind' => 'all'],
            'dot_nav' => ['enabled' => true], 'sticky_behavior' => 'always', 'header_states' => [],
        ]], JSON_THROW_ON_ERROR);
        self::assertSame([], SiteAuthoringChecks::inspect($json)['issues']);
    }

    public function testTraversalLimitIsVisible(): void
    {
        self::assertTrue(SiteAuthoringChecks::inspect(json_encode(array_fill(0, 101, ['columns' => []]), JSON_THROW_ON_ERROR))['limited']);
    }

    public function testStoredDocumentsAreCheckedReadOnlyWithCorrectTargets(): void
    {
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/site-authoring-checks-probe.php'));
        self::assertSame("Site authoring checks passed\n", $output);
    }
}
