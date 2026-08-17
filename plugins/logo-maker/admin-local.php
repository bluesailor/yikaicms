<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

$localLayouts = [
    'horizontal' => __('logo_maker_layout_horizontal'),
    'stacked' => __('logo_maker_layout_stacked'),
    'mark-only' => __('logo_maker_layout_mark_only'),
];
$localSymbols = [
    'circle' => __('logo_maker_symbol_circle'),
    'diamond' => __('logo_maker_symbol_diamond'),
    'square' => __('logo_maker_symbol_square'),
    'spark' => __('logo_maker_symbol_spark'),
    'none' => __('logo_maker_symbol_none'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['logo_maker_action'] ?? '');
    try {
        if ($action === 'local_svg') {
            $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
            if (!is_array($payload)) {
                throw new InvalidArgumentException(__('logo_maker_request_invalid'));
            }
            $svg = logoMakerLocalSvg($payload);
            $token = logoMakerRememberCandidates([['svg' => $svg]]);
            success(['token' => $token, 'svg' => $svg], __('logo_maker_local_done'));
        }

        if ($action === 'apply') {
            $token = trim((string) ($_POST['candidate_token'] ?? ''));
            if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
                throw new InvalidArgumentException(__('logo_maker_request_invalid'));
            }
            $path = logoMakerApplyCandidate($token, 0);
            success(['path' => $path], str_replace(':path', $path, __('logo_maker_applied')));
        }

        if ($action === 'build_ico') {
            $ico = logoMakerBuildIco((string) ($_POST['png_data'] ?? ''));
            success(['file' => base64_encode($ico), 'name' => 'favicon.ico'], __('logo_maker_ico_ready'));
        }

        if ($action === 'apply_ico') {
            $path = logoMakerApplyIco((string) ($_POST['png_data'] ?? ''));
            success(['path' => $path], str_replace(':path', $path, __('logo_maker_ico_applied')));
        }

        throw new InvalidArgumentException(__('logo_maker_request_invalid'));
    } catch (Throwable $exception) {
        error($exception->getMessage());
    }
}

$siteName = trim((string) configRawLang('site_name', 'YikaiCMS'));
$currentMenu = 'appearance';
$pageTitle = __('logo_maker_title');
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-6" id="logoMakerPage">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-gray-900"><?= e(__('logo_maker_title')) ?></h1>
            <p class="mt-1 text-sm text-gray-500"><?= e(__('logo_maker_desc')) ?></p>
        </div>
        <a href="https://logo.yikaicms.com/" target="_blank" rel="noopener" class="inline-flex items-center gap-2 rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
            <span aria-hidden="true">↗</span><?= e(__('logo_maker_open_lab')) ?>
        </a>
    </div>

    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="font-medium text-gray-900"><?= e(__('logo_maker_local_title')) ?></h2>
                <p class="mt-1 max-w-3xl text-sm text-gray-500"><?= e(__('logo_maker_local_desc')) ?></p>
            </div>
            <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs text-emerald-700"><?= e(__('logo_maker_local_status')) ?></span>
        </div>

        <form id="logoMakerLocalForm" class="mt-5 grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="logoMakerMark" class="mb-1 block text-sm font-medium text-gray-700"><?= e(__('logo_maker_mark')) ?></label>
                <input id="logoMakerMark" name="mark" maxlength="24" value="<?= e($siteName) ?>" placeholder="<?= e(__('logo_maker_mark_placeholder')) ?>" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="logoMakerTagline" class="mb-1 block text-sm font-medium text-gray-700"><?= e(__('logo_maker_tagline')) ?></label>
                <input id="logoMakerTagline" name="tagline" maxlength="48" placeholder="<?= e(__('logo_maker_tagline_placeholder')) ?>" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="logoMakerLayout" class="mb-1 block text-sm font-medium text-gray-700"><?= e(__('logo_maker_layout')) ?></label>
                <select id="logoMakerLayout" name="layout" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                    <?php foreach ($localLayouts as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="logoMakerSymbol" class="mb-1 block text-sm font-medium text-gray-700"><?= e(__('logo_maker_symbol')) ?></label>
                <select id="logoMakerSymbol" name="symbol" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                    <?php foreach ($localSymbols as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="logoMakerPrimary" class="mb-1 block text-sm font-medium text-gray-700"><?= e(__('logo_maker_primary_color')) ?></label>
                <input id="logoMakerPrimary" name="primary" type="color" value="#2563EB" class="h-10 w-full rounded border border-gray-300 bg-white px-1">
            </div>
            <div>
                <label for="logoMakerSecondary" class="mb-1 block text-sm font-medium text-gray-700"><?= e(__('logo_maker_secondary_color')) ?></label>
                <input id="logoMakerSecondary" name="secondary" type="color" value="#0F172A" class="h-10 w-full rounded border border-gray-300 bg-white px-1">
            </div>
            <div>
                <label for="logoMakerBackground" class="mb-1 block text-sm font-medium text-gray-700"><?= e(__('logo_maker_background')) ?></label>
                <select id="logoMakerBackground" name="background" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                    <option value="transparent"><?= e(__('logo_maker_background_transparent')) ?></option>
                    <option value="#FFFFFF"><?= e(__('logo_maker_background_white')) ?></option>
                    <option value="#F3F4F6"><?= e(__('logo_maker_background_light')) ?></option>
                    <option value="#111827"><?= e(__('logo_maker_background_dark')) ?></option>
                </select>
            </div>
            <div class="flex items-end">
                <button id="logoMakerGenerate" type="submit" class="inline-flex items-center gap-2 rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                    <span aria-hidden="true">✦</span><?= e(__('logo_maker_generate')) ?>
                </button>
            </div>
        </form>

        <div id="logoMakerResult" class="mt-5 hidden rounded border border-gray-200 bg-gray-50 p-4">
            <div class="flex min-h-56 items-center justify-center rounded bg-white p-6">
                <img id="logoMakerPreview" alt="<?= e(__('logo_maker_preview')) ?>" class="max-h-64 max-w-full object-contain">
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <a id="logoMakerDownloadSvg" download="logo.svg" class="rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"><?= e(__('logo_maker_download')) ?></a>
                <button id="logoMakerDownloadPng" type="button" class="rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"><?= e(__('logo_maker_download_png')) ?></button>
                <button id="logoMakerDownloadIco" type="button" class="rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"><?= e(__('logo_maker_download_ico')) ?></button>
                <button id="logoMakerApply" type="button" class="rounded bg-blue-600 px-3 py-2 text-sm font-medium text-white hover:bg-blue-700"><?= e(__('logo_maker_apply')) ?></button>
                <button id="logoMakerApplyIco" type="button" class="rounded bg-gray-800 px-3 py-2 text-sm font-medium text-white hover:bg-gray-700"><?= e(__('logo_maker_apply_ico')) ?></button>
            </div>
        </div>
    </section>

    <section class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <h2 class="font-medium text-gray-900"><?= e(__('logo_maker_advanced_title')) ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?= e(__('logo_maker_advanced_desc')) ?></p>
        <a href="https://logo.yikaicms.com/" target="_blank" rel="noopener" class="mt-4 inline-flex items-center gap-2 rounded border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
            <span aria-hidden="true">↗</span><?= e(__('logo_maker_advanced_open')) ?>
        </a>
    </section>

    <p id="logoMakerMessage" class="min-h-5 text-sm text-gray-500"></p>
</div>

<script>
(() => {
    const page = document.getElementById('logoMakerPage');
    if (!page) return;
    const copy = <?= json_encode([
        'generating' => __('logo_maker_generating'),
        'localDone' => __('logo_maker_local_done'),
        'downloadPng' => __('logo_maker_download_png'),
        'downloadIco' => __('logo_maker_download_ico'),
        'applyIco' => __('logo_maker_apply_ico'),
        'applyIcoConfirm' => __('logo_maker_apply_ico_confirm'),
        'confirm' => __('logo_maker_apply_confirm'),
        'applied' => __('logo_maker_applied'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const form = document.getElementById('logoMakerLocalForm');
    const button = document.getElementById('logoMakerGenerate');
    const result = document.getElementById('logoMakerResult');
    const preview = document.getElementById('logoMakerPreview');
    const downloadSvg = document.getElementById('logoMakerDownloadSvg');
    const downloadPng = document.getElementById('logoMakerDownloadPng');
    const downloadIco = document.getElementById('logoMakerDownloadIco');
    const apply = document.getElementById('logoMakerApply');
    const applyIco = document.getElementById('logoMakerApplyIco');
    const message = document.getElementById('logoMakerMessage');
    let state = null;

    const setMessage = (value, isError = false) => {
        message.textContent = value || '';
        message.className = 'min-h-5 text-sm ' + (isError ? 'text-red-600' : 'text-gray-500');
    };
    const request = async (formData) => {
        const response = await fetch(location.href, { method: 'POST', body: formData, credentials: 'same-origin' });
        let payload;
        try { payload = await response.json(); } catch (error) { throw new Error('HTTP ' + response.status); }
        if (!response.ok || payload.code !== 0) throw new Error(payload.msg || 'Request failed');
        return payload;
    };
    const svgDataUrl = (svg) => 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    const svgToPng = (svg, size = 512) => new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => {
            const canvas = document.createElement('canvas');
            canvas.width = size; canvas.height = size;
            const context = canvas.getContext('2d');
            if (!context) { reject(new Error('Canvas unavailable')); return; }
            context.clearRect(0, 0, size, size);
            const scale = Math.min(size / image.width, size / image.height);
            const width = image.width * scale;
            const height = image.height * scale;
            context.drawImage(image, (size - width) / 2, (size - height) / 2, width, height);
            resolve(canvas.toDataURL('image/png'));
        };
        image.onerror = () => reject(new Error('SVG preview failed'));
        image.src = svgDataUrl(svg);
    });
    const base64Blob = (value, type) => {
        const binary = atob(value);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        return new Blob([bytes], { type });
    };
    const buildIco = async (svg, shouldApply, trigger) => {
        trigger.disabled = true;
        try {
            const data = new FormData();
            data.set('logo_maker_action', shouldApply ? 'apply_ico' : 'build_ico');
            data.set('png_data', await svgToPng(svg));
            const payload = await request(data);
            if (!shouldApply) {
                const link = document.createElement('a');
                link.href = URL.createObjectURL(base64Blob(payload.data.file, 'image/x-icon'));
                link.download = payload.data.name || 'favicon.ico';
                link.click();
                setTimeout(() => URL.revokeObjectURL(link.href), 1000);
            }
            setMessage(payload.msg || (shouldApply ? copy.applyIco : copy.downloadIco));
        } catch (error) {
            setMessage(error.message, true);
        } finally {
            trigger.disabled = false;
        }
    };
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        button.disabled = true;
        setMessage(copy.generating);
        const data = new FormData();
        data.set('logo_maker_action', 'local_svg');
        data.set('payload', JSON.stringify(Object.fromEntries(new FormData(form).entries())));
        try {
            const payload = await request(data);
            state = { svg: payload.data.svg || '', token: payload.data.token || '' };
            preview.src = svgDataUrl(state.svg);
            downloadSvg.href = svgDataUrl(state.svg);
            result.classList.remove('hidden');
            setMessage(payload.msg || copy.localDone);
        } catch (error) {
            setMessage(error.message, true);
        } finally {
            button.disabled = false;
        }
    });
    downloadPng.addEventListener('click', async () => {
        if (!state) return;
        downloadPng.disabled = true;
        try {
            const link = document.createElement('a');
            link.href = await svgToPng(state.svg);
            link.download = 'logo.png';
            link.click();
        } catch (error) {
            setMessage(error.message, true);
        } finally {
            downloadPng.disabled = false;
        }
    });
    downloadIco.addEventListener('click', () => { if (state) buildIco(state.svg, false, downloadIco); });
    apply.addEventListener('click', async () => {
        if (!state || !window.confirm(copy.confirm)) return;
        apply.disabled = true;
        try {
            const data = new FormData();
            data.set('logo_maker_action', 'apply');
            data.set('candidate_token', state.token);
            data.set('candidate_index', '0');
            const payload = await request(data);
            setMessage(payload.msg || copy.applied);
        } catch (error) {
            setMessage(error.message, true);
        } finally {
            apply.disabled = false;
        }
    });
    applyIco.addEventListener('click', () => {
        if (state && window.confirm(copy.applyIcoConfirm)) buildIco(state.svg, true, applyIco);
    });
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
