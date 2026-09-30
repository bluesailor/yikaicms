<?php
declare(strict_types=1);

require_once __DIR__ . '/i18n/LanguageRegistry.php';

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

    /**
     * 建站向导顶部「从行业模板开始」要露出的几套推荐模板。纯函数：只整理已取到的目录，不发网络请求。
     * 只推荐当前能导入的；目录给了模板语言时，和本站内容语言一致的排前面（稳定排序，其余保持目录顺序）。
     *
     * @param array{templates?:list<array<string,mixed>>} $catalog SiteTemplateMarket::request() 的结果
     * @return array{total:int,templates:list<array{slug:string,name:string,screenshot:string}>}
     */
    public static function marketPreview(array $catalog, string $adminLanguage, string $siteLanguage, int $limit = 4): array
    {
        $items = array_values(array_filter(is_array($catalog['templates'] ?? null) ? $catalog['templates'] : [], 'is_array'));
        $ready = array_values(array_filter($items, static fn(array $item): bool => ($item['blocked_reason'] ?? 'x') === ''));
        $matches = static fn(array $item): bool => in_array($siteLanguage, is_array($item['languages'] ?? null) ? $item['languages'] : [], true);
        $ordered = array_merge(
            array_values(array_filter($ready, $matches)),
            array_values(array_filter($ready, static fn(array $item): bool => !$matches($item)))
        );
        $picked = [];
        foreach (array_slice($ordered, 0, max(0, $limit)) as $item) {
            $name = LanguageRegistry::localizedField($item, 'name', $adminLanguage);
            $picked[] = [
                'slug' => (string) ($item['slug'] ?? ''),
                'name' => $name !== '' ? $name : (string) ($item['name'] ?? ''),
                'screenshot' => (string) ($item['screenshot'] ?? ''),
            ];
        }
        return ['total' => count($items), 'templates' => $picked];
    }
}
