<?php
/**
 * YikaiCMS - 插件管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/security.php';   // zipUnsafeEntry()：插件安装/解压前的 zip-slip 校验依赖它（init.php 只在前台加载）
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 插件市场 API（升级服务器承载，见 update.yikaicms/api/plugins/list.php）
const PLUGIN_MARKET_API = 'https://update.yikaicms.com/api/plugins/list.php';

/**
 * 从本地 ZIP 安装插件（上传安装与市场安装共用）。
 * 校验 plugin.json、zip-slip 防护、解压到 plugins/、登记数据库。
 * @return array{0: bool, 1: string, 2: string} [成功?, 消息, slug]
 */
function pluginInstallFromZip(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        return [false, __('pl_no_zip_ext'), ''];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return [false, __('pl_zip_open_failed'), ''];
    }

    // 查找 slug/plugin.json 确定插件标识
    $pluginSlug = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^([a-z0-9][a-z0-9\-]*[a-z0-9]|[a-z0-9])/plugin\.json$#', $name, $m)) {
            $pluginSlug = $m[1];
            break;
        }
    }
    if (!$pluginSlug) {
        $zip->close();
        return [false, __('pl_no_manifest'), ''];
    }

    $meta = json_decode((string) $zip->getFromName($pluginSlug . '/plugin.json'), true);
    if (!is_array($meta) || empty($meta['name'])) {
        $zip->close();
        return [false, __('pl_manifest_invalid'), ''];
    }

    // zip-slip 防护：任一条目会逃出目录则拒绝，绝不 extractTo
    $unsafe = zipUnsafeEntry($zip);
    if ($unsafe !== null) {
        $zip->close();
        return [false, __('pl_unsafe_entry') . ': ' . $unsafe, ''];
    }
    // zip bomb 防护：文件数/解压总量/单文件/压缩比
    $violation = zipResourceViolation($zip);
    if ($violation !== null) {
        $zip->close();
        return [false, __('zip_resource_blocked', ['reason' => $violation]), ''];
    }

    $pluginsDir = ROOT_PATH . '/plugins';
    if (!is_dir($pluginsDir)) {
        @mkdir($pluginsDir, 0755, true);
    }
    if (is_dir($pluginsDir . '/' . $pluginSlug)) {
        deletePluginDir($pluginSlug);
    }
    $zip->extractTo($pluginsDir);
    $zip->close();

    if (!pluginModel()->findBySlug($pluginSlug)) {
        pluginModel()->create([
            'slug' => $pluginSlug,
            'status' => 0,
            'installed_at' => time(),
            'activated_at' => 0,
        ]);
    }
    return [true, __('pl_installed') . ': ' . ($meta['name'] ?? $pluginSlug), $pluginSlug];
}

/** GET 一个 URL 返回 body（curl 优先，回退 allow_url_fopen；失败返回 null） */
function pluginMarketHttpGet(string $url, int $timeout = 15): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        return is_string($resp) && $resp !== '' ? $resp : null;
    }
    if (ini_get('allow_url_fopen')) {
        $resp = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout]]));
        return is_string($resp) && $resp !== '' ? $resp : null;
    }
    return null;
}

// 确保 plugins 表存在
if (!db()->tableExists('plugins')) {
    db()->execute("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "plugins` (
        `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        `slug` varchar(100) NOT NULL COMMENT '插件标识',
        `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '状态：0禁用 1启用',
        `installed_at` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '安装时间',
        `activated_at` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '启用时间',
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_slug` (`slug`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='插件表'");
}

// AJAX 操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    $slug = trim($_POST['slug'] ?? '');

    // 验证 slug 格式（upload/market_list 不携带 slug）
    if (!in_array($action, ['upload', 'market_list'], true) && !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug)) {
        echo json_encode(['code' => 1, 'msg' => __('pl_bad_slug')]);
        exit;
    }

    switch ($action) {
        case 'activate':
            $exists = pluginModel()->findBySlug($slug);
            if ($exists) {
                pluginModel()->updateById((int)$exists['id'], [
                    'status' => 1,
                    'activated_at' => time()
                ]);
            } else {
                pluginModel()->create([
                    'slug' => $slug,
                    'status' => 1,
                    'installed_at' => time(),
                    'activated_at' => time()
                ]);
            }
            adminLog('plugin', 'activate', '启用插件: ' . $slug);
            echo json_encode(['code' => 0, 'msg' => __('pl_activated')]);
            break;

        case 'deactivate':
            pluginModel()->deactivate($slug);
            adminLog('plugin', 'deactivate', '禁用插件: ' . $slug);
            echo json_encode(['code' => 0, 'msg' => __('pl_deactivated')]);
            break;

        case 'delete':
            // 删除数据库记录
            pluginModel()->query(
                'DELETE FROM ' . pluginModel()->tableName() . ' WHERE slug = ?', [$slug]
            );
            // 删除目录
            $deleted = deletePluginDir($slug);
            adminLog('plugin', 'delete', '删除插件: ' . $slug);
            echo json_encode([
                'code' => 0,
                'msg' => $deleted ? __('pl_deleted') : __('pl_record_cleared')
            ]);
            break;

        case 'upload':
            if (empty($_FILES['plugin_zip']) || $_FILES['plugin_zip']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['code' => 1, 'msg' => __('pl_pick_zip')]);
                exit;
            }
            if (strtolower(pathinfo($_FILES['plugin_zip']['name'], PATHINFO_EXTENSION)) !== 'zip') {
                echo json_encode(['code' => 1, 'msg' => __('pl_zip_only')]);
                exit;
            }
            [$ok, $msg, $pluginSlug] = pluginInstallFromZip($_FILES['plugin_zip']['tmp_name']);
            if ($ok) {
                adminLog('plugin', 'upload', '上传安装插件: ' . $pluginSlug);
            }
            echo json_encode(['code' => $ok ? 0 : 1, 'msg' => $msg]);
            break;

        case 'market_list':
            // 服务端代理市场列表（避免浏览器跨域 + 便于将来附加授权参数）
            $q = trim((string) ($_POST['q'] ?? ''));
            // 带上本站授权码与域名：付费插件的下载地址由服务端按授权下发
            $url = PLUGIN_MARKET_API . '?' . http_build_query(array_filter([
                'q'      => $q,
                'key'    => function_exists('license_key') ? license_key() : '',
                'domain' => function_exists('license_domain') ? license_domain() : '',
            ]));
            $resp = pluginMarketHttpGet($url);
            if ($resp === null) {
                echo json_encode(['code' => 1, 'msg' => __('pl_market_unreachable')]);
                exit;
            }
            $data = json_decode($resp, true);
            if (!is_array($data) || ($data['code'] ?? 1) !== 0) {
                echo json_encode(['code' => 1, 'msg' => __('pl_market_bad_response')]);
                exit;
            }
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
            break;

        case 'market_install':
            // 以服务端拿到的市场元数据为准（不信任前端传来的 URL/哈希）；
            // 同样带授权参数，否则付费插件拿不到下载地址
            $resp = pluginMarketHttpGet(PLUGIN_MARKET_API . '?' . http_build_query(array_filter([
                'key'    => function_exists('license_key') ? license_key() : '',
                'domain' => function_exists('license_domain') ? license_domain() : '',
            ])));
            $data = $resp !== null ? json_decode($resp, true) : null;
            if (!is_array($data) || ($data['code'] ?? 1) !== 0) {
                echo json_encode(['code' => 1, 'msg' => __('pl_market_offline')]);
                exit;
            }
            $item = null;
            foreach (($data['data']['plugins'] ?? []) as $p) {
                if (($p['slug'] ?? '') === $slug) {
                    $item = $p;
                    break;
                }
            }
            if ($item && empty($item['download_url']) && !empty($item['paid'])) {
                // 服务端因授权不足未下发下载地址
                $why = (string) ($item['locked_reason'] ?? '');
                $tip = match ($why) {
                    'expired'         => __('plugin_locked_expired'),
                    'domain_mismatch' => __('plugin_locked_domain'),
                    default           => __('plugin_locked_need_license'),
                };
                echo json_encode(['code' => 1, 'msg' => $tip], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if (!$item || empty($item['download_url']) || empty($item['hash'])) {
                echo json_encode(['code' => 1, 'msg' => __('pl_not_in_market')]);
                exit;
            }

            // 下载到临时文件
            $tmpZip = tempnam(sys_get_temp_dir(), 'ykplg');
            $body = pluginMarketHttpGet($item['download_url'], 120);
            if ($body === null || file_put_contents($tmpZip, $body) === false) {
                @unlink($tmpZip);
                echo json_encode(['code' => 1, 'msg' => __('pl_download_failed')]);
                exit;
            }

            // 完整性：sha256 必须一致
            $expected = strtolower((string) preg_replace('/^sha256:/', '', $item['hash']));
            $actual = strtolower((string) hash_file('sha256', $tmpZip));
            if (!hash_equals($expected, $actual)) {
                @unlink($tmpZip);
                echo json_encode(['code' => 1, 'msg' => __('pl_hash_failed')]);
                exit;
            }

            // 来源：RSA-SHA256 验签（规范串 slug|version|sha256:hash，公钥同在线升级）
            require_once ROOT_PATH . '/includes/License.php';
            $sig = base64_decode((string) ($item['sig'] ?? ''), true);
            $canonical = $slug . '|' . ($item['version'] ?? '') . '|sha256:' . $expected;
            if ($sig === false || $sig === ''
                || !function_exists('openssl_verify')
                || openssl_verify($canonical, $sig, license_pubkey(), OPENSSL_ALGO_SHA256) !== 1) {
                @unlink($tmpZip);
                echo json_encode(['code' => 1, 'msg' => __('pl_sig_failed')]);
                exit;
            }

            [$ok, $msg, $pluginSlug] = pluginInstallFromZip($tmpZip);
            @unlink($tmpZip);
            if ($ok && $pluginSlug !== $slug) {
                // 包内 slug 与市场条目不符：清掉刚解压的目录，拒绝
                deletePluginDir($pluginSlug);
                pluginModel()->query('DELETE FROM ' . pluginModel()->tableName() . ' WHERE slug = ?', [$pluginSlug]);
                echo json_encode(['code' => 1, 'msg' => __('pl_mismatch')]);
                exit;
            }
            if ($ok) {
                adminLog('plugin', 'market_install', '市场安装插件: ' . $pluginSlug . ' v' . ($item['version'] ?? ''));
            }
            echo json_encode(['code' => $ok ? 0 : 1, 'msg' => $msg, 'slug' => $ok ? $pluginSlug : '']);
            break;

        default:
            echo json_encode(['code' => 1, 'msg' => __('blox_invalid_action')]);
    }
    exit;
}

// 获取所有插件
$plugins = getAllPlugins();

// 本地已装版本表（市场页签据此显示 已安装/可升级）
$localVersions = [];
foreach ($plugins as $slug => $p) {
    $localVersions[$slug] = (string) ($p['version'] ?? '0');
}

$pageTitle = __('pl_title');
$currentMenu = 'plugin';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div x-data="pluginMarket()">
    <!-- 页签 + 操作栏 -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-1">
            <button @click="tab = 'installed'"
                    :class="tab === 'installed' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:text-primary'"
                    class="px-4 py-2 rounded-lg text-sm font-medium shadow-sm transition cursor-pointer inline-flex items-center gap-1">
                <i class="ti ti-clipboard-list text-base"></i><?php echo e(__('pl_installed_tab')); ?> (<?php echo count($plugins); ?>)
                <span x-show="updCount() > 0" x-cloak
                      class="ml-0.5 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-amber-500 text-white text-xs font-bold"
                      x-text="updCount()"></span>
            </button>
            <button @click="openMarket()"
                    :class="tab === 'market' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:text-primary'"
                    class="px-4 py-2 rounded-lg text-sm font-medium shadow-sm transition cursor-pointer inline-flex items-center gap-1">
                <i class="ti ti-building-store text-base"></i><?php echo e(__('pl_market')); ?>
            </button>
        </div>
        <button x-show="tab === 'installed'" onclick="document.getElementById('uploadModal').classList.remove('hidden')"
                class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded transition inline-flex items-center gap-2">
            <i class="ti ti-cloud-upload text-base"></i>
            <?php echo e(__('pl_upload_install')); ?>
        </button>
    </div>

    <!-- Tab: 插件市场 -->
    <div x-show="tab === 'market'" x-cloak>
        <div class="flex items-center gap-2 mb-4">
            <div class="relative flex-1">
                <i class="ti ti-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input type="text" x-model="q" @keydown.enter="search()" placeholder="<?php echo e(__('pl_search_ph')); ?>"
                       data-testid="plugin-market-search"
                       class="w-full border rounded-lg pl-9 pr-3 py-2 text-sm bg-white">
            </div>
            <button @click="search()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded-lg text-sm transition cursor-pointer"><?php echo e(__('admin_search')); ?></button>
        </div>

        <template x-if="loading">
            <div class="bg-white rounded-lg shadow p-10 text-center text-gray-400 text-sm">
                <i class="ti ti-loader-2 animate-spin text-2xl block mb-2"></i><?php echo e(__('pl_loading_market')); ?>
            </div>
        </template>
        <template x-if="!loading && error">
            <div class="bg-white rounded-lg shadow p-10 text-center text-sm">
                <i class="ti ti-plug-connected-x text-3xl block mb-2 text-gray-300"></i>
                <p class="text-red-500 mb-2" x-text="error"></p>
                <button @click="search()" class="text-primary hover:underline cursor-pointer"><?php echo e(__('pl_retry')); ?></button>
            </div>
        </template>
        <template x-if="!loading && !error && loaded && items.length === 0">
            <div class="bg-white rounded-lg shadow p-10 text-center text-gray-400 text-sm"><?php echo e(__('pl_no_match')); ?></div>
        </template>

        <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4" x-show="!loading && !error" data-testid="plugin-market-list">
            <template x-for="p in items" :key="p.slug">
                <div class="bg-white rounded-lg shadow px-5 py-4 flex flex-col gap-2" :data-plugin-slug="p.slug">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-lg bg-blue-100 text-blue-600 flex items-center justify-center">
                            <i class="ti ti-puzzle text-xl"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-semibold text-gray-800 truncate" x-text="p.name"></span>
                                <span class="text-xs text-gray-400" x-text="'v' + p.version"></span>
                                <!-- 付费标识：统一金色 PRO；已授权时加勾并在 title 说明 -->
                                <template x-if="p.paid">
                                    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 whitespace-nowrap inline-flex items-center gap-0.5"
                                          :title="p.entitled ? '<?php echo e(__('plugin_badge_licensed')); ?>' : '<?php echo e(__('plugin_tier_freemium_note')); ?>'">
                                        PRO
                                        <template x-if="p.entitled">
                                            <i class="ti ti-check text-xs"></i>
                                        </template>
                                    </span>
                                </template>
                            </div>
                            <div class="text-xs text-gray-400" x-text="(p.author || '') + (p.size_kb ? ' · ' + p.size_kb + ' KB' : '')"></div>
                        </div>
                        <template x-if="statusOf(p) === 'installed'">
                            <span class="text-xs px-2.5 py-1 rounded bg-gray-100 text-gray-400 whitespace-nowrap"><?php echo e(__('pl_installed_tab')); ?></span>
                        </template>
                        <template x-if="statusOf(p) === 'upgrade'">
                            <button @click="install(p)" :disabled="installing === p.slug"
                                    data-testid="plugin-market-upgrade"
                                    class="text-sm px-3 py-1.5 rounded bg-amber-500 hover:bg-amber-600 text-white transition cursor-pointer whitespace-nowrap disabled:opacity-50"
                                    x-text="installing === p.slug ? marketText.upgrading : marketText.upgrade"></button>
                        </template>
                        <template x-if="statusOf(p) === 'none' && p.tier === 'pro' && !p.entitled">
                            <a href="https://www.yikaicms.com/#pricing" target="_blank"
                               class="text-sm px-3 py-1.5 rounded bg-amber-500 hover:bg-amber-600 text-white transition cursor-pointer whitespace-nowrap inline-flex items-center gap-1">
                                <i class="ti ti-key text-sm"></i><?php echo e(__('plugin_get_license')); ?>
                            </a>
                        </template>
                        <template x-if="statusOf(p) === 'none' && !(p.tier === 'pro' && !p.entitled)">
                            <button @click="install(p)" :disabled="installing === p.slug"
                                    data-testid="plugin-market-install"
                                    class="text-sm px-3 py-1.5 rounded bg-primary hover:bg-secondary text-white transition cursor-pointer whitespace-nowrap disabled:opacity-50"
                                    x-text="installing === p.slug ? marketText.installing : marketText.install"></button>
                        </template>
                    </div>
                    <p class="text-sm text-gray-500" x-text="p.description"></p>
                    <template x-if="p.paid && !p.entitled">
                        <p class="text-xs text-amber-700 flex items-center gap-1">
                            <i class="ti ti-lock text-sm"></i>
                            <span x-text="p.tier === 'freemium'
                                ? '<?php echo e(__('plugin_tier_freemium_note')); ?>'
                                : '<?php echo e(__('plugin_tier_pro_note')); ?>'"></span>
                            <a href="https://www.yikaicms.com/#pricing" target="_blank"
                               class="underline hover:no-underline"><?php echo e(__('plugin_view_license')); ?></a>
                        </p>
                    </template>
                </div>
            </template>
        </div>
        <p class="text-xs text-gray-400 mt-4"><?php echo e(__('pl_security_note')); ?></p>
    </div>

    <!-- Tab: 已安装 -->
    <div x-show="tab === 'installed'">
    <?php if (empty($plugins)): ?>
    <!-- 空状态 -->
    <div class="bg-white rounded-lg shadow p-12 text-center">
        <i class="ti ti-clipboard text-base mx-auto text-gray-300 mb-4"></i>
        <p class="text-gray-500 text-lg mb-2"><?php echo e(__('pl_empty')); ?></p>
        <p class="text-gray-400 text-sm"><?php echo e(__('pl_empty_tip')); ?> <code class="bg-gray-100 px-1 rounded">/plugins/</code> 目录，或点击上方按钮上传安装。</p>
    </div>
    <?php else: ?>
    <!-- 插件列表 -->
    <div class="space-y-4" id="pluginList">
        <?php foreach ($plugins as $slug => $p): ?>
        <div class="bg-white rounded-lg shadow" id="plugin-<?php echo e($slug); ?>">
            <div class="px-6 py-5 flex items-start gap-4">
                <!-- 插件图标 -->
                <div class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center <?php echo $p['status'] ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>">
                    <i class="ti ti-clipboard text-xl"></i>
                </div>
                <!-- 插件信息 -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3 mb-1">
                        <?php
                        $pName = pluginMetaLabel($p, 'name', (string) $slug);
                        ?>
                        <h3 class="font-semibold text-gray-800"><?php echo e($pName); ?></h3>
                        <?php if (!empty($p['version'])): ?>
                        <span class="text-xs text-gray-400">v<?php echo e($p['version']); ?></span>
                        <?php endif; ?>
                        <?php
                        // 付费插件标识：统一金色 PRO；已授权时加勾（Pro 能力已解锁）
                        $pModule = trim((string) ($p['module'] ?? ''));
                        $pTier   = strtolower((string) ($p['tier'] ?? 'free'));
                        if ($pModule !== '' && $pTier !== 'free'):
                            $pLicensed = function_exists('license_has_module') && license_has_module($pModule);
                        ?>
                        <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded whitespace-nowrap bg-amber-100 text-amber-700 inline-flex items-center gap-0.5"
                              title="<?php echo e($pLicensed ? __('plugin_badge_licensed') : __('plugin_tier_freemium_note')); ?>">
                            PRO<?php if ($pLicensed): ?><i class="ti ti-check text-xs"></i><?php endif; ?>
                        </span>
                        <?php endif; ?>
                        <template x-if="upd['<?php echo e($slug); ?>']">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">
                                <i class="ti ti-arrow-big-up-line text-xs"></i><?php echo e(__('pl_upgradable')); ?> v<span x-text="upd['<?php echo e($slug); ?>'].version"></span>
                            </span>
                        </template>
                        <?php if ($p['status']): ?>
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700"><?php echo __('status_enabled'); ?></span>
                        <?php else: ?>
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500"><?php echo __('status_disabled'); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($p['description'])): ?>
                    <?php $pDesc = pluginMetaLabel($p, 'description'); ?>
                    <p class="text-sm text-gray-500 mb-2"><?php echo e($pDesc); ?></p>
                    <?php endif; ?>
                    <div class="text-xs text-gray-400 flex flex-wrap gap-4">
                        <?php if (!empty($p['author'])): ?>
                        <span><?php echo __('label_author'); ?>: <?php echo e($p['author']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($p['requires_php'])): ?>
                        <span>PHP: &ge; <?php echo e($p['requires_php']); ?></span>
                        <?php endif; ?>
                        <span>ID: <?php echo e($slug); ?></span>
                    </div>
                </div>
                <!-- 操作按钮 -->
                <div class="flex-shrink-0 flex items-center gap-2">
                    <button x-show="upd['<?php echo e($slug); ?>']" x-cloak
                            @click="upgradeInstalled('<?php echo e($slug); ?>')"
                            :disabled="installing === '<?php echo e($slug); ?>'"
                            class="px-3 py-1.5 text-sm bg-amber-500 hover:bg-amber-600 text-white rounded transition inline-flex items-center gap-1">
                        <i class="ti ti-cloud-download text-sm"></i>
                        <span x-text="installing === '<?php echo e($slug); ?>' ? marketText.upgrading : marketText.upgrade"></span>
                    </button>
                    <?php if ($p['status']): ?>
                    <?php if (file_exists(ROOT_PATH . '/plugins/' . $slug . '/admin.php')): ?>
                    <a href="/admin/plugin_page.php?plugin=<?php echo e($slug); ?>"
                       class="px-3 py-1.5 text-sm bg-primary text-white rounded hover:bg-secondary transition inline-flex items-center gap-1">
                        <i class="ti ti-settings text-sm"></i>
                        <?php echo e(__('pl_settings')); ?>
                    </a>
                    <?php endif; ?>
                    <button onclick="pluginAction('deactivate', '<?php echo e($slug); ?>')"
                            class="px-3 py-1.5 text-sm border border-yellow-300 text-yellow-700 rounded hover:bg-yellow-50 transition">
                        <?php echo e(__('pl_disable')); ?>
                    </button>
                    <?php else: ?>
                    <button onclick="pluginAction('activate', '<?php echo e($slug); ?>')"
                            class="px-3 py-1.5 text-sm bg-primary text-white rounded hover:bg-secondary transition">
                        <?php echo e(__('pl_enable')); ?>
                    </button>
                    <button onclick="pluginAction('delete', '<?php echo e($slug); ?>')"
                            class="px-3 py-1.5 text-sm border border-red-300 text-red-600 rounded hover:bg-red-50 transition">
                        <?php echo __('admin_delete'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    </div><!-- /Tab: 已安装 -->
</div>

<!-- 上传弹窗 -->
<div id="uploadModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800"><?php echo e(__('pl_upload_install')); ?></h3>
            <button onclick="document.getElementById('uploadModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600">
                <i class="ti ti-x text-lg"></i>
            </button>
        </div>
        <form id="uploadForm" class="p-6">
            <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center hover:border-primary transition cursor-pointer" id="dropZone">
                <i class="ti ti-cloud-upload text-base mx-auto text-gray-300 mb-3"></i>
                <p class="text-sm text-gray-500 mb-1"><?php echo e(__('pl_drop_hint')); ?></p>
                <p class="text-xs text-gray-400"><?php echo e(__('pl_zip_hint')); ?></p>
                <input type="file" id="pluginFile" name="plugin_zip" accept=".zip" class="hidden">
            </div>
            <div id="selectedFile" class="hidden mt-3 p-3 bg-gray-50 rounded flex items-center justify-between">
                <span id="fileName" class="text-sm text-gray-700 truncate"></span>
                <button type="button" onclick="clearFile()" class="text-gray-400 hover:text-red-500">
                    <i class="ti ti-x text-base"></i>
                </button>
            </div>
            <div class="mt-4 flex justify-end gap-3">
                <button type="button" onclick="document.getElementById('uploadModal').classList.add('hidden')"
                        class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded transition"><?php echo __('admin_cancel'); ?></button>
                <button type="button" id="btnUpload" onclick="uploadPlugin()"
                        class="px-4 py-2 text-sm bg-primary hover:bg-secondary text-white rounded transition"><?php echo e(__('pl_install')); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
// ===== 插件市场（Alpine 组件） =====
function pluginMarket() {
    // 深链：控制台推荐卡按 ?tab=market&q=<slug> 直达市场对应条目
    var ykQs = new URLSearchParams(location.search);
    var ykTab = ykQs.get('tab') === 'market' ? 'market' : 'installed';
    return {
        tab: ykTab,
        q: ykTab === 'market' ? (ykQs.get('q') || '') : '',
        items: [],
        loading: false,
        loaded: false,
        installing: '',
        error: '',
        marketText: <?php echo json_encode([
            'install' => __('pl_install'),
            'installing' => __('pl_installing'),
            'upgrade' => __('pl_upgrade'),
            'upgrading' => __('pl_upgrading'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        // 本地已装版本表：slug -> version
        local: <?php echo json_encode($localVersions, JSON_UNESCAPED_UNICODE); ?>,
        // 可升级映射：slug -> 市场条目（进页面即后台静默检测）
        upd: {},

        init() {
            this.checkUpdates();
            // 直接落在市场页签时立刻检索（checkUpdates 只预热不筛关键词）
            if (this.tab === 'market') this.search();
        },
        async checkUpdates() {
            if (!Object.keys(this.local).length) return;
            var body = new URLSearchParams();
            body.set('action', 'market_list');
            try {
                var resp = await fetch('', { method: 'POST', body: body });
                var data = await resp.json();
                if (data.code !== 0) return;
                var marketItems = (data.data && data.data.plugins) || [];
                var u = {};
                for (var i = 0; i < marketItems.length; i++) {
                    var it = marketItems[i];
                    if (it.slug in this.local && this.verCmp(it.version, this.local[it.slug]) > 0) u[it.slug] = it;
                }
                this.upd = u;
            } catch (e) { /* 静默：检测失败不打扰 */ }
        },
        updCount() { return Object.keys(this.upd).length; },
        upgradeInstalled(slug) {
            if (this.upd[slug]) this.install(this.upd[slug]);
        },

        openMarket() {
            this.tab = 'market';
            if (!this.loaded && !this.loading) this.search();
        },
        filterMarketItems(items) {
            var terms = String(this.q || '').trim().toLocaleLowerCase().split(/\s+/).filter(Boolean);
            if (!terms.length) return items;
            return items.filter(function(p) {
                var haystack = [p.slug, p.name, p.description, p.category, p.author]
                    .map(function(value) { return String(value || ''); })
                    .join(' ')
                    .toLocaleLowerCase();
                return terms.every(function(term) { return haystack.indexOf(term) !== -1; });
            });
        },
        async search() {
            this.loading = true;
            this.error = '';
            var body = new URLSearchParams();
            body.set('action', 'market_list');
            body.set('q', this.q);
            try {
                var resp = await fetch('', { method: 'POST', body: body });
                var data = await resp.json();
                if (data.code === 0) {
                    this.items = this.filterMarketItems((data.data && data.data.plugins) || []);
                    this.loaded = true;
                } else {
                    this.error = data.msg || <?php echo json_encode(__('admin_load_failed'), JSON_UNESCAPED_UNICODE); ?>;
                }
            } catch (e) {
                this.error = <?php echo json_encode(__('ai_network_error'), JSON_UNESCAPED_UNICODE); ?> + e.message;
            }
            this.loading = false;
        },
        verCmp(a, b) {
            var x = String(a || '0').split('.'), y = String(b || '0').split('.');
            for (var i = 0; i < Math.max(x.length, y.length); i++) {
                var d = (parseInt(x[i] || '0', 10)) - (parseInt(y[i] || '0', 10));
                if (d) return d;
            }
            return 0;
        },
        statusOf(p) {
            if (!(p.slug in this.local)) return 'none';
            return this.verCmp(p.version, this.local[p.slug]) > 0 ? 'upgrade' : 'installed';
        },
        async install(p) {
            var st = this.statusOf(p);
            if (st === 'installed') return;
            if (!confirm((st === 'upgrade' ? <?php echo json_encode(__('pl_upgrade'), JSON_UNESCAPED_UNICODE); ?> : <?php echo json_encode(__('pl_install'), JSON_UNESCAPED_UNICODE); ?>) + ' ' + p.name + ' v' + p.version + '?')) return;
            this.installing = p.slug;
            var body = new URLSearchParams();
            body.set('action', 'market_install');
            body.set('slug', p.slug);
            try {
                var resp = await fetch('', { method: 'POST', body: body });
                var data = await resp.json();
                if (data.code === 0) {
                    showMessage(data.msg);
                    // 装完即问启用：免去用户去已安装列表里找启用按钮（2026-08-08 用户反馈）
                    if (data.slug && confirm(<?php echo json_encode(__('pl_activate_now'), JSON_UNESCAPED_UNICODE); ?>.replace(':name', p.name))) {
                        var act = new URLSearchParams();
                        act.set('action', 'activate');
                        act.set('slug', data.slug);
                        try { await fetch('', { method: 'POST', body: act }); } catch (e) {}
                    }
                    setTimeout(function() { location.reload(); }, 400);
                } else {
                    showMessage(data.msg, 'error');
                }
            } catch (e) {
                showMessage(<?php echo json_encode(__('pl_install_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            }
            this.installing = '';
        }
    };
}

// 文件选择 / 拖拽
var dropZone = document.getElementById('dropZone');
var fileInput = document.getElementById('pluginFile');

dropZone.addEventListener('click', function() { fileInput.click(); });
dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.classList.add('border-primary'); });
dropZone.addEventListener('dragleave', function() { dropZone.classList.remove('border-primary'); });
dropZone.addEventListener('drop', function(e) {
    e.preventDefault();
    dropZone.classList.remove('border-primary');
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        showFile(e.dataTransfer.files[0].name);
    }
});
fileInput.addEventListener('change', function() {
    if (fileInput.files.length) showFile(fileInput.files[0].name);
});

function showFile(name) {
    document.getElementById('fileName').textContent = name;
    document.getElementById('selectedFile').classList.remove('hidden');
}
function clearFile() {
    fileInput.value = '';
    document.getElementById('selectedFile').classList.add('hidden');
}

// 上传安装
async function uploadPlugin() {
    if (!fileInput.files.length) {
        showMessage(<?php echo json_encode(__('pl_pick_zip'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        return;
    }
    var btn = document.getElementById('btnUpload');
    btn.disabled = true;
    btn.textContent = <?php echo json_encode(__('pl_installing'), JSON_UNESCAPED_UNICODE); ?>;

    var formData = new FormData();
    formData.append('action', 'upload');
    formData.append('plugin_zip', fileInput.files[0]);

    try {
        var resp = await fetch('', { method: 'POST', body: formData });
        var data = await resp.json();
        if (data.code === 0) {
            showMessage(data.msg);
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_upload_failed'), JSON_UNESCAPED_UNICODE); ?> + ': ' + err.message, 'error');
    }
    btn.disabled = false;
    btn.textContent = <?php echo json_encode(__('pl_install'), JSON_UNESCAPED_UNICODE); ?>;
}

// 启用/禁用/删除
async function pluginAction(action, slug) {
    if (action === 'delete' && !confirm(<?php echo json_encode(__('pl_del_confirm'), JSON_UNESCAPED_UNICODE); ?>)) {
        return;
    }

    var formData = new FormData();
    formData.append('action', action);
    formData.append('slug', slug);

    try {
        var resp = await fetch('', { method: 'POST', body: formData });
        var data = await resp.json();
        if (data.code === 0) {
            showMessage(data.msg);
            setTimeout(function() { location.reload(); }, 800);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_action_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
