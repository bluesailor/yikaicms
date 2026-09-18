<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/MarketDownloadUrl.php';

final class MarketDownloadUrlTest extends TestCase
{
    public function testOnlyCanonicalOfficialTokenUrlIsAccepted(): void
    {
        $url = MarketDownloadUrl::ENDPOINT . '?token=YWJj.ZGVm-_';
        self::assertTrue(MarketDownloadUrl::isTokenUrl($url));
        foreach ([
            str_replace('https:', 'http:', $url),
            str_replace('update.yikaicms.com', '127.0.0.1', $url),
            str_replace('update.yikaicms.com', 'update.yikaicms.com.evil.test', $url),
            str_replace('update.yikaicms.com', 'user@update.yikaicms.com', $url),
            str_replace('update.yikaicms.com', 'update.yikaicms.com:443', $url),
            str_replace('/download.php', '/other.php', $url),
            str_replace('/market/', '/market/../market/', $url),
            str_replace('token=', 'token[]=', $url),
            str_replace('YWJj.', 'YWJj%2e', $url),
            $url . '&token=second.value', $url . '&key=secret', $url . '#fragment',
            $url . "\n", MarketDownloadUrl::ENDPOINT . '?token=',
            MarketDownloadUrl::ENDPOINT . '?token=<expires-after-300s>',
            MarketDownloadUrl::ENDPOINT . '?token=' . str_repeat('a', 4096) . '.b',
        ] as $invalid) {
            self::assertFalse(MarketDownloadUrl::isTokenUrl($invalid), $invalid);
        }
    }
}
