<?php
/**
 * R1B/R1C：本机恢复稿可观察性与保存状态整理的源码契约。
 *
 * - 最近服务器保存时间只能在 acceptSavedDocument（服务器成功响应）里推进；
 * - 保存/发布四条链路都识别登录过期，不把"需要重新登录"报成一般失败；
 * - 失败的草稿保存有就地重试；恢复稿异常有可见指示与首次变化提示。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxSaveStatusR1ContractTest extends TestCase
{
    private static string $editor = '';
    private static string $header = '';
    private static string $recovery = '';

    public static function setUpBeforeClass(): void
    {
        self::$editor = (string) bloxEditorSourceForTest();
        self::$header = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/header.php');
        self::$recovery = (string) file_get_contents(ROOT_PATH . '/assets/js/blox-draft-recovery.js');
    }

    public function testServerSaveTimeOnlyAdvancesOnServerSuccess(): void
    {
        self::assertSame(1, substr_count(self::$editor, 'this.lastServerSaveAt = Date.now()'),
            'lastServerSaveAt 只允许一个赋值点');
        $method = substr(self::$editor, strpos(self::$editor, 'acceptSavedDocument(payload, savedData, res) {'));
        $method = substr($method, 0, strpos($method, 'isCanvasPublishedCurrent()'));
        self::assertStringContainsString('this.lastServerSaveAt = Date.now()', $method,
            '推进点必须在 acceptSavedDocument（服务器成功响应）内');
        self::assertStringContainsString('this.sessionExpired = false;', $method);
        // 时间只装饰安静状态；未保存/失败态不能带着旧时间造成"已保存"的错觉
        self::assertStringContainsString('case "saved": return this.withSaveTime(this.uiText.draftSaved);', self::$editor);
        self::assertStringContainsString('case "dirty": return this.uiText.unsaved;', self::$editor);
    }

    public function testAllFourSubmitFlowsDetectSessionExpiry(): void
    {
        self::assertGreaterThanOrEqual(4, substr_count(self::$editor, 'self.authExpiredResponse(r)'),
            '保存/页面发布/首页动作/模板发布都要识别登录过期');
        self::assertGreaterThanOrEqual(4, substr_count(self::$editor, 'self.handleAuthExpired(res,'));
        // 收尾任务 4 的契约更新：过期只认 401 或跳到登录页；403（权限/CSRF）走普通失败分支，
        // 细则见 BloxAuthExpiredContractTest。
        self::assertStringContainsString('if (r.status === 401) return true;', self::$editor);
        self::assertStringContainsString('r.redirected && String(r.url || "").indexOf("/admin/login.php")', self::$editor);
        self::assertStringContainsString("'sessionExpired' => __('blox_session_expired')", self::$editor);
    }

    public function testFailedDraftSaveOffersInlineRetry(): void
    {
        self::assertStringContainsString('data-testid="blox-save-retry"', self::$header);
        $button = substr(self::$header, strpos(self::$header, 'blox-save-retry') - 400, 800);
        self::assertStringContainsString('@click="save()"', $button);
        self::assertStringContainsString("failedAction !== 'publish'", $button, '发布失败走发布按钮，不复用草稿重试');
    }

    public function testRecoveryFailuresAreObservableOncePerTransition(): void
    {
        // JS 层：状态机 + 失败分类 + 失败保留旧副本/待写内容
        self::assertStringContainsString('this.state = "idle";', self::$recovery);
        self::assertStringContainsString('isQuotaError(error)', self::$recovery);
        self::assertStringContainsString('if (state === this.state) return;', self::$recovery,
            '只在状态变化时回调一次');
        self::assertStringContainsString('this.pending = pending;', self::$recovery,
            '写失败必须保留待写内容供重试');
        // 编辑器层：接线 + 可见指示
        self::assertStringContainsString('onStateChange: function (state) { self.onRecoveryStateChange(state); }', self::$editor);
        self::assertStringContainsString('data-testid="blox-recovery-state"', self::$header);
        // 恢复弹窗展示本机时间与"非服务器草稿"说明
        $overlays = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/overlays.php');
        self::assertStringContainsString('data-testid="blox-recovery-meta"', $overlays);
        self::assertStringContainsString('recoveryDraftTimeText()', $overlays);
    }

    public function testServerCleanupStillRequiresContentToMatchSavedSnapshot(): void
    {
        // 既有语义回归锚点：服务器保存成功后，只有当前内容等于已保存快照才清理恢复稿
        $method = substr(self::$editor, strpos(self::$editor, 'acceptSavedDocument(payload, savedData, res) {'));
        $method = substr($method, 0, strpos($method, 'isCanvasPublishedCurrent()'));
        self::assertStringContainsString('if (this.dirty) this.queueDraftRecovery();', $method);
        self::assertStringContainsString('else recovery.clear();', $method);
    }
}
