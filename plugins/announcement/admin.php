<?php
/**
 * 网站公告 - 配置页
 * 由 /admin/plugin_page.php?plugin=announcement 加载（已 checkLogin + CSRF）。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ann_action'] ?? '') === 'save') {
    settingModel()->set('ann_enabled',   isset($_POST['ann_enabled']) ? '1' : '0', 'plugin');
    settingModel()->set('ann_home_only', isset($_POST['ann_home_only']) ? '1' : '0', 'plugin');
    settingModel()->set('ann_title',     trim((string) ($_POST['ann_title'] ?? '')), 'plugin');
    settingModel()->set('ann_content',   (string) ($_POST['ann_content'] ?? ''), 'plugin');
    settingModel()->set('ann_button',    trim((string) ($_POST['ann_button'] ?? '')) ?: '我知道了', 'plugin');
    settingModel()->set('ann_cooldown',  (string) max(0, (int) ($_POST['ann_cooldown'] ?? 1)), 'plugin');
    adminLog('plugin', 'update', '更新网站公告配置');
    success([], '已保存');
}

$enabled  = (string) config('ann_enabled', '0') === '1';
$homeOnly = (string) config('ann_home_only', '0') === '1';
$title    = (string) config('ann_title', '网站公告');
$content  = (string) config('ann_content', '');
$button   = (string) config('ann_button', '我知道了');
$cooldown = (int) config('ann_cooldown', '1');

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl space-y-6">
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-1">网站公告弹窗</h2>
        <p class="text-sm text-gray-500 mb-5">开启后，访客进入网站时会弹出公告（如严正声明、放假通知）。同一访客在设定的冷却天数内只弹一次；<b>你修改标题或内容后，会自动对所有访客重新弹出一次</b>。</p>

        <form id="annForm" class="space-y-5">
            <input type="hidden" name="ann_action" value="save">

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="ann_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm font-medium text-gray-700">启用公告弹窗</span>
            </label>

            <div>
                <label class="block text-sm text-gray-700 mb-1">弹窗标题</label>
                <input type="text" name="ann_title" value="<?php echo htmlspecialchars($title, ENT_QUOTES); ?>" class="w-full border rounded px-3 py-2 text-sm" placeholder="网站公告">
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1">公告内容</label>
                <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
                <div id="editor-container" class="border rounded-b-lg" style="min-height: 320px;"></div>
                <input type="hidden" name="ann_content" id="ann_content_input">
                <p class="text-xs text-gray-400 mt-1">可视化编辑，支持加粗、居中、插入图片等；标题与按钮由下方单独设置。</p>
            </div>

            <div class="grid grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm text-gray-700 mb-1">按钮文字</label>
                    <input type="text" name="ann_button" value="<?php echo htmlspecialchars($button, ENT_QUOTES); ?>" class="w-full border rounded px-3 py-2 text-sm" placeholder="我知道了">
                    <p class="text-xs text-gray-400 mt-1">如"我知道了""同意并继续""关闭"等</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1">弹出频率</label>
                    <div class="flex items-center gap-2">
                        <input type="number" name="ann_cooldown" value="<?php echo $cooldown; ?>" min="0" class="w-20 border rounded px-3 py-2 text-sm">
                        <span class="text-sm text-gray-500">天一次（0＝每次都弹）</span>
                    </div>
                </div>
            </div>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="ann_home_only" value="1" <?php echo $homeOnly ? 'checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700">仅首页显示（不勾选＝全站每页）</span>
            </label>

            <div class="pt-2">
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition">保存</button>
            </div>
        </form>
    </div>

    <div class="bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-gray-600">
        <b>提示：</b>测试时如果自己看不到弹窗，是"冷却"cookie（<code>ik_ann_seen</code>）生效了——用无痕窗口或清 cookie，或临时把弹出频率设为 0 即可。
    </div>
</div>

<?php
$annContentJson = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$extraJs = '<script>
var annEditor = initWangEditor("#toolbar-container", "#editor-container", {
    placeholder: "公告正文……",
    html: ' . $annContentJson . ',
    uploadUrl: "/admin/upload.php",
    onChange: function (ed) { document.getElementById("ann_content_input").value = ed.getHtml(); }
});
document.getElementById("ann_content_input").value = ' . $annContentJson . ';
document.getElementById("annForm").addEventListener("submit", function (e) {
    e.preventDefault();
    document.getElementById("ann_content_input").value = annEditor.getHtml();
    adminSave(this, { reload: true });
});
</script>';

require_once ROOT_PATH . '/admin/includes/footer.php';
