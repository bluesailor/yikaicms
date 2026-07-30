<?php
/**
 * Yikai CMS - 静态 HTML 生成
 * 功能：开关 / 全量生成（分批）/ 清空 / 状态查看
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$queueFile = ROOT_PATH . '/storage/cache/static_gen_queue.json';

// 自爬基址：优先后台配置，否则用当前请求的 scheme+host
function staticBaseUrl(): string
{
    $cfg = trim((string) config('static_html_base_url', ''));
    if ($cfg !== '') return rtrim($cfg, '/');
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $https ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    return $scheme . '://' . $host;
}

// ============ POST：JSON 动作 ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 本页自身的记账写入（last_gen / 设置）不应触发"内容变更清空静态"的失效钩子，否则自毁
    StaticHtml::$mute = true;
    $action = post('action');

    if ($action === 'save') {
        settingModel()->saveBatch([
            'static_html_enabled'  => post('static_html_enabled') ? '1' : '0',
            'static_html_base_url' => trim((string) post('static_html_base_url', '')),
        ]);
        adminLog('static_html', 'setting', '保存静态HTML设置');
        success(['msg' => __('admin_success')]);
    }

    if ($action === 'clear') {
        $n = StaticHtml::clearAll();
        @unlink($queueFile);
        settingModel()->saveBatch(['static_html_last_gen' => '0']);
        adminLog('static_html', 'clear', "清空静态文件 {$n} 个");
        success(['cleared' => $n, 'msg' => sprintf(__('sh_cleared'), $n)]);
    }

    // 开始：枚举 URL 写入队列，返回总数
    if ($action === 'start') {
        $items = StaticHtml::enumerate();
        $dir = dirname($queueFile);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($queueFile, json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        success(['total' => count($items)]);
    }

    // 分批生成
    if ($action === 'batch') {
        $offset = max(0, (int) post('offset'));
        $size   = min(50, max(1, (int) post('size', 20)));

        $items = [];
        if (is_file($queueFile)) {
            $items = json_decode((string) file_get_contents($queueFile), true) ?: [];
        }
        if (!$items) {
            error(__('sh_queue_lost'));
        }

        $total = count($items);
        $slice = array_slice($items, $offset, $size);
        $res   = StaticHtml::generateBatch($slice, staticBaseUrl());

        $next = $offset + $size;
        $done = $next >= $total;
        if ($done) {
            settingModel()->saveBatch(['static_html_last_gen' => (string) time()]);
            @unlink($queueFile);
            adminLog('static_html', 'generate', "生成静态文件，共 {$total} 个URL");
        }

        success([
            'ok'     => $res['ok'],
            'skip'   => $res['skip'],
            'extra'  => $res['extra'],
            'fail'   => $res['fail'],
            'failed' => $res['failed'],
            'next'   => $next,
            'total'  => $total,
            'done'   => $done,
        ]);
    }

    error(__('admin_fail'));
}

// ============ GET：渲染页面 ============
$enabled  = (string) config('static_html_enabled', '0') === '1';
$baseUrl  = trim((string) config('static_html_base_url', ''));
$stats    = StaticHtml::stats();
$hasCurl  = function_exists('curl_init');

$pageTitle   = __('sh_title');
$currentMenu = 'static_html';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-xl font-bold text-gray-800"><?php echo __('sh_title'); ?></h1>
    <p class="text-sm text-gray-500 mt-1"><?php echo __('sh_intro'); ?></p>
</div>

<?php if (!$hasCurl): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6 text-sm text-red-700">
    <?php echo __('sh_no_curl'); ?>
</div>
<?php endif; ?>

<?php
// 「管理员绕过静态直出」是否真的生效——自检一次而不是只写在文档里。
// 静态文件由 Web 服务器在 PHP 之前直出，那一层看不到会话；本系统靠登录时种下的
// yk_admin cookie 让服务器跳过静态。Apache 的 .htaccess 随升级自动更新，
// **自建 Nginx 的站点必须手工更新 server 配置**，否则管理员在前台看不到管理条、
// 改了内容也不生效，而且毫无提示——这里替他们检出来。
$__shSelfCheck = null;
if ($stats['files'] > 0 && function_exists('curl_init')) {
    $__probe = rtrim(siteBaseUrl(), '/') . '/';
    $__ch = curl_init($__probe);
    curl_setopt_array($__ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_NOBODY => true,
        CURLOPT_HEADER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_COOKIE => 'yk_admin=1',
        CURLOPT_HTTPHEADER => ['X-Static-Gen: 1'],
    ]);
    $__head = (string) curl_exec($__ch);
    $__code = (int) curl_getinfo($__ch, CURLINFO_HTTP_CODE);
    curl_close($__ch);
    // 判据是响应头 X-Yikai-Render：只有 PHP 实时渲染才会带它，
    // 静态文件由 Web 服务器直出、不经 PHP，一定没有。
    // 不能拿页面内容判断——探测请求只带 yk_admin、不带会话，
    // PHP 渲染出来的也是匿名视角，与静态文件几乎一模一样。
    if ($__code === 200) {
        $__shSelfCheck = stripos($__head, 'X-Yikai-Render:') !== false;
    }
}
?>
<?php if ($__shSelfCheck === false): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 text-sm text-amber-800">
    <p class="font-medium"><?php echo __('sh_bypass_warn_title'); ?></p>
    <p class="mt-1"><?php echo __('sh_bypass_warn_body'); ?></p>
</div>
<?php endif; ?>

<!-- 状态卡片 -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-gray-400 text-sm"><?php echo __('sh_stat_files'); ?></div>
        <div class="text-2xl font-bold text-gray-800 mt-1" id="statFiles"><?php echo number_format($stats['files']); ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-gray-400 text-sm"><?php echo __('sh_stat_size'); ?></div>
        <div class="text-2xl font-bold text-gray-800 mt-1"><?php echo $stats['size'] > 0 ? number_format($stats['size'] / 1024, 1) . ' KB' : '0'; ?></div>
    </div>
    <div class="bg-white rounded-lg shadow p-5">
        <div class="text-gray-400 text-sm"><?php echo __('sh_stat_last'); ?></div>
        <div class="text-base font-medium text-gray-800 mt-2"><?php echo $stats['last_gen'] ? date('Y-m-d H:i', $stats['last_gen']) : '—'; ?></div>
    </div>
</div>

<!-- 设置 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b"><h2 class="font-bold text-gray-800"><?php echo __('sh_settings'); ?></h2></div>
    <div class="p-6 space-y-4">
        <div>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" id="enabledChk" <?php echo $enabled ? 'checked' : ''; ?>
                       class="rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700 font-medium"><?php echo __('sh_enable'); ?></span>
            </label>
            <p class="text-xs text-gray-400 mt-1"><?php echo __('sh_enable_tip'); ?></p>
        </div>
        <div>
            <label class="block text-sm text-gray-700 mb-1"><?php echo __('sh_base_url'); ?></label>
            <input type="text" id="baseUrlInput" value="<?php echo e($baseUrl); ?>"
                   class="w-full md:w-2/3 border rounded px-4 py-2 text-sm" placeholder="<?php echo e(staticBaseUrl()); ?>">
            <p class="text-xs text-gray-400 mt-1"><?php echo __('sh_base_url_tip'); ?></p>
        </div>
        <button type="button" id="saveBtn" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded text-sm transition">
            <?php echo __('admin_save'); ?>
        </button>
    </div>
</div>

<!-- 生成操作 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b"><h2 class="font-bold text-gray-800"><?php echo __('sh_actions'); ?></h2></div>
    <div class="p-6 space-y-4">
        <div class="flex flex-wrap gap-3">
            <button type="button" id="genBtn" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded text-sm transition inline-flex items-center gap-2">
                <i class="ti ti-bolt text-base"></i>
                <?php echo __('sh_generate'); ?>
            </button>
            <button type="button" id="clearBtn" class="border border-gray-300 text-gray-700 hover:border-gray-400 hover:text-red-600 px-6 py-2 rounded text-sm transition">
                <?php echo __('sh_clear'); ?>
            </button>
        </div>

        <!-- 进度 -->
        <div id="progressWrap" class="hidden">
            <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
                <div id="progressBar" class="bg-green-500 h-3 transition-all" style="width:0%"></div>
            </div>
            <p class="text-sm text-gray-600 mt-2" id="progressText">0 / 0</p>
            <p class="text-xs text-red-500 mt-1 hidden" id="failText"></p>
        </div>
    </div>
</div>

<!-- 服务器配置提醒 -->
<div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
    <p class="font-medium mb-1"><?php echo __('sh_server_note_title'); ?></p>
    <p><?php echo __('sh_server_note'); ?></p>
</div>

<script>
const SH = {
    post: async function(data) {
        const fd = new FormData();
        for (const k in data) fd.append(k, data[k]);
        const r = await fetch('', { method: 'POST', body: fd });
        return await safeJson(r);
    }
};

document.getElementById('saveBtn').addEventListener('click', async function() {
    const d = await SH.post({
        action: 'save',
        static_html_enabled: document.getElementById('enabledChk').checked ? 1 : '',
        static_html_base_url: document.getElementById('baseUrlInput').value
    });
    showMessage(d.code === 0 ? d.msg : d.msg, d.code === 0 ? 'success' : 'error');
});

document.getElementById('clearBtn').addEventListener('click', async function() {
    if (!confirm('<?php echo __('sh_clear_confirm'); ?>')) return;
    const d = await SH.post({ action: 'clear' });
    showMessage(d.msg, d.code === 0 ? 'success' : 'error');
    if (d.code === 0) setTimeout(() => location.reload(), 800);
});

document.getElementById('genBtn').addEventListener('click', async function() {
    const genBtn = this, clearBtn = document.getElementById('clearBtn');
    const wrap = document.getElementById('progressWrap'),
          bar = document.getElementById('progressBar'),
          txt = document.getElementById('progressText'),
          failText = document.getElementById('failText');

    genBtn.disabled = true; clearBtn.disabled = true;
    genBtn.classList.add('opacity-60');
    wrap.classList.remove('hidden');
    failText.classList.add('hidden'); failText.textContent = '';

    // 1) 枚举
    const start = await SH.post({ action: 'start' });
    if (start.code !== 0) { showMessage(start.msg, 'error'); genBtn.disabled = false; clearBtn.disabled = false; return; }
    const total = start.data.total;
    if (!total) { showMessage('<?php echo __('sh_nothing'); ?>', 'error'); genBtn.disabled = false; clearBtn.disabled = false; return; }

    // 2) 分批
    let offset = 0, okSum = 0, skipSum = 0, extraSum = 0, failSum = 0;
    const size = 20, failedList = [];
    while (true) {
        const b = await SH.post({ action: 'batch', offset: offset, size: size });
        if (b.code !== 0) { showMessage(b.msg, 'error'); break; }
        const d = b.data;
        okSum += d.ok; skipSum += (d.skip || 0); extraSum += (d.extra || 0); failSum += d.fail;
        if (d.failed && d.failed.length) failedList.push(...d.failed);
        const done = Math.min(d.next, d.total);
        bar.style.width = (done / d.total * 100).toFixed(1) + '%';
        let info = '成功 ' + (okSum + extraSum);
        if (extraSum) info += '（含分页 ' + extraSum + '）';
        if (skipSum) info += '，跳过 ' + skipSum;
        if (failSum) info += '，失败 ' + failSum;
        txt.textContent = done + ' / ' + d.total + '（' + info + '）';
        if (d.done) break;
        offset = d.next;
    }

    if (failedList.length) {
        failText.classList.remove('hidden');
        failText.textContent = '<?php echo __('sh_failed_prefix'); ?>' + failedList.slice(0, 10).join(', ') + (failedList.length > 10 ? ' …' : '');
    }
    document.getElementById('statFiles').textContent = okSum + extraSum;
    showMessage('<?php echo __('sh_done'); ?>', 'success');
    genBtn.disabled = false; clearBtn.disabled = false; genBtn.classList.remove('opacity-60');
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
