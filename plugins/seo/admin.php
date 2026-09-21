<?php
/**
 * SEO 助手 - 管理页面
 * 由 /admin/plugin_page.php?plugin=seo 加载（已 checkLogin + requirePermission('*') + CSRF）。
 *
 * 免费：llms.txt 生成器（写站点根 /llms.txt）。
 * 专业版（license_has_module('seo-pro')）：AI 一键优化 meta / 重定向管理 / 自动推送 / 内链建议 —— 就地上锁展示。
 *
 * PHP 8.0+
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/redirects.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/linkcheck.php';
require_once __DIR__ . '/slugs.php';

$seoHasPro = function_exists('license_has_module') && license_has_module('seo-pro');

// ============================================================
// URL 别名：单条改名（免费）／批量规范化（专业版）
// CSRF 由 checkLogin() 全局校验（后台 fetch 自动带 _token），此处只判授权与入参
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['action'] ?? ''), ['slug_rename', 'slug_list', 'slug_bulk_fix'], true)
    && !seo_slug_available()) {
    error(__('seo_slug_needs_cms'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'slug_rename') {
    // 改名同时写 301 属专业版；免费站改名成功但旧地址不接管（界面已说明）
    [$ok, $msg, $applied] = seo_slug_rename(
        (string) ($_POST['table'] ?? ''),
        (int) ($_POST['id'] ?? 0),
        (string) ($_POST['slug'] ?? ''),
        $seoHasPro && !empty($_POST['redirect'])
    );
    if (!$ok) {
        error($msg);
    }
    adminLog('plugin', 'seo', 'slug rename ' . (string) ($_POST['table'] ?? '') . '#'
        . (int) ($_POST['id'] ?? 0) . ' => ' . $applied);
    success(['slug' => $applied], $msg);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'slug_list') {
    $filter = ($_POST['filter'] ?? '') === 'all' ? 'all' : 'invalid';
    $scan = seo_slug_scan($filter, 500);
    success(['rows' => $scan['rows'], 'total' => $scan['total'], 'invalid' => $scan['invalid']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'slug_bulk_fix') {
    if (!$seoHasPro) {
        error(__('seo_slug_bulk_pro_only'));
    }
    $result = seo_slug_normalize_all(!empty($_POST['redirect']));
    adminLog('plugin', 'seo', 'slug bulk fix: ' . $result['fixed'] . ' fixed / ' . $result['failed'] . ' failed');
    success($result, __('seo_slug_bulk_done', [
        'fixed' => (string) $result['fixed'],
        'failed' => (string) $result['failed'],
    ]));
}

// ============================================================
// POST：生成 /llms.txt（在输出 HTML 前处理，返回 JSON）
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gen_llms') {
    [$ok, $msg] = seo_write_llms_txt();
    if (!$ok) {
        error($msg);
    }
    settingModel()->set('seo_llms_generated_at', (string) time());
    adminLog('plugin', 'seo', '生成 llms.txt');
    success(['at' => date('Y-m-d H:i')], $msg);
}

// 保存推送配置（百度站点/token）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_push') {
    settingModel()->set('seo_baidu_site', trim((string) ($_POST['baidu_site'] ?? '')));
    settingModel()->set('seo_baidu_token', trim((string) ($_POST['baidu_token'] ?? '')));
    success([], '已保存');
}

// 生成 IndexNow 密钥文件
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gen_indexnow_key') {
    [$ok, $msg, $key] = seo_ensure_indexnow_key();
    if (!$ok) {
        error($msg);
    }
    success(['key' => $key], $msg);
}

// 重定向管理器（专业版）CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['redirect_add', 'redirect_delete', 'log_clear', 'log_delete'], true)) {
    if (!$seoHasPro) {
        error('该功能需要 SEO 助手专业版');
    }
    seo_redirect_ensure_tables();
    $act = $_POST['action'];
    if ($act === 'redirect_add') {
        [$ok, $msg] = seo_redirect_add((string) ($_POST['source'] ?? ''), (string) ($_POST['target'] ?? ''), (int) ($_POST['type'] ?? 301));
        $ok ? success([], $msg) : error($msg);
    } elseif ($act === 'redirect_delete') {
        seo_redirect_delete((int) ($_POST['id'] ?? 0));
        success([], '已删除');
    } elseif ($act === 'log_delete') {
        seo_404_delete((int) ($_POST['id'] ?? 0));
        success([], '已删除');
    } else { // log_clear
        seo_404_clear();
        success([], '已清空');
    }
}

// 失效链接检查（专业版）：全站扫描并把报告存档，页面直接读档渲染
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'linkcheck_run') {
    if (!$seoHasPro) {
        error('该功能需要 SEO 助手专业版');
    }
    $report = seo_linkcheck_scan(1000);
    settingModel()->set('seo_linkcheck_last', (string) json_encode($report, JSON_UNESCAPED_UNICODE), 'system');
    adminLog('plugin', 'seo', 'linkcheck: ' . count($report['dead']) . ' dead / ' . $report['scanned'] . ' docs');
    success(['dead' => count($report['dead']), 'scanned' => $report['scanned']],
        '扫描完成：' . $report['scanned'] . ' 篇内容，发现 ' . count($report['dead']) . ' 个死链');
}

// SEO 体检：保存单条 SEO 字段（专业版）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'audit_save') {
    if (!$seoHasPro) {
        error('该功能需要 SEO 助手专业版');
    }
    [$ok, $msg] = seo_audit_save(
        (string) ($_POST['table'] ?? ''), (int) ($_POST['id'] ?? 0),
        (string) ($_POST['seo_title'] ?? ''), (string) ($_POST['seo_description'] ?? ''), (string) ($_POST['seo_keywords'] ?? '')
    );
    $ok ? success([], $msg) : error($msg);
}

// 推送到百度 / IndexNow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['push_baidu', 'push_indexnow'], true)) {
    $urls = seo_all_urls(500);
    if (($_POST['action']) === 'push_baidu') {
        [$ok, $msg] = seo_submit_baidu((string) config('seo_baidu_site', ''), (string) config('seo_baidu_token', ''), $urls);
    } else {
        $host = parse_url(siteBaseUrl(), PHP_URL_HOST) ?: '';
        [$ok, $msg] = seo_submit_indexnow((string) $host, (string) config('seo_indexnow_key', ''), $urls);
    }
    if (!$ok) {
        error($msg);
    }
    adminLog('plugin', 'seo', $_POST['action'] . ': ' . $msg);
    success(['count' => count($urls)], $msg);
}

// 自动推送（专业版）：开关保存 + 立即推送
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['autopush_toggle', 'autopush_now'], true)) {
    if (!$seoHasPro) {
        error('该功能需要 SEO 助手专业版');
    }
    require_once __DIR__ . '/autopush.php';
    if (($_POST['action']) === 'autopush_toggle') {
        $on = ($_POST['val'] ?? '0') === '1';
        settingModel()->set('seo_autopush_enabled', $on ? '1' : '0', 'system');
        // 开启的那一刻建立游标，避免第一次跑就把全站历史内容推出去打光配额
        // 开启的那一刻给两个服务各建一次游标，避免第一次跑就把全站历史推出去打光配额
        if ($on) {
            foreach (['seo_autopush_cursor_baidu', 'seo_autopush_cursor_indexnow'] as $ck) {
                if ((int) config($ck, '0') <= 0) {
                    settingModel()->set($ck, (string) time(), 'system');
                }
            }
        }
        adminLog('plugin', 'seo', 'autopush: ' . ($on ? 'on' : 'off'));
        success([], $on ? '已开启自动推送' : '已关闭自动推送');
    }
    $msg = seo_autopush_run(true);
    adminLog('plugin', 'seo', 'autopush_now: ' . $msg);
    success([], $msg);
}

// —— 页面数据 ——
$llmsPreview = seo_build_llms_txt();
$llmsPath    = seo_llms_path();
$llmsExists  = file_exists($llmsPath);
$llmsGenAt   = (int) config('seo_llms_generated_at', 0);
$siteUrl     = rtrim(siteBaseUrl(), '/');
$siteUrlSet  = trim((string) config('site_url', '')) !== '';

// 搜索引擎推送
$baiduSite         = (string) config('seo_baidu_site', '');
$baiduToken        = (string) config('seo_baidu_token', '');
$indexnowKey       = (string) config('seo_indexnow_key', '');
$indexnowHost      = (string) (parse_url(siteBaseUrl(), PHP_URL_HOST) ?: '');
$indexnowKeyExists = $indexnowKey !== '' && file_exists(seo_indexnow_key_path($indexnowKey));
$pushUrlCount      = count(seo_all_urls(500));

// 重定向管理器（专业版）
$redirectRules = [];
$log404 = [];
if ($seoHasPro) {
    seo_redirect_ensure_tables();
    $redirectRules = seo_redirect_list(500);
    $log404 = seo_404_list(200);
}

// 失效链接检查（专业版）最近一次报告 + 索引健康摘要（免费）
$linkcheck = $seoHasPro ? json_decode((string) config('seo_linkcheck_last', ''), true) : null;
if (!is_array($linkcheck)) {
    $linkcheck = null;
}
$indexHealth = seo_index_health();

// URL 别名清单（免费）：默认只列异常项，全部清单由前端按需切换
$slugAvailable = seo_slug_available();
$slugScan = $slugAvailable
    ? seo_slug_scan('invalid', 500)
    : ['rows' => [], 'total' => 0, 'invalid' => 0, 'pretty' => true];

// 自动推送（专业版）
$autopushOn = false;
$autopushLog = [];
$autopushCursor = 0;
$cronConfigured = false;
if ($seoHasPro) {
    require_once __DIR__ . '/autopush.php';
    $autopushOn = (string) config('seo_autopush_enabled', '0') === '1';
    $autopushLog = seo_autopush_log();
    // 两个服务各有游标，页面上取较早的那个展示「增量起点」
    $autopushCursor = min(array_filter([
        (int) config('seo_autopush_cursor_baidu', '0'),
        (int) config('seo_autopush_cursor_indexnow', '0'),
    ]) ?: [0]);
    // 定时任务最近跑过没有——没配 crontab 的主机上自动推送不会自己触发，要明说
    try {
        if (class_exists('Cron')) {
            foreach (Cron::tasks() as $t) {
                if ((int) ($t['last'] ?? 0) > time() - 86400) {
                    $cronConfigured = true;
                    break;
                }
            }
        }
    } catch (\Throwable $e) {
    }
}

// 基石内容（专业版）
$cornerstones = [];
if ($seoHasPro) {
    require_once __DIR__ . '/links.php';
    seo_cornerstone_ensure_table();
    $cornerstones = seo_cornerstone_list();
}

// SEO 体检（专业版）
$audit = $seoHasPro ? seo_audit_scan(500) : ['items' => [], 'summary' => [], 'total' => 0, 'healthy' => 0];
$auditIssueMeta = seo_audit_issue_meta();
$auditTables = seo_audit_tables();

$pageTitle   = 'SEO 助手';
$currentMenu = 'plugin';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <a href="/admin/plugin.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
            <i class="ti ti-chevron-left text-base"></i> 插件管理
        </a>
        <span class="text-gray-300">|</span>
        <a href="/admin/setting_seo.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
            <i class="ti ti-settings text-base"></i> 基础 SEO 设置
        </a>
    </div>
    <?php if ($seoHasPro): ?>
        <span class="text-xs font-medium bg-amber-100 text-amber-700 px-2 py-1 rounded inline-flex items-center gap-1">
            <i class="ti ti-crown text-sm"></i> 专业版已激活
        </span>
    <?php else: ?>
        <a href="/admin/license.php" class="text-xs font-medium bg-gray-900 text-white px-3 py-1.5 rounded inline-flex items-center gap-1 hover:bg-black" title="CMS 授权码内含全部官方插件 Pro，填码即解锁">
            <i class="ti ti-crown text-sm text-amber-400"></i> 升级专业版
        </a>
    <?php endif; ?>
</div>

<div x-data="seoWorkshop()">

    <!-- ===== 免费：llms.txt 生成器 ===== -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <div>
                <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                    <i class="ti ti-robot text-blue-500"></i> llms.txt 生成器
                </h2>
                <p class="text-xs text-gray-400 mt-0.5">生成站点根 <code>/llms.txt</code>，用简洁 Markdown 指引 AI 助手理解你的站点结构与重点内容。</p>
            </div>
            <span class="text-xs font-medium bg-green-100 text-green-700 px-2 py-1 rounded">免费</span>
        </div>
        <div class="p-6">
            <?php if (!$siteUrlSet): ?>
            <div class="mb-4 text-sm bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-4 py-2.5 flex items-start gap-2">
                <i class="ti ti-alert-triangle text-base mt-0.5"></i>
                <span>未配置站点网址（系统设置 → 站点网址），llms.txt 里的链接可能不完整。当前推断为 <code><?php echo e($siteUrl); ?></code>。</span>
            </div>
            <?php endif; ?>

            <div class="flex flex-wrap items-center gap-3 mb-4">
                <button type="button" @click="generate()" :disabled="loading"
                        class="bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white text-sm font-medium px-4 py-2 rounded-lg inline-flex items-center gap-1.5">
                    <i class="ti text-base" aria-hidden="true" :class="loading ? 'ti-loader-2 animate-spin' : 'ti-file-download'"></i>
                    <span x-text="loading ? '生成中…' : '生成 / 更新 llms.txt'">生成 / 更新 llms.txt</span>
                </button>

                <template x-if="exists">
                    <a href="/llms.txt" target="_blank"
                       class="text-sm border border-gray-200 hover:border-blue-400 hover:text-blue-500 text-gray-600 px-3 py-2 rounded-lg inline-flex items-center gap-1.5">
                        <i class="ti ti-external-link text-base" aria-hidden="true"></i> 查看 /llms.txt
                    </a>
                </template>

                <span class="text-xs text-gray-400" x-show="genAt" x-text="'上次生成：' + genAt"></span>
                <template x-if="!exists && !genAt">
                    <span class="text-xs text-gray-400">尚未生成</span>
                </template>
            </div>

            <details class="border border-gray-200 rounded-lg">
                <summary class="cursor-pointer select-none px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-50 flex items-center gap-1.5">
                    <i class="ti ti-eye text-base"></i> 预览内容
                </summary>
                <pre class="text-xs bg-gray-50 border-t border-gray-100 p-4 overflow-x-auto leading-relaxed text-gray-700 whitespace-pre-wrap"><?php echo e($llmsPreview); ?></pre>
            </details>

            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                提示：内容变化后需重新点击「生成 / 更新」刷新 llms.txt（自动刷新将在后续版本加入）。全站完整清单请用
                <a href="/sitemap.php" target="_blank" class="text-blue-500 hover:underline">sitemap.xml</a>，二者互补。
            </p>
        </div>
    </div>

    <!-- ===== 免费：索引健康 ===== -->
    <div id="seo-index-health" class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <div>
                <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                    <i class="ti ti-heartbeat text-blue-500"></i> 索引健康
                </h2>
                <p class="text-xs text-gray-400 mt-0.5">搜索引擎能抓到什么、站点哪里有坑，一屏速览。</p>
            </div>
            <span class="text-xs text-gray-400">只读摘要</span>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                <?php
                $healthItems = [
                    [$indexHealth['sitemap_enabled'], 'Sitemap', $indexHealth['sitemap_enabled'] ? '已开启（/sitemap.php）' : '未开启（基础 SEO 设置）', '/admin/setting_seo.php?tab=sitemap'],
                    [$indexHealth['robots_exists'], 'robots.txt', $indexHealth['robots_exists'] ? ($indexHealth['robots_has_sitemap'] ? '存在且声明了 Sitemap' : '存在（未声明 Sitemap）') : '站点根目录缺少 robots.txt', '/robots.txt'],
                    [$indexHealth['llms_exists'], 'llms.txt', $indexHealth['llms_exists'] ? '已生成' : '尚未生成（上方卡片）', '#'],
                    [$indexHealth['indexnow_ready'], 'IndexNow 密钥', $indexHealth['indexnow_ready'] ? '已就绪' : '未生成（下方推送卡片）', '#'],
                ];
                foreach ($healthItems as [$ok, $label, $hint, $href]):
                    $anchor = $href !== '#' ? ' href="' . e($href) . '" target="_blank"' : '';
                    $tag = $anchor !== '' ? 'a' : 'span';
                ?>
                <<?php echo $tag; ?> class="border rounded-lg p-3 flex items-start gap-2 <?php echo $ok ? 'border-green-200 bg-green-50/50' : 'border-amber-200 bg-amber-50/50'; ?>"<?php echo $anchor; ?>>
                    <i class="ti <?php echo $ok ? 'ti-circle-check text-green-500' : 'ti-alert-triangle text-amber-500'; ?> text-base mt-0.5"></i>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-gray-800"><?php echo e($label); ?></span>
                        <span class="block text-xs text-gray-500 mt-0.5 leading-relaxed"><?php echo e($hint); ?></span>
                    </span>
                </<?php echo $tag; ?>>
                <?php endforeach; ?>
            </div>
            <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-600 border-t border-gray-100 pt-4">
                <span>重定向规则 <strong><?php echo (int) $indexHealth['redirect_count']; ?></strong> 条</span>
                <span>404 记录 <strong><?php echo (int) $indexHealth['log404_count']; ?></strong> 条<?php if ($indexHealth['log404_count'] > 0): ?><a href="#seo-redirects" class="text-blue-500 hover:underline text-xs ml-1">去处理</a><?php endif; ?></span>
                <?php if ($seoHasPro): ?>
                <span>死链 <strong class="<?php echo $indexHealth['dead_count'] > 0 ? 'text-red-600' : ''; ?>"><?php echo (int) $indexHealth['dead_count']; ?></strong> 个
                    <?php if ($indexHealth['last_scan_at'] > 0): ?><span class="text-xs text-gray-400">（上次扫描 <?php echo date('Y-m-d H:i', (int) $indexHealth['last_scan_at']); ?>）</span><?php endif; ?>
                    <a href="#seo-linkcheck" class="text-blue-500 hover:underline text-xs ml-1">去查看</a></span>
                <?php else: ?>
                <span>死链检查 <span class="text-xs text-amber-600">专业版</span></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ===== 免费：URL 别名管理（单条改名）／专业版：批量规范化 + 自动 301 ===== -->
    <div class="bg-white rounded-lg shadow mb-6" id="seo-slugs">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <div>
                <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                    <i class="ti ti-link text-blue-500"></i> <?php echo e(__('seo_slug_card_title')); ?>
                </h2>
                <p class="text-xs text-gray-400 mt-0.5"><?php echo e(__('seo_slug_card_desc')); ?></p>
            </div>
            <span class="text-xs font-medium bg-green-100 text-green-700 px-2 py-1 rounded"><?php echo e(__('seo_free_badge')); ?></span>
        </div>
        <div class="p-6">
            <?php if (!$slugAvailable): ?>
            <div class="rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                <i class="ti ti-info-circle"></i> <?php echo e(__('seo_slug_needs_cms')); ?>
            </div>
            <?php else: ?>
            <?php if ($slugScan['invalid'] > 0): ?>
            <div class="mb-4 rounded border px-4 py-3 text-sm <?php echo $slugScan['pretty'] ? 'border-red-200 bg-red-50 text-red-700' : 'border-amber-200 bg-amber-50 text-amber-700'; ?>">
                <i class="ti <?php echo $slugScan['pretty'] ? 'ti-alert-triangle' : 'ti-info-circle'; ?>"></i>
                <?php echo e($slugScan['pretty']
                    ? __('seo_slug_warn_pretty', ['count' => (string) $slugScan['invalid']])
                    : __('seo_slug_warn_query', ['count' => (string) $slugScan['invalid']])); ?>
            </div>
            <?php endif; ?>

            <div class="flex flex-wrap items-center gap-3 mb-3">
                <label class="text-sm text-gray-600 inline-flex items-center gap-1.5">
                    <input type="checkbox" x-model="slugOnlyInvalid" @change="slugReload()" class="rounded">
                    <?php echo e(__('seo_slug_only_invalid')); ?>
                </label>
                <span class="text-xs text-gray-400"
                      x-text="<?php echo e(json_encode(__('seo_slug_counts'), JSON_UNESCAPED_UNICODE)); ?>.replace(':total', slugTotal).replace(':invalid', slugInvalid)"></span>
                <div class="flex-1"></div>
                <?php if ($seoHasPro): ?>
                <label class="text-xs text-gray-600 inline-flex items-center gap-1.5">
                    <input type="checkbox" x-model="slugRedirect" class="rounded">
                    <?php echo e(__('seo_slug_with_redirect')); ?>
                </label>
                <button type="button" @click="slugBulkFix()" :disabled="slugBusy || !slugInvalid"
                        class="text-xs bg-gray-900 text-white px-3 py-1.5 rounded hover:bg-black disabled:opacity-40">
                    <i class="ti ti-wand"></i> <?php echo e(__('seo_slug_bulk_btn')); ?>
                </button>
                <?php else: ?>
                <span class="text-xs text-gray-400 inline-flex items-center gap-1">
                    <i class="ti ti-lock"></i> <?php echo e(__('seo_slug_bulk_locked')); ?>
                </span>
                <a href="/admin/license.php" class="text-xs bg-gray-900 text-white px-3 py-1.5 rounded hover:bg-black">
                    <i class="ti ti-crown text-amber-400"></i> <?php echo e(__('seo_upgrade_btn')); ?>
                </a>
                <?php endif; ?>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs text-gray-500 border-b">
                            <th class="py-2 pr-3"><?php echo e(__('seo_slug_col_item')); ?></th>
                            <th class="py-2 pr-3"><?php echo e(__('seo_slug_col_slug')); ?></th>
                            <th class="py-2 w-56"><?php echo e(__('seo_slug_col_action')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in slugRows" :key="row.table + row.id">
                            <tr class="border-b last:border-0">
                                <td class="py-2 pr-3">
                                    <div class="text-gray-800" x-text="row.label"></div>
                                    <div class="text-xs text-gray-400" x-text="row.kind + ' #' + row.id"></div>
                                </td>
                                <td class="py-2 pr-3">
                                    <code class="text-xs" :class="row.valid ? 'text-gray-600' : 'text-red-600 font-medium'" x-text="row.slug"></code>
                                    <span x-show="!row.valid && slugPretty" class="ml-1 text-[10px] bg-red-100 text-red-700 px-1 py-0.5 rounded"><?php echo e(__('seo_slug_dead')); ?></span>
                                </td>
                                <td class="py-2">
                                    <div class="flex items-center gap-1.5">
                                        <input type="text" x-model="row.draft" :placeholder="row.suggested || row.slug"
                                               class="w-36 border rounded px-2 py-1 text-xs font-mono">
                                        <button type="button" @click="slugSave(row)" :disabled="slugBusy"
                                                class="text-xs border px-2 py-1 rounded hover:bg-gray-50 disabled:opacity-40"><?php echo e(__('seo_slug_save_btn')); ?></button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="!slugRows.length">
                            <td colspan="3" class="py-6 text-center text-xs text-gray-400"><?php echo e(__('seo_slug_empty')); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs mt-3" :class="slugMsgError ? 'text-red-600' : 'text-green-600'" x-show="slugMsg" x-text="slugMsg"></p>
            <?php if (!$seoHasPro): ?>
            <p class="text-xs text-gray-400 mt-3"><i class="ti ti-info-circle"></i> <?php echo e(__('seo_slug_free_note')); ?></p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== 免费：搜索引擎主动推送 ===== -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <div>
                <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                    <i class="ti ti-send text-blue-500"></i> 搜索引擎主动推送
                </h2>
                <p class="text-xs text-gray-400 mt-0.5">把站点 URL 主动提交给搜索引擎，加快收录。当前可推送约 <strong x-text="urlCount"><?php echo (int) $pushUrlCount; ?></strong> 条 URL。</p>
            </div>
            <span class="text-xs font-medium bg-green-100 text-green-700 px-2 py-1 rounded">免费</span>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">

            <!-- 百度 -->
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="font-medium text-gray-800 text-sm mb-3 flex items-center gap-1.5">
                    <i class="ti ti-brand-baidu text-blue-600"></i> 百度（普通收录）
                </div>
                <label class="block text-xs text-gray-500 mb-1">站点（与百度资源平台一致，如 http://www.example.com）</label>
                <input type="text" x-model="baiduSite" placeholder="http://www.example.com"
                       class="w-full border border-gray-200 rounded px-3 py-1.5 text-sm mb-2">
                <label class="block text-xs text-gray-500 mb-1">推送 token</label>
                <input type="text" x-model="baiduToken" placeholder="百度资源平台 → 普通收录 → API 提交"
                       class="w-full border border-gray-200 rounded px-3 py-1.5 text-sm mb-3">
                <div class="flex items-center gap-2">
                    <button type="button" @click="savePush()" :disabled="busy"
                            class="text-sm border border-gray-200 hover:border-blue-400 hover:text-blue-500 text-gray-600 px-3 py-1.5 rounded-lg">保存</button>
                    <button type="button" @click="push('baidu')" :disabled="busy"
                            class="bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white text-sm px-3 py-1.5 rounded-lg inline-flex items-center gap-1.5">
                        <i class="ti ti-send text-base"></i> 推送到百度
                    </button>
                </div>
            </div>

            <!-- IndexNow -->
            <div class="border border-gray-200 rounded-lg p-4">
                <div class="font-medium text-gray-800 text-sm mb-3 flex items-center gap-1.5">
                    <i class="ti ti-brand-bing text-teal-600"></i> IndexNow（Bing / Yandex 等）
                </div>
                <p class="text-xs text-gray-500 mb-2">一次提交，多引擎共享。需在站点根放一个密钥文件供验证。</p>
                <div class="text-xs mb-2">
                    <span class="text-gray-500">域名：</span><code><?php echo e($indexnowHost ?: '未配置站点网址'); ?></code>
                </div>
                <div class="text-xs mb-3 flex items-center gap-2">
                    <span class="text-gray-500">密钥文件：</span>
                    <template x-if="keyReady">
                        <a :href="'/' + keyName + '.txt'" target="_blank" class="text-green-600 inline-flex items-center gap-1"><i class="ti ti-circle-check"></i><span x-text="'/' + keyName + '.txt'"></span></a>
                    </template>
                    <template x-if="!keyReady">
                        <span class="text-amber-600 inline-flex items-center gap-1"><i class="ti ti-alert-circle"></i>未生成</span>
                    </template>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="genKey()" :disabled="busy"
                            class="text-sm border border-gray-200 hover:border-blue-400 hover:text-blue-500 text-gray-600 px-3 py-1.5 rounded-lg">
                        <span x-text="keyReady ? '重新生成密钥' : '生成密钥文件'"><?php echo $indexnowKeyExists ? '重新生成密钥' : '生成密钥文件'; ?></span>
                    </button>
                    <button type="button" @click="push('indexnow')" :disabled="busy || !keyReady"
                            class="bg-teal-600 hover:bg-teal-500 disabled:opacity-50 text-white text-sm px-3 py-1.5 rounded-lg inline-flex items-center gap-1.5">
                        <i class="ti ti-send text-base"></i> 推送 IndexNow
                    </button>
                </div>
            </div>
        </div>
        <div class="px-6 pb-4 -mt-2">
            <p class="text-xs text-gray-400" x-show="pushMsg" x-text="pushMsg"></p>
        </div>
    </div>

    <?php if ($seoHasPro): ?>
    <?php // AI 一键优化 meta 的入口不在本页，而在内容编辑页的 SEO 分析面板里；
          // 路线图卡的「已上线」链接锚到这里，免得用户在本页翻半天找不到。 ?>
    <div id="seo-ai-note" class="bg-white rounded-lg shadow mb-6 px-6 py-4 flex items-start gap-3">
        <div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center shrink-0">
            <i class="ti ti-sparkles text-lg"></i>
        </div>
        <div class="min-w-0 text-sm">
            <div class="font-medium text-gray-800">AI 一键优化 meta</div>
            <p class="text-gray-500 mt-1 leading-relaxed">
                入口在<b>内容编辑页</b>右侧的「SEO 分析」面板里：写完正文点「AI 生成」，
                即可一键生成或改写 SEO 标题、描述与关键词。用的是站点已配置的 AI 服务，
                未配置时到 <a href="/admin/setting_ai.php" class="text-primary hover:underline">AI 设置</a> 填一次即可。
            </p>
        </div>
    </div>

    <!-- ===== 专业版：搜索引擎自动推送 ===== -->
    <div id="seo-autopush" class="bg-white rounded-lg shadow mb-6" x-data="seoAutopush()">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                <i class="ti ti-send text-amber-500"></i> 搜索引擎自动推送
            </h2>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" class="sr-only peer" x-model="on" @change="toggle()">
                <span class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:bg-primary transition-colors"></span>
                <span class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-4"></span>
            </label>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-500 leading-relaxed mb-4">
                开启后每 15 分钟检查一次，把新发布和有改动的内容自动推给
                <?php echo $baiduSite !== '' && $baiduToken !== '' ? '百度' : '<span class="text-gray-400">百度（未配置）</span>'; ?>、<?php echo $indexnowKey !== '' ? 'IndexNow（Bing / Yandex）' : '<span class="text-gray-400">IndexNow（未配置）</span>'; ?>。
                单次最多 <?php echo SEO_AUTOPUSH_BATCH; ?> 条，避免一次打光百度每日配额。
            </p>

            <?php if (!$cronConfigured): ?>
            <?php // 没配定时任务的主机上，自动推送永远不会自己触发——与其静默失效不如直说 ?>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-4 py-3 mb-4">
                <i class="ti ti-alert-triangle mr-1"></i>
                本站近 24 小时没有定时任务运行记录，自动推送不会自行触发。请在
                <a href="/admin/cron.php" class="underline font-medium">系统 → 定时任务</a>
                按说明配置 crontab；在此之前可用下方「立即推送」手动触发。
            </div>
            <?php endif; ?>

            <div class="flex items-center gap-3 flex-wrap">
                <button type="button" @click="pushNow()" :disabled="busy"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-primary hover:bg-secondary text-white rounded text-sm font-medium disabled:opacity-50">
                    <i class="ti ti-send text-base"></i> <span x-text="busy ? '推送中…' : '立即推送'"></span>
                </button>
                <span class="text-xs text-gray-400">
                    <?php if ($autopushCursor > 0): ?>
                    增量起点：<?php echo e(date('Y-m-d H:i', $autopushCursor)); ?>
                    <?php else: ?>
                    尚未建立增量起点（开启开关后自动建立）
                    <?php endif; ?>
                </span>
                <span class="text-xs" :class="msgOk ? 'text-green-600' : 'text-red-500'" x-text="msg"></span>
            </div>

            <?php if ($autopushLog): ?>
            <div class="mt-5">
                <div class="text-xs font-medium text-gray-500 mb-2">推送历史（最近 <?php echo count($autopushLog); ?> 次）</div>
                <div class="overflow-x-auto border border-gray-100 rounded-lg">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">时间</th>
                                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">条数</th>
                                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">方式</th>
                                <th class="px-3 py-2 text-left font-medium">结果</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($autopushLog as $row): ?>
                            <tr>
                                <td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?php echo e((string) ($row['time'] ?? '')); ?></td>
                                <td class="px-3 py-2 text-gray-700 whitespace-nowrap"><?php echo (int) ($row['count'] ?? 0); ?></td>
                                <td class="px-3 py-2 text-gray-400 whitespace-nowrap"><?php echo !empty($row['manual']) ? '手动' : '自动'; ?></td>
                                <td class="px-3 py-2 <?php echo !empty($row['ok']) ? 'text-gray-600' : 'text-red-500'; ?>"><?php echo e((string) ($row['msg'] ?? '')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== 专业版：基石内容 ===== -->
    <div id="seo-cornerstone" class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                <i class="ti ti-diamond text-amber-500"></i> 基石内容
            </h2>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-500 leading-relaxed mb-4">
                基石内容是你最想让搜索引擎排上去的少数几篇。标记之后，写别的文章时内链建议会把它们排在最前，
                让站内权重往它们身上汇聚。在<b>内容编辑页</b>右侧的「内链建议」卡里勾选即可标记。
            </p>
            <?php if (!$cornerstones): ?>
            <div class="text-sm text-gray-400 border border-dashed border-gray-200 rounded-lg px-4 py-6 text-center">
                还没有标记基石内容。建议先挑 3–5 篇最重要的（如主打产品页、核心服务介绍）。
            </div>
            <?php else: ?>
            <div class="overflow-x-auto border border-gray-100 rounded-lg">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-gray-500 text-xs">
                        <tr>
                            <th class="px-3 py-2 text-left font-medium">标题</th>
                            <th class="px-3 py-2 text-left font-medium whitespace-nowrap">站内链入</th>
                            <th class="px-3 py-2 text-left font-medium whitespace-nowrap">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($cornerstones as $cs): ?>
                        <tr>
                            <td class="px-3 py-2 text-gray-700">
                                <a href="<?php echo e((string) $cs['url']); ?>" target="_blank" class="hover:text-primary"><?php echo e((string) $cs['title']); ?></a>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <?php $n = (int) ($cs['inbound'] ?? 0); ?>
                                <?php // 链入为 0 = 标了基石却没人链它，权重根本没汇聚——这才是要提醒的 ?>
                                <span class="<?php echo $n === 0 ? 'text-red-500' : ($n < 3 ? 'text-amber-600' : 'text-green-600'); ?>">
                                    <?php echo $n; ?> 篇<?php echo $n === 0 ? '（还没有文章链向它）' : ''; ?>
                                </span>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap">
                                <a href="/admin/article_edit.php?id=<?php echo (int) $cs['id']; ?>" class="text-xs text-primary hover:underline">编辑</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== 专业版：重定向管理器 ===== -->
    <div id="seo-redirects" class="bg-white rounded-lg shadow mb-6" x-data="seoRedirects()" @seo-fix-redirect.window="fixFrom($event.detail.path)">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                <i class="ti ti-arrows-right-left text-amber-500"></i> 重定向管理器
            </h2>
            <span class="text-xs font-medium bg-amber-100 text-amber-700 px-2 py-1 rounded inline-flex items-center gap-1"><i class="ti ti-crown text-sm"></i> Pro</span>
        </div>
        <div class="p-6">
            <!-- 添加规则 -->
            <div class="flex flex-wrap items-end gap-2 mb-4">
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs text-gray-500 mb-1">来源路径</label>
                    <input type="text" x-model="src" x-ref="src" placeholder="/old-page.html"
                           class="w-full border border-gray-200 rounded px-3 py-1.5 text-sm">
                </div>
                <div class="text-gray-300 pb-1.5"><i class="ti ti-arrow-right"></i></div>
                <div class="flex-1 min-w-[180px]">
                    <label class="block text-xs text-gray-500 mb-1">目标（路径或完整 URL）</label>
                    <input type="text" x-model="dst" placeholder="/new-page.html 或 https://…"
                           class="w-full border border-gray-200 rounded px-3 py-1.5 text-sm">
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1">类型</label>
                    <select x-model="type" class="border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                        <option value="301">301 永久</option>
                        <option value="302">302 临时</option>
                    </select>
                </div>
                <button type="button" @click="add()" :disabled="busy"
                        class="bg-amber-500 hover:bg-amber-400 disabled:opacity-50 text-white text-sm px-4 py-1.5 rounded-lg inline-flex items-center gap-1.5">
                    <i class="ti ti-plus text-base"></i> 添加
                </button>
            </div>
            <p class="text-xs text-red-500 mb-3" x-show="msg" x-text="msg"></p>

            <!-- 规则列表 -->
            <?php if (!$redirectRules): ?>
                <p class="text-sm text-gray-400 text-center py-6">还没有重定向规则。改版换链接时，在此把旧地址跳到新地址，保住 SEO 权重与流量。</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-400 border-b">
                        <th class="py-2 pr-3">来源</th><th class="py-2 pr-3">目标</th>
                        <th class="py-2 pr-3 whitespace-nowrap">类型</th><th class="py-2 pr-3 whitespace-nowrap">命中</th><th class="py-2 w-10"></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($redirectRules as $r): ?>
                        <tr class="border-b border-gray-50">
                            <td class="py-2 pr-3 font-mono text-xs text-gray-700 break-all"><?php echo e($r['source']); ?></td>
                            <td class="py-2 pr-3 font-mono text-xs text-blue-600 break-all"><?php echo e($r['target']); ?></td>
                            <td class="py-2 pr-3"><span class="text-xs px-1.5 py-0.5 rounded <?php echo (int) $r['type'] === 302 ? 'bg-gray-100 text-gray-600' : 'bg-green-100 text-green-700'; ?>"><?php echo (int) $r['type']; ?></span></td>
                            <td class="py-2 pr-3 text-gray-500"><?php echo (int) $r['hits']; ?></td>
                            <td class="py-2 text-right">
                                <button type="button" @click="del(<?php echo (int) $r['id']; ?>)" class="text-gray-400 hover:text-red-500 p-1" title="删除"><i class="ti ti-trash text-sm"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- 404 监控 -->
        <div class="px-6 py-4 border-t border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800 inline-flex items-center gap-2">
                <i class="ti ti-alert-triangle text-red-400"></i> 404 监控
                <span class="text-xs font-normal text-gray-400">（访客碰到的死链，点「建重定向」一键修复）</span>
            </h3>
            <?php if ($log404): ?>
            <button type="button" @click="clearLog()" class="text-xs text-gray-400 hover:text-red-500 inline-flex items-center gap-1"><i class="ti ti-trash text-sm"></i> 清空</button>
            <?php endif; ?>
        </div>
        <div class="p-6">
            <?php if (!$log404): ?>
                <p class="text-sm text-gray-400 text-center py-6">暂无 404 记录。访客访问到不存在的页面时会记录在此。</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-400 border-b">
                        <th class="py-2 pr-3">路径</th><th class="py-2 pr-3 whitespace-nowrap">次数</th>
                        <th class="py-2 pr-3 whitespace-nowrap">最近</th><th class="py-2 w-28"></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($log404 as $l): ?>
                        <tr class="border-b border-gray-50">
                            <td class="py-2 pr-3 font-mono text-xs text-gray-700 break-all"><?php echo e($l['path']); ?></td>
                            <td class="py-2 pr-3 text-gray-500"><?php echo (int) $l['hits']; ?></td>
                            <td class="py-2 pr-3 text-gray-400 text-xs whitespace-nowrap"><?php echo $l['last_seen'] ? date('m-d H:i', (int) $l['last_seen']) : '—'; ?></td>
                            <td class="py-2 text-right whitespace-nowrap">
                                <button type="button" @click="fixFrom(<?php echo htmlspecialchars(json_encode($l['path']), ENT_QUOTES); ?>)" class="text-xs text-amber-600 hover:text-amber-500 px-2 py-1">建重定向</button>
                                <button type="button" @click="delLog(<?php echo (int) $l['id']; ?>)" class="text-gray-400 hover:text-red-500 p-1" title="删除"><i class="ti ti-x text-sm"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function seoRedirects() {
            return {
                src: "", dst: "", type: "301", busy: false, msg: "",
                _post(action, extra) {
                    var b = new URLSearchParams(); b.set("action", action);
                    Object.keys(extra || {}).forEach(function (k) { b.set(k, extra[k]); });
                    return fetch(window.location.href, { method: "POST", body: b }).then(function (r) { return r.json(); });
                },
                add() {
                    var self = this; this.busy = true; this.msg = "";
                    this._post("redirect_add", { source: this.src, target: this.dst, type: this.type })
                        .then(function (res) { if (res && res.code === 0) location.reload(); else { self.msg = (res && res.msg) || "添加失败"; self.busy = false; } })
                        .catch(function () { self.msg = "添加失败"; self.busy = false; });
                },
                del(id) {
                    if (!confirm("删除这条重定向规则？")) return;
                    this._post("redirect_delete", { id: id }).then(function () { location.reload(); });
                },
                delLog(id) { this._post("log_delete", { id: id }).then(function () { location.reload(); }); },
                clearLog() { if (confirm("清空全部 404 记录？")) this._post("log_clear").then(function () { location.reload(); }); },
                fixFrom(path) {
                    this.src = path; this.dst = "";
                    this.$refs.src.scrollIntoView({ behavior: "smooth", block: "center" });
                    this.$nextTick(function () {}); var self = this; setTimeout(function () { self.$refs.src.focus(); }, 300);
                },
            };
        }
        </script>
    </div>
    <?php endif; ?>

    <?php if ($seoHasPro): ?>
    <!-- ===== 专业版：失效链接检查 ===== -->
    <div id="seo-linkcheck" class="bg-white rounded-lg shadow mb-6" x-data="seoLinkcheck()">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <div>
                <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                    <i class="ti ti-link-off text-amber-500"></i> 失效链接检查
                </h2>
                <p class="text-xs text-gray-400 mt-0.5">扫描已发布文章与产品正文里的链接：站内死链、已被重定向接管的旧链、外链数量。全部离线判定，不访问外部服务。</p>
            </div>
            <span class="text-xs font-medium bg-amber-100 text-amber-700 px-2 py-1 rounded inline-flex items-center gap-1"><i class="ti ti-crown text-sm"></i> Pro</span>
        </div>
        <div class="p-6">
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <button type="button" @click="run()" :disabled="busy"
                        class="bg-amber-500 hover:bg-amber-400 disabled:opacity-50 text-white text-sm px-4 py-1.5 rounded-lg inline-flex items-center gap-1.5">
                    <i class="ti text-base" :class="busy ? 'ti-loader-2 animate-spin' : 'ti-scan'"></i>
                    <span x-text="busy ? '扫描中…' : '扫描全站链接'">扫描全站链接</span>
                </button>
                <?php if ($linkcheck): ?>
                <span class="text-xs text-gray-400">
                    上次扫描 <?php echo date('Y-m-d H:i', (int) ($linkcheck['at'] ?? 0)); ?>：
                    <?php echo (int) ($linkcheck['scanned'] ?? 0); ?> 篇内容，
                    站内链 <?php echo (int) ($linkcheck['internal'] ?? 0); ?>，
                    外链 <?php echo (int) ($linkcheck['external'] ?? 0); ?>，
                    重定向接管 <?php echo (int) ($linkcheck['redirected'] ?? 0); ?>，
                    死链 <strong class="<?php echo count($linkcheck['dead'] ?? []) > 0 ? 'text-red-600' : 'text-green-600'; ?>"><?php echo count($linkcheck['dead'] ?? []); ?></strong>
                </span>
                <?php endif; ?>
            </div>
            <p class="text-xs text-red-500 mb-3" x-show="msg" x-text="msg"></p>

            <?php if (!$linkcheck): ?>
                <p class="text-sm text-gray-400 text-center py-6">还没有扫描记录。改版、删稿、换固定链接后跑一次，正文里的旧链接一目了然。</p>
            <?php elseif (empty($linkcheck['dead'])): ?>
                <p class="text-sm text-green-600 text-center py-6">未发现站内死链。</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-400 border-b">
                        <th class="py-2 pr-3">死链地址</th><th class="py-2 pr-3 whitespace-nowrap">出现次数</th>
                        <th class="py-2 pr-3">出现在</th><th class="py-2 w-28"></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($linkcheck['dead'] as $d): ?>
                        <tr class="border-b border-gray-50">
                            <td class="py-2 pr-3 font-mono text-xs text-gray-700 break-all"><?php echo e((string) $d['url']); ?></td>
                            <td class="py-2 pr-3 text-gray-500"><?php echo (int) ($d['uses'] ?? 0); ?></td>
                            <td class="py-2 pr-3 text-xs text-gray-500">
                                <?php foreach (($d['where'] ?? []) as $w): ?>
                                <span class="inline-block bg-gray-100 rounded px-1.5 py-0.5 mr-1 mb-1">
                                    <?php echo e((string) $w['type']); ?> #<?php echo (int) $w['id']; ?> <?php echo e((string) $w['title']); ?>
                                </span>
                                <?php endforeach; ?>
                            </td>
                            <td class="py-2 text-right whitespace-nowrap">
                                <a href="#seo-redirects" @click.prevent="fixFrom(<?php echo htmlspecialchars(json_encode((string) $d['url']), ENT_QUOTES); ?>)"
                                   class="text-xs text-amber-600 hover:text-amber-500 px-2 py-1">建重定向</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function seoLinkcheck() {
            return {
                busy: false, msg: "",
                run() {
                    var self = this; this.busy = true; this.msg = "";
                    var b = new URLSearchParams(); b.set("action", "linkcheck_run");
                    fetch(window.location.href, { method: "POST", body: b })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res && res.code === 0) location.reload();
                            else { self.msg = (res && res.msg) || "扫描失败"; self.busy = false; }
                        })
                        .catch(function () { self.msg = "扫描失败"; self.busy = false; });
                },
                fixFrom(url) {
                    // 跨组件事件：重定向卡片监听 seo-fix-redirect 并回填来源输入框
                    window.dispatchEvent(new CustomEvent('seo-fix-redirect', { detail: { path: url } }));
                },
            };
        }
        </script>
    </div>
    <?php endif; ?>

    <?php if ($seoHasPro): ?>
    <!-- ===== 专业版：SEO 体检 + 批量修复 ===== -->
    <?php
    $colorCls = ['red' => 'bg-red-100 text-red-700', 'amber' => 'bg-amber-100 text-amber-700', 'gray' => 'bg-gray-100 text-gray-600'];
    $auditShown = array_slice($audit['items'], 0, 150);
    ?>
    <div id="seo-audit" class="bg-white rounded-lg shadow mb-6" x-data="seoAudit()">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <div>
                <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                    <i class="ti ti-stethoscope text-amber-500"></i> SEO 体检
                </h2>
                <p class="text-xs text-gray-400 mt-0.5">
                    共扫描 <strong><?php echo (int) $audit['total']; ?></strong> 条，
                    <span class="text-green-600"><?php echo (int) $audit['healthy']; ?> 条健康</span>，
                    <span class="text-red-500"><?php echo count($audit['items']); ?> 条有待优化</span>。可在下方直接改，即改即存。
                </p>
            </div>
            <span class="text-xs font-medium bg-amber-100 text-amber-700 px-2 py-1 rounded inline-flex items-center gap-1"><i class="ti ti-crown text-sm"></i> Pro</span>
        </div>

        <!-- 汇总 -->
        <?php if ($audit['summary']): ?>
        <div class="px-6 py-3 border-b flex flex-wrap gap-2">
            <?php foreach ($audit['summary'] as $code => $cnt):
                [$label, $color] = $auditIssueMeta[$code] ?? [$code, 'gray']; ?>
                <span class="text-xs px-2 py-1 rounded <?php echo $colorCls[$color]; ?>"><?php echo e($label); ?> · <?php echo (int) $cnt; ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="p-6">
            <?php if (!$audit['items']): ?>
                <p class="text-sm text-gray-400 text-center py-8"><i class="ti ti-circle-check text-2xl text-green-400 block mb-2"></i>全部内容 SEO 健康，暂无待优化项 🎉</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-xs text-gray-400 border-b">
                        <th class="py-2 pr-3 min-w-[140px]">内容</th>
                        <th class="py-2 pr-3">问题</th>
                        <th class="py-2 pr-3 min-w-[180px]">SEO 标题</th>
                        <th class="py-2 pr-3 min-w-[220px]">SEO 描述</th>
                        <th class="py-2 w-16"></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($auditShown as $it): ?>
                        <tr class="border-b border-gray-50 align-top" data-table="<?php echo e($it['table']); ?>" data-id="<?php echo (int) $it['id']; ?>">
                            <td class="py-2 pr-3">
                                <div class="text-gray-700 text-xs font-medium break-all line-clamp-2"><?php echo e($it['title'] ?: '（无标题）'); ?></div>
                                <span class="text-[10px] text-gray-400"><?php echo e($auditTables[$it['table']]['type'] ?? $it['table']); ?></span>
                            </td>
                            <td class="py-2 pr-3">
                                <div class="flex flex-wrap gap-1">
                                    <?php foreach ($it['issues'] as $code):
                                        [$label, $color] = $auditIssueMeta[$code] ?? [$code, 'gray']; ?>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded <?php echo $colorCls[$color]; ?>"><?php echo e($label); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="py-2 pr-3">
                                <input type="text" class="ykA-title w-full border border-gray-200 rounded px-2 py-1 text-xs" value="<?php echo e($it['seo_title']); ?>" placeholder="留空用标题">
                                <input type="hidden" class="ykA-kw" value="<?php echo e($it['seo_keywords']); ?>">
                            </td>
                            <td class="py-2 pr-3">
                                <textarea class="ykA-desc w-full border border-gray-200 rounded px-2 py-1 text-xs" rows="2" placeholder="搜索摘要，60–160 字"><?php echo e($it['seo_description']); ?></textarea>
                            </td>
                            <td class="py-2 text-right">
                                <button type="button" @click="save($event.target)" class="text-xs border border-amber-200 text-amber-700 bg-amber-50 hover:bg-amber-100 px-2 py-1 rounded">保存</button>
                                <div class="ykA-msg text-[10px] text-green-600 mt-1"></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($audit['items']) > count($auditShown)): ?>
                <p class="text-xs text-gray-400 mt-3">仅显示前 <?php echo count($auditShown); ?> 条待优化项，共 <?php echo count($audit['items']); ?> 条。修复后刷新本页继续。</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <script>
        function seoAudit() {
            return {
                save(btn) {
                    var tr = btn.closest('tr');
                    var msg = tr.querySelector('.ykA-msg');
                    var body = new URLSearchParams();
                    body.set('action', 'audit_save');
                    body.set('table', tr.getAttribute('data-table'));
                    body.set('id', tr.getAttribute('data-id'));
                    body.set('seo_title', tr.querySelector('.ykA-title').value);
                    body.set('seo_description', tr.querySelector('.ykA-desc').value);
                    body.set('seo_keywords', tr.querySelector('.ykA-kw').value);
                    btn.disabled = true; msg.style.color = '#6b7280'; msg.textContent = '保存中…';
                    fetch(window.location.href, { method: 'POST', body: body })
                        .then(function (r) { return r.json(); })
                        .then(function (res) {
                            if (res && res.code === 0) { msg.style.color = '#16a34a'; msg.textContent = '✓ 已存'; }
                            else { msg.style.color = '#dc2626'; msg.textContent = (res && res.msg) || '失败'; }
                        })
                        .catch(function () { msg.style.color = '#dc2626'; msg.textContent = '失败'; })
                        .finally(function () { btn.disabled = false; });
                },
            };
        }
        </script>
    </div>
    <?php endif; ?>

    <!-- ===== 专业版功能（就地上锁展示） ===== -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                <i class="ti ti-crown text-amber-500"></i> 专业版功能
            </h2>
            <?php if (!$seoHasPro): ?>
            <span class="text-xs text-gray-400">升级后解锁</span>
            <?php endif; ?>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php
            $proCards = [
                ['ti-sparkles',   'AI 一键优化 meta', '基于站内 AI，一键生成 / 改写 SEO 标题与描述、推荐关键词。', '#seo-ai-note'],
                ['ti-arrows-right-left', '重定向管理器', '301/404 监控与重定向规则，改版换链接不丢权重、不丢流量。', '#seo-redirects'],
                ['ti-link-off',   '失效链接检查', '扫描文章与产品正文，揪出站内死链；一键转入重定向修复。', '#seo-linkcheck'],
                ['ti-send',       '搜索引擎自动推送', '内容有增改就自动 ping 百度、IndexNow（Bing/Yandex），带历史与配额。', '#seo-autopush'],
                ['ti-link',       '内链建议 + 基石内容', '正文里提到、站内又有对应内容的地方给出内链建议；标记基石内容汇聚权重。', '#seo-cornerstone'],
            ];
            foreach ($proCards as [$icon, $title, $desc, $liveAnchor]):
                $isLive = $liveAnchor !== '';
            ?>
            <div class="relative border rounded-lg p-4 <?php echo $seoHasPro ? 'border-gray-200' : 'border-gray-200 bg-gray-50'; ?>">
                <div class="flex items-start gap-3">
                    <div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-500 flex items-center justify-center shrink-0">
                        <i class="ti <?php echo $icon; ?> text-lg"></i>
                    </div>
                    <div class="min-w-0">
                        <div class="font-medium text-gray-800 text-sm flex items-center gap-1.5">
                            <?php echo e($title); ?>
                            <?php if (!$seoHasPro): ?><i class="ti ti-lock text-xs text-gray-400"></i><?php endif; ?>
                        </div>
                        <p class="text-xs text-gray-500 mt-1 leading-relaxed"><?php echo e($desc); ?></p>
                        <?php if ($seoHasPro && $isLive): ?>
                        <a href="<?php echo e($liveAnchor); ?>" class="inline-flex items-center gap-1 mt-2 text-xs text-green-600 hover:text-green-500"><i class="ti ti-circle-check text-sm"></i> 已上线 · 前往设置</a>
                        <?php elseif ($seoHasPro): ?>
                        <span class="inline-block mt-2 text-xs text-gray-400">即将上线</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!$seoHasPro): ?>
        <div class="px-6 pb-6">
            <div class="bg-gradient-to-r from-gray-900 to-gray-700 rounded-lg px-5 py-4 flex items-center justify-between gap-4">
                <div class="text-white text-sm">
                    <div class="font-medium">解锁 SEO 助手专业版</div>
                    <div class="text-gray-300 text-xs mt-0.5">购买注册码后即可开启以上全部高级功能。</div>
                </div>
                <a href="/admin/plugin.php" class="bg-amber-400 hover:bg-amber-300 text-gray-900 text-sm font-medium px-4 py-2 rounded-lg whitespace-nowrap">
                    了解专业版
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // Separate factories: an object literal cannot contain a function declaration.
    function seoAutopush() {
        return {
            on: <?php echo $autopushOn ? 'true' : 'false'; ?>,
            busy: false,
            msg: '',
            msgOk: true,
            async _post(action, extra) {
                var b = new URLSearchParams();
                b.set('action', action);
                b.set('_token', document.querySelector('meta[name="csrf-token"]')?.content || '');
                for (var k in (extra || {})) b.set(k, extra[k]);
                var r = await fetch('', { method: 'POST', body: b, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                return await r.json();
            },
            async toggle() {
                var d = await this._post('autopush_toggle', { val: this.on ? '1' : '0' });
                this.msgOk = d.code === 0;
                this.msg = d.msg || '';
                if (d.code !== 0) this.on = !this.on;
            },
            async pushNow() {
                this.busy = true; this.msg = '';
                try {
                    var d = await this._post('autopush_now');
                    this.msgOk = d.code === 0;
                    this.msg = d.msg || '';
                    if (d.code === 0) setTimeout(function () { location.reload(); }, 1200);
                } finally {
                    this.busy = false;
                }
            },
        };
    }

    function seoWorkshop() {
        return {
            loading: false,
            exists: <?php echo $llmsExists ? 'true' : 'false'; ?>,
            genAt: <?php echo $llmsGenAt ? ('"' . date('Y-m-d H:i', $llmsGenAt) . '"') : '""'; ?>,
            // 搜索引擎推送
            busy: false,
            pushMsg: "",
            urlCount: <?php echo (int) $pushUrlCount; ?>,
            baiduSite: <?php echo json_encode($baiduSite, JSON_UNESCAPED_UNICODE); ?>,
            baiduToken: <?php echo json_encode($baiduToken, JSON_UNESCAPED_UNICODE); ?>,
            keyName: <?php echo json_encode($indexnowKey, JSON_UNESCAPED_UNICODE); ?>,
            keyReady: <?php echo $indexnowKeyExists ? 'true' : 'false'; ?>,
            // URL 别名管理：draft 是输入框草稿（留空则提交建议值）
            slugRows: <?php echo json_encode(array_map(static function (array $r): array {
                $r['draft'] = '';
                return $r;
            }, $slugScan['rows']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            slugTotal: <?php echo (int) $slugScan['total']; ?>,
            slugInvalid: <?php echo (int) $slugScan['invalid']; ?>,
            slugPretty: <?php echo $slugScan['pretty'] ? 'true' : 'false'; ?>,
            slugOnlyInvalid: true,
            slugRedirect: <?php echo $seoHasPro ? 'true' : 'false'; ?>,
            slugBusy: false,
            slugMsg: "",
            slugMsgError: false,
            slugReload() {
                var self = this; this.slugBusy = true;
                this._post("slug_list", { filter: this.slugOnlyInvalid ? "invalid" : "all" })
                    .then(function (res) {
                        if (!res || res.code !== 0) throw new Error((res && res.msg) || "error");
                        self.slugRows = (res.data.rows || []).map(function (r) { r.draft = ""; return r; });
                        self.slugTotal = res.data.total; self.slugInvalid = res.data.invalid;
                    })
                    .catch(function (e) { self.slugMsgError = true; self.slugMsg = String(e.message || e); })
                    .finally(function () { self.slugBusy = false; });
            },
            slugSave(row) {
                var self = this;
                // 留空即采用建议值：异常行最常见的操作就是"就按建议改"
                var value = (row.draft || "").trim() || row.suggested || "";
                if (!value) { this.slugMsgError = true; this.slugMsg = <?php echo json_encode(__('seo_slug_bad_value'), JSON_UNESCAPED_UNICODE); ?>; return; }
                this.slugBusy = true; this.slugMsg = "";
                this._post("slug_rename", { table: row.table, id: row.id, slug: value, redirect: this.slugRedirect ? "1" : "" })
                    .then(function (res) {
                        if (!res || res.code !== 0) throw new Error((res && res.msg) || "error");
                        self.slugMsgError = false; self.slugMsg = res.msg;
                        self.slugReload();
                    })
                    .catch(function (e) { self.slugMsgError = true; self.slugMsg = String(e.message || e); })
                    .finally(function () { self.slugBusy = false; });
            },
            slugBulkFix() {
                var self = this; this.slugBusy = true; this.slugMsg = "";
                this._post("slug_bulk_fix", { redirect: this.slugRedirect ? "1" : "" })
                    .then(function (res) {
                        if (!res || res.code !== 0) throw new Error((res && res.msg) || "error");
                        self.slugMsgError = false; self.slugMsg = res.msg;
                        self.slugReload();
                    })
                    .catch(function (e) { self.slugMsgError = true; self.slugMsg = String(e.message || e); })
                    .finally(function () { self.slugBusy = false; });
            },
            _post(action, extra) {
                var body = new URLSearchParams();
                body.set("action", action);
                Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
                return fetch(window.location.href, { method: "POST", body: body }).then(function (r) { return r.json(); });
            },
            savePush() {
                var self = this; this.busy = true; this.pushMsg = "";
                this._post("save_push", { baidu_site: this.baiduSite, baidu_token: this.baiduToken })
                    .then(function (res) { self.pushMsg = (res && res.msg) || "已保存"; })
                    .catch(function () { self.pushMsg = "保存失败"; })
                    .finally(function () { self.busy = false; });
            },
            genKey() {
                var self = this; this.busy = true; this.pushMsg = "";
                this._post("gen_indexnow_key")
                    .then(function (res) {
                        if (res && res.code === 0) { self.keyName = res.data.key; self.keyReady = true; self.pushMsg = res.msg; }
                        else self.pushMsg = (res && res.msg) || "生成失败";
                    })
                    .catch(function () { self.pushMsg = "生成失败"; })
                    .finally(function () { self.busy = false; });
            },
            push(engine) {
                var self = this; this.busy = true; this.pushMsg = "推送中…";
                this._post(engine === "baidu" ? "push_baidu" : "push_indexnow")
                    .then(function (res) { self.pushMsg = (res && res.msg) || "完成"; })
                    .catch(function () { self.pushMsg = "推送失败"; })
                    .finally(function () { self.busy = false; });
            },
            generate() {
                var self = this;
                this.loading = true;
                var body = new URLSearchParams();
                body.set("action", "gen_llms");
                fetch(window.location.href, { method: "POST", body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        if (res && res.code === 0) {
                            self.exists = true;
                            self.genAt = (res.data && res.data.at) || "";
                            if (window.showToast) window.showToast(res.msg || "已生成");
                            else alert(res.msg || "已生成");
                        } else {
                            alert((res && res.msg) || "生成失败");
                        }
                    })
                    .catch(function() { alert("生成失败"); })
                    .finally(function() { self.loading = false; });
            },
        };
    }
    </script>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
