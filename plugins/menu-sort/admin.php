<?php
/**
 * 后台菜单排序插件 - 管理页面（排序 + 显示/隐藏）
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 保存排序（CSRF 已由 plugin_page.php -> checkLogin() 自动校验）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ms_action'] ?? '') === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $orderJson = $_POST['order_json'] ?? '';
    $order = json_decode($orderJson, true);
    if (!$order) {
        echo json_encode(['code' => 1, 'msg' => '无效的排序数据']);
        exit;
    }
    // labels 按语言分存：本次只提交当前后台语言的改名，需与既有其它语言合并，
    // 否则切到别的语言保存一次就会把这边的自定义名抹掉。
    $curLang = function_exists('getLang') ? getLang() : 'zh-CN';
    $prev    = json_decode((string) config('admin_menu_order', ''), true);
    $prevLabels = is_array($prev) ? (array) ($prev['labels'] ?? []) : [];
    $prevLabels[$curLang] = array_filter((array) ($order['labels'][$curLang] ?? []), static fn($v) => trim((string) $v) !== '');
    $order['labels'] = array_filter($prevLabels, static fn($v) => !empty($v));
    $orderJson = json_encode($order, JSON_UNESCAPED_UNICODE);

    settingModel()->set('admin_menu_order', $orderJson, 'plugin');
    adminLog('plugin', 'update', '更新菜单排序配置');
    echo json_encode(['code' => 0, 'msg' => '保存成功']);
    exit;
}

// 重置排序
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ms_action'] ?? '') === 'reset') {
    header('Content-Type: application/json; charset=utf-8');
    settingModel()->set('admin_menu_order', '', 'plugin');
    adminLog('plugin', 'update', '重置菜单排序');
    echo json_encode(['code' => 0, 'msg' => '已恢复默认排序']);
    exit;
}

// 菜单结构：v1.1.0 起不再硬编码快照，直接读侧栏的权威定义
// （resolveAdminSidebar 已排序/按权限过滤/含插件注册项——与当前后台永远一致）
if (!function_exists('resolveAdminSidebar') && is_file(ROOT_PATH . '/admin/includes/sidebar_menu_api.php')) {
    require_once ROOT_PATH . '/admin/includes/sidebar_menu_api.php';
}

/**
 * 菜单项的排序键：与 main.php 前端 DOM 匹配规则一致。
 * 普通页 = 文件名（/admin/license.php → license）；
 * 插件页 = plugin_page:插件名（/admin/plugin_page.php?plugin=seo → plugin_page:seo），
 * 避免多个插件页共用 plugin_page 键相互覆盖。
 */
if (!function_exists('ms_item_key')):
function ms_item_key(string $url): string
{
    if (!preg_match('#/admin/([^./?]+)\.php#', $url, $m)) {
        return '';
    }
    if ($m[1] === 'plugin_page' && preg_match('#[?&]plugin=([\w\-]+)#', $url, $pm)) {
        return 'plugin_page:' . $pm[1];
    }
    return $m[1];
}
endif;

$defaultGroups = [];
if (function_exists('resolveAdminSidebar')) {
    foreach (resolveAdminSidebar() as $gKey => $g) {
        $items = [];
        foreach (($g['items'] ?? []) as $it) {
            $k = ms_item_key((string) ($it['url'] ?? ''));
            if ($k !== '') {
                $items[$k] = trim(strip_tags((string) ($it['label'] ?? $k)));
            }
        }
        if ($items) {
            $defaultGroups[$gKey] = [
                'label' => trim(strip_tags((string) ($g['label'] ?? $gKey))),
                'items' => $items,
            ];
        }
    }
}
if (!$defaultGroups) {
    // 兜底（老内核无 API 时）：极简快照，保证页面可用
    $defaultGroups = [
        'system' => ['label' => '系统', 'items' => ['setting' => '站点设置', 'plugin' => '插件管理']],
    ];
}

// 读取已保存的排序
$savedOrder = json_decode(config('admin_menu_order', ''), true) ?: null;
// 当前后台语言下的改名（其它语言的互不影响）
$msLang   = function_exists('getLang') ? getLang() : 'zh-CN';
$msLabels = (array) (($savedOrder['labels'] ?? [])[$msLang] ?? []);
$hiddenGroups = $savedOrder['hidden'] ?? [];
$hiddenItems = $savedOrder['hiddenItems'] ?? [];

// 按保存的排序重排结构
$sortedGroups = $defaultGroups;
if ($savedOrder && !empty($savedOrder['groups'])) {
    $reordered = [];
    foreach ($savedOrder['groups'] as $gKey) {
        if (isset($defaultGroups[$gKey])) {
            $reordered[$gKey] = $defaultGroups[$gKey];
            if (!empty($savedOrder['items'][$gKey])) {
                $reItems = [];
                foreach ($savedOrder['items'][$gKey] as $iKey) {
                    if (isset($defaultGroups[$gKey]['items'][$iKey])) {
                        $reItems[$iKey] = $defaultGroups[$gKey]['items'][$iKey];
                    }
                }
                foreach ($defaultGroups[$gKey]['items'] as $iKey => $iLabel) {
                    if (!isset($reItems[$iKey])) {
                        $reItems[$iKey] = $iLabel;
                    }
                }
                $reordered[$gKey]['items'] = $reItems;
            }
        }
    }
    foreach ($defaultGroups as $gKey => $gData) {
        if (!isset($reordered[$gKey])) {
            $reordered[$gKey] = $gData;
        }
    }
    $sortedGroups = $reordered;
}

$pageTitle = '后台菜单排序';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<style>
.group-item.is-hidden { opacity: 0.45; }
.menu-item.is-hidden { opacity: 0.45; }
.toggle-vis { cursor: pointer; padding: 4px; border-radius: 4px; }
.toggle-vis:hover { background: #f3f4f6; }
.toggle-vis svg { width: 16px; height: 16px; }
</style>

<div class="bg-white rounded-lg shadow mb-6 sticky top-0 z-20">
    <div class="p-4 flex items-center justify-between gap-3 flex-wrap">
        <p class="text-sm text-gray-500">拖拽排序、点击名称可改名、👁 切换显示隐藏——<b>改动自动保存</b>，刷新后台页面后生效。改名仅作用于当前后台语言（<?php echo e($msLang); ?>），清空恢复默认。</p>
        <div class="flex items-center gap-3">
            <span id="msStatus" class="text-xs text-gray-400"></span>
            <button onclick="resetOrder()" class="border border-gray-300 hover:bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm">恢复默认</button>
            <button onclick="saveOrder(false)" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                立即保存
            </button>
        </div>
    </div>
</div>

<div id="groupList" class="space-y-4">
    <?php foreach ($sortedGroups as $groupKey => $group):
        $groupHidden = in_array($groupKey, $hiddenGroups);
    ?>
    <div class="bg-white rounded-lg shadow group-item <?php echo $groupHidden ? 'is-hidden' : ''; ?>" data-group="<?php echo $groupKey; ?>" data-hidden="<?php echo $groupHidden ? '1' : '0'; ?>">
        <div class="px-4 py-3 border-b flex items-center gap-3 group-handle">
            <svg class="w-5 h-5 text-gray-400 cursor-move" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
            <input type="text" class="group-label font-bold text-gray-800 flex-1 bg-transparent border border-transparent hover:border-gray-200 focus:border-primary focus:bg-white rounded px-2 py-1 outline-none"
                   value="<?php echo e($msLabels['__group:' . $groupKey] ?? $group['label']); ?>"
                   data-default="<?php echo e($group['label']); ?>"
                   onclick="event.stopPropagation()" placeholder="<?php echo e($group['label']); ?>" title="改名后立即保存；清空则恢复默认">
            <span class="text-xs text-gray-400">(<?php echo $groupKey; ?>)</span>
            <span class="toggle-vis" onclick="toggleGroup(this)" title="显示/隐藏整个分组">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <?php if ($groupHidden): ?>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>
                    <?php else: ?>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    <?php endif; ?>
                </svg>
            </span>
        </div>
        <div class="p-2 item-list" data-group="<?php echo $groupKey; ?>">
            <?php foreach ($group['items'] as $itemKey => $itemLabel):
                $itemHidden = in_array($itemKey, $hiddenItems);
            ?>
            <div class="flex items-center gap-3 px-4 py-2.5 rounded hover:bg-gray-50 cursor-move menu-item <?php echo $itemHidden ? 'is-hidden' : ''; ?>" data-key="<?php echo $itemKey; ?>" data-hidden="<?php echo $itemHidden ? '1' : '0'; ?>">
                <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
                <input type="text" class="item-label text-sm text-gray-700 flex-1 bg-transparent border border-transparent hover:border-gray-200 focus:border-primary focus:bg-white rounded px-2 py-1 outline-none"
                       value="<?php echo e($msLabels[$itemKey] ?? $itemLabel); ?>"
                       data-default="<?php echo e($itemLabel); ?>"
                       placeholder="<?php echo e($itemLabel); ?>" title="改名后立即保存；清空则恢复默认">
                <span class="text-xs text-gray-400"><?php echo e(str_starts_with($itemKey, 'plugin_page:') ? '插件·' . substr($itemKey, 12) : '/admin/' . $itemKey . '.php'); ?></span>
                <span class="toggle-vis" onclick="toggleItem(this)" title="显示/隐藏">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?php if ($itemHidden): ?>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>
                        <?php else: ?>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        <?php endif; ?>
                    </svg>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
var eyeOpen = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>';
var eyeClosed = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"></path>';

// 分组排序
new Sortable(document.getElementById('groupList'), {
    animation: 200,
    handle: '.group-handle',
    ghostClass: 'opacity-50',
    onEnd: autoSave
});

// 组内菜单项排序
document.querySelectorAll('.item-list').forEach(function(el) {
    new Sortable(el, { animation: 200, ghostClass: 'opacity-50', group: 'items', onEnd: autoSave });
});

// 自动保存（防抖 600ms，静默）
var _msTimer = null;
function autoSave() {
    var st = document.getElementById('msStatus');
    if (st) st.textContent = '保存中…';
    clearTimeout(_msTimer);
    _msTimer = setTimeout(function () { saveOrder(true); }, 600);
}

// 改名输入：停止输入 600ms 后自动保存（复用同一防抖）
document.addEventListener('input', function (e) {
    if (e.target.classList && (e.target.classList.contains('group-label') || e.target.classList.contains('item-label'))) {
        autoSave();
    }
});

function toggleGroup(btn) {
    var groupEl = btn.closest('.group-item');
    var isHidden = groupEl.dataset.hidden === '1';
    groupEl.dataset.hidden = isHidden ? '0' : '1';
    groupEl.classList.toggle('is-hidden');
    btn.querySelector('svg').innerHTML = isHidden ? eyeOpen : eyeClosed;
    autoSave();
}

function toggleItem(btn) {
    var itemEl = btn.closest('.menu-item');
    var isHidden = itemEl.dataset.hidden === '1';
    itemEl.dataset.hidden = isHidden ? '0' : '1';
    itemEl.classList.toggle('is-hidden');
    btn.querySelector('svg').innerHTML = isHidden ? eyeOpen : eyeClosed;
    autoSave();
}

var MS_LANG = <?php echo json_encode($msLang); ?>;
function collectOrder() {
    var groups = [], items = {}, hidden = [], hiddenItems = [], labels = {};
    document.querySelectorAll('.group-item').forEach(function(g) {
        var gKey = g.dataset.group;
        groups.push(gKey);
        if (g.dataset.hidden === '1') hidden.push(gKey);
        // 改名：与默认一致或留空则不记录，配置保持精简、跟随核心文案更新
        var gl = g.querySelector('.group-label');
        if (gl) {
            var gv = gl.value.trim();
            if (gv && gv !== gl.dataset.default) labels['__group:' + gKey] = gv;
        }
        items[gKey] = [];
        g.querySelectorAll('.menu-item').forEach(function(m) {
            var key = m.dataset.key;
            items[gKey].push(key);
            if (m.dataset.hidden === '1') hiddenItems.push(key);
            var il = m.querySelector('.item-label');
            if (il) {
                var iv = il.value.trim();
                if (iv && iv !== il.dataset.default) labels[key] = iv;
            }
        });
    });
    var out = { groups: groups, items: items, hidden: hidden, hiddenItems: hiddenItems, labels: {} };
    out.labels[MS_LANG] = labels;
    return out;
}

async function saveOrder(silent) {
    var order = collectOrder();
    var formData = new FormData();
    formData.append('ms_action', 'save');
    formData.append('order_json', JSON.stringify(order));
    var st = document.getElementById('msStatus');
    try {
        var res = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        var data = await res.json();
        if (data.code === 0) {
            if (st) st.textContent = '✓ 已自动保存 ' + new Date().toLocaleTimeString();
            if (!silent) showMessage('已保存，刷新后台页面后侧栏生效');
        } else {
            if (st) st.textContent = '保存失败';
            showMessage(data.msg, 'error');
        }
    } catch(e) {
        if (st) st.textContent = '保存失败';
        showMessage('请求失败', 'error');
    }
}

async function resetOrder() {
    if (!confirm('确定恢复默认菜单排序？')) return;
    var formData = new FormData();
    formData.append('ms_action', 'reset');
    try {
        var res = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        var data = await res.json();
        if (data.code === 0) { showMessage('已恢复默认'); setTimeout(function(){ location.reload(); }, 1000); }
        else showMessage(data.msg, 'error');
    } catch(e) { showMessage('请求失败', 'error'); }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
