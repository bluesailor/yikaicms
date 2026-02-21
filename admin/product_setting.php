<?php
/**
 * Yikai CMS - 产品设置
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('content');

// 保存设置
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    settingModel()->set('product_layout', post('product_layout', 'sidebar'));
    settingModel()->set('show_price', post('show_price', '0'));
    adminLog('setting', 'update', '更新产品设置');
    success();
}

$pageTitle = '产品设置';
$currentMenu = 'product_setting';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/product.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">产品列表</a>
        <a href="/admin/product_category.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">分类管理</a>
        <a href="/admin/product_setting.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">产品设置</a>
    </div>
</div>

<!-- 设置表单 -->
<div class="bg-white rounded-lg shadow">
    <div class="p-6">
        <form id="settingForm" class="space-y-6 max-w-xl">
            <!-- 产品列表版式 -->
            <div>
                <label class="font-medium text-gray-800">产品列表版式</label>
                <p class="text-sm text-gray-500 mt-1 mb-3">选择产品中心页面的展示方式</p>
                <?php $currentLayout = config('product_layout', 'sidebar'); ?>
                <div class="grid grid-cols-2 gap-4" id="layoutPicker">
                    <label class="relative cursor-pointer layout-option" data-value="sidebar">
                        <input type="radio" name="product_layout" value="sidebar" class="hidden" <?php echo $currentLayout === 'sidebar' ? 'checked' : ''; ?>>
                        <div class="border-2 rounded-lg p-4 transition <?php echo $currentLayout === 'sidebar' ? 'border-primary bg-blue-50' : 'border-gray-200 hover:border-gray-300'; ?>">
                            <div class="flex gap-2 mb-2 h-16">
                                <div class="w-1/4 bg-gray-300 rounded text-[10px] flex items-center justify-center text-gray-500">分类</div>
                                <div class="flex-1 grid grid-cols-3 gap-1">
                                    <div class="bg-gray-200 rounded"></div>
                                    <div class="bg-gray-200 rounded"></div>
                                    <div class="bg-gray-200 rounded"></div>
                                    <div class="bg-gray-200 rounded"></div>
                                    <div class="bg-gray-200 rounded"></div>
                                    <div class="bg-gray-200 rounded"></div>
                                </div>
                            </div>
                            <div class="text-sm font-medium text-center <?php echo $currentLayout === 'sidebar' ? 'text-primary' : 'text-gray-700'; ?>">侧栏模式</div>
                        </div>
                    </label>
                    <label class="relative cursor-pointer layout-option" data-value="top">
                        <input type="radio" name="product_layout" value="top" class="hidden" <?php echo $currentLayout === 'top' ? 'checked' : ''; ?>>
                        <div class="border-2 rounded-lg p-4 transition <?php echo $currentLayout === 'top' ? 'border-primary bg-blue-50' : 'border-gray-200 hover:border-gray-300'; ?>">
                            <div class="mb-2 h-16">
                                <div class="flex gap-1 mb-1.5">
                                    <div class="h-3 bg-gray-300 rounded-full flex-1"></div>
                                    <div class="h-3 bg-gray-300 rounded-full flex-1"></div>
                                    <div class="h-3 bg-gray-200 rounded-full flex-1"></div>
                                    <div class="h-3 bg-gray-200 rounded-full flex-1"></div>
                                </div>
                                <div class="grid grid-cols-4 gap-1 flex-1">
                                    <div class="bg-gray-200 rounded h-full"></div>
                                    <div class="bg-gray-200 rounded h-full"></div>
                                    <div class="bg-gray-200 rounded h-full"></div>
                                    <div class="bg-gray-200 rounded h-full"></div>
                                </div>
                            </div>
                            <div class="text-sm font-medium text-center <?php echo $currentLayout === 'top' ? 'text-primary' : 'text-gray-700'; ?>">顶栏模式</div>
                        </div>
                    </label>
                </div>
            </div>

            <hr>

            <!-- 显示产品价格 -->
            <div class="flex items-center justify-between">
                <div>
                    <label class="font-medium text-gray-800">显示产品价格</label>
                    <p class="text-sm text-gray-500 mt-1">开启后前台将展示产品价格信息</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="hidden" name="show_price" value="0">
                    <input type="checkbox" name="show_price" value="1"
                           class="sr-only peer"
                           <?php echo config('show_price', '0') === '1' ? 'checked' : ''; ?>>
                    <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-primary/20 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                </label>
            </div>

            <hr>

            <div class="flex justify-end">
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存设置
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// 版式选择切换
document.querySelectorAll('.layout-option').forEach(function(label) {
    label.addEventListener('click', function() {
        document.querySelectorAll('.layout-option').forEach(function(item) {
            var box = item.querySelector('div');
            var text = item.querySelector('.text-sm');
            box.classList.remove('border-primary', 'bg-blue-50');
            box.classList.add('border-gray-200');
            text.classList.remove('text-primary');
            text.classList.add('text-gray-700');
        });
        var box = this.querySelector('div');
        var text = this.querySelector('.text-sm');
        box.classList.remove('border-gray-200');
        box.classList.add('border-primary', 'bg-blue-50');
        text.classList.remove('text-gray-700');
        text.classList.add('text-primary');
    });
});

document.getElementById('settingForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    try {
        const response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage('保存成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch(err) {
        showMessage('请求失败', 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
