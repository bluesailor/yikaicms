<?php
/**
 * YikaiCMS - 产品设置
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/currencies.php';   // commonCurrencies()：货币下拉的数据源
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('edit_product');

require_once ROOT_PATH . '/admin/includes/trans_pills.php';   // adminLangView / 语言切换器

// 视图语言：货币可按语言分设（多语言站中文版 ¥ / 英文版 $），沿用
// <key>_<lang> 后缀惯例；版式与规格预置是全局的，不分语言。
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];

// 保存设置
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (isset($_POST['catalog_product_page_size'])) {
        if (!validCatalogPageSize($_POST['catalog_product_page_size'])) error(__('catalog_page_size_invalid'), 422);
        settingModel()->saveBatch(['catalog_product_page_size' => $_POST['catalog_product_page_size']]);
    }
    settingModel()->set('product_layout', post('product_layout', 'sidebar'));
    settingModel()->set('show_price', post('show_price', '0'));
    settingModel()->set('product_spec_presets', trim((string) ($_POST['product_spec_presets'] ?? '')));

    // 货币：默认语言写 base 行，其它语言写 <key>_<lang>
    $_sfx = $_viewLang === $_defaultLang ? '' : ('_' . $_viewLang);
    $_sym = trim((string) ($_POST['currency_symbol'] ?? ''));
    $_dec = trim((string) ($_POST['currency_decimals'] ?? ''));
    if ($_dec !== '') {
        $_dec = (string) max(0, min(4, (int) $_dec));
    }
    settingModel()->set('currency_symbol' . $_sfx, mb_substr($_sym, 0, 8));
    settingModel()->set('currency_decimals' . $_sfx, $_dec);

    adminLog('setting', 'update', '更新产品设置');
    success();
}

// 当前视图下的货币值（用于回填表单）
$_curSfx     = $_viewLang === $_defaultLang ? '' : ('_' . $_viewLang);
$_curSymbol  = (string) config('currency_symbol' . $_curSfx, '');
$_curDecimal = (string) config('currency_decimals' . $_curSfx, '');

$pageTitle = __('psetting_title');
$currentMenu = 'product_setting';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/product.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('product_tab_list'); ?></a>
        <a href="/admin/product_category.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('product_tab_category'); ?></a>
        <a href="/admin/product_brand.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_brand'); ?></a>
        <a href="/admin/product_tag.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_tag'); ?></a>
        <a href="/admin/product_setting.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('product_tab_setting'); ?></a>
    </div>
</div>

<!-- 设置表单 -->
<div class="bg-white rounded-lg shadow">
    <div class="p-6">
        <form id="settingForm" class="space-y-6 max-w-xl">
    <?php echo adminLangField(); ?>
            <div>
                <label for="catalog-page-size"><?= e(__('setting_catalog_product_page_size')) ?></label>
                <input id="catalog-page-size" type="number" min="1" max="100" step="1" name="catalog_product_page_size" value="<?= e((string) config('catalog_product_page_size', '')) ?>" class="border rounded px-4 py-2">
                <p class="text-sm text-gray-500"><?= e(__('setting_catalog_product_page_size_tip')) ?></p>
                <a href="/admin/setting.php?tab=pagination" class="text-primary"><?= e(__('setting_tab_pagination')) ?></a>
            </div>
            <!-- 产品列表版式 -->
            <div>
                <label class="font-medium text-gray-800"><?php echo e(__('psetting_layout')); ?></label>
                <p class="text-sm text-gray-500 mt-1 mb-3"><?php echo e(__('psetting_layout_tip')); ?></p>
                <?php $currentLayout = config('product_layout', 'sidebar'); ?>
                <div class="grid grid-cols-2 gap-4" id="layoutPicker">
                    <label class="relative cursor-pointer layout-option" data-value="sidebar">
                        <input type="radio" name="product_layout" value="sidebar" class="hidden" <?php echo $currentLayout === 'sidebar' ? 'checked' : ''; ?>>
                        <div class="border-2 rounded-lg p-4 transition <?php echo $currentLayout === 'sidebar' ? 'border-primary bg-blue-50' : 'border-gray-200 hover:border-gray-300'; ?>">
                            <div class="flex gap-2 mb-2 h-16">
                                <div class="w-1/4 bg-gray-300 rounded text-[10px] flex items-center justify-center text-gray-500"><?php echo e(__('admin_category')); ?></div>
                                <div class="flex-1 grid grid-cols-3 gap-1">
                                    <div class="bg-gray-200 rounded"></div>
                                    <div class="bg-gray-200 rounded"></div>
                                    <div class="bg-gray-200 rounded"></div>
                                    <div class="bg-gray-200 rounded"></div>
                                    <div class="bg-gray-200 rounded"></div>
                                    <div class="bg-gray-200 rounded"></div>
                                </div>
                            </div>
                            <div class="text-sm font-medium text-center <?php echo $currentLayout === 'sidebar' ? 'text-primary' : 'text-gray-700'; ?>"><?php echo e(__('psetting_layout_sidebar')); ?></div>
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
                            <div class="text-sm font-medium text-center <?php echo $currentLayout === 'top' ? 'text-primary' : 'text-gray-700'; ?>"><?php echo e(__('psetting_layout_top')); ?></div>
                        </div>
                    </label>
                </div>
            </div>

            <hr>

            <!-- 显示产品价格 -->
            <div class="flex items-center justify-between">
                <div>
                    <label class="font-medium text-gray-800"><?php echo e(__('psetting_show_price')); ?></label>
                    <p class="text-sm text-gray-500 mt-1"><?php echo e(__('psetting_show_price_tip')); ?></p>
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

            <!-- 货币设置 -->
            <div>
                <label class="font-medium text-gray-800"><?php echo e(__('psetting_currency')); ?></label>
                <p class="text-sm text-gray-500 mt-1 mb-3"><?php echo e(__('psetting_currency_tip')); ?></p>
                <?php echo renderAdminLangSwitcher($_viewLang, __('psetting_currency_lang_hint')); ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-2">
                    <div>
                        <label class="block text-sm text-gray-600 mb-1"><?php echo e(__('psetting_currency_pick')); ?></label>
                        <select id="currencyPick" class="w-full border rounded px-4 py-2">
                            <option value=""><?php echo e(__('psetting_currency_custom')); ?></option>
                            <?php foreach (commonCurrencies() as $_c): ?>
                            <option value="<?php echo e($_c['code']); ?>"
                                    data-symbol="<?php echo e($_c['symbol']); ?>"
                                    data-decimals="<?php echo (int) $_c['decimals']; ?>"
                                    <?php echo $_curSymbol !== '' && $_curSymbol === $_c['symbol'] && (string) $_curDecimal === (string) $_c['decimals'] ? 'selected' : ''; ?>>
                                <?php echo e($_c['code'] . ' · ' . __($_c['name_key']) . ' · ' . $_c['symbol']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1"><?php echo e(__('psetting_currency_symbol')); ?></label>
                        <input type="text" name="currency_symbol" maxlength="8" value="<?php echo e($_curSymbol); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="<?php echo e(__('psetting_currency_symbol_ph')); ?>">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-600 mb-1"><?php echo e(__('psetting_currency_decimals')); ?></label>
                        <input type="number" name="currency_decimals" min="0" max="4" value="<?php echo e($_curDecimal); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="<?php echo e(__('psetting_currency_decimals_ph')); ?>">
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2"><?php echo e(__('psetting_currency_pick_tip')); ?></p>
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('psetting_currency_preview')); ?>
                    <span class="font-medium text-gray-700" id="currencyPreview"><?php echo formatPrice(1234.5); ?></span></p>
                <script>
                (function () {
                    // 选货币 → 自动填符号与小数位。小数位才是这个下拉的价值：日元/韩元/越南盾
                    // 是 0 位，客户多半不知道，填成 2 位就出现「¥1,234.00」这种一眼假的价格。
                    var pick = document.getElementById('currencyPick');
                    if (!pick) return;
                    var sym = document.querySelector('input[name="currency_symbol"]');
                    var dec = document.querySelector('input[name="currency_decimals"]');
                    var prev = document.getElementById('currencyPreview');

                    function render() {
                        if (!prev) return;
                        var s = (sym && sym.value) || '';
                        var d = dec && dec.value !== '' ? Math.max(0, Math.min(4, parseInt(dec.value, 10) || 0)) : 2;
                        prev.textContent = s === '' ? prev.textContent : s + (1234.5).toFixed(d).replace(/\B(?=(\d{3})+(?!\d))/, ',');
                    }
                    pick.addEventListener('change', function () {
                        var o = pick.options[pick.selectedIndex];
                        if (!o || !o.value) return;            // 「自定义」不覆盖已填内容
                        if (sym) sym.value = o.dataset.symbol || '';
                        if (dec) dec.value = o.dataset.decimals || '';
                        render();
                    });
                    // 手动改符号/小数位时，下拉退回「自定义」，避免显示与实际不符
                    [sym, dec].forEach(function (el) {
                        if (!el) return;
                        el.addEventListener('input', function () {
                            var o = pick.options[pick.selectedIndex];
                            if (o && o.value && (el === sym ? o.dataset.symbol !== el.value : o.dataset.decimals !== el.value)) {
                                pick.value = '';
                            }
                            render();
                        });
                    });
                })();
                </script>
            </div>

            <hr>

            <!-- 预置规格参数 -->
            <div>
                <label class="font-medium text-gray-800"><?php echo __('admin_spec_presets'); ?></label>
                <p class="text-sm text-gray-500 mt-1 mb-3"><?php echo __('admin_spec_presets_tip'); ?></p>
                <textarea name="product_spec_presets" rows="7" class="w-full border rounded px-4 py-2 font-mono text-sm"
                          placeholder="<?php echo e(__('psetting_spec_ph')); ?>"><?php echo e((string) config('product_spec_presets', '')); ?></textarea>
                <p class="text-xs text-gray-400 mt-2"><?php echo __('admin_spec_presets_fmt'); ?></p>
            </div>

            <hr>

            <div class="flex justify-end">
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo e(__('admin_save_settings')); ?>
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
            showMessage('<?php echo __('admin_saved'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch(err) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
