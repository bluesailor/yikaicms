<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/UpgradeRunner.php';

final class UpgradeApplyStateTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yikai-upgrade-state-');
        self::assertNotFalse($path);
        $this->stateFile = $path;
    }

    protected function tearDown(): void
    {
        if (isset($this->stateFile) && is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    public function testMissingOffsetAdvancesFromPersistedServerCursor(): void
    {
        UpgradeApplyState::write($this->stateFile, $this->state(80));

        $result = UpgradeApplyState::transact($this->stateFile, static function (array &$state): int {
            $offset = UpgradeApplyState::resolveOffset($state, null);
            $state['next_offset'] = min((int) $state['total'], $offset + 80);
            return $offset;
        });

        self::assertSame(80, $result);
        self::assertSame(160, UpgradeApplyState::read($this->stateFile)['next_offset']);
    }

    public function testRepeatedRequestsWithoutOffsetReachCompletion(): void
    {
        $state = $this->state(0);
        $state['total'] = 205;
        UpgradeApplyState::write($this->stateFile, $state);

        $nextOffsets = [];
        while (!UpgradeApplyState::isComplete(UpgradeApplyState::read($this->stateFile))) {
            $nextOffsets[] = UpgradeApplyState::transact($this->stateFile, static function (array &$current): int {
                $offset = UpgradeApplyState::resolveOffset($current, null);
                $current['next_offset'] = min((int) $current['total'], $offset + 80);
                return $current['next_offset'];
            });
            self::assertLessThanOrEqual(4, count($nextOffsets), 'Missing-offset calls must not loop on the first batch.');
        }

        self::assertSame([80, 160, 205], $nextOffsets);
    }

    public function testStaleClientOffsetCannotRewindServerCursor(): void
    {
        $state = $this->state(160);

        self::assertSame(160, UpgradeApplyState::resolveOffset($state, '0'));
        self::assertSame(160, UpgradeApplyState::resolveOffset($state, '80'));
        self::assertSame(160, UpgradeApplyState::resolveOffset($state, '160'));
    }

    public function testClientCannotSkipAheadOfServerCursor(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(UpgradeApplyState::ERROR_OFFSET_AHEAD);

        UpgradeApplyState::resolveOffset($this->state(80), '160');
    }

    public function testLegacyStateCanBeTakenOverFromClientOffsetOnce(): void
    {
        $state = $this->state(0);
        unset($state['next_offset']);

        self::assertSame(160, UpgradeApplyState::resolveOffset($state, '160'));
        self::assertSame(0, UpgradeApplyState::resolveOffset($state, null));
    }

    /** @dataProvider invalidOffsets */
    public function testMalformedOffsetsAreRejected(mixed $offset): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(UpgradeApplyState::ERROR_INVALID_OFFSET);

        UpgradeApplyState::resolveOffset($this->state(0), $offset);
    }

    /** @return array<string,array{0:mixed}> */
    public static function invalidOffsets(): array
    {
        return [
            'negative integer' => [-1],
            'negative string' => ['-1'],
            'decimal' => ['1.5'],
            'array' => [['80']],
            'empty string' => [''],
            'overflow' => [str_repeat('9', 40)],
        ];
    }

    public function testFailedTransactionDoesNotPersistMutatedCursor(): void
    {
        UpgradeApplyState::write($this->stateFile, $this->state(80));

        try {
            UpgradeApplyState::transact($this->stateFile, static function (array &$state): void {
                $state['next_offset'] = 160;
                throw new RuntimeException('batch_failed');
            });
            self::fail('Expected transaction failure');
        } catch (RuntimeException $e) {
            self::assertSame('batch_failed', $e->getMessage());
        }

        self::assertSame(80, UpgradeApplyState::read($this->stateFile)['next_offset']);
    }

    public function testCompletionRequiresNewServerCursorToReachTotal(): void
    {
        self::assertFalse(UpgradeApplyState::isComplete($this->state(160)));
        self::assertTrue(UpgradeApplyState::isComplete($this->state(200)));

        $legacy = $this->state(0);
        unset($legacy['next_offset']);
        self::assertTrue(UpgradeApplyState::isComplete($legacy));
    }

    /** @return array<string,mixed> */
    private function state(int $nextOffset): array
    {
        return [
            'total' => 200,
            'next_offset' => $nextOffset,
            'entries' => [],
            'pkg' => 'package.zip',
        ];
    }
}
