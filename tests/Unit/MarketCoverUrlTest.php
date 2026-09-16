<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/MarketCoverUrl.php';

final class MarketCoverUrlTest extends TestCase
{
    public function testOnlyExactOfficialEndpointAndResourceIdentityAreAllowed(): void
    {
        foreach (['theme', 'plugin', 'template'] as $kind) {
            $url = 'https://update.yikaicms.com/api/market/cover.php?kind=' . $kind . '&slug=cover-demo&version=1.0.0&hash=' . str_repeat('a', 64);
            self::assertSame($url, MarketCoverUrl::accept($url, $kind, 'cover-demo', '1.0.0'));
            foreach ([str_replace('https:', 'http:', $url), str_replace('update.yikaicms.com', 'evil.test', $url),
                $url . '&slug=cover-demo', $url . '#x', str_replace('1.0.0', '2.0.0', $url),
                str_replace('cover-demo', '../secret', $url), str_replace('/api/', '/%61pi/', $url)] as $invalid) {
                self::assertSame('', MarketCoverUrl::accept($invalid, $kind, 'cover-demo', '1.0.0'));
            }
        }
        self::assertSame('', MarketCoverUrl::accept([], 'theme', 'cover-demo', '1.0.0'));
    }
}
