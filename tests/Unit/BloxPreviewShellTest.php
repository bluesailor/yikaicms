<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/HtmlTagRewriter.php';
require_once ROOT_PATH . '/includes/builder/BloxPreviewShell.php';

final class BloxPreviewShellTest extends TestCase
{
    public function testThemePresentationIsRetainedWithoutPageScriptsOrHandlers(): void
    {
        BloxPreviewShell::capture('<html><head><link rel="icon" href="/favicon.ico"><link href="/theme.css" rel="stylesheet"><style>.minimal-theme{color:red}</style><script src="/page.js"></script></head><body class="minimal-theme flex" style="background:#fff" onload="alert(1)"><header></header><main class="container" style="max-width:960px">');
        self::assertSame('<link href="/theme.css" rel="stylesheet"><style>.minimal-theme{color:red}</style>', BloxPreviewShell::$styles);
        self::assertSame('<body class="minimal-theme flex" style="background:#fff">', BloxPreviewShell::$body);
        self::assertSame('<main class="container" style="max-width:960px">', BloxPreviewShell::$main);
    }
}
