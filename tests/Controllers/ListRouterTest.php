<?php
/**
 * Tests for ListRouter::dispatch — the channel-type → controller map.
 *
 * Locked-in behavior:
 *   - 'download' → DownloadController
 *   - 'job'      → JobController
 *   - everything else (incl. unknowns) returns null until a controller
 *     for that type is extracted in later refactor steps.
 *
 * The viewName() default also gets a smoke test here so subclasses know
 * what view file the dispatcher will look for.
 */

declare(strict_types=1);

namespace Yikai\Tests\Controllers;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/controllers/list/ListRouter.php';

class ListRouterTest extends TestCase
{
    public function testDispatchesDownload(): void
    {
        $this->assertInstanceOf(\DownloadController::class, \ListRouter::dispatch('download'));
    }

    public function testDispatchesJob(): void
    {
        $this->assertInstanceOf(\JobController::class, \ListRouter::dispatch('job'));
    }

    public function testDispatchesProduct(): void
    {
        $this->assertInstanceOf(\ProductController::class, \ListRouter::dispatch('product'));
    }

    public function testDispatchesPageAndLinkToRedirect(): void
    {
        $this->assertInstanceOf(\PageRedirectController::class, \ListRouter::dispatch('page'));
        $this->assertInstanceOf(\PageRedirectController::class, \ListRouter::dispatch('link'));
    }

    public function testCaseListArticleAllFallToContent(): void
    {
        $this->assertInstanceOf(\ContentController::class, \ListRouter::dispatch('case'));
        $this->assertInstanceOf(\ContentController::class, \ListRouter::dispatch('list'));
        $this->assertInstanceOf(\ContentController::class, \ListRouter::dispatch('article'));
    }

    public function testUnknownTypeFallsToContent(): void
    {
        // Default branch is ContentController (content table). This is the
        // safest fallback since unknown types likely store rows there.
        $this->assertInstanceOf(\ContentController::class, \ListRouter::dispatch('totally-unknown'));
    }

    public function testViewNameDefaultStripsControllerSuffix(): void
    {
        $this->assertSame('download',     (new \DownloadController())->viewName());
        $this->assertSame('job',          (new \JobController())->viewName());
        $this->assertSame('product',      (new \ProductController())->viewName());
        $this->assertSame('content',      (new \ContentController())->viewName());
        $this->assertSame('pageredirect', (new \PageRedirectController())->viewName());
    }

    public function testShortCircuitDefaultsFalse(): void
    {
        $this->assertFalse((new \DownloadController())->shortCircuit(['type' => 'download']));
        $this->assertFalse((new \JobController())->shortCircuit(['type' => 'job']));
        $this->assertFalse((new \ContentController())->shortCircuit(['type' => 'list']));
    }
}
