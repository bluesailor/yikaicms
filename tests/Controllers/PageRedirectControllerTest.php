<?php
/**
 * Tests for PageRedirectController — handles `type=page` and `type=link`.
 *
 * The page-include path is awkward to exercise headlessly (it includes
 * the production page.php), so we cover only:
 *   - link with link_url issues a redirect and returns true
 *   - link without link_url passes through (returns false)
 *   - page is left for an integration test (would require page.php boot)
 */

declare(strict_types=1);

namespace Yikai\Tests\Controllers;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/controllers/list/PageRedirectController.php';

class PageRedirectControllerTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testLinkWithUrlSendsRedirect(): void
    {
        $ctrl = new \PageRedirectController();
        $result = $ctrl->shortCircuit([
            'type'     => 'link',
            'link_url' => 'https://example.com/',
        ]);
        $this->assertTrue($result);
        // Header inspection is environment-dependent (xdebug helper not
        // always loaded in CI). The return-value contract is enough — we
        // trust that header() succeeded if shortCircuit returned true.
        if (function_exists('xdebug_get_headers')) {
            $loc = array_filter(xdebug_get_headers(), fn($h) => stripos($h, 'Location:') === 0);
            $this->assertNotEmpty($loc);
        }
    }

    #[RunInSeparateProcess]
    public function testLinkWithoutUrlDoesNotShortCircuit(): void
    {
        $ctrl = new \PageRedirectController();
        $this->assertFalse($ctrl->shortCircuit([
            'type'     => 'link',
            'link_url' => '',
        ]));
    }

    #[RunInSeparateProcess]
    public function testUnrelatedTypeDoesNotShortCircuit(): void
    {
        $ctrl = new \PageRedirectController();
        $this->assertFalse($ctrl->shortCircuit(['type' => 'list']));
        $this->assertFalse($ctrl->shortCircuit(['type' => 'product']));
    }

    public function testPrepareReturnsEmptyArray(): void
    {
        $this->assertSame([], (new \PageRedirectController())->prepare([], []));
    }
}
