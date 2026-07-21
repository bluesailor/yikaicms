<?php
/**
 * YikaiCMS - 主题管理（本地主题 + 在线模板市场）
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/security.php';   // zipUnsafeEntry
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 模板市场 API（升级服务器承载，见 update.yikaicms/api/themes/list.php）
const THEME_MARKET_API = 'https://update.yikaicms.com/api/themes/list.php';

/** GET 一个 URL 返回 body（curl 优先，回退 allow_url_fopen；失败返回 null） */
function themeMarketHttpGet(string $url, int $timeout = 15): ?string
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

/** 递归删除主题目录（限定在 themes/ 内，防越界） */
function deleteThemeDir(string $slug): bool
{
    $slug = basename($slug);
    if ($slug === '' || $slug === 'default') return false;   // default 不可删
    $dir = ROOT_PATH . '/themes/' . $slug;
    if (!is_dir($dir)) return false;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    return @rmdir($dir);
}

/**
 * 从本地 ZIP 安装主题（上传安装与市场安装共用）。
 * 校验 theme.json、zip-slip 防护、解压到 themes/。
 * @return array{0: bool, 1: string, 2: string} [成功?, 消息, slug]
 */
function installThemeFromZip(string $zipPath): array
{
    if (!class_exists('ZipArchive')) {
        return [false, __('theme_err_nozip'), ''];
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return [false, __('theme_err_openzip'), ''];
    }

    // 查找 slug/theme.json 确定主题标识
    $themeSlug = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^([a-z0-9][a-z0-9\-]*[a-z0-9]|[a-z0-9])/theme\.json$#', $name, $m)) {
            $themeSlug = $m[1];
            break;
        }
    }
    if (!$themeSlug) {
        $zip->close();
        return [false, __('theme_err_nojson'), ''];
    }

    $meta = json_decode((string) $zip->getFromName($themeSlug . '/theme.json'), true);
    if (!is_array($meta) || empty($meta['name'])) {
        $zip->close();
        return [false, __('theme_err_badjson'), ''];
    }

    // zip-slip 防护：任一条目会逃出目录则拒绝，绝不 extractTo
    $unsafe = zipUnsafeEntry($zip);
    if ($unsafe !== null) {
        $zip->close();
        return [false, __('theme_err_unsafe') . '：' . $unsafe, ''];
    }

    $themesDir = ROOT_PATH . '/themes';
    if (!is_dir($themesDir)) {
        @mkdir($themesDir, 0755, true);
    }
    if (is_dir($themesDir . '/' . $themeSlug)) {
        deleteThemeDir($themeSlug);
    }
    $zip->extractTo($themesDir);
    $zip->close();

    return [true, __('theme_installed_ok') . '：' . ($meta['name'] ?? $themeSlug), $themeSlug];
}

// ============================================================
// AJAX（JSON）：模板市场列表 / 市场安装
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['market_list', 'market_install'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'];
    $slug = trim($_POST['slug'] ?? '');

    if ($action === 'market_install' && !preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug)) {
        echo json_encode(['code' => 1, 'msg' => __('theme_err_badslug')]);
        exit;
    }

    if ($action === 'market_list') {
        $q = trim((string) ($_POST['q'] ?? ''));
        $url = THEME_MARKET_API . ($q !== '' ? '?q=' . urlencode($q) : '');
        $resp = themeMarketHttpGet($url);
        if ($resp === null) {
            echo json_encode(['code' => 1, 'msg' => __('theme_err_market_conn')]);
            exit;
        }
        $data = json_decode($resp, true);
        if (!is_array($data) || ($data['code'] ?? 1) !== 0) {
            echo json_encode(['code' => 1, 'msg' => __('theme_err_market_resp')]);
            exit;
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // market_install：以服务端拿到的市场元数据为准（不信任前端传来的 URL/哈希）
    $resp = themeMarketHttpGet(THEME_MARKET_API);
    $data = $resp !== null ? json_decode($resp, true) : null;
    if (!is_array($data) || ($data['code'] ?? 1) !== 0) {
        echo json_encode(['code' => 1, 'msg' => __('theme_err_market_conn')]);
        exit;
    }
    $item = null;
    foreach (($data['data']['themes'] ?? []) as $t) {
        if (($t['slug'] ?? '') === $slug) { $item = $t; break; }
    }
    if (!$item || empty($item['download_url']) || empty($item['hash'])) {
        echo json_encode(['code' => 1, 'msg' => __('theme_err_market_notfound')]);
        exit;
    }

    // 下载到临时文件
    $tmpZip = tempnam(sys_get_temp_dir(), 'ykthm');
    $body = themeMarketHttpGet($item['download_url'], 120);
    if ($body === null || file_put_contents($tmpZip, $body) === false) {
        @unlink($tmpZip);
        echo json_encode(['code' => 1, 'msg' => __('theme_err_download')]);
        exit;
    }

    // 完整性：sha256 必须一致
    $expected = strtolower((string) preg_replace('/^sha256:/', '', $item['hash']));
    $actual = strtolower((string) hash_file('sha256', $tmpZip));
    if (!hash_equals($expected, $actual)) {
        @unlink($tmpZip);
        echo json_encode(['code' => 1, 'msg' => __('theme_err_hash')]);
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
        echo json_encode(['code' => 1, 'msg' => __('theme_err_sig')]);
        exit;
    }

    [$ok, $msg, $themeSlug] = installThemeFromZip($tmpZip);
    @unlink($tmpZip);
    if ($ok && $themeSlug !== $slug) {
        // 包内 slug 与市场条目不符：清掉刚解压的目录，拒绝
        deleteThemeDir($themeSlug);
        echo json_encode(['code' => 1, 'msg' => __('theme_err_slug_mismatch')]);
        exit;
    }
    if ($ok) {
        adminLog('theme', 'market_install', '市场安装模板: ' . $themeSlug . ' v' . ($item['version'] ?? ''));
    }
    echo json_encode(['code' => $ok ? 0 : 1, 'msg' => $msg]);
    exit;
}

$currentMenu = 'theme';
$pageTitle = __('admin_theme');
$message = '';
$messageType = '';

// 处理主题切换（页面表单，刷新式）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate') {
    try {
        $slug = $_POST['slug'] ?? '';
        $themeDir = ROOT_PATH . '/themes/' . basename($slug);
        if ($slug && is_dir($themeDir) && file_exists($themeDir . '/theme.json')) {
            settingModel()->set('current_theme', $slug);
            $message = __('theme_switched') . '「' . e($slug) . '」';
            $messageType = 'success';
        } else {
            $message = __('theme_not_found');
            $messageType = 'error';
        }
    } catch (\Throwable $ex) {
        $message = 'Error: ' . $ex->getMessage();
        $messageType = 'error';
    }
}

$themes = getThemes();
$currentTheme = currentTheme();

// 本地已装版本表（市场页签据此显示 已安装/可升级）
$localThemeVersions = [];
foreach ($themes as $t) {
    $localThemeVersions[$t['slug']] = (string) ($t['version'] ?? '0');
}

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6" x-data="themeManager()">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><?php echo __('admin_theme'); ?></h1>
        <span class="text-sm text-gray-500"><?php echo __('theme_current'); ?>：<span class="font-medium text-primary"><?php echo e($currentTheme); ?></span></span>
    </div>

    <?php if ($message): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
        <?php echo e($message); ?>
    </div>
    <?php endif; ?>

    <!-- 页签：本地主题 / 模板市场 -->
    <div class="flex gap-1 mb-6 border-b border-gray-200">
        <button type="button" @click="tab='local'"
            :class="tab==='local' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'"
            class="px-4 py-2 -mb-px border-b-2 font-medium text-sm cursor-pointer transition">
            <i class="ti ti-layout-grid mr-1"></i><?php echo __('theme_tab_local'); ?>
        </button>
        <button type="button" @click="openMarket()"
            :class="tab==='market' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'"
            class="px-4 py-2 -mb-px border-b-2 font-medium text-sm cursor-pointer transition">
            <i class="ti ti-shopping-bag mr-1"></i><?php echo __('theme_tab_market'); ?>
        </button>
    </div>

    <!-- ============ 本地主题 ============ -->
    <div x-show="tab==='local'">
    <?php if (empty($themes)): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
        <i class="ti ti-brush text-base mx-auto mb-4 text-gray-300"></i>
        <p><?php echo __('theme_none'); ?></p>
        <p class="text-xs mt-2"><?php echo __('theme_none_hint'); ?></p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($themes as $theme):
            $isActive = ($theme['slug'] === $currentTheme);
            $screenshot = '';
            if (!empty($theme['screenshot'])) {
                $screenshotPath = ROOT_PATH . '/themes/' . $theme['slug'] . '/' . $theme['screenshot'];
                if (file_exists($screenshotPath)) {
                    $screenshot = '/themes/' . $theme['slug'] . '/' . $theme['screenshot'];
                }
            }
        ?>
        <div class="bg-white rounded-lg shadow overflow-hidden <?php echo $isActive ? 'ring-2 ring-primary' : ''; ?>">
            <div class="aspect-[16/10] bg-gray-100 relative overflow-hidden">
                <?php if ($screenshot): ?>
                <img src="<?php echo e($screenshot); ?>" alt="<?php echo e($theme['name']); ?>" class="w-full h-full object-cover">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center text-gray-300">
                    <i class="ti ti-photo text-base"></i>
                </div>
                <?php endif; ?>
                <?php if ($isActive): ?>
                <div class="absolute top-3 right-3 bg-primary text-white text-xs font-bold px-3 py-1 rounded-full">
                    <?php echo __('theme_active'); ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-bold text-gray-800"><?php echo e($theme['name']); ?></h3>
                        <p class="text-sm text-gray-500 mt-1"><?php
                            $lang = getLang();
                            $descKey = ($lang === 'en' && !empty($theme['description_en'])) ? 'description_en'
                                : (($lang === 'ja' && !empty($theme['description_ja'])) ? 'description_ja' : 'description');
                            echo e($theme[$descKey] ?? '');
                        ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-4 mt-3 text-xs text-gray-400">
                    <?php if (!empty($theme['version'])): ?>
                    <span>v<?php echo e($theme['version']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($theme['author'])): ?>
                    <span><?php echo e($theme['author']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="mt-4 flex gap-2">
                    <?php if (!$isActive): ?>
                    <form method="POST" class="inline" onsubmit="return confirm('<?php echo __('theme_confirm_switch'); ?>')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="slug" value="<?php echo e($theme['slug']); ?>">
                        <button type="submit" class="px-4 py-2 bg-primary text-white text-sm rounded-lg hover:opacity-90 transition cursor-pointer">
                            <?php echo __('theme_activate'); ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="px-4 py-2 bg-gray-100 text-gray-500 text-sm rounded-lg"><?php echo __('theme_activated'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mt-8 bg-gray-50 rounded-lg p-6 text-sm text-gray-500">
        <h3 class="font-medium text-gray-700 mb-2"><?php echo __('theme_install_title'); ?></h3>
        <ol class="list-decimal list-inside space-y-1">
            <li><?php echo __('theme_install_step1'); ?></li>
            <li><?php echo __('theme_install_step2'); ?></li>
            <li><?php echo __('theme_install_step3'); ?></li>
            <li><?php echo __('theme_install_step4'); ?></li>
        </ol>
    </div>
    </div><!-- /local -->

    <!-- ============ 模板市场 ============ -->
    <div x-show="tab==='market'" x-cloak>
        <div class="flex items-center gap-3 mb-6">
            <div class="relative flex-1 max-w-md">
                <i class="ti ti-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <input type="text" x-model="q" @keydown.enter="search()"
                    placeholder="<?php echo __('theme_market_search_ph'); ?>"
                    class="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
            </div>
            <button type="button" @click="search()" :disabled="loading"
                class="px-4 py-2 bg-primary text-white text-sm rounded-lg hover:opacity-90 transition cursor-pointer disabled:opacity-50">
                <span x-show="!loading"><?php echo __('theme_market_search'); ?></span>
                <span x-show="loading"><?php echo __('theme_market_loading'); ?></span>
            </button>
        </div>

        <div x-show="error" class="mb-6 px-4 py-3 rounded-lg text-sm bg-red-50 text-red-700 border border-red-200" x-text="error"></div>

        <div x-show="loading" class="text-center text-gray-400 py-12"><?php echo __('theme_market_loading'); ?></div>

        <template x-if="!loading && loaded && items.length === 0">
            <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500"><?php echo __('theme_market_empty'); ?></div>
        </template>

        <div x-show="!loading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <template x-for="t in items" :key="t.slug">
                <div class="bg-white rounded-lg shadow overflow-hidden flex flex-col">
                    <div class="aspect-[16/10] bg-gray-100 relative overflow-hidden">
                        <img x-show="t.screenshot" :src="t.screenshot" :alt="t.name" class="w-full h-full object-cover">
                        <div x-show="!t.screenshot" class="w-full h-full flex items-center justify-center text-gray-300">
                            <i class="ti ti-photo text-base"></i>
                        </div>
                        <span x-show="t.category" class="absolute top-3 left-3 bg-black/50 text-white text-xs px-2 py-0.5 rounded" x-text="t.category"></span>
                    </div>
                    <div class="p-4 flex flex-col flex-1">
                        <h3 class="font-bold text-gray-800" x-text="t.name"></h3>
                        <p class="text-sm text-gray-500 mt-1 flex-1" x-text="descOf(t)"></p>
                        <div class="flex items-center gap-4 mt-3 text-xs text-gray-400">
                            <span x-text="'v' + t.version"></span>
                            <span x-text="t.author"></span>
                        </div>
                        <div class="mt-4">
                            <button type="button" @click="install(t)"
                                x-show="statusOf(t) !== 'installed'"
                                :disabled="installing === t.slug"
                                class="px-4 py-2 bg-primary text-white text-sm rounded-lg hover:opacity-90 transition cursor-pointer disabled:opacity-50">
                                <span x-show="installing !== t.slug" x-text="statusOf(t) === 'upgrade' ? '<?php echo __('theme_market_upgrade'); ?>' : '<?php echo __('theme_market_install'); ?>'"></span>
                                <span x-show="installing === t.slug"><?php echo __('theme_market_installing'); ?></span>
                            </button>
                            <span x-show="statusOf(t) === 'installed'" class="px-4 py-2 bg-gray-100 text-gray-500 text-sm rounded-lg inline-block"><?php echo __('theme_market_installed'); ?></span>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div><!-- /market -->
</div>

<script>
function themeManager() {
    return {
        tab: 'local',
        q: '',
        items: [],
        loading: false,
        loaded: false,
        installing: '',
        error: '',
        local: <?php echo json_encode($localThemeVersions, JSON_UNESCAPED_UNICODE); ?>,
        lang: <?php echo json_encode(getLang()); ?>,

        openMarket() {
            this.tab = 'market';
            if (!this.loaded && !this.loading) this.search();
        },
        descOf(t) {
            if (this.lang === 'en' && t.description_en) return t.description_en;
            if (this.lang === 'ja' && t.description_ja) return t.description_ja;
            return t.description || '';
        },
        async search() {
            this.loading = true;
            this.error = '';
            var body = new URLSearchParams();
            body.set('action', 'market_list');
            body.set('q', this.q);
            try {
                var resp = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body });
                if (resp.status === 401) { this.error = '登录已过期，请刷新页面重新登录'; this.loading = false; return; }
                var data = await resp.json();
                if (data.code === 0) {
                    this.items = (data.data && data.data.themes) || [];
                    this.loaded = true;
                } else {
                    this.error = data.msg || '<?php echo __('theme_market_error'); ?>';
                }
            } catch (e) {
                this.error = '<?php echo __('theme_market_neterr'); ?>：' + e.message;
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
        statusOf(t) {
            if (!(t.slug in this.local)) return 'none';
            return this.verCmp(t.version, this.local[t.slug]) > 0 ? 'upgrade' : 'installed';
        },
        async install(t) {
            var st = this.statusOf(t);
            if (st === 'installed') return;
            var verb = st === 'upgrade' ? '<?php echo __('theme_market_upgrade'); ?>' : '<?php echo __('theme_market_install'); ?>';
            if (!confirm(verb + '「' + t.name + '」 v' + t.version + ' ?')) return;
            this.installing = t.slug;
            var body = new URLSearchParams();
            body.set('action', 'market_install');
            body.set('slug', t.slug);
            try {
                var resp = await fetch('', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body });
                if (resp.status === 401) { showMessage('登录已过期，请刷新页面重新登录', 'error'); this.installing = ''; return; }
                var data = await resp.json();
                if (data.code === 0) {
                    showMessage(data.msg);
                    setTimeout(function() { location.reload(); }, 900);
                } else {
                    showMessage(data.msg, 'error');
                }
            } catch (e) {
                showMessage('<?php echo __('theme_market_error'); ?>', 'error');
            }
            this.installing = '';
        }
    };
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
