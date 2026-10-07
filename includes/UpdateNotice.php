<?php
/**
 * 已知的最新版本（2.0.6）：后台右上角铃铛读它，页面加载时不再请求更新服务器。
 *
 * 控制台检查、升级页检查、定时任务回访（AutoUpgrade::check）拿到 check.php 的结果后调 record()；
 * available() 只和本地 CMS_VERSION 比较——升级完自然消失，不需要「已读」状态。
 * 主题更新数由控制台的主题检测写进来（recordThemes）。
 */

declare(strict_types=1);

final class UpdateNotice
{
    private const KEY = 'update_latest_known';
    private const THEME_KEY = 'theme_updates_known';

    /** @param array<string,mixed> $data check.php 返回的 data 段 */
    public static function record(array $data): void
    {
        $latest = (string) ($data['latest_version'] ?? '');
        if (empty($data['has_update']) || preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/D', $latest) !== 1) {
            $latest = '';
        }
        $value = json_encode([
            'version' => $latest,
            'level' => in_array($data['level'] ?? '', ['security', 'feature', 'fix'], true) ? (string) $data['level'] : '',
            'checked_at' => time(),
        ], JSON_UNESCAPED_SLASHES);
        try {
            settingModel()->set(self::KEY, (string) $value, 'system');
        } catch (Throwable) {
            // 记不下来只是铃铛晚一点知道，不能让检查更新本身失败
        }
    }

    public static function recordThemes(int $count): void
    {
        try {
            settingModel()->set(self::THEME_KEY, (string) json_encode(['count' => max(0, $count), 'checked_at' => time()]), 'system');
        } catch (Throwable) {
        }
    }

    /**
     * 有可升级的新版本时返回版本号；按「更新提醒级别」过滤（off = 不提醒，security = 只提醒安全更新）。
     */
    public static function available(?string $current = null): string
    {
        $level = self::notifyLevel();
        if ($level === 'off') {
            return '';
        }
        $known = json_decode((string) config(self::KEY, ''), true);
        $version = is_array($known) ? (string) ($known['version'] ?? '') : '';
        $current ??= defined('CMS_VERSION') ? (string) CMS_VERSION : '0';
        if ($version === '' || version_compare($version, $current, '<=')) {
            return '';
        }
        if ($level === 'security' && (string) ($known['level'] ?? '') !== 'security') {
            return '';
        }
        return $version;
    }

    public static function themeUpdates(): int
    {
        if (self::notifyLevel() !== 'all') {
            return 0;
        }
        $known = json_decode((string) config(self::THEME_KEY, ''), true);
        return is_array($known) ? max(0, (int) ($known['count'] ?? 0)) : 0;
    }

    /** all / security / off（未设置时兼容旧的布尔开关，与控制台同一规则） */
    public static function notifyLevel(): string
    {
        $level = (string) config('update_notify_level', '');
        if ($level === '') {
            $level = (string) config('dashboard_update_check', '1') === '0' ? 'off' : 'all';
        }
        return in_array($level, ['all', 'security', 'off'], true) ? $level : 'all';
    }
}
