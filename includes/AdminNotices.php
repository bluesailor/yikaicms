<?php
/**
 * 后台右上角铃铛的待办（2.0.6）：原来分散在控制台顶部的提醒卡片收到这里，每页都看得到、不占控制台版面。
 *
 * 每项只读本地设置 / 缓存，页面加载不请求外网；新版本与主题更新来自 UpdateNotice（各处检查更新时写入）。
 * 「关闭」沿用各提醒原有的处理动作（admin/index.php、admin/upgrade.php），条件与原来的卡片一致。
 * critical 项（伪静态没配好，前台链接会 404）在控制台另有一行醒目提示，不只藏在铃铛里。
 */

declare(strict_types=1);

final class AdminNotices
{
    /**
     * @return list<array{id:string,critical:bool,icon:string,title:string,body:string,url:string,action:string,external:bool,dismiss:?array{endpoint:string,action:string},form:string,email:string}>
     */
    public static function collect(): array
    {
        if (!function_exists('hasPermission') || !hasPermission('*')) {
            return [];
        }
        $items = [];
        $add = static function (array $item) use (&$items): void {
            $items[] = $item + ['critical' => false, 'body' => '', 'url' => '', 'action' => '', 'external' => false, 'dismiss' => null, 'form' => '', 'email' => ''];
        };

        // 数据库待升级不进这里：后台每页顶部已有醒目横幅、侧栏「升级」也有计数，再放一份只会重复

        if (!isDynamicUrlMode() && (string) config('onboarding_rewrite_dismissed', '1') === '0') {
            $add(['id' => 'rewrite', 'critical' => true, 'icon' => 'route',
                'title' => __('onb_rewrite_title'), 'body' => __('onb_rewrite_body'),
                'url' => adminHelpUrl(), 'action' => __('onb_rewrite_help'), 'external' => true,
                'dismiss' => ['endpoint' => '/admin/index.php', 'action' => 'dismiss_rewrite_onboarding']]);
        }

        require_once ROOT_PATH . '/includes/UpdateNotice.php';
        $version = UpdateNotice::available();
        if ($version !== '') {
            $add(['id' => 'update', 'icon' => 'cloud-download',
                'title' => __('notice_update_title', ['version' => $version]),
                'url' => '/admin/upgrade_online.php', 'action' => __('dashboard_update_go')]);
        }
        $themes = UpdateNotice::themeUpdates();
        if ($themes > 0) {
            $add(['id' => 'themes', 'icon' => 'palette',
                'title' => __('notice_theme_updates', ['n' => (string) $themes]),
                'url' => '/admin/theme.php?tab=market', 'action' => __('dashboard_theme_update_go')]);
        }

        // 升级后已装语言包还是旧版本：新增文案暂时显示英文/中文兜底，提醒一键更新（只看本地文件，不联网）
        require_once ROOT_PATH . '/includes/i18n/LanguagePackSite.php';
        $outdatedPacks = LanguagePackSite::outdated();
        if ($outdatedPacks !== []) {
            $add(['id' => 'lang_packs', 'icon' => 'language',
                'title' => __('notice_lang_packs_title', ['count' => (string) count($outdatedPacks)]),
                'body' => __('notice_lang_packs_body'),
                'url' => '/admin/setting_lang.php#langPacks', 'action' => __('lpack_update_all')]);
        }

        // 新站正在走「开始建站」引导时，定时任务提示先不打扰（与原控制台卡片同一规则）
        $startOnboarding = (string) config('onboarding_start_dismissed', '1') === '0';
        if (!$startOnboarding) {
            require_once ROOT_PATH . '/includes/Cron.php';
            $cron = Cron::health();
            if ($cron['state'] !== 'ok' && (int) config('cron_notice_dismissed_at', '0') < time() - 30 * 86400) {
                $add(['id' => 'cron', 'icon' => 'clock-pause',
                    'title' => $cron['state'] === 'never' ? __('cron_health_never') : __('cron_health_stale', ['days' => (string) $cron['days']]),
                    'body' => __('cron_health_body'),
                    'url' => '/admin/cron.php', 'action' => __('cron_health_setup'),
                    'dismiss' => ['endpoint' => '/admin/index.php', 'action' => 'dismiss_cron_notice']]);
            }
        }

        require_once ROOT_PATH . '/includes/UpdateMailSubscription.php';
        if (UpdateMailSubscription::promptDue()) {
            $email = '';
            try {
                $email = (string) db()->fetchColumn('SELECT email FROM ' . DB_PREFIX . 'users WHERE id = ?', [(int) ($_SESSION['admin_id'] ?? 0)]);
            } catch (Throwable) {
            }
            $add(['id' => 'mail', 'icon' => 'shield-check',
                'title' => __('upgrade_mail_prompt_title'), 'body' => __('upgrade_mail_prompt_text'),
                'form' => 'mail', 'email' => $email,
                'dismiss' => ['endpoint' => '/admin/upgrade.php', 'action' => 'dismiss_update_mail_prompt']]);
        }

        if ((string) config('onboarding_channel_dismissed', '') !== '1') {
            $channels = (int) (db()->fetchOne(
                'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'channels WHERE lang = ? AND is_home = 0',
                [siteLang()]
            )['c'] ?? 0);
            if ($channels === 0) {
                $add(['id' => 'channels', 'icon' => 'align-left',
                    'title' => __('onb_title'), 'body' => __('onb_body'),
                    'url' => '/admin/channel_batch.php', 'action' => __('chbatch_title'),
                    'dismiss' => ['endpoint' => '/admin/index.php', 'action' => 'dismiss_onboard']]);
            }
        }

        // 要紧的在前：critical → 其余按加入顺序
        usort($items, static fn (array $a, array $b): int => (int) $b['critical'] <=> (int) $a['critical']);
        return $items;
    }
}
