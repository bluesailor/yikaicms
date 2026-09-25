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
            (string) file_get_contents(ROOT_PATH . '/tests/fixtures/accessibility/problematic.html'),
            'problematic.html'
        );
        self::assertSame(
            ['image_alt', 'form_label', 'accessible_name', 'keyboard_click'],
            array_column($bad['issues'], 'code')
        );
        self::assertGreaterThan(0, $bad['issues'][0]['line']);

        $good = AccessibilityAudit::auditHtml(
            (string) file_get_contents(ROOT_PATH . '/tests/fixtures/accessibility/accessible.html'),
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

    public function testThemeAuditStopsDuringEnumerationAtFileAndEntryBudgets(): void
    {
        $root = sys_get_temp_dir() . '/yk-a11y-budget-' . bin2hex(random_bytes(6));
        $fileTheme = $root . '/themes/files';
        $entryTheme = $root . '/themes/entries';
        self::assertTrue(mkdir($fileTheme, 0777, true));
        self::assertTrue(mkdir($entryTheme, 0777, true));
        try {
            for ($index = 0; $index < AccessibilityAudit::MAX_THEME_FILES + 5; $index++) {
                file_put_contents($fileTheme . '/' . $index . '.html', '<p>ok</p>');
            }
            $fileAudit = AccessibilityAudit::auditTheme($fileTheme, $root);
            self::assertTrue($fileAudit['truncated']);
            self::assertFalse($fileAudit['unavailable']);
            self::assertSame(AccessibilityAudit::MAX_THEME_FILES, $fileAudit['files']);

            for ($index = 0; $index < AccessibilityAudit::MAX_THEME_ENTRIES + 5; $index++) {
                file_put_contents($entryTheme . '/' . $index . '.txt', 'ignored');
            }
            $entryAudit = AccessibilityAudit::auditTheme($entryTheme, $root);
            self::assertTrue($entryAudit['truncated']);
            self::assertFalse($entryAudit['unavailable']);
            self::assertSame(0, $entryAudit['files']);
        } finally {
            foreach (glob($fileTheme . '/*') ?: [] as $path) @unlink($path);
            foreach (glob($entryTheme . '/*') ?: [] as $path) @unlink($path);
            @rmdir($fileTheme);
            @rmdir($entryTheme);
            @rmdir($root . '/themes');
            @rmdir($root);
        }
    }

    public function testThemeAuditDoesNotFollowDirectoryLinksOutsideTheme(): void
    {
        $root = sys_get_temp_dir() . '/yk-a11y-link-' . bin2hex(random_bytes(6));
        $theme = $root . '/themes/test';
        $outside = $root . '/outside';
        $link = $theme . '/escape';
        self::assertTrue(mkdir($theme, 0777, true));
        self::assertTrue(mkdir($outside, 0777, true));
        file_put_contents($outside . '/outside.html', '<img src="outside">');
        try {
            $created = DIRECTORY_SEPARATOR === '\\'
                ? $this->createWindowsJunction($link, $outside)
                : @symlink($outside, $link);
            if (!$created) self::markTestSkipped('Directory link creation is unavailable');

            $audit = AccessibilityAudit::auditTheme($theme, $root);
            self::assertTrue($audit['truncated']);
            self::assertTrue($audit['unavailable']);
            self::assertSame([], $audit['issues']);
        } finally {
            if (DIRECTORY_SEPARATOR === '\\') @rmdir($link); else @unlink($link);
            @unlink($outside . '/outside.html');
            @rmdir($outside);
            @rmdir($theme);
            @rmdir($root . '/themes');
            @rmdir($root);
        }
    }

    private function createWindowsJunction(string $link, string $target): bool
    {
        $output = [];
        $exitCode = 1;
        exec('cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($target) . ' 2>NUL', $output, $exitCode);
        return $exitCode === 0 && is_dir($link);
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
