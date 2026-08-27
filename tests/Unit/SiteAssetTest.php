<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/SiteAsset.php';

final class SiteAssetTest extends TestCase
{
    public function testLocalRemoteMissingAndInvalidAssetsAreDistinguished(): void
    {
        self::assertSame(
            SiteAsset::LOCAL_AVAILABLE,
            SiteAsset::inspect('/images/logo.png', ROOT_PATH)['state']
        );
        self::assertSame(
            SiteAsset::LOCAL_MISSING,
            SiteAsset::inspect('/uploads/brand/missing.png', ROOT_PATH)['state']
        );
        self::assertSame(SiteAsset::REMOTE, SiteAsset::inspect('https://cdn.example.test/logo.svg', ROOT_PATH)['state']);
        self::assertSame(SiteAsset::REMOTE, SiteAsset::inspect('//cdn.example.test/logo.svg', ROOT_PATH)['state']);
        self::assertSame(SiteAsset::INVALID, SiteAsset::inspect('../config/config.php', ROOT_PATH)['state']);
        self::assertSame(SiteAsset::INVALID, SiteAsset::inspect('/uploads/%00logo.png', ROOT_PATH)['state']);
        self::assertSame(SiteAsset::INVALID, SiteAsset::inspect('javascript:alert(1)', ROOT_PATH)['state']);
    }

    public function testAvailableUrlOnlyReturnsRenderableAssets(): void
    {
        self::assertSame('/images/logo.png?v=1', SiteAsset::availableUrl('/images/logo.png?v=1', ROOT_PATH));
        self::assertSame('/images/logo.png?v=1#brand', SiteAsset::availableUrl('images/logo.png?v=1#brand', ROOT_PATH));
        self::assertSame('https://cdn.example.test/logo.png', SiteAsset::availableUrl('https://cdn.example.test/logo.png', ROOT_PATH));
        self::assertSame('', SiteAsset::availableUrl('/uploads/brand/missing.png', ROOT_PATH));
        self::assertSame('', SiteAsset::availableUrl('data:image/png;base64,AAAA', ROOT_PATH));
    }

    public function testBareLocalPathIsCanonicalizedWithoutWeakeningTraversalChecks(): void
    {
        $asset = SiteAsset::inspect('images/logo.png', ROOT_PATH);

        self::assertSame(SiteAsset::LOCAL_AVAILABLE, $asset['state']);
        self::assertSame('/images/logo.png', $asset['url']);
        self::assertSame('/images/logo.png', $asset['path']);
        self::assertSame(SiteAsset::INVALID, SiteAsset::inspect('uploads/../config/config.php', ROOT_PATH)['state']);
    }
}
