<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once ROOT_PATH . '/includes/SiteSetup.php';

final class SiteSetupTest extends TestCase
{
    public function testBundledRecipesUseSupportedChannelModels(): void
    {
        foreach (['business-site', 'blog-basic'] as $slug) {
            $recipe = json_decode((string) file_get_contents(ROOT_PATH . '/recipes/' . $slug . '/recipe.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($recipe['channels'] as $channel) {
                self::assertContains($channel['type'], ['list', 'page', 'product', 'case']);
            }
            if ($slug === 'business-site') {
                self::assertSame('product', $recipe['channels'][0]['slug']);
                self::assertSame('product', $recipe['channels'][0]['type']);
            }
        }
    }

    public function testHomeSwitchPreservesContentAndRejectsStaleOrPinnedSettings(): void
    {
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/site-setup-home-probe.php'));
        self::assertIsString($output);
        self::assertSame(['switched' => true, 'preserved' => true, 'restored' => true,
            'stale_blocked' => true, 'override_blocked' => true], json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testHomeModeMatchesPublishedRenderingPrecedence(): void
    {
        foreach ([false, true] as $layoutActive) {
            foreach ([false, true] as $layoutPublished) {
                foreach ([false, true] as $builderActive) {
                    foreach ([false, true] as $builderPublished) {
                        $expected = $layoutActive && $layoutPublished ? 'layout'
                            : ($builderActive && $builderPublished ? 'builder' : 'theme');
                        self::assertSame($expected, SiteSetup::homeMode($layoutActive, $layoutPublished, $builderActive, $builderPublished));
                    }
                }
            }
        }
    }
}
