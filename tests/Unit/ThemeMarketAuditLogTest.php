<?php

/**
 * 主题市场安装流程的失败路径必须留痕。
 *
 * 2026-09-07 演示站连续三次装不上主题，服务器日志里唯一的痕迹是
 * `unlink(/tmp/ykthmXXXXXX): No such file or directory` —— 一条被 @ 抑制却仍写进
 * 日志的误导性警告。真正的失败码（invalid_url / open_target / too_large / http_error …）
 * 在 admin/theme.php 里被整个丢弃，运维查不出任何东西。
 *
 * 哈希不符与验签失败更严重：那是安全事件（包被换过、传输被篡改），原来同样零记录，
 * 事后无从判断发生过几次、针对哪个包。
 *
 * admin/*.php 是页面脚本，没法在单测里直接执行（顶部就 checkLogin()），所以这里锁的是
 * 源码契约——和仓库里 BloxAssetPolicyTest 守 CI 工作流的做法一致。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeMarketAuditLogTest extends TestCase
{
    private static function source(): string
    {
        $path = ROOT_PATH . '/admin/theme.php';
        self::assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /** @return array<string,array{string}> 失败场景 => 该场景必须记录的 adminLog 动作名 */
    public static function failurePaths(): array
    {
        return [
            '暂存文件创建失败' => ['market_staging_failed'],
            '包下载失败' => ['market_download_failed'],
            '包哈希与注册表不符' => ['market_hash_mismatch'],
            '包签名验证不通过' => ['market_signature_rejected'],
        ];
    }

    /** @dataProvider failurePaths */
    public function testEveryFailurePathWritesAnAdminLog(string $action): void
    {
        self::assertStringContainsString(
            "adminLog('theme', '{$action}'",
            self::source(),
            "主题市场的失败路径 {$action} 必须写审计日志；没有它，线上出问题时日志里什么都查不到"
        );
    }

    public function testDownloadFailureRecordsTheSpecificFailureCode(): void
    {
        $source = self::source();
        // 光记「下载失败了」没用，必须带上区分是哪一步断的失败码。
        self::assertMatchesRegularExpression(
            "/market_download_failed[^;]*\\\$download\['code'\]/s",
            $source,
            '下载失败日志必须带上 $download[\'code\']，否则分不清 invalid_url / open_target / too_large'
        );
    }

    public function testIntegrityFailuresRecordBothExpectedAndActualHash(): void
    {
        // 哈希不符时只说「不符」无法追查，要能看出期望值与实际值。
        self::assertMatchesRegularExpression(
            '/market_hash_mismatch[^;]*expected=.*actual=/s',
            self::source(),
            '哈希不符日志必须同时记录期望值与实际值'
        );
    }

    public function testStagedFileIsDiscardedThroughTheGuardedHelper(): void
    {
        $source = self::source();
        self::assertStringContainsString(
            'function themeDiscardStaged(string $path): void',
            $source,
            '暂存文件清理要走带存在性判断的辅助函数'
        );
        // 无条件 @unlink($tmpZip) 会在文件已不存在时留下误导性警告，
        // 那条噪音正是 2026-09-07 把排查带偏的东西。
        self::assertStringNotContainsString(
            '@unlink($tmpZip)',
            $source,
            '不要再无条件 @unlink 暂存文件：文件不存在时会写一条误导性的警告到错误日志'
        );
    }
}
