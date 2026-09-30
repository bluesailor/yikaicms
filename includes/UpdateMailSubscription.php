<?php
/**
 * YikaiCMS —— 升级与安全邮件通知订阅（2.0.3，WP-14 B）。
 *
 * 站长主动勾选后，站点回访 update 服务器时附带一个通知邮箱，服务器在发布安全更新、
 * 重要版本时按这个邮箱发信。出过严重安全问题时，这是唯一能主动找到站长的渠道——
 * 回访本身不含任何联系方式。
 *
 * 边界：
 *   - 只有站长勾选订阅才上报邮箱与语言；不勾选不上报任何个人信息。
 *   - 取消订阅后，下一次回访带空邮箱（服务器据此删除记录），送达后不再带。
 *   - 邮箱走 POST 正文，不进 URL（服务器与 CDN 的访问日志会记录完整 URL）。
 *   - 客户端不发邮件；邮件由更新服务器发送（WP-15）。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class UpdateMailSubscription
{
    private const ON = 'update_mail_on';
    private const EMAIL = 'update_mail_email';
    private const LANG = 'update_mail_lang';
    /** 退订尚未送达服务器：下一次回访带空邮箱。 */
    private const UNSUB_PENDING = 'update_mail_unsub_pending';
    /** 控制台「订阅安全通知」提示条已被站长关掉。 */
    private const PROMPT_DISMISSED = 'update_mail_prompt_dismissed';

    public const LANGS = ['zh-CN', 'en', 'ja'];

    /** @return array{on: bool, email: string, lang: string} */
    public static function current(): array
    {
        $email = (string) self::get(self::EMAIL, '');
        return [
            'on' => (string) self::get(self::ON, '0') === '1' && self::validEmail($email),
            'email' => $email,
            'lang' => self::normalizeLang((string) self::get(self::LANG, '')),
        ];
    }

    public static function validEmail(string $email): bool
    {
        return $email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function normalizeLang(string $lang): string
    {
        $lang = strtolower(trim($lang));
        return match (true) {
            str_starts_with($lang, 'ja') => 'ja',
            str_starts_with($lang, 'en') => 'en',
            default => 'zh-CN',
        };
    }

    /** 订阅；邮箱不合规返回 false，什么也不改。 */
    public static function subscribe(string $email, string $lang): bool
    {
        $email = trim($email);
        if (!self::validEmail($email)) {
            return false;
        }
        self::save([
            self::ON => '1',
            self::EMAIL => $email,
            self::LANG => self::normalizeLang($lang),
            self::UNSUB_PENDING => '0',
        ]);
        return true;
    }

    /** 退订：本地立即停止上报，并在下一次回访时通知服务器删除。 */
    public static function unsubscribe(): void
    {
        $wasOn = self::current()['on'] || (string) self::get(self::UNSUB_PENDING, '0') === '1';
        self::save([
            self::ON => '0',
            self::UNSUB_PENDING => $wasOn ? '1' : '0',
        ]);
    }

    /** 直接读设置表：这些键只由本类写入，不参与 config/overrides.php 覆盖。 */
    private static function get(string $key, string $default): string
    {
        return (string) settingModel()->get($key, $default);
    }

    /** 逐键写入 system 组（saveBatch 对未登记的新键会落到「基础设置」组）。 */
    private static function save(array $values): void
    {
        foreach ($values as $key => $value) {
            settingModel()->set((string) $key, (string) $value, 'system');
        }
    }

    /**
     * 本次回访要附带的字段（放进 POST 正文）。
     * 未订阅且没有待送达的退订时返回空数组——什么都不带。
     *
     * @return array<string, string>
     */
    public static function reportFields(): array
    {
        $cur = self::current();
        if ($cur['on']) {
            return ['notify_email' => $cur['email'], 'notify_lang' => $cur['lang']];
        }
        if ((string) self::get(self::UNSUB_PENDING, '0') === '1') {
            return ['notify_email' => ''];
        }
        return [];
    }

    /** 回访成功后调用：退订已送达，之后不再带空邮箱。 */
    public static function acknowledge(array $sent): void
    {
        if (array_key_exists('notify_email', $sent) && $sent['notify_email'] === ''
            && (string) self::get(self::UNSUB_PENDING, '0') === '1') {
            settingModel()->set(self::UNSUB_PENDING, '0', 'system');
        }
    }

    /** 控制台是否该显示「订阅安全通知」提示：没订阅、没关掉过提示。 */
    public static function promptDue(): bool
    {
        return !self::current()['on'] && (string) self::get(self::PROMPT_DISMISSED, '0') !== '1';
    }

    public static function dismissPrompt(): void
    {
        settingModel()->set(self::PROMPT_DISMISSED, '1', 'system');
    }
}
