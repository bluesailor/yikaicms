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
require_once ROOT_PATH . '/includes/ThemeValidator.php';
require_once ROOT_PATH . '/includes/ThemeInstaller.php';
require_once ROOT_PATH . '/includes/ThemeMarket.php';
require_once ROOT_PATH . '/includes/ThemePalette.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

/**
 * @param array{ok:bool,code:string,detail:string,slug:string,name:string,warnings:list<string>,backup:string} $result
 */
function themeInstallMessage(array $result): string
{
    if ($result['ok']) {
        $message = __('theme_installed_ok') . '：' . ($result['name'] !== '' ? $result['name'] : $result['slug']);
        if ($result['warnings'] !== []) {
            $message .= '（' . count($result['warnings']) . ' ' . __('theme_install_warning_count') . '：'
                . implode('；', array_slice($result['warnings'], 0, 3))
                . (count($result['warnings']) > 3 ? '…' : '') . '）';
        }
        return $message;
    }

    $key = match ($result['code']) {
        'no_zip' => 'theme_err_nozip',
        'open_zip' => 'theme_err_openzip',
        'no_json' => 'theme_err_nojson',
        'bad_json' => 'theme_err_badjson',
        'unsafe' => 'theme_err_unsafe',
        'slug_mismatch' => 'theme_err_slug_mismatch',
        'version_mismatch' => 'theme_err_version_mismatch',
        'default_protected' => 'theme_err_default_protected',
        'staging_create', 'backup_create' => 'theme_err_staging',
        'extract' => 'theme_err_extract',
        'backup_move', 'activate' => 'theme_err_replace',
        'rollback_failed' => 'theme_err_rollback',
        'cleanup' => 'theme_err_cleanup',
        default => 'theme_err_invalid',
    };
    $message = $result['code'] === 'resource'
        ? __('zip_resource_blocked', ['reason' => $result['detail']])
        : __($key);
    return $result['detail'] !== '' && $result['code'] !== 'resource'
        ? $message . '：' . $result['detail']
        : $message;
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
        $data = ThemeMarket::request($q);
        if ($data === null) {
            echo json_encode(['code' => 1, 'msg' => __('theme_err_market_conn')]);
            exit;
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    verifyCsrf();

    // market_install：以服务端拿到的市场元数据为准（不信任前端传来的 URL/哈希）
    $data = ThemeMarket::request();
    if ($data === null) {
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

    $remoteVersion = (string) ($item['version'] ?? '');
    $localVersions = ThemeMarket::localVersions(ROOT_PATH . '/themes');
    if (!ThemeMarket::isRemoteVersionNewer($localVersions, $slug, $remoteVersion)) {
        adminLog('theme', 'market_install_blocked', 'Theme marketplace downgrade blocked: ' . $slug
            . ' local=' . ($localVersions[$slug] ?? 'unknown') . ' remote=' . $remoteVersion);
        echo json_encode(['code' => 1, 'msg' => __('theme_err_not_newer')]);
        exit;
    }

    // 下载到临时文件
    $tmpZip = tempnam(sys_get_temp_dir(), 'ykthm');
    if (!is_string($tmpZip)) {
        echo json_encode(['code' => 1, 'msg' => __('theme_err_staging')]);
        exit;
    }
    $download = ThemeMarket::downloadPackageToFile((string) $item['download_url'], $tmpZip);
    if (!$download['ok']) {
        @unlink($tmpZip);
        $downloadMessage = $download['code'] === 'too_large'
            ? __('theme_err_download_too_large')
            : __('theme_err_download');
        echo json_encode(['code' => 1, 'msg' => $downloadMessage]);
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
    if (!ThemeMarket::verifyPackageSignature(
        $slug,
        $remoteVersion,
        'sha256:' . $expected,
        (string) ($item['sig'] ?? ''),
        license_pubkey()
    )) {
        @unlink($tmpZip);
        echo json_encode(['code' => 1, 'msg' => __('theme_err_sig')]);
        exit;
    }

    $installer = new ThemeInstaller(ROOT_PATH . '/themes', ROOT_PATH . '/storage');
    $installResult = $installer->install($tmpZip, $slug, $remoteVersion);
    @unlink($tmpZip);
    $msg = themeInstallMessage($installResult);
    if ($installResult['ok']) {
        adminLog('theme', 'market_install', 'Theme marketplace install: ' . $installResult['slug'] . ' v' . ($item['version'] ?? ''));
    } else {
        adminLog('theme', 'market_install_failed', 'Theme marketplace install failed: ' . $slug
            . ' [' . $installResult['code'] . '] ' . $installResult['detail']);
    }
    echo json_encode(['code' => $installResult['ok'] ? 0 : 1, 'msg' => $msg]);
    exit;
}

$currentMenu = 'theme';
$pageTitle = __('admin_theme');
$message = '';
$messageType = '';
$themeFlash = $_SESSION['theme_flash'] ?? null;
unset($_SESSION['theme_flash']);
if (is_array($themeFlash) && is_string($themeFlash['message'] ?? null)) {
    $message = $themeFlash['message'];
    $messageType = ($themeFlash['type'] ?? '') === 'success' ? 'success' : 'error';
}

// 保存模板外观设置。前台继续读取既有颜色 key；额外按主题保存配置档案。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_theme_settings') {
    verifyCsrf();

    $primaryColor = strtoupper(trim((string) ($_POST['primary_color'] ?? '')));
    $secondaryColor = strtoupper(trim((string) ($_POST['secondary_color'] ?? '')));
    if (preg_match('/^#[0-9A-F]{6}$/D', $primaryColor) !== 1
        || preg_match('/^#[0-9A-F]{6}$/D', $secondaryColor) !== 1) {
        $message = __('theme_settings_invalid_color');
        $messageType = 'error';
    } else {
        $heightPc = max(200, min(1000, postInt('banner_height_pc', 650)));
        $heightMobile = max(150, min(600, postInt('banner_height_mobile', 300)));
        $fullscreen = (string) ($_POST['banner_fullscreen'] ?? '0') === '1' ? '1' : '0';
        $rawThemeStyle = $_POST['theme_style'] ?? [];
        $themeStyleInput = [];
        if (is_array($rawThemeStyle)) {
            foreach ($rawThemeStyle as $key => $value) {
                if (is_string($key)) {
                    $themeStyleInput[$key] = $value;
                }
            }
        }
        $styleSettings = ThemeSettings::normalize($themeStyleInput);

        $activeTheme = currentTheme();
        $colorProfiles = ThemePalette::profiles((string) config('theme_color_profiles', '{}'));
        $colorProfiles[$activeTheme] = [
            'primary' => $primaryColor,
            'secondary' => $secondaryColor,
        ];

        settingModel()->saveBatch([
            'primary_color' => $primaryColor,
            'secondary_color' => $secondaryColor,
            'theme_color_profiles' => ThemePalette::encodeProfiles($colorProfiles),
            'banner_height_pc' => (string) $heightPc,
            'banner_height_mobile' => (string) $heightMobile,
            'banner_fullscreen' => $fullscreen,
            ThemeSettings::KEY => ThemeSettings::encodeProfile($activeTheme, $styleSettings, (string) config(ThemeSettings::KEY, '')),
        ]);
        adminLog('theme', 'settings', '更新模板设置（配色与首页轮播）');
        do_action('data_changed');
        $message = __('theme_settings_saved');
        $messageType = 'success';
    }
}

// 处理主题切换（页面表单，刷新式）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'activate') {
    verifyCsrf();

    try {
        $slug = $_POST['slug'] ?? '';
        $themeDir = ROOT_PATH . '/themes/' . basename($slug);
        if ($slug && is_dir($themeDir) && file_exists($themeDir . '/theme.json')) {
            // 切过去之前先校验：缺 layouts/header.php 之类的主题一旦启用就是整站白屏，
            // 而那时候后台也进不去了（前台后台共用 header 的站尤其致命）。
            $vr = ThemeValidator::validateDir($themeDir, basename($slug));
            if ($vr['errors'] !== []) {
                $message = __('theme_err_invalid') . '：' . implode('；', $vr['errors']);
                $messageType = 'error';
            } else {
                $previousTheme = currentTheme();
                $colorProfiles = ThemePalette::profiles((string) config('theme_color_profiles', '{}'));
                $currentPrimary = strtoupper((string) config('primary_color', '#2563EB'));
                $currentSecondary = strtoupper((string) config('secondary_color', '#1D4ED8'));
                if (preg_match('/^#[0-9A-F]{6}$/D', $currentPrimary) === 1
                    && preg_match('/^#[0-9A-F]{6}$/D', $currentSecondary) === 1) {
                    $colorProfiles[$previousTheme] = [
                        'primary' => $currentPrimary,
                        'secondary' => $currentSecondary,
                    ];
                }
                $targetColors = $colorProfiles[(string) $slug]
                    ?? ThemePalette::definition(ROOT_PATH . '/themes', basename((string) $slug))['colors'];
                settingModel()->saveBatch([
                    'current_theme' => (string) $slug,
                    'primary_color' => $targetColors['primary'],
                    'secondary_color' => $targetColors['secondary'],
                    'theme_color_profiles' => ThemePalette::encodeProfiles($colorProfiles),
                ]);
                $_SESSION['theme_flash'] = [
                    'message' => __('theme_switched') . '「' . (string) $slug . '」',
                    'type' => 'success',
                ];
                redirect('/admin/theme.php');
            }
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
// 每套主题附上校验结果与区块覆盖，供卡片展示。
// 覆盖率是**扫文件系统**得出的（theme.json 的 supports 声明五套里三套与实际不符，已废弃）。
foreach ($themes as &$__t) {
    $__slug = (string) ($__t['slug'] ?? '');
    $__vr = ThemeValidator::validateDir(ROOT_PATH . '/themes/' . $__slug, $__slug);
    $__t['_errors']   = $__vr['errors'];
    $__t['_warnings'] = $__vr['warnings'];
    $__t['_coverage'] = themeBlockCoverage($__slug);
    $__t['_palette'] = ThemePalette::definition(ROOT_PATH . '/themes', $__slug);
}
unset($__t);
$currentTheme = currentTheme();
$requestedThemeTab = (string) get('tab');
$initialThemeTab = in_array($requestedThemeTab, ['local', 'market', 'settings'], true)
    ? $requestedThemeTab
    : 'local';
$highlightTheme = preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/D', (string) get('update')) === 1
    ? (string) get('update') : '';

$themePrimaryColor = (string) config('primary_color', '#2563EB');
$themeSecondaryColor = (string) config('secondary_color', '#1D4ED8');
$themeBannerHeightPc = max(200, min(1000, (int) config('banner_height_pc', 650)));
$themeBannerHeightMobile = max(150, min(600, (int) config('banner_height_mobile', 300)));
$themeBannerFullscreen = (string) config('banner_fullscreen', '0') === '1';
$themeStyle = ThemeSettings::read($currentTheme);
$activeThemeMeta = [];
foreach ($themes as $themeMeta) {
    if (($themeMeta['slug'] ?? '') === $currentTheme) {
        $activeThemeMeta = $themeMeta;
        break;
    }
}
$activeThemeName = (string) ($activeThemeMeta['name'] ?? $currentTheme);
$activeThemePalette = (array) ($activeThemeMeta['_palette']
    ?? ThemePalette::definition(ROOT_PATH . '/themes', $currentTheme));
$themeColorPresets = (array) ($activeThemePalette['palettes'] ?? []);
$presetNameKey = getLang() === 'en' ? 'name_en' : (getLang() === 'ja' ? 'name_ja' : 'name');

// 本地已装版本表（市场页签据此显示 已安装/可升级）
$localThemeVersions = [];
foreach ($themes as $t) {
    $localThemeVersions[$t['slug']] = (string) ($t['version'] ?? '0');
}

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6" x-data="themeManager()" x-init="init()">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><?php echo __('admin_theme'); ?></h1>
        <span class="text-sm text-gray-500"><?php echo __('theme_current'); ?>：<span class="font-medium text-primary"><?php echo e($currentTheme); ?></span></span>
    </div>

    <?php if ($message): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
        <?php echo e($message); ?>
    </div>
    <?php endif; ?>

    <!-- 页签：本地主题 / 模板市场 / 模板设置 -->
    <div class="flex gap-1 mb-6 border-b border-gray-200">
        <button type="button" @click="tab='local'"
            :class="tab==='local' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'"
            class="px-4 py-2 -mb-px border-b-2 font-medium text-sm cursor-pointer transition">
            <i class="ti ti-layout-grid mr-1"></i><?php echo __('theme_tab_local'); ?>
        </button>
        <button type="button" @click="openMarket()" data-testid="theme-market-tab"
            :class="tab==='market' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'"
            class="px-4 py-2 -mb-px border-b-2 font-medium text-sm cursor-pointer transition">
            <i class="ti ti-shopping-bag mr-1"></i><?php echo __('theme_tab_market'); ?>
        </button>
        <button type="button" @click="tab='settings'" data-testid="theme-settings-tab"
            :class="tab==='settings' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'"
            class="px-4 py-2 -mb-px border-b-2 font-medium text-sm cursor-pointer transition">
            <i class="ti ti-adjustments-horizontal mr-1"></i><?php echo __('theme_tab_settings'); ?>
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
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" data-testid="theme-local-list">
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
        <div class="bg-white rounded-lg shadow overflow-hidden <?php echo $isActive ? 'ring-2 ring-primary' : ''; ?>" data-theme-slug="<?php echo e($theme['slug']); ?>">
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
                        <?php $__palettePreview = (array) ($theme['_palette']['preview'] ?? []); ?>
                        <?php if ($__palettePreview !== []): ?>
                        <div class="mt-3 flex items-center gap-2" aria-label="<?php echo e(__('theme_factory_palette')); ?>">
                            <span class="text-xs text-gray-400"><?php echo e(__('theme_factory_palette')); ?></span>
                            <span class="inline-flex overflow-hidden border border-gray-200 rounded" aria-hidden="true">
                                <?php foreach ($__palettePreview as $__color): ?>
                                <span class="block w-5 h-5" style="background-color:<?php echo e((string) $__color); ?>"></span>
                                <?php endforeach; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="flex items-center gap-4 mt-3 text-xs text-gray-400">
                    <?php if (!empty($theme['version'])): ?>
                    <span>v<?php echo e($theme['version']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($theme['author'])): ?>
                    <span><?php echo e($theme['author']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($theme['category'])): ?>
                    <span class="px-1.5 py-0.5 bg-gray-100 rounded"><?php echo e($theme['category']); ?></span>
                    <?php endif; ?>
                </div>

                <?php
                // 校验状态与区块覆盖：让站长在**切过去之前**就知道这套主题能不能用、
                // 哪些区块会退回默认样式，而不是切完才发现。
                $__fb = (array) ($theme['_coverage']['fallback'] ?? []);
                ?>
                <?php if (!empty($theme['_errors'])): ?>
                <div class="mt-2 text-xs text-red-600 bg-red-50 border border-red-200 rounded px-2 py-1.5">
                    <?php echo __('theme_check_failed'); ?>：<?php echo e(implode('；', $theme['_errors'])); ?>
                </div>
                <?php elseif (!empty($theme['_warnings'])): ?>
                <div class="mt-2 text-xs text-amber-600" title="<?php echo e(implode('&#10;', $theme['_warnings'])); ?>">
                    <i class="ti ti-alert-triangle"></i>
                    <?php echo count($theme['_warnings']); ?> <?php echo __('theme_check_warnings'); ?>
                </div>
                <?php endif; ?>
                <?php if ($__fb): ?>
                <div class="mt-1.5 text-xs text-gray-400" title="<?php echo e(implode('、', $__fb)); ?>">
                    <?php echo count($__fb); ?> <?php echo __('theme_blocks_fallback'); ?>
                </div>
                <?php endif; ?>

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

        <div x-show="!loading" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" data-testid="theme-market-list">
            <template x-for="t in items" :key="t.slug">
                <div class="bg-white rounded-lg shadow overflow-hidden flex flex-col"
                    :class="highlight === t.slug ? 'ring-2 ring-amber-400' : ''" :data-theme-slug="t.slug">
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

    <!-- ============ 模板设置 ============ -->
    <div x-show="tab==='settings'" x-cloak data-testid="theme-settings-panel">
        <form method="POST" action="/admin/theme.php?tab=settings" class="bg-white rounded-lg shadow max-w-5xl">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="save_theme_settings">

            <div class="px-6 py-5 border-b border-gray-100">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-gray-800"><?php echo e(__('theme_settings_title')); ?></h2>
                    <span class="px-2 py-0.5 text-xs bg-gray-100 text-gray-600 rounded"><?php echo e($activeThemeName); ?></span>
                </div>
                <p class="mt-1 text-sm text-gray-500"><?php echo e(__('theme_settings_intro', ['theme' => $activeThemeName])); ?></p>
            </div>

            <div class="px-6 py-6 border-b border-gray-100">
                <h3 class="font-medium text-gray-800"><?php echo e(__('theme_settings_colors')); ?></h3>
                <p class="mt-1 text-sm text-gray-500"><?php echo e(__('theme_settings_colors_hint')); ?></p>

                <div class="mt-4 flex flex-wrap gap-2" aria-label="<?php echo e(__('setting_color_presets')); ?>">
                    <?php foreach ($themeColorPresets as $preset):
                        $isPresetActive = strtolower($themePrimaryColor) === strtolower($preset['primary'])
                            && strtolower($themeSecondaryColor) === strtolower($preset['secondary']);
                        $presetClass = $isPresetActive
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'border-gray-200 text-gray-600 hover:border-gray-400';
                    ?>
                    <button type="button"
                        data-theme-color-preset
                        data-primary="<?php echo e($preset['primary']); ?>"
                        data-secondary="<?php echo e($preset['secondary']); ?>"
                        aria-pressed="<?php echo $isPresetActive ? 'true' : 'false'; ?>"
                        onclick="applyThemeColorPreset(this)"
                        class="inline-flex items-center gap-2 px-3 py-2 border rounded-lg text-sm transition focus:outline-none focus:ring-2 focus:ring-primary/30 <?php echo $presetClass; ?>">
                        <span class="relative w-7 h-4 flex-shrink-0" aria-hidden="true">
                            <span class="absolute left-0 top-0 w-4 h-4 rounded-full border border-white shadow-sm" style="background: <?php echo e($preset['primary']); ?>"></span>
                            <span class="absolute right-0 top-0 w-4 h-4 rounded-full border border-white shadow-sm" style="background: <?php echo e($preset['secondary']); ?>"></span>
                        </span>
                        <?php
                        $presetLabel = (string) ($preset[$presetNameKey] ?? $preset['name'] ?? '');
                        echo e($presetLabel !== '' ? $presetLabel : __('theme_palette_default'));
                        ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-5">
                    <?php foreach ([
                        ['key' => 'primary_color', 'label' => __('setting_primary_color'), 'value' => $themePrimaryColor],
                        ['key' => 'secondary_color', 'label' => __('setting_secondary_color'), 'value' => $themeSecondaryColor],
                    ] as $colorField): ?>
                    <label class="block">
                        <span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e($colorField['label']); ?></span>
                        <span class="flex items-center gap-2">
                            <input type="color"
                                id="theme_<?php echo e($colorField['key']); ?>_picker"
                                value="<?php echo e($colorField['value']); ?>"
                                oninput="syncThemeColorPicker('<?php echo e($colorField['key']); ?>', this.value)"
                                class="w-11 h-10 p-1 border border-gray-200 rounded-lg cursor-pointer bg-white">
                            <input type="text"
                                id="theme_<?php echo e($colorField['key']); ?>"
                                name="<?php echo e($colorField['key']); ?>"
                                value="<?php echo e($colorField['value']); ?>"
                                pattern="#[0-9a-fA-F]{6}"
                                maxlength="7"
                                required
                                oninput="syncThemeColorText('<?php echo e($colorField['key']); ?>', this.value)"
                                class="flex-1 border border-gray-200 rounded-lg px-3 py-2 font-mono text-sm focus:outline-none focus:ring-2 focus:ring-primary/30">
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="px-6 py-6 border-b border-gray-100">
                <h3 class="font-medium text-gray-800"><?php echo e(__('theme_settings_global')); ?></h3>
                <p class="mt-1 text-sm text-gray-500"><?php echo e(__('theme_settings_global_hint')); ?></p>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-5">
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_layout')); ?></span>
                        <select name="theme_style[general][site_layout]" class="w-full border border-gray-200 rounded-lg px-3 py-2"><option value="full" <?php echo $themeStyle['general']['site_layout'] === 'full' ? 'selected' : ''; ?>><?php echo e(__('theme_settings_layout_full')); ?></option><option value="boxed" <?php echo $themeStyle['general']['site_layout'] === 'boxed' ? 'selected' : ''; ?>><?php echo e(__('theme_settings_layout_boxed')); ?></option></select>
                    </label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_max_width')); ?></span>
                        <input type="number" name="theme_style[general][content_max_width]" value="<?php echo (int) $themeStyle['general']['content_max_width']; ?>" min="760" max="1920" class="w-full border border-gray-200 rounded-lg px-3 py-2">
                    </label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_site_background')); ?></span><input type="color" name="theme_style[general][site_background]" value="<?php echo e($themeStyle['general']['site_background']); ?>" class="w-11 h-10 p-1 border border-gray-200 rounded-lg"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_content_background')); ?></span><input type="color" name="theme_style[general][content_background]" value="<?php echo e($themeStyle['general']['content_background']); ?>" class="w-11 h-10 p-1 border border-gray-200 rounded-lg"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_color_mode')); ?></span><select name="theme_style[general][color_mode]" class="w-full border border-gray-200 rounded-lg px-3 py-2"><option value="light" <?php echo $themeStyle['general']['color_mode'] === 'light' ? 'selected' : ''; ?>><?php echo e(__('theme_settings_light')); ?></option><option value="dark" <?php echo $themeStyle['general']['color_mode'] === 'dark' ? 'selected' : ''; ?>><?php echo e(__('theme_settings_dark')); ?></option><option value="auto" <?php echo $themeStyle['general']['color_mode'] === 'auto' ? 'selected' : ''; ?>><?php echo e(__('theme_settings_auto')); ?></option></select></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_base_font')); ?></span><input type="number" name="theme_style[typography][html_font_size]" value="<?php echo (int) $themeStyle['typography']['html_font_size']; ?>" min="14" max="20" class="w-full border border-gray-200 rounded-lg px-3 py-2"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_body_font')); ?></span><input type="text" name="theme_style[typography][body_font]" value="<?php echo e((string) $themeStyle['typography']['body_font']); ?>" maxlength="160" class="w-full border border-gray-200 rounded-lg px-3 py-2 font-mono text-xs" placeholder="system / Arial, sans-serif"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_heading_font')); ?></span><input type="text" name="theme_style[typography][heading_font]" value="<?php echo e((string) $themeStyle['typography']['heading_font']); ?>" maxlength="160" class="w-full border border-gray-200 rounded-lg px-3 py-2 font-mono text-xs" placeholder="system / Arial, sans-serif"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_section_spacing')); ?></span><input type="number" name="theme_style[spacing][section_padding_y]" value="<?php echo (int) $themeStyle['spacing']['section_padding_y']; ?>" min="0" max="240" class="w-full border border-gray-200 rounded-lg px-3 py-2"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_button_radius')); ?></span><input type="number" name="theme_style[button][radius]" value="<?php echo (int) $themeStyle['button']['radius']; ?>" min="0" max="32" class="w-full border border-gray-200 rounded-lg px-3 py-2"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_button_background')); ?></span><input type="color" name="theme_style[button][background]" value="<?php echo e($themeStyle['button']['background']); ?>" class="w-11 h-10 p-1 border border-gray-200 rounded-lg"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_button_text')); ?></span><input type="color" name="theme_style[button][text]" value="<?php echo e($themeStyle['button']['text']); ?>" class="w-11 h-10 p-1 border border-gray-200 rounded-lg"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_button_hover')); ?></span><input type="color" name="theme_style[button][hover_background]" value="<?php echo e($themeStyle['button']['hover_background']); ?>" class="w-11 h-10 p-1 border border-gray-200 rounded-lg"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_tablet_breakpoint')); ?></span><input type="number" name="theme_style[responsive][tablet]" value="<?php echo (int) $themeStyle['responsive']['tablet']; ?>" min="800" max="1400" class="w-full border border-gray-200 rounded-lg px-3 py-2"></label>
                    <label class="block"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_mobile_breakpoint')); ?></span><input type="number" name="theme_style[responsive][mobile]" value="<?php echo (int) $themeStyle['responsive']['mobile']; ?>" min="480" max="900" class="w-full border border-gray-200 rounded-lg px-3 py-2"></label>
                    <label class="block md:col-span-2"><span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('theme_settings_custom_css')); ?></span><textarea name="theme_style[custom_css]" rows="5" maxlength="20000" class="w-full border border-gray-200 rounded-lg px-3 py-2 font-mono text-xs" placeholder=".my-class { ... }"><?php echo e((string) $themeStyle['custom_css']); ?></textarea></label>
                </div>
            </div>

            <div class="px-6 py-6">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                    <div>
                        <h3 class="font-medium text-gray-800"><?php echo e(__('theme_settings_banner')); ?></h3>
                        <p class="mt-1 text-sm text-gray-500"><?php echo e(__('theme_settings_banner_hint')); ?></p>
                    </div>
                    <a href="/admin/banner.php" class="inline-flex items-center gap-1 text-sm text-primary hover:underline whitespace-nowrap">
                        <?php echo e(__('theme_settings_manage_banner')); ?><i class="ti ti-arrow-right"></i>
                    </a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mt-5">
                    <label class="block">
                        <span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('setting_banner_height_pc')); ?></span>
                        <span class="relative block">
                            <input type="number" name="banner_height_pc" value="<?php echo $themeBannerHeightPc; ?>" min="200" max="1000" required
                                class="w-full border border-gray-200 rounded-lg px-3 py-2 pr-12 focus:outline-none focus:ring-2 focus:ring-primary/30">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">px</span>
                        </span>
                    </label>
                    <label class="block">
                        <span class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('setting_banner_height_mobile')); ?></span>
                        <span class="relative block">
                            <input type="number" name="banner_height_mobile" value="<?php echo $themeBannerHeightMobile; ?>" min="150" max="600" required
                                class="w-full border border-gray-200 rounded-lg px-3 py-2 pr-12 focus:outline-none focus:ring-2 focus:ring-primary/30">
                            <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm text-gray-400">px</span>
                        </span>
                    </label>
                </div>

                <label class="mt-5 flex items-start gap-3 cursor-pointer">
                    <span class="relative mt-0.5 inline-flex flex-shrink-0">
                        <input type="checkbox" name="banner_fullscreen" value="1" class="sr-only peer" <?php echo $themeBannerFullscreen ? 'checked' : ''; ?>>
                        <span class="relative w-9 h-5 rounded-full bg-gray-200 transition peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40 peer-checked:bg-primary after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:w-4 after:h-4 after:rounded-full after:bg-white after:shadow-sm after:transition-transform peer-checked:after:translate-x-4"></span>
                    </span>
                    <span>
                        <span class="block text-sm font-medium text-gray-700"><?php echo e(__('theme_settings_fullscreen')); ?></span>
                        <span class="block mt-0.5 text-sm text-gray-500"><?php echo e(__('theme_settings_fullscreen_hint')); ?></span>
                    </span>
                </label>
            </div>

            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end rounded-b-lg">
                <button type="submit" class="inline-flex items-center gap-2 px-5 py-2 bg-primary text-white text-sm font-medium rounded-lg hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-primary/30 transition">
                    <i class="ti ti-device-floppy"></i><?php echo e(__('admin_save')); ?>
                </button>
            </div>
        </form>
    </div><!-- /settings -->
</div>

<script>
function setThemePresetState(primary, secondary) {
    document.querySelectorAll('[data-theme-color-preset]').forEach(function(button) {
        var active = button.dataset.primary.toUpperCase() === primary.toUpperCase()
            && button.dataset.secondary.toUpperCase() === secondary.toUpperCase();
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.classList.toggle('border-primary', active);
        button.classList.toggle('bg-primary/10', active);
        button.classList.toggle('text-primary', active);
        button.classList.toggle('border-gray-200', !active);
        button.classList.toggle('text-gray-600', !active);
    });
}

function updateThemePresetState() {
    var primary = document.getElementById('theme_primary_color').value || '';
    var secondary = document.getElementById('theme_secondary_color').value || '';
    setThemePresetState(primary, secondary);
}

function applyThemeColorPreset(button) {
    ['primary_color', 'secondary_color'].forEach(function(key) {
        var value = button.dataset[key === 'primary_color' ? 'primary' : 'secondary'];
        document.getElementById('theme_' + key).value = value;
        document.getElementById('theme_' + key + '_picker').value = value;
    });
    updateThemePresetState();
}

function syncThemeColorPicker(key, value) {
    document.getElementById('theme_' + key).value = value.toUpperCase();
    updateThemePresetState();
}

function syncThemeColorText(key, value) {
    if (/^#[0-9a-fA-F]{6}$/.test(value)) {
        document.getElementById('theme_' + key + '_picker').value = value;
    }
    updateThemePresetState();
}

function themeManager() {
    return {
        tab: <?php echo json_encode($initialThemeTab); ?>,
        highlight: <?php echo json_encode($highlightTheme); ?>,
        q: '',
        items: [],
        loading: false,
        loaded: false,
        installing: '',
        error: '',
        local: <?php echo json_encode($localThemeVersions, JSON_UNESCAPED_UNICODE); ?>,
        lang: <?php echo json_encode(getLang()); ?>,

        init() {
            if (this.tab === 'market') this.search();
        },
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
                if (resp.status === 401) { this.error = <?php echo json_encode(__('admin_session_expired'), JSON_UNESCAPED_UNICODE); ?>; this.loading = false; return; }
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
                if (resp.status === 401) { showMessage(<?php echo json_encode(__('admin_session_expired'), JSON_UNESCAPED_UNICODE); ?>, 'error'); this.installing = ''; return; }
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
