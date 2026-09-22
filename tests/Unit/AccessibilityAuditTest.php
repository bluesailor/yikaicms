<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/AccessibilityAudit.php';

final class AccessibilityAuditTest extends TestCase
{
    public function testContrastUsesWcagNormalLargeAndUiThresholds(): void
    {
        $blackWhite = AccessibilityAudit::evaluateContrast('#000', '#fff');
        self::assertSame(21.0, $blackWhite['ratio']);
        self::assertTrue($blackWhite['normal']);
        self::assertTrue($blackWhite['large']);
        self::assertTrue($blackWhite['ui']);

        $largeOnly = AccessibilityAudit::evaluateContrast('#777777', '#ffffff');
        self::assertSame(4.48, $largeOnly['ratio']);
        self::assertFalse($largeOnly['normal']);
        self::assertTrue($largeOnly['large']);
        self::assertTrue($largeOnly['ui']);
    }

    public function testInvalidAndTransparentColorsAreUnknownRatherThanGuessed(): void
    {
        self::assertSame('invalid', AccessibilityAudit::evaluateContrast('var(--brand)', '#fff')['reason']);
        self::assertSame('transparent', AccessibilityAudit::evaluateContrast('#00000080', '#fff')['reason']);
        self::assertNull(AccessibilityAudit::evaluateContrast('transparent', '#fff')['ratio']);
    }

    public function testHtmlAuditReportsOnlyHighConfidenceFixtureProblems(): void
    {
        $bad = AccessibilityAudit::auditHtml(
            (string) file_get_contents(ROOT_PATH . '/tests/Fixtures/accessibility/problematic.html'),
            'problematic.html'
        );
        self::assertSame(
            ['image_alt', 'form_label', 'accessible_name', 'keyboard_click'],
            array_column($bad['issues'], 'code')
        );
        self::assertGreaterThan(0, $bad['issues'][0]['line']);

        $good = AccessibilityAudit::auditHtml(
            (string) file_get_contents(ROOT_PATH . '/tests/Fixtures/accessibility/accessible.html'),
            'accessible.html'
        );
        self::assertSame([], $good['issues']);
    }

    public function testCssAuditRequiresAVisibleFocusReplacement(): void
    {
        $issues = AccessibilityAudit::auditCss(
            "a:focus { outline: none; }\nbutton:focus-visible { outline: 0; box-shadow: 0 0 0 2px blue; }",
            'theme.css'
        );
        self::assertCount(1, $issues);
        self::assertSame('focus_hidden', $issues[0]['code']);
        self::assertSame(1, $issues[0]['line']);
    }

    public function testThemeAuditIsBoundedAndDoesNotExecutePhp(): void
    {
        $root = sys_get_temp_dir() . '/yk-a11y-' . bin2hex(random_bytes(6));
        $theme = $root . '/themes/test';
        self::assertTrue(mkdir($theme, 0777, true));
        $marker = $root . '/executed.txt';
        file_put_contents($theme . '/page.php', '<?php file_put_contents(' . var_export($marker, true) . ", 'bad'); ?><img src=\"x\"> ");
        try {
            $audit = AccessibilityAudit::auditTheme($theme, $root);
            self::assertFalse($audit['unavailable']);
            self::assertFileDoesNotExist($marker);
            self::assertSame('image_alt', $audit['issues'][0]['code']);
        } finally {
            @unlink($theme . '/page.php');
            @rmdir($theme);
            @rmdir($root . '/themes');
            @rmdir($root);
        }
    }

    public function testPublishedContentScanCapsRowsAndKeepsStableLocations(): void
    {
        $rows = [];
        for ($id = 1; $id <= AccessibilityAudit::MAX_CONTENT_ROWS + 5; $id++) {
            $rows[] = ['id' => $id, 'content' => '<img src="x">', 'blocks_data' => null];
        }
        $audit = AccessibilityAudit::auditContentRows($rows);
        self::assertTrue($audit['truncated']);
        self::assertLessThanOrEqual(AccessibilityAudit::MAX_CONTENT_ROWS, $audit['rows']);
        self::assertStringStartsWith('content#1', $audit['issues'][0]['location']);
    }
}
