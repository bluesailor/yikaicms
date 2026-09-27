<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The Blox canvas only lifts the theme header's body markup into its own
 * document. Themes such as Havenform link their stylesheet in the header
 * <head> and scope rules under a body class, so the canvas must carry both
 * over or the theme banner/footer render as unstyled text.
 */
final class BloxCanvasThemeAssetsTest extends TestCase
{
    private const THEME_HEADER = '<!doctype html><html><head>'
        . '<link rel="stylesheet" href="/assets/css/tailwind.css?v=1">'
        . '<link rel="preload" href="/assets/fonts/a.woff2">'
        . '<link rel="stylesheet" href="https://fonts.example.com/css">'
        . '<link rel="stylesheet" href="//cdn.example.com/x.css">'
        . '<link rel="stylesheet" href="/themes/havenform/assets/css/havenform.css?v=2">'
        . '<link href="/themes/havenform/assets/css/extra.css" rel="stylesheet">'
        . '</head><body class="yk-site-body havenform-theme home-page  bg-gray-50">'
        . '<link rel="stylesheet" href="/themes/havenform/assets/css/in-body.css">'
        . '<header id="siteHeader"></header><main></main></body></html>';

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnlySameSiteHeadStylesheetsAreCarriedOver(): void
    {
        require_once ROOT_PATH . '/includes/builder/BloxCanvasPreview.php';

        $links = bloxCanvasThemeStylesheets(self::THEME_HEADER);

        self::assertSame([
            '<link rel="stylesheet" href="/assets/css/tailwind.css?v=1">',
            '<link rel="stylesheet" href="/themes/havenform/assets/css/havenform.css?v=2">',
            '<link rel="stylesheet" href="/themes/havenform/assets/css/extra.css">',
        ], $links);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCanvasHeadSkipsStylesheetsItAlreadyLoads(): void
    {
        require_once ROOT_PATH . '/includes/builder/BloxCanvasPreview.php';
        $GLOBALS['ykCanvasThemeStylesheets'] = bloxCanvasThemeStylesheets(self::THEME_HEADER);

        self::assertSame(
            '<link rel="stylesheet" href="/themes/havenform/assets/css/havenform.css?v=2">'
            . '<link rel="stylesheet" href="/themes/havenform/assets/css/extra.css">',
            bloxCanvasExtraThemeStylesheets()
        );
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCanvasBodyCarriesTheThemeBodyClasses(): void
    {
        require_once ROOT_PATH . '/includes/builder/BloxCanvasPreview.php';

        $GLOBALS['ykPageLayout'] = [];
        $GLOBALS['ykCanvasThemeBodyClass'] = 'yk-site-body havenform-theme home-page md:flex "><script>';
        self::assertSame(' class="yk-site-body havenform-theme home-page md:flex"', bloxCanvasBodyClassAttr());

        unset($GLOBALS['ykPageLayout'], $GLOBALS['ykCanvasThemeBodyClass']);
        self::assertSame('', bloxCanvasBodyClassAttr());
    }

    public function testThemeHeaderIsRenderedEvenWhenBloxOwnsTheHeader(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxCanvasPreview.php');

        self::assertStringContainsString("\$GLOBALS['ykCanvasThemeStylesheets'] = bloxCanvasThemeStylesheets(\$rendered);", $source);
        self::assertStringContainsString("\$headerBlox !== '' ? \$headerBlox : \$themeHeader,", $source);
        self::assertStringContainsString('. bloxCanvasExtraThemeStylesheets()', $source);
        self::assertStringContainsString("</style></head><body' . bloxCanvasBodyClassAttr() . '>'", $source);
        // A theme that overlays its own header on the home banner must stay above page content.
        self::assertStringContainsString('.yk-home-context-area[data-yk-region="header"]{z-index:60}', $source);
    }
}
