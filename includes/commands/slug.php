<?php
/**
 * Command: seo:fix-slugs
 *
 * 把存量的非规范 URL 别名（中文/空格/大写等）改写成拼音别名。
 *
 * 为什么需要一次性命令：伪静态模式下 Dispatcher 的路由正则只接受 `[a-z0-9_-]`，
 * `/商业保险.html` 匹配不到任何规则 → 页面 404。别名是 2026-09-21 之前由
 * admin/channel.php 的"栏目名原样当别名"写进去的，站点可能已经积了几十条死链。
 *
 * 默认**只演练**（列出将要改什么），`--apply` 才落库。改名同时为旧地址写一条 301
 * （需已安装 SEO 助手插件），避免外部既有链接彻底断掉。
 */
declare(strict_types=1);

if (!defined('IK_CLI')) {
    return;
}

require_once ROOT_PATH . '/includes/Slug.php';   // 净化口径单一来源

CLI::register('seo:fix-slugs', __('slugfix_cli_desc'), function (array $args, array $opts): int {
    $apply = !empty($opts['apply']);
    /** @var array<string,string> 表 => 记录标题列（仅用于日志可读） */
    $tables = [
        'channels' => 'name',
        'contents' => 'title',
        'products' => 'title',
        'product_categories' => 'name',
    ];

    $redirects = ROOT_PATH . '/plugins/seo/redirects.php';
    $canRedirect = is_file($redirects);
    if ($canRedirect) {
        require_once $redirects;
        $canRedirect = function_exists('seo_redirect_add');
    }

    $planned = 0;
    $changed = 0;
    $skipped = 0;

    foreach ($tables as $table => $labelColumn) {
        if (!db()->tableExists($table)) {
            continue;
        }
        $rows = db()->fetchAll('SELECT id, slug, ' . $labelColumn . ' AS label FROM '
            . DB_PREFIX . $table . ' WHERE slug <> \'\'');
        foreach ($rows as $row) {
            $old = (string) ($row['slug'] ?? '');
            if ($old === '' || preg_match('/^[a-z0-9\-]+$/', $old) === 1) {
                continue;
            }
            $label = (string) ($row['label'] ?? '');
            // 先按现有别名转写（保留站长本来的命名），无法转写再退回标题
            $new = normalizeSlugInput($old);
            if ($new === '') {
                $new = generateSlug($label);
            }
            if ($new === '') {
                $new = $table . '-' . (int) $row['id'];
            }
            // 同表内重名：加 id 后缀而不是时间戳，重复执行结果稳定
            $taken = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . $table
                . ' WHERE slug = ? AND id <> ?', [$new, (int) $row['id']]);
            if ($taken) {
                $new .= '-' . (int) $row['id'];
            }

            $planned++;
            CLI::out(sprintf('%s #%d  %s  %s => %s', $table, (int) $row['id'], $label, $old, $new));

            if (!$apply) {
                continue;
            }
            try {
                db()->update($table, ['slug' => $new], 'id = ?', [(int) $row['id']]);
                $changed++;
                if ($canRedirect && $table === 'channels') {
                    // 栏目是顶级 .html 地址，旧地址最可能被外部引用
                    seo_redirect_add('/' . rawurlencode($old) . '.html', '/' . $new . '.html', 301);
                }
            } catch (Throwable $e) {
                $skipped++;
                CLI::err(sprintf('  ! %s #%d %s', $table, (int) $row['id'], $e->getMessage()));
            }
        }
    }

    if ($planned === 0) {
        CLI::ok(__('slugfix_cli_none'));
        return 0;
    }
    if (!$apply) {
        CLI::warn(__('slugfix_cli_dry', ['count' => (string) $planned]));
        return 0;
    }
    if (function_exists('do_action')) {
        do_action('data_changed', 'channels', 0);
    }
    CLI::ok(__('slugfix_cli_done', ['changed' => (string) $changed, 'failed' => (string) $skipped]));
    return $skipped > 0 ? 1 : 0;
});
