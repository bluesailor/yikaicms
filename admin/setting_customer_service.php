<?php
/**
 * YikaiCMS - 在线客服设置（数据驱动版）
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/customer_service.php';

checkLogin();
requirePermission('*');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = $_POST['settings'] ?? [];
    foreach (['cs_enabled','cs_show_mobile'] as $sw) {
        if (!array_key_exists($sw, $settings)) $settings[$sw] = '0';
    }
    // cs_items 已是 JSON 字符串（前端 commit 时写入）
    if (!isset($settings['cs_items']) || trim($settings['cs_items']) === '') {
        $settings['cs_items'] = '[]';
    }
    settingModel()->saveBatch($settings);
    adminLog('setting', 'update', '更新在线客服设置');
    success();
}

$c = [
    'enabled'     => config('cs_enabled', '0'),
    'position'    => config('cs_position', 'right'),
    'show_mobile' => config('cs_show_mobile', '1'),
    'button_text' => config('cs_button_text', __('cs_default_button')),
    'panel_title' => config('cs_panel_title', __('cs_default_panel_title')),
    'items'       => config('cs_items', '[]'),
];

$iconPresets = array_keys(csIconPresets());
$iconSvgs    = csIconPresets();

$pageTitle   = __('cs_title');
$currentMenu = 'setting_customer_service';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b">
        <h2 class="font-bold text-gray-800"><?php echo e(__('cs_heading')); ?></h2>
        <p class="text-sm text-gray-500 mt-1"><?php echo e(__('cs_intro')); ?></p>
    </div>

    <form id="settingForm" class="p-6 space-y-6">

        <fieldset class="border rounded-lg p-4 space-y-3">
            <legend class="px-2 text-sm font-semibold text-gray-700"><?php echo e(__('cs_basic')); ?></legend>
            <div class="flex flex-wrap items-center gap-6">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="hidden" name="settings[cs_enabled]" value="0">
                    <input type="checkbox" name="settings[cs_enabled]" value="1" <?php echo $c['enabled']==='1'?'checked':''; ?> class="w-4 h-4 rounded">
                    <span class="ml-2 text-sm"><?php echo e(__('cs_enable')); ?></span>
                </label>
                <label class="inline-flex items-center cursor-pointer">
                    <input type="hidden" name="settings[cs_show_mobile]" value="0">
                    <input type="checkbox" name="settings[cs_show_mobile]" value="1" <?php echo $c['show_mobile']==='1'?'checked':''; ?> class="w-4 h-4 rounded">
                    <span class="ml-2 text-sm"><?php echo e(__('cs_mobile')); ?></span>
                </label>
                <div class="flex items-center gap-2">
                    <span class="text-sm"><?php echo e(__('cs_position')); ?></span>
                    <select name="settings[cs_position]" class="border rounded px-3 py-1 text-sm">
                        <option value="right" <?php echo $c['position']==='right'?'selected':''; ?>><?php echo e(__('cs_right')); ?></option>
                        <option value="left"  <?php echo $c['position']==='left' ?'selected':''; ?>><?php echo e(__('cs_left')); ?></option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-sm block mb-1"><?php echo e(__('cs_button_text')); ?></label>
                    <input type="text" name="settings[cs_button_text]" value="<?php echo e($c['button_text']); ?>" class="w-full border rounded px-3 py-1.5 text-sm">
                </div>
                <div>
                    <label class="text-sm block mb-1"><?php echo e(__('cs_panel_title')); ?></label>
                    <input type="text" name="settings[cs_panel_title]" value="<?php echo e($c['panel_title']); ?>" class="w-full border rounded px-3 py-1.5 text-sm">
                </div>
            </div>
        </fieldset>

        <fieldset class="border rounded-lg p-4">
            <legend class="px-2 text-sm font-semibold text-gray-700"><?php echo e(__('cs_channels')); ?></legend>
            <input type="hidden" name="settings[cs_items]" id="csItemsJson">
            <div id="csItemsList" class="space-y-3"></div>
            <button type="button" onclick="csAdd()" class="mt-3 inline-flex items-center gap-1 text-sm text-blue-600 hover:underline">
                <i class="ti ti-plus text-base"></i>
                <?php echo e(__('cs_add_item')); ?>
            </button>
        </fieldset>

        <div class="flex items-center gap-3">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded"><?php echo e(__('btn_save')); ?></button>
            <a href="/" target="_blank" class="text-sm text-gray-500 hover:text-primary"><?php echo e(__('cs_front_preview')); ?> →</a>
            <span class="text-xs text-gray-400 ml-auto"><?php echo e(__('cs_cache_note')); ?></span>
        </div>
    </form>
</div>

<!-- icon picker modal -->
<div id="csIconModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(0,0,0,.4)">
    <div class="bg-white rounded-lg w-full max-w-md p-5">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold"><?php echo e(__('cs_pick_icon')); ?></h3>
            <button type="button" onclick="document.getElementById('csIconModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
        </div>
        <div class="grid grid-cols-6 gap-2 mb-4" id="csIconGrid"></div>
        <div class="border-t pt-3">
            <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('cs_custom_icon')); ?></label>
            <div class="flex gap-2">
                <input type="text" id="csCustomIconUrl" placeholder="<?php echo e(__('cs_custom_icon_ph')); ?>" class="flex-1 border rounded px-3 py-1.5 text-sm">
                <button type="button" onclick="csPickCustom()" class="bg-blue-500 text-white text-sm px-3 py-1.5 rounded"><?php echo e(__('cs_use')); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const CS_ICON_SVGS = <?php echo json_encode($iconSvgs, JSON_UNESCAPED_UNICODE); ?>;
const CS_ICON_KEYS = <?php echo json_encode($iconPresets); ?>;
const CS_TYPES = [
    {value: 'qq',         label: <?php echo json_encode(__('cs_type_qq'), JSON_UNESCAPED_UNICODE); ?>,    valueHint: <?php echo json_encode(__('cs_hint_qq'), JSON_UNESCAPED_UNICODE); ?>},
    {value: 'wechat-id',  label: <?php echo json_encode(__('cs_type_wechat_id'), JSON_UNESCAPED_UNICODE); ?>, valueHint: <?php echo json_encode(__('cs_hint_wechat_id'), JSON_UNESCAPED_UNICODE); ?>},
    {value: 'wechat-qr',  label: <?php echo json_encode(__('cs_type_wechat_qr'), JSON_UNESCAPED_UNICODE); ?>,        valueHint: <?php echo json_encode(__('cs_hint_qr'), JSON_UNESCAPED_UNICODE); ?>},
    {value: 'work-wechat',label: <?php echo json_encode(__('cs_type_work_wechat'), JSON_UNESCAPED_UNICODE); ?>,     valueHint: <?php echo json_encode(__('cs_hint_work_qr'), JSON_UNESCAPED_UNICODE); ?>},
    {value: 'phone',      label: <?php echo json_encode(__('contact_icon_phone'), JSON_UNESCAPED_UNICODE); ?>,             valueHint: <?php echo json_encode(__('cs_hint_phone'), JSON_UNESCAPED_UNICODE); ?>},
    {value: 'mobile',     label: <?php echo json_encode(__('cs_type_mobile'), JSON_UNESCAPED_UNICODE); ?>,             valueHint: <?php echo json_encode(__('cs_hint_mobile'), JSON_UNESCAPED_UNICODE); ?>},
    {value: 'email',      label: <?php echo json_encode(__('contact_icon_email'), JSON_UNESCAPED_UNICODE); ?>,             valueHint: <?php echo json_encode(__('cs_hint_email'), JSON_UNESCAPED_UNICODE); ?>},
    {value: 'link',       label: <?php echo json_encode(__('cs_type_link'), JSON_UNESCAPED_UNICODE); ?>,       valueHint: <?php echo json_encode(__('cs_hint_link'), JSON_UNESCAPED_UNICODE); ?>},
    {value: 'text',       label: <?php echo json_encode(__('cs_type_text'), JSON_UNESCAPED_UNICODE); ?>,           valueHint: <?php echo json_encode(__('cs_hint_text'), JSON_UNESCAPED_UNICODE); ?>},
];

let csItems = [];
try { csItems = JSON.parse(<?php echo json_encode($c['items']); ?>) || []; } catch(e) { csItems = []; }
if (!Array.isArray(csItems)) csItems = [];

let csCurrentIconIdx = -1;

function csRenderIconHtml(icon){
    if (icon && CS_ICON_SVGS[icon]) return CS_ICON_SVGS[icon];
    if (icon && (icon.startsWith('/') || icon.startsWith('http'))) {
        return `<img src="${icon}" class="w-5 h-5 object-contain" alt="">`;
    }
    return '<div class="w-5 h-5 bg-gray-200 rounded"></div>';
}

function csRender(){
    const list = document.getElementById('csItemsList');
    list.innerHTML = '';
    if (csItems.length === 0) {
        list.innerHTML = '<div class="text-center text-sm text-gray-400 py-8 border-2 border-dashed rounded">' + <?php echo json_encode(__('cs_empty'), JSON_UNESCAPED_UNICODE); ?> + '</div>';
        return;
    }
    csItems.forEach((it, idx) => {
        const row = document.createElement('div');
        row.className = 'border rounded p-3 flex flex-wrap items-center gap-2 bg-gray-50';
        row.innerHTML = `
            <button type="button" title="<?php echo e(__('nav_menu_move_up')); ?>" onclick="csMove(${idx},-1)" class="text-gray-400 hover:text-gray-700 px-1" ${idx===0?'disabled':''}>↑</button>
            <button type="button" title="<?php echo e(__('nav_menu_move_down')); ?>" onclick="csMove(${idx},1)" class="text-gray-400 hover:text-gray-700 px-1" ${idx===csItems.length-1?'disabled':''}>↓</button>
            <button type="button" title="<?php echo e(__('cs_pick_icon')); ?>" onclick="csOpenIcon(${idx})" class="w-9 h-9 bg-white border rounded flex items-center justify-center text-primary">${csRenderIconHtml(it.icon)}</button>
            <select onchange="csUpdate(${idx},'type',this.value); csRender()" class="border rounded px-2 py-1.5 text-sm w-40">
                ${CS_TYPES.map(t=>`<option value="${t.value}" ${it.type===t.value?'selected':''}>${t.label}</option>`).join('')}
            </select>
            <input type="text" placeholder="<?php echo e(__('cs_label_ph')); ?>" value="${(it.label||'').replace(/"/g,'&quot;')}" oninput="csUpdate(${idx},'label',this.value)" class="border rounded px-3 py-1.5 text-sm flex-1 min-w-[120px]">
            <input type="text" placeholder="${(CS_TYPES.find(t=>t.value===it.type)||{}).valueHint||''}" value="${(it.value||'').replace(/"/g,'&quot;')}" oninput="csUpdate(${idx},'value',this.value)" class="border rounded px-3 py-1.5 text-sm flex-[2] min-w-[150px]">
            ${(it.type==='wechat-qr'||it.type==='work-wechat') ? `<button type="button" onclick="csPickValueImage(${idx})" class="text-xs text-blue-600 hover:underline">选图</button>` : ''}
            <label class="inline-flex items-center gap-1 text-xs">
                <input type="checkbox" ${it.enabled?'checked':''} onchange="csUpdate(${idx},'enabled',this.checked)" class="w-3.5 h-3.5"> <?php echo e(__('admin_enabled')); ?>
            </label>
            <button type="button" onclick="csDel(${idx})" title="<?php echo e(__('admin_delete')); ?>" class="text-red-400 hover:text-red-600 px-2">×</button>
        `;
        list.appendChild(row);
    });
}

function csAdd(){
    csItems.push({type:'qq', icon:'qq', label:'', value:'', enabled:true});
    csRender();
}
function csDel(idx){
    if (!confirm(<?php echo json_encode(__('cs_delete_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
    csItems.splice(idx,1); csRender();
}
function csUpdate(idx, field, val){
    csItems[idx][field] = val;
    // 类型切换时自动配对默认图标
    if (field==='type') {
        const def = {qq:'qq','wechat-id':'wechat','wechat-qr':'wechat','work-wechat':'work-wechat',phone:'phone',mobile:'mobile',email:'email',link:'message',text:'message'};
        if (CS_ICON_KEYS.includes(csItems[idx].icon) || !csItems[idx].icon) {
            csItems[idx].icon = def[val] || 'message';
        }
    }
}
function csMove(idx, dir){
    const j = idx+dir;
    if (j<0||j>=csItems.length) return;
    [csItems[idx], csItems[j]] = [csItems[j], csItems[idx]];
    csRender();
}

function csOpenIcon(idx){
    csCurrentIconIdx = idx;
    const grid = document.getElementById('csIconGrid');
    grid.innerHTML = '';
    CS_ICON_KEYS.forEach(k => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'aspect-square border rounded flex items-center justify-center text-gray-700 hover:bg-blue-50 hover:text-primary hover:border-primary';
        btn.title = k;
        btn.innerHTML = CS_ICON_SVGS[k];
        btn.onclick = () => csPickIcon(k);
        grid.appendChild(btn);
    });
    document.getElementById('csCustomIconUrl').value = '';
    document.getElementById('csIconModal').classList.remove('hidden');
    document.getElementById('csIconModal').classList.add('flex');
}
function csPickIcon(key){
    csItems[csCurrentIconIdx].icon = key;
    csRender();
    document.getElementById('csIconModal').classList.add('hidden');
    document.getElementById('csIconModal').classList.remove('flex');
}
function csPickCustom(){
    const u = document.getElementById('csCustomIconUrl').value.trim();
    if (!u) return alert(<?php echo json_encode(__('cs_url_required'), JSON_UNESCAPED_UNICODE); ?>);
    csItems[csCurrentIconIdx].icon = u;
    csRender();
    document.getElementById('csIconModal').classList.add('hidden');
    document.getElementById('csIconModal').classList.remove('flex');
}
function csPickValueImage(idx){
    if (typeof openMediaPicker === 'function') {
        openMediaPicker(url => { csItems[idx].value = url; csRender(); });
    } else {
        const u = prompt(<?php echo json_encode(__('cs_paste_url'), JSON_UNESCAPED_UNICODE); ?>, csItems[idx].value || '');
        if (u !== null) { csItems[idx].value = u; csRender(); }
    }
}

document.getElementById('settingForm').addEventListener('submit', async function(e){
    e.preventDefault();
    document.getElementById('csItemsJson').value = JSON.stringify(csItems);
    const fd = new FormData(this);
    const res = await fetch('', { method:'POST', body: fd });
    const data = await safeJson(res);
    if (data.code === 0) showMessage(<?php echo json_encode(__('save_success'), JSON_UNESCAPED_UNICODE); ?>);
    else showMessage(data.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
});

csRender();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
