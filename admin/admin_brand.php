<?php
/**
 * YikaiCMS - 后台品牌（后台名称 / Logo / 版权）
 *
 * 自基本设置页独立出来。自定义后台品牌属注册码授权权益：
 * 未授权站点只读查看，后台显示出厂品牌；已保存的值保留，授权后自动生效。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/License.php';

checkLogin();
requirePermission('*');

$brandAllowed = adminBrandingCustomizable();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (!$brandAllowed) {
        error(__('admin_brand_license_required'), 403);
    }
    $settings = $_POST['settings'] ?? [];
    if (!is_array($settings)) {
        error(__('admin_bad_params'), 422);
    }

    $logo = trim((string) ($settings['admin_logo'] ?? ''));
    if ($logo !== '' && safeUrl($logo) === '') {
        error(__('admin_brand_logo_invalid'), 422);
    }
    $maxHeight = (int) ($settings['admin_logo_max_height'] ?? 80);

    settingModel()->saveBatch([
        'admin_title'           => mb_substr(trim((string) ($settings['admin_title'] ?? '')), 0, 60),
        'admin_copyright'       => mb_substr(trim((string) ($settings['admin_copyright'] ?? '')), 0, 200),
        'admin_logo'            => $logo,
        'admin_logo_max_height' => (string) max(16, min(200, $maxHeight ?: 80)),
    ]);
    adminLog('setting', 'admin_brand', '更新后台品牌');
    success();
}

$brandValues = [
    'admin_title'           => (string) config('admin_title', ADMIN_BRAND_DEFAULT_NAME),
    'admin_copyright'       => (string) config('admin_copyright', ''),
    'admin_logo'            => (string) config('admin_logo', ''),
    'admin_logo_max_height' => (string) config('admin_logo_max_height', '80'),
];
$brandLogoPreview = SiteAsset::availableUrl($brandValues['admin_logo']);

$pageTitle   = __('admin_brand_page_title');
$currentMenu = 'admin_brand';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-6" data-testid="admin-brand-page">
    <?php if (!$brandAllowed): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 p-5" data-testid="admin-brand-license-notice">
        <div class="flex items-start gap-3">
            <i class="ti ti-lock text-xl text-amber-600 mt-0.5" aria-hidden="true"></i>
            <div class="min-w-0 flex-1">
                <h2 class="font-semibold text-amber-900"><?php echo e(__('admin_brand_license_title')); ?></h2>
                <p class="mt-1 text-sm text-amber-800"><?php echo e(__('admin_brand_license_desc')); ?></p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <a href="/admin/license.php" class="inline-flex items-center gap-1 rounded bg-primary px-4 py-2 text-sm text-white hover:bg-secondary">
                        <i class="ti ti-key text-base"></i><?php echo e(__('admin_brand_enter_key')); ?>
                    </a>
                    <a href="https://www.yikaicms.com/pro.php" target="_blank" rel="noopener" class="inline-flex items-center gap-1 rounded border border-amber-300 bg-white px-4 py-2 text-sm text-amber-800 hover:bg-amber-100">
                        <i class="ti ti-external-link text-base"></i><?php echo e(__('admin_brand_learn_license')); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <form id="adminBrandForm" class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('admin_brand_page_title')); ?></h2>
            <p class="text-sm text-gray-500 mt-1"><?php echo e(__('admin_brand_page_desc')); ?></p>
        </div>

        <fieldset class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1fr)_16rem]" <?php echo $brandAllowed ? '' : 'disabled'; ?>>
            <div class="space-y-5 min-w-0">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                    <label for="brand_admin_title" class="text-gray-700 pt-2">
                        <?php echo e(__('setting_admin_title')); ?>
                        <span class="text-gray-400 text-sm block"><?php echo e(__('setting_admin_title_tip')); ?></span>
                    </label>
                    <div class="md:col-span-3">
                        <input type="text" id="brand_admin_title" name="settings[admin_title]" maxlength="60"
                               value="<?php echo e($brandValues['admin_title']); ?>"
                               class="w-full border rounded px-4 py-2 disabled:bg-gray-50 disabled:text-gray-400">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                    <label for="brand_admin_logo" class="text-gray-700 pt-2">
                        <?php echo e(__('setting_admin_logo')); ?>
                        <span class="text-gray-400 text-sm block"><?php echo e(__('setting_admin_logo_tip')); ?></span>
                    </label>
                    <div class="md:col-span-3">
                        <div class="flex flex-wrap gap-2 items-center">
                            <input type="text" id="brand_admin_logo" name="settings[admin_logo]"
                                   value="<?php echo e($brandValues['admin_logo']); ?>"
                                   class="min-w-0 flex-1 border rounded px-4 py-2 disabled:bg-gray-50 disabled:text-gray-400">
                            <button type="button" data-brand-upload
                                    class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                                <i class="ti ti-upload text-base"></i><?php echo __('btn_upload'); ?>
                            </button>
                            <button type="button" data-brand-media
                                    class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded inline-flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                                <i class="ti ti-photo text-base"></i><?php echo __('admin_media_library'); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                    <label for="brand_admin_logo_max_height" class="text-gray-700 pt-2">
                        <?php echo e(__('setting_admin_logo_max_height')); ?>
                        <span class="text-gray-400 text-sm block"><?php echo e(__('setting_admin_logo_max_height_tip')); ?></span>
                    </label>
                    <div class="md:col-span-3">
                        <input type="number" id="brand_admin_logo_max_height" name="settings[admin_logo_max_height]" min="16" max="200" step="1"
                               value="<?php echo e($brandValues['admin_logo_max_height']); ?>"
                               class="w-32 border rounded px-4 py-2 disabled:bg-gray-50 disabled:text-gray-400">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                    <label for="brand_admin_copyright" class="text-gray-700 pt-2">
                        <?php echo e(__('setting_admin_copyright')); ?>
                        <span class="text-gray-400 text-sm block"><?php echo e(__('setting_admin_copyright_tip')); ?></span>
                    </label>
                    <div class="md:col-span-3">
                        <input type="text" id="brand_admin_copyright" name="settings[admin_copyright]" maxlength="200"
                               value="<?php echo e($brandValues['admin_copyright']); ?>"
                               class="w-full border rounded px-4 py-2 disabled:bg-gray-50 disabled:text-gray-400">
                    </div>
                </div>
            </div>

            <div class="min-w-0">
                <p class="mb-2 text-xs font-medium text-gray-500"><?php echo e(__('admin_brand_preview')); ?></p>
                <div class="overflow-hidden rounded-lg border bg-sidebar" data-testid="admin-brand-preview">
                    <div class="flex min-h-12 items-center justify-center px-3 py-1">
                        <img id="brandPreviewLogo" src="<?php echo e($brandLogoPreview); ?>" alt="" class="w-auto max-w-full<?php echo $brandLogoPreview === '' ? ' hidden' : ''; ?>"
                             style="max-height: <?php echo max(16, min(200, (int) $brandValues['admin_logo_max_height'] ?: 80)); ?>px">
                        <span id="brandPreviewTitle" class="truncate text-xl font-bold text-white<?php echo $brandLogoPreview === '' ? '' : ' hidden'; ?>"><?php echo e($brandValues['admin_title'] !== '' ? $brandValues['admin_title'] : ADMIN_BRAND_DEFAULT_NAME); ?></span>
                    </div>
                    <div class="space-y-2 px-4 pb-4 pt-2" aria-hidden="true">
                        <div class="h-2 w-3/4 rounded bg-white/15"></div>
                        <div class="h-2 w-1/2 rounded bg-white/10"></div>
                        <div class="h-2 w-2/3 rounded bg-white/10"></div>
                    </div>
                </div>
                <p id="brandPreviewCopyright" class="mt-2 truncate text-center text-xs text-gray-500"></p>
            </div>
        </fieldset>

        <div class="px-6 py-4 border-t bg-gray-50 rounded-b-lg">
            <button type="submit" <?php echo $brandAllowed ? '' : 'disabled'; ?>
                    class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed">
                <i class="ti <?php echo $brandAllowed ? 'ti-check' : 'ti-lock'; ?> text-base"></i>
                <?php echo __('btn_save_settings'); ?>
            </button>
        </div>
    </form>
</div>

<input type="file" id="brandLogoFile" class="hidden" accept="image/*">

<script>
(function () {
    var form = document.getElementById('adminBrandForm');
    var title = document.getElementById('brand_admin_title');
    var logo = document.getElementById('brand_admin_logo');
    var maxHeight = document.getElementById('brand_admin_logo_max_height');
    var copyright = document.getElementById('brand_admin_copyright');
    var previewLogo = document.getElementById('brandPreviewLogo');
    var previewTitle = document.getElementById('brandPreviewTitle');
    var previewCopyright = document.getElementById('brandPreviewCopyright');
    var fileInput = document.getElementById('brandLogoFile');
    var defaultName = <?php echo json_encode(ADMIN_BRAND_DEFAULT_NAME); ?>;
    var year = <?php echo json_encode(date('Y')); ?>;

    function refreshPreview() {
        var url = logo.value.trim();
        previewTitle.textContent = title.value.trim() || defaultName;
        previewLogo.classList.toggle('hidden', url === '');
        previewTitle.classList.toggle('hidden', url !== '');
        if (url !== '' && previewLogo.getAttribute('src') !== url) previewLogo.src = url;
        var height = Math.max(16, Math.min(200, parseInt(maxHeight.value, 10) || 80));
        previewLogo.style.maxHeight = height + 'px';
        previewCopyright.textContent = copyright.value.trim() || ('© ' + year + ' ' + (title.value.trim() || defaultName));
    }

    [title, logo, maxHeight, copyright].forEach(function (input) {
        input.addEventListener('input', refreshPreview);
    });
    refreshPreview();

    form.querySelector('[data-brand-media]').addEventListener('click', function () {
        if (typeof openMediaPicker !== 'function') return;
        openMediaPicker(function (url) {
            logo.value = url;
            refreshPreview();
        });
    });
    form.querySelector('[data-brand-upload]').addEventListener('click', function () {
        fileInput.click();
    });
    fileInput.addEventListener('change', async function () {
        if (!this.files[0]) return;
        var data = new FormData();
        data.append('file', this.files[0]);
        data.append('type', 'images');
        try {
            var response = await fetch('/admin/upload.php', { method: 'POST', body: data });
            var result = await safeJson(response);
            if (result.code === 0) {
                logo.value = result.data.url;
                refreshPreview();
            } else {
                showMessage(result.msg || <?php echo json_encode(__('setting_upload_failed')); ?>, 'error');
            }
        } catch (err) {
            showMessage(<?php echo json_encode(__('setting_upload_failed')); ?>, 'error');
        }
        this.value = '';
    });

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        try {
            var response = await fetch(location.href, { method: 'POST', body: new FormData(form) });
            var result = await safeJson(response);
            if (result.code === 0) {
                showMessage(<?php echo json_encode(__('admin_success')); ?>);
                setTimeout(function () { location.reload(); }, 600);
            } else {
                showMessage(result.msg, 'error');
            }
        } catch (err) {
            showMessage(<?php echo json_encode(__('admin_brand_save_failed')); ?>, 'error');
        }
    });
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
