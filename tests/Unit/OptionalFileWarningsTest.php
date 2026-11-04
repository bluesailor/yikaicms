<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/i18n/LanguagePacks.php';
require_once ROOT_PATH . '/includes/MarketInstallOrigin.php';
require_once ROOT_PATH . '/includes/image.php';

final class OptionalFileWarningsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yk-optional-files-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage', 0700, true);
        mkdir($this->root . '/themes/default', 0700, true);
        // Capture even @-suppressed warnings, as the customer logger did.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    public function testAbsentAndMalformedLanguageManifestAreEmptyWithoutWarnings(): void
    {
        self::assertSame([], LanguagePacks::manifest($this->root));
        file_put_contents($this->root . '/storage/lang-packs.json', '{broken');
        self::assertSame([], LanguagePacks::manifest($this->root));
        file_put_contents($this->root . '/storage/lang-packs.json', json_encode(['fr' => ['version' => '1.0', 'sha256' => 'abc', 'installed_at' => 10]]));
        self::assertSame('1.0', LanguagePacks::manifest($this->root)['fr']['version']);
    }

    public function testAbsentAndMalformedThemeReceiptRemainUnlinkedWithoutWarnings(): void
    {
        self::assertNull(MarketInstallOrigin::linked($this->root . '/themes', 'default'));
        file_put_contents($this->root . '/themes/default/' . MarketInstallOrigin::FILE, '{broken');
        self::assertNull(MarketInstallOrigin::linked($this->root . '/themes', 'default'));
        file_put_contents($this->root . '/themes/default/' . MarketInstallOrigin::FILE, json_encode([
            'kind' => 'theme', 'slug' => 'default', 'origin' => 'official', 'provider' => 'update.yikaicms.com', 'market_slug' => 'business', 'rewrite' => [],
        ]));
        self::assertSame(['market_slug' => 'business', 'rewrite' => []], MarketInstallOrigin::linked($this->root . '/themes', 'default'));
    }

    public function testNewWebpTargetIsPlannedAndThenRecognizedAsCurrent(): void
    {
        $source = $this->root . '/image.png';
        file_put_contents($source, 'plan-only-source');
        $plan = webpDerivativePlan($source, 'png');
        self::assertFalse($plan['original']['current']);
        self::assertSame($this->root . '/image.webp', $plan['original']['target']);
        file_put_contents($this->root . '/image.webp', 'plan-only-target');
        clearstatcache();
        self::assertTrue(webpDerivativePlan($source, 'png')['original']['current']);
        self::assertFalse(webpDerivativePlan($source, 'png', true)['original']['current']);
    }
}
