<?php
declare(strict_types=1);

/** Read-only overview. Rendering precedence must match index.php. */
final class SiteSetup
{
    private const MODE_KEYS = ['home_layout_active', 'home_blox_active'];

    /** @return array<string,string> */
    public static function homeState(): array
    {
        $rows = db()->fetchAll('SELECT `key`, `value` FROM ' . DB_PREFIX
            . 'settings WHERE `key` LIKE ? OR `key` = ? ORDER BY `key`', ['home_%', 'current_theme']);
        $state = [];
        foreach ($rows as $row) $state[(string) $row['key']] = (string) $row['value'];
        $state['current_theme'] = (string) config('current_theme', 'default');
        foreach (self::MODE_KEYS as $key) $state[$key] = (string) config($key, '0');
        return $state;
    }

    public static function homeFingerprint(): string
    {
        return hash('sha256', serialize(self::homeState()));
    }

    /** Switch only the rendering flags; preserve drafts, publications and their history. */
    public static function changeHomeFlags(array $flags, string $expected): array
    {
        if (array_keys($flags) !== self::MODE_KEYS || array_diff(array_values($flags), ['0', '1']) !== []) {
            throw new InvalidArgumentException(__('setup_plan_stale'));
        }
        db()->beginTransaction();
        try {
            if (!db()->isSqlite()) {
                db()->fetchAll('SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` LIKE ? FOR UPDATE', ['home_%']);
            }
            settingModel()->clearCache();
            if (!hash_equals(self::homeFingerprint(), $expected)) throw new RuntimeException(__('setup_plan_stale'));
            $previous = [];
            foreach (self::MODE_KEYS as $key) {
                $previous[$key] = (string) config($key, '0');
                settingModel()->set($key, (string) $flags[$key], 'home');
            }
            foreach (self::MODE_KEYS as $key) {
                if ((string) config($key, '0') !== $flags[$key]) throw new RuntimeException(__('setup_home_locked'));
            }
            db()->commit();
            return ['flags' => $previous, 'fingerprint' => self::homeFingerprint()];
        } catch (Throwable $e) {
            db()->rollback();
            settingModel()->clearCache();
            throw $e;
        }
    }

    public static function homeMode(bool $layoutActive, bool $layoutPublished, bool $builderActive, bool $builderPublished): string
    {
        if ($layoutActive && $layoutPublished) {
            return 'layout';
        }
        return $builderActive && $builderPublished ? 'builder' : 'theme';
    }

    public static function currentHomeMode(): string
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        return self::homeMode(
            HomeLayoutDocument::isActive(), HomeLayoutDocument::hasPublished(),
            HomeBloxDocument::isActive(), HomeBloxDocument::hasPublished()
        );
    }

    /**
     * 新手「编辑首页」只给一个入口：有构建器首页权限就进构建器（主题首页模式下打开也只是草稿，
     * 发布前访客看不到），否则回落经典首页设置。控制台引导卡与建站向导共用。
     */
    public static function homeEditUrl(): string
    {
        return hasPermission('blox_home') ? '/admin/blox_editor.php?home=1' : '/admin/setting_home.php';
    }

    /** @return list<array{label:string,url:string,done:bool}> */
    public static function checklist(): array
    {
        return [
            ['label' => 'setup_brand', 'url' => '/admin/setting.php?tab=basic',
                'done' => trim((string) config('site_name', '')) !== '' && trim((string) config('site_logo', '')) !== ''],
            ['label' => 'setup_channels', 'url' => '/admin/channel.php',
                'done' => count(channelModel()->getByParent(0, true)) > 0],
            ['label' => 'setup_contact', 'url' => '/admin/setting_contact.php',
                'done' => trim((string) config('contact_phone', '')) !== '' || trim((string) config('contact_email', '')) !== ''],
            ['label' => 'setup_form', 'url' => '/admin/form_design.php?edit=contact',
                'done' => formTemplateModel()->findBySlug('contact') !== null],
        ];
    }
}
