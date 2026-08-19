<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/UpdateChannel.php';

final class UpdateChannelTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($GLOBALS['_test_config']['update_channel']);
    }

    public function testStableIsTheDefaultAndInvalidValuesFailClosed(): void
    {
        self::assertSame(UpdateChannel::STABLE, UpdateChannel::normalize(null));
        self::assertSame(UpdateChannel::STABLE, UpdateChannel::normalize('preview'));
        self::assertSame(UpdateChannel::STABLE, UpdateChannel::current());
    }

    public function testExplicitBetaSubscriptionIsPreserved(): void
    {
        $GLOBALS['_test_config']['update_channel'] = 'beta';

        self::assertSame(UpdateChannel::BETA, UpdateChannel::current());
    }
}
