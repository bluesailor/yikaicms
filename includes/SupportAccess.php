<?php
/**
 * 官方技术支持临时访问（2.0.3）：由站长发起、限时、可随时撤销的支持账号。
 *
 * 为什么不是「预留账号」或「客户给我们建账号」：每个站都有同一个我们知道的账号就是后门，
 * 我们替每个站保管密码也会变成高价值目标。这里反过来——
 *   - 站长在「系统 → 技术支持访问」点开启，选时长（4 / 24 / 72 小时），得到一条一次性登录链接，
 *     自己发给我们；链接只能用一次，令牌只存哈希，放在网址 # 后面（不进服务器日志）。
 *   - 账号 yikai-support 只在开启期间可用，权限锁死为「系统维护」（升级与迁移、体检、系统信息与错误日志、
 *     数据库备份与优化）。看不到会员、询盘、订单，不能下载/恢复数据库、改升级授权、管理用户——
 *     即使有人把它的角色改成超管，每次请求也会被拉回只读维护权限。
 *   - 到期或撤销即停用账号，已登录的会话下一次请求就失效；开启、登录、撤销都记操作日志并邮件通知站长。
 */

declare(strict_types=1);

final class SupportAccess
{
    public const USERNAME = 'yikai-support';
    /** @var list<string> */
    public const PERMISSIONS = ['system_maintenance'];
    /** @var list<int> */
    public const DURATIONS = [4, 24, 72];

    private const KEY_UNTIL = 'support_access_until';
    private const KEY_TOKEN = 'support_access_token';
    private const KEY_BY = 'support_access_by';
    private const KEY_NOTE = 'support_access_note';
    /** 我们自己建的支持账号的 id：同名的既有用户（站长自建的）绝不接管 */
    private const KEY_USER = 'support_access_user_id';
    private const ROLE_NAME = '官方技术支持';

    /** @return array{active:bool, until:int, link_pending:bool, by:int, note:string, user_id:int} */
    public static function state(): array
    {
        $until = (int) config(self::KEY_UNTIL, 0);
        $user = self::user();
        return [
            'active' => $until > time() && $user !== null && (int) $user['status'] === 1,
            'until' => $until,
            'link_pending' => (string) config(self::KEY_TOKEN, '') !== '',
            'by' => (int) config(self::KEY_BY, 0),
            'note' => (string) config(self::KEY_NOTE, ''),
            'user_id' => $user !== null ? (int) $user['id'] : 0,
        ];
    }

    /** 开启（或延长）访问并生成一次性链接令牌。返回原始令牌——只在此刻可见，库里只存哈希。 */
    public static function enable(int $hours, int $ownerId, string $note = ''): string
    {
        if (!in_array($hours, self::DURATIONS, true)) {
            throw new InvalidArgumentException('support_access_duration');
        }
        $existing = userModel()->findWhere(['username' => self::USERNAME]);
        if (is_array($existing) && (int) $existing['id'] !== (int) config(self::KEY_USER, 0)) {
            throw new RuntimeException('support_access_username_taken');
        }
        $roleId = self::ensureRole();
        $user = self::user();
        $now = time();
        if ($user === null) {
            $newId = (int) userModel()->create([
                'username' => self::USERNAME,
                // 随机且不告诉任何人的密码：账号只能经一次性链接登录
                'password' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                'nickname' => 'YikaiCMS Support',
                'email' => '',
                'role_id' => $roleId,
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            settingModel()->set(self::KEY_USER, (string) $newId, 'system');
        } else {
            userModel()->updateById((int) $user['id'], ['role_id' => $roleId, 'status' => 1, 'totp_secret' => '', 'updated_at' => $now]);
        }
        $token = bin2hex(random_bytes(24));
        settingModel()->saveBatch([
            self::KEY_UNTIL => (string) ($now + $hours * 3600),
            self::KEY_TOKEN => hash('sha256', $token),
            self::KEY_BY => (string) $ownerId,
            self::KEY_NOTE => mb_substr(trim($note), 0, 200),
        ]);
        adminLog('support', 'enable', '开启官方技术支持访问 ' . $hours . ' 小时' . ($note !== '' ? '：' . mb_substr($note, 0, 200) : ''));
        self::notifyOwner('enabled', $hours);
        return $token;
    }

    /** 访问期间重新生成一次性链接（上一条作废）。 */
    public static function newLink(): string
    {
        if (!self::state()['active']) {
            throw new RuntimeException('support_access_inactive');
        }
        $token = bin2hex(random_bytes(24));
        settingModel()->set(self::KEY_TOKEN, hash('sha256', $token), 'system');
        adminLog('support', 'new_link', '重新生成技术支持登录链接');
        return $token;
    }

    /** 撤销 / 到期：停用账号、作废链接。已登录的会话下一次请求即失效（refreshAdminIdentity 发现账号停用）。 */
    public static function revoke(string $reason = 'revoked'): void
    {
        $user = self::user();
        if ($user !== null && (int) $user['status'] === 1) {
            userModel()->updateById((int) $user['id'], ['status' => 0, 'updated_at' => time()]);
        }
        $wasActive = (int) config(self::KEY_UNTIL, 0) > 0;
        settingModel()->saveBatch([self::KEY_UNTIL => '0', self::KEY_TOKEN => '']);
        if ($wasActive) {
            if (!empty($_SESSION['admin_id']) && !self::isSupportSession()) {
                adminLog('support', $reason === 'expired' ? 'expire' : 'revoke', $reason === 'expired' ? '技术支持访问到期' : '撤销官方技术支持访问');
            }
            self::notifyOwner($reason === 'expired' ? 'expired' : 'revoked');
        }
    }

    /**
     * 兑换一次性链接：访问期内、令牌匹配才返回账号行；兑换后令牌作废（只能用一次）。
     * @return array<string,mixed>|null
     */
    public static function redeem(string $token): ?array
    {
        self::expireIfDue();
        $hash = (string) config(self::KEY_TOKEN, '');
        if ($hash === '' || preg_match('/^[a-f0-9]{48}$/D', $token) !== 1 || !hash_equals($hash, hash('sha256', $token))) {
            return null;
        }
        $state = self::state();
        if (!$state['active']) {
            return null;
        }
        settingModel()->set(self::KEY_TOKEN, '', 'system');
        $user = self::user();
        if ($user === null) {
            return null;
        }
        self::notifyOwner('login');
        return $user;
    }

    /** 到期自动收回（后台每次请求与计划任务都会调用）。 */
    public static function expireIfDue(): void
    {
        $until = (int) config(self::KEY_UNTIL, 0);
        if ($until > 0 && $until <= time()) {
            self::revoke('expired');
        }
    }

    /**
     * 每次后台请求（refreshAdminIdentity）调用：当前登录的是支持账号时，
     * 访问已结束就让它失效；权限一律锁回「系统维护」，角色被改成超管也不生效。
     */
    public static function enforce(): void
    {
        self::expireIfDue();
        if (!self::isSupportSession()) {
            return;
        }
        $_SESSION['admin_permissions'] = self::PERMISSIONS;
        $_SESSION['support_access'] = true;
    }

    public static function isSupportSession(): bool
    {
        $id = (int) config(self::KEY_USER, 0);
        return $id > 0 && (int) ($_SESSION['admin_id'] ?? 0) === $id;
    }

    /** @return array<string,mixed>|null */
    private static function user(): ?array
    {
        $id = (int) config(self::KEY_USER, 0);
        $row = $id > 0 ? userModel()->find($id) : null;
        return is_array($row) && (string) $row['username'] === self::USERNAME ? $row : null;
    }

    /** 专用角色：权限只有系统维护；每次开启都校正回来。 */
    private static function ensureRole(): int
    {
        $perms = json_encode(self::PERMISSIONS);
        $role = roleModel()->findWhere(['name' => self::ROLE_NAME]);
        $fields = [
            'name_en' => 'Official support', 'name_ja' => '公式サポート',
            'description' => '易开官方技术支持临时账号：升级、体检、错误日志、数据库备份；看不到业务数据',
            'description_en' => 'Temporary YikaiCMS support account: upgrades, site health, error logs, database backups; no business data',
            'description_ja' => 'YikaiCMS 公式サポートの一時アカウント：アップグレード・診断・エラーログ・DB バックアップ。業務データは見られません',
            'permissions' => $perms, 'status' => 1,
        ];
        if (is_array($role)) {
            roleModel()->updateById((int) $role['id'], $fields);
            return (int) $role['id'];
        }
        return (int) roleModel()->create(['name' => self::ROLE_NAME, 'created_at' => time()] + $fields);
    }

    /** 邮件通知开启访问的站长（没配发信或没留邮箱时静默跳过）。 */
    private static function notifyOwner(string $event, int $hours = 0): void
    {
        try {
            $owner = userModel()->find((int) config(self::KEY_BY, 0));
            $email = is_array($owner) ? trim((string) ($owner['email'] ?? '')) : '';
            if ($email === '' || !function_exists('sendMail')) {
                return;
            }
            $site = (string) config('site_name', 'YikaiCMS');
            $subject = __('support_mail_subject_' . $event, ['site' => $site]);
            $body = __('support_mail_body_' . $event, ['site' => $site, 'hours' => (string) $hours,
                'time' => date('Y-m-d H:i'), 'ip' => function_exists('getClientIp') ? getClientIp() : '']);
            sendMail($email, $subject, nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')));
        } catch (Throwable $e) {
            error_log('[SupportAccess] notify failed: ' . $e->getMessage());
        }
    }
}
