<?php
/**
 * Yikai CMS - 批量添加常用栏目
 *
 * 从「常用栏目目录」勾选客户想要的栏目，一键批量生成（幂等：已存在的 slug 自动跳过）。
 * 复用 RecipeService::applyRecipe() 的建栏目逻辑，不重复造轮子。
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/RecipeService.php';

checkLogin();
requirePermission('*');

$catalog = require ROOT_PATH . '/includes/channel_catalog.php';

// 按语言挑名称
$pickName = function (array $it, string $lang): string {
    if ($lang === 'en' && !empty($it['name_en'])) return (string) $it['name_en'];
    if ($lang === 'ja' && !empty($it['name_ja'])) return (string) $it['name_ja'];
    return (string) ($it['name'] ?? $it['slug']);
};

// ── 生成动作 ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (post('action') === 'generate') {
        $keys = $_POST['keys'] ?? [];
        if (!is_array($keys)) $keys = [];
        $siteLang = siteLang();

        $channels = [];
        $order = 10;
        foreach ($catalog['items'] as $key => $it) {           // 按目录顺序，只取勾选项
            if (!in_array($key, $keys, true)) continue;
            $channels[] = [
                'slug'       => (string) $it['slug'],
                'name'       => $pickName($it, $siteLang),
                'type'       => (string) $it['type'],
                'is_nav'     => 1,
                'seo_title'  => (string) ($it['seo_title'] ?? ''),
                'content'    => (string) ($it['content'] ?? ''),
                'sort_order' => $order,
            ];
            $order += 10;
        }

        if (empty($channels)) {
            error(__('chbatch_err_none'));
        }

        try {
            $report = (new RecipeService())->applyRecipe([
                'slug'     => 'channel-batch',
                'name'     => '批量常用栏目',
                'lang'     => $siteLang,
                'channels' => $channels,
            ]);
            adminLog('channel', 'batch_add', 'batch add channels: created ' . $report['channels_created'] . ', skipped ' . $report['channels_skipped']);
            $msg = __('chbatch_done', ['n' => $report['channels_created']])
                 . ($report['channels_skipped'] ? __('chbatch_done_skipped', ['n' => $report['channels_skipped']]) : '');
            success($report, $msg);
        } catch (\Throwable $e) {
            error(__('chbatch_err_fail') . ': ' . $e->getMessage());
        }
    }
    error('unknown action');
}

// ── 渲染 ─────────────────────────────────────────────────
// 已存在的 slug（当前站点语言）→ 目录里对应项标记为「已存在」
$existing = [];
foreach (db()->fetchAll("SELECT slug FROM " . DB_PREFIX . "channels WHERE lang = ?", [siteLang()]) as $r) {
    $existing[(string) $r['slug']] = true;
}

$uiLang = getLang();

// group 中文名 → i18n key
$groupLabelKey = ['通用' => 'chbatch_group_general', '业务' => 'chbatch_group_business', '内容' => 'chbatch_group_content'];

// 按 group 分组目录项
$grouped = [];
foreach ($catalog['items'] as $key => $it) {
    $grouped[(string) ($it['group'] ?? '其他')][$key] = $it;
}

$pageTitle = __('chbatch_title');
$currentMenu = 'channel';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-6">
    <!-- 说明 -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800">
        <div class="font-bold mb-1">💡 <?php echo e(__('chbatch_title')); ?></div>
        <p><?php echo e(__('chbatch_intro_body')); ?></p>
    </div>

    <!-- 一键预选套餐 -->
    <div class="bg-white rounded-lg shadow p-4">
        <div class="flex items-center flex-wrap gap-2">
            <span class="text-sm text-gray-500 mr-1"><?php echo e(__('chbatch_quick_preset')); ?></span>
            <?php foreach ($catalog['presets'] as $pk => $p):
                $plabel = $uiLang === 'en' ? ($p['label_en'] ?? $p['label']) : ($uiLang === 'ja' ? ($p['label_ja'] ?? $p['label']) : $p['label']);
            ?>
            <button type="button" class="js-preset px-3 py-1.5 text-sm rounded-full border border-gray-300 hover:border-primary hover:text-primary transition"
                    data-items='<?php echo e(json_encode($p['items'], JSON_UNESCAPED_UNICODE)); ?>'><?php echo e($plabel); ?></button>
            <?php endforeach; ?>
            <button type="button" id="selAll" class="ml-auto text-sm text-gray-500 hover:text-primary"><?php echo e(__('chbatch_select_all')); ?></button>
            <button type="button" id="selNone" class="text-sm text-gray-500 hover:text-primary"><?php echo e(__('chbatch_select_none')); ?></button>
        </div>
    </div>

    <!-- 栏目勾选 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo e(__('chbatch_pick_title')); ?></h2>
            <span class="text-sm text-gray-400"><span id="selCount">0</span> <?php echo e(__('chbatch_items_selected')); ?></span>
        </div>
        <div class="p-6 space-y-6">
            <?php foreach ($grouped as $group => $items):
                $glk = $groupLabelKey[$group] ?? '';
            ?>
            <div>
                <h3 class="text-sm font-semibold text-gray-500 mb-3"><?php echo e($glk ? __($glk) : $group); ?></h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php foreach ($items as $key => $it):
                        $exists = isset($existing[$it['slug']]);
                        $label = $pickName($it, $uiLang);
                    ?>
                    <label class="flex items-start gap-3 border rounded-lg p-3 <?php echo $exists ? 'bg-gray-50 opacity-70' : 'hover:border-primary cursor-pointer'; ?>">
                        <input type="checkbox" class="js-ch mt-0.5 w-4 h-4 rounded" value="<?php echo e($key); ?>" <?php echo $exists ? 'disabled' : ''; ?>>
                        <div class="min-w-0">
                            <div class="font-medium text-gray-800 flex items-center gap-2">
                                <?php echo e($label); ?>
                                <?php if ($exists): ?><span class="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded"><?php echo e(__('chbatch_exists')); ?></span><?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-400 mt-0.5">/<?php echo e($it['slug']); ?> · <?php echo e($it['type']); ?></div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="px-6 py-4 border-t flex items-center justify-between">
            <a href="/admin/channel.php" class="text-sm text-gray-500 hover:text-primary">← <?php echo e(__('chbatch_back')); ?></a>
            <button type="button" id="btnGenerate" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded font-medium disabled:opacity-50" disabled>
                <?php echo e(__('chbatch_generate')); ?>
            </button>
        </div>
    </div>
</div>

<script>
(function () {
    var boxes = Array.prototype.slice.call(document.querySelectorAll('.js-ch'));
    var countEl = document.getElementById('selCount');
    var btn = document.getElementById('btnGenerate');
    var T = {
        gen:  <?php echo json_encode(__('chbatch_generate'), JSON_HEX_TAG); ?>,
        ing:  <?php echo json_encode(__('chbatch_generating'), JSON_HEX_TAG); ?>,
        fail: <?php echo json_encode(__('chbatch_err_fail'), JSON_HEX_TAG); ?>,
        net:  <?php echo json_encode(__('error_network'), JSON_HEX_TAG); ?>,
    };

    function refresh() {
        var n = boxes.filter(function (b) { return b.checked && !b.disabled; }).length;
        countEl.textContent = n;
        btn.disabled = n === 0;
    }
    boxes.forEach(function (b) { b.addEventListener('change', refresh); });

    document.querySelectorAll('.js-preset').forEach(function (p) {
        p.addEventListener('click', function () {
            var items = [];
            try { items = JSON.parse(this.dataset.items || '[]'); } catch (e) {}
            boxes.forEach(function (b) { if (!b.disabled) b.checked = items.indexOf(b.value) !== -1; });
            refresh();
        });
    });
    document.getElementById('selAll').addEventListener('click', function () {
        boxes.forEach(function (b) { if (!b.disabled) b.checked = true; }); refresh();
    });
    document.getElementById('selNone').addEventListener('click', function () {
        boxes.forEach(function (b) { if (!b.disabled) b.checked = false; }); refresh();
    });

    btn.addEventListener('click', async function () {
        var picked = boxes.filter(function (b) { return b.checked && !b.disabled; }).map(function (b) { return b.value; });
        if (!picked.length) return;
        var fd = new FormData();
        fd.set('_token', '<?php echo csrfToken(); ?>');
        fd.set('action', 'generate');
        picked.forEach(function (k) { fd.append('keys[]', k); });
        btn.disabled = true;
        btn.textContent = T.ing;
        try {
            var resp = await fetch('', { method: 'POST', body: fd });
            var data = await safeJson(resp);
            if (data.code === 0) {
                showMessage(data.msg || T.gen);
                setTimeout(function () { location.href = '/admin/channel.php'; }, 1200);
            } else {
                showMessage(data.msg || T.fail, 'error');
                btn.disabled = false; btn.textContent = T.gen;
            }
        } catch (e) {
            showMessage(T.net + ': ' + e.message, 'error');
            btn.disabled = false; btn.textContent = T.gen;
        }
    });
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
