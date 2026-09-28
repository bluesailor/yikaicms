<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The admin bar and draft preview bar make room with html { margin-top }, which
 * absolutely/fixed positioned headers ignore. Core overlay headers are handled
 * in CSS; theme-built overlay headers (Havenform's home #siteHeader) rely on the
 * script fallback, which must never offset a header twice.
 */
final class AdminBarHeaderOffsetTest extends TestCase
{
    public function testBothBarsPushThemeOverlayHeadersBelowThemselves(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/admin_bar.php');

        self::assertStringContainsString("adminBarHeaderOffsetScript('ik-adminbar');", $source);
        self::assertStringContainsString("adminBarHeaderOffsetScript('ik-draft-previewbar');", $source);

        $script = substr($source, (int) strpos($source, 'function adminBarHeaderOffsetScript'), 2400);
        self::assertStringContainsString("style.position !== 'absolute' && style.position !== 'fixed'", $script);
        // Headers already below the bar (core CSS, a theme's own script) are left alone.
        self::assertStringContainsString('if (top >= height - 1) return;', $script);
        self::assertStringContainsString("header.hasAttribute('data-yk-adminbar-offset')", $script);
        // Headers that only become fixed after scrolling are caught too.
        self::assertStringContainsString("window.addEventListener('scroll', schedule, { passive: true });", $script);
    }
}
