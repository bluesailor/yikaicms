<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/UpgradeRunner.php';

/**
 * 升级状态机的故障恢复语义：状态文件损坏/丢失/不可写、finalize 后重放、
 * 游标越界与类型污染。补 UpgradeApplyStateTest 未覆盖的中断恢复面。
 */
final class UpgradeApplyRecoveryTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'yikai-upgrade-recovery-');
        self::assertNotFalse($path);
        $this->stateFile = $path;
    }

    protected function tearDown(): void
    {
        if (isset($this->stateFile) && is_file($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    /** @return array<string,mixed> */
    private function state(int $nextOffset, int $total = 93): array
    {
        return ['total' => $total, 'next_offset' => $nextOffset, 'entries' => [], 'done' => 0];
    }

    public function testCorruptedStateFileIsRejectedOnRead(): void
    {
        file_put_contents($this->stateFile, '{"total": 93, "next_off');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(UpgradeApplyState::ERROR_INVALID_STATE);
        UpgradeApplyState::read($this->stateFile);
    }

    public function testCorruptedStateFileAbortsTransactionWithoutRepair(): void
    {
        file_put_contents($this->stateFile, 'not json at all');

        try {
            UpgradeApplyState::transact($this->stateFile, static function (array &$state): int {
                $state['next_offset'] = 0;
                return 0;
            });
            self::fail('corrupted state must not enter the transaction callback');
        } catch (RuntimeException $e) {
            self::assertSame(UpgradeApplyState::ERROR_INVALID_STATE, $e->getMessage());
        }

        // 损坏内容原样保留，供人工排查；绝不静默重建进度。
        self::assertSame('not json at all', file_get_contents($this->stateFile));
    }

    public function testScalarJsonStateIsRejected(): void
    {
        file_put_contents($this->stateFile, '"just-a-string"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(UpgradeApplyState::ERROR_INVALID_STATE);
        UpgradeApplyState::read($this->stateFile);
    }

    public function testReplayAfterFinalizeDeletedStateFailsCleanly(): void
    {
        // finalize 成功后会 unlink 状态文件；迟到的 apply_batch/apply_finalize 重放
        // 必须拿到 invalid_state 而不是重建进度或致命错误。
        UpgradeApplyState::write($this->stateFile, $this->state(93));
        unlink($this->stateFile);

        try {
            UpgradeApplyState::transact($this->stateFile, static fn (array &$state): int => 0);
            self::fail('transact on a finalized (deleted) state must fail');
        } catch (RuntimeException $e) {
            self::assertSame(UpgradeApplyState::ERROR_INVALID_STATE, $e->getMessage());
        }

        try {
            UpgradeApplyState::read($this->stateFile);
            self::fail('read on a finalized (deleted) state must fail');
        } catch (RuntimeException $e) {
            self::assertSame(UpgradeApplyState::ERROR_INVALID_STATE, $e->getMessage());
        }
    }

    public function testUnopenableStatePathFailsWithIoError(): void
    {
        // 用目录冒充状态文件路径：跨平台地模拟 fopen 失败（chmod 在 drvfs 上无效）。
        $dir = sys_get_temp_dir() . '/yikai-upgrade-recovery-dir-' . getmypid();
        self::assertTrue(is_dir($dir) || mkdir($dir, 0755, true));

        try {
            UpgradeApplyState::write($dir, $this->state(0));
            self::fail('writing state to an unopenable path must fail');
        } catch (RuntimeException $e) {
            self::assertSame(UpgradeApplyState::ERROR_IO, $e->getMessage());
        } finally {
            @rmdir($dir);
        }
    }

    public function testCursorBeyondTotalIsRejectedAsInvalidState(): void
    {
        $state = $this->state(200);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(UpgradeApplyState::ERROR_INVALID_STATE);
        UpgradeApplyState::resolveOffset($state, null);
    }

    public function testNonIntegerPersistedCursorIsRejected(): void
    {
        // 手工改过或半截写坏的 state：next_offset 变字符串时拒绝，不猜测进度。
        $state = ['total' => 93, 'next_offset' => '80', 'entries' => []];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(UpgradeApplyState::ERROR_INVALID_STATE);
        UpgradeApplyState::resolveOffset($state, null);
    }

    public function testMissingTotalAndEntriesIsRejected(): void
    {
        $state = ['next_offset' => 0];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(UpgradeApplyState::ERROR_INVALID_STATE);
        UpgradeApplyState::resolveOffset($state, null);
    }

    public function testLostFinalizeResponseThenBatchReplayIsIdempotent(): void
    {
        // finalize 响应丢失但状态文件尚在（finalize 未执行到 unlink）：
        // 客户端重放 apply_batch 时游标已到 total，批次窗口为空、状态不回退。
        UpgradeApplyState::write($this->stateFile, $this->state(93));

        $window = UpgradeApplyState::transact($this->stateFile, static function (array &$state): array {
            $offset = UpgradeApplyState::resolveOffset($state, null);
            $end = min((int) $state['total'], $offset + 80);
            $state['next_offset'] = $end;
            return [$offset, $end];
        });

        self::assertSame([93, 93], $window, 'replayed batch after completion must copy nothing');
        $persisted = UpgradeApplyState::read($this->stateFile);
        self::assertSame(93, $persisted['next_offset']);
        self::assertTrue(UpgradeApplyState::isComplete($persisted));
    }
}
