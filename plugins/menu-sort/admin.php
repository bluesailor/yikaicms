<?php
/**
 * 后台菜单排序插件 - 管理页面
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
    settingModel()->set('admin_menu_order', $orderJson);
    adminLog('plugin', 'update', '更新菜单排序配置');
    echo json_encode(['code' => 0, 'msg' => '保存成功']);
    exit;
}

// 重置排序
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ms_action'] ?? '') === 'reset') {
    header('Content-Type: application/json; charset=utf-8');
    settingModel()->set('admin_menu_order', '');
    adminLog('plugin', 'update', '重置菜单排序');
    echo json_encode(['code' => 0, 'msg' => '已恢复默认排序']);
    exit;
}

// 默认菜单结构
$defaultGroups = [
    'content' => [
        'label' => '内容管理',
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
            'form'   => '表单管理',
            'member' => '会员管理',
        ]
    ],
    'system' => [
        'label' => '系统设置',
        'items' => [
            'setting'  => '系统设置',
            'user'     => '管理员',
            'plugin'   => '插件管理',
            'upgrade'  => '系统升级',
            'system'   => '系统信息',
            'log'      => '操作日志',
        ]
    ],
];

// 读取已保存的排序
$savedOrder = json_decode(config('admin_menu_order', ''), true) ?: null;

// 按保存的排序重排结构
$sortedGroups = $defaultGroups;
if ($savedOrder && !empty($savedOrder['groups'])) {
    $reordered = [];
    foreach ($savedOrder['groups'] as $gKey) {
        if (isset($defaultGroups[$gKey])) {
            $reordered[$gKey] = $defaultGroups[$gKey];
            // 重排组内项目
            if (!empty($savedOrder['items'][$gKey])) {
                $reItems = [];
                foreach ($savedOrder['items'][$gKey] as $iKey) {
                    if (isset($defaultGroups[$gKey]['items'][$iKey])) {
                        $reItems[$iKey] = $defaultGroups[$gKey]['items'][$iKey];
                    }
                }
                // 补上可能遗漏的项目
                foreach ($defaultGroups[$gKey]['items'] as $iKey => $iLabel) {
                    if (!isset($reItems[$iKey])) {
                        $reItems[$iKey] = $iLabel;
                    }
                }
                $reordered[$gKey]['items'] = $reItems;
            }
        }
    }
    // 补上遗漏的分组
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

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex items-center justify-between">
        <p class="text-sm text-gray-500">拖拽分组或菜单项调整显示顺序，保存后立即生效</p>
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
    <?php foreach ($sortedGroups as $groupKey => $group): ?>
    <div class="bg-white rounded-lg shadow group-item" data-group="<?php echo $groupKey; ?>">
        <div class="px-4 py-3 border-b flex items-center gap-3 cursor-move group-handle">
            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
            </svg>
            <span class="font-bold text-gray-800"><?php echo e($group['label']); ?></span>
            <span class="text-xs text-gray-400">(<?php echo $groupKey; ?>)</span>
        </div>
        <div class="p-2 item-list" data-group="<?php echo $groupKey; ?>">
            <?php foreach ($group['items'] as $itemKey => $itemLabel): ?>
            <div class="flex items-center gap-3 px-4 py-2.5 rounded hover:bg-gray-50 cursor-move menu-item" data-key="<?php echo $itemKey; ?>">
                <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
                <span class="text-sm text-gray-700"><?php echo e($itemLabel); ?></span>
                <span class="text-xs text-gray-400">/admin/<?php echo $itemKey; ?>.php</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// 初始化分组排序
new Sortable(document.getElementById('groupList'), {
    animation: 200,
    handle: '.group-handle',
    ghostClass: 'opacity-50'
});

// 初始化每个分组内的菜单项排序
document.querySelectorAll('.item-list').forEach(function(el) {
    new Sortable(el, {
        animation: 200,
        ghostClass: 'opacity-50',
        group: 'items'
    });
});

function collectOrder() {
    var groups = [];
    var items = {};
    document.querySelectorAll('.group-item').forEach(function(groupEl) {
        var gKey = groupEl.dataset.group;
        groups.push(gKey);
        items[gKey] = [];
        groupEl.querySelectorAll('.menu-item').forEach(function(itemEl) {
            items[gKey].push(itemEl.dataset.key);
        });
    });
    return { groups: groups, items: items };
}

async function saveOrder() {
    var order = collectOrder();
    var formData = new FormData();
    formData.append('ms_action', 'save');
    formData.append('order_json', JSON.stringify(order));
    try {
        var response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        var data = await response.json();
        if (data.code === 0) {
            showMessage('保存成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch(err) {
        showMessage('请求失败', 'error');
    }
}

async function resetOrder() {
    if (!confirm('确定恢复默认菜单排序？')) return;
    var formData = new FormData();
    formData.append('ms_action', 'reset');
    try {
        var response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        var data = await response.json();
        if (data.code === 0) {
            showMessage('已恢复默认排序');
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch(err) {
        showMessage('请求失败', 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
