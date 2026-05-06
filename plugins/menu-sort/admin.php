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

// 默认菜单结构
$defaultGroups = [
    'content' => [
        'label' => '栏目与内容',
        'items' => [
            'channel'  => '栏目管理',
            'page'     => '单页管理',
        ]
    ],
    'product' => [
        'label' => '商品与展示',
        'items' => [
            'product'  => '产品管理',
            'case'     => '案例管理',
        ]
    ],
    'article' => [
        'label' => '文章与资讯',
        'items' => [
            'article'  => '文章管理',
            'download' => '下载管理',
            'job'      => '招聘管理',
        ]
    ],
    'media' => [
        'label' => '媒体与组件',
        'items' => [
            'media'    => '媒体管理',
            'album'    => '图库相册',
            'banner'   => '横幅管理',
            'timeline' => '发展历程',
            'link'     => '友情链接',
        ]
    ],
    'data' => [
        'label' => '互动数据',
        'items' => [
            'form'    => '询盘管理',
            'member'  => '会员管理',
        ]
    ],
    'system' => [
        'label' => '系统设置',
        'items' => [
            'setting'          => '站点设置',
            'setting_home'     => '首页设置',
            'setting_contact'  => '联系设置',
            'setting_email'    => '邮件设置',
            'setting_seo'      => 'SEO 设置',
            'setting_ai'       => 'AI 设置',
            'setting_security' => '安全设置',
            'setting_translate' => '多语言翻译',
            'theme'            => '主题管理',
            'user'             => '管理员',
            'plugin'           => '插件管理',
            'upgrade'          => '系统升级',
            'system'           => '系统信息',
        ]
    ],
];

// 读取已保存的排序
$savedOrder = json_decode(config('admin_menu_order', ''), true) ?: null;
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

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex items-center justify-between">
        <p class="text-sm text-gray-500">拖拽排序，点击 👁 切换显示/隐藏，保存后立即生效</p>
        <div class="flex gap-2">
            <button onclick="resetOrder()" class="border border-gray-300 hover:bg-gray-100 text-gray-700 px-4 py-2 rounded text-sm">恢复默认</button>
            <button onclick="saveOrder()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                保存排序
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
            <span class="font-bold text-gray-800 flex-1"><?php echo e($group['label']); ?></span>
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
                <span class="text-sm text-gray-700 flex-1"><?php echo e($itemLabel); ?></span>
                <span class="text-xs text-gray-400">/admin/<?php echo $itemKey; ?>.php</span>
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
    ghostClass: 'opacity-50'
});

// 组内菜单项排序
document.querySelectorAll('.item-list').forEach(function(el) {
    new Sortable(el, { animation: 200, ghostClass: 'opacity-50', group: 'items' });
});

function toggleGroup(btn) {
    var groupEl = btn.closest('.group-item');
    var isHidden = groupEl.dataset.hidden === '1';
    groupEl.dataset.hidden = isHidden ? '0' : '1';
    groupEl.classList.toggle('is-hidden');
    btn.querySelector('svg').innerHTML = isHidden ? eyeOpen : eyeClosed;
}

function toggleItem(btn) {
    var itemEl = btn.closest('.menu-item');
    var isHidden = itemEl.dataset.hidden === '1';
    itemEl.dataset.hidden = isHidden ? '0' : '1';
    itemEl.classList.toggle('is-hidden');
    btn.querySelector('svg').innerHTML = isHidden ? eyeOpen : eyeClosed;
}

function collectOrder() {
    var groups = [], items = {}, hidden = [], hiddenItems = [];
    document.querySelectorAll('.group-item').forEach(function(g) {
        var gKey = g.dataset.group;
        groups.push(gKey);
        if (g.dataset.hidden === '1') hidden.push(gKey);
        items[gKey] = [];
        g.querySelectorAll('.menu-item').forEach(function(m) {
            var key = m.dataset.key;
            items[gKey].push(key);
            if (m.dataset.hidden === '1') hiddenItems.push(key);
        });
    });
    return { groups: groups, items: items, hidden: hidden, hiddenItems: hiddenItems };
}

async function saveOrder() {
    var order = collectOrder();
    var formData = new FormData();
    formData.append('ms_action', 'save');
    formData.append('order_json', JSON.stringify(order));
    try {
        var res = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        var data = await res.json();
        data.code === 0 ? showMessage('保存成功，刷新页面生效') : showMessage(data.msg, 'error');
    } catch(e) { showMessage('请求失败', 'error'); }
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
