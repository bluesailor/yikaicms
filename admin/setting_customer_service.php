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
    'button_text' => config('cs_button_text', '在线客服'),
    'panel_title' => config('cs_panel_title', '欢迎咨询，期待与您合作'),
    'items'       => config('cs_items', '[]'),
];

$iconPresets = array_keys(csIconPresets());
$iconSvgs    = csIconPresets();

$pageTitle   = '在线客服';
$currentMenu = 'setting_customer_service';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b">
        <h2 class="font-bold text-gray-800">在线客服浮动侧边栏</h2>
        <p class="text-sm text-gray-500 mt-1">数据驱动：每一项独立配置，可任意添加 QQ / 微信 / 电话 / 邮箱等通道，图标可从预设选择或上传。</p>
    </div>

    <form id="settingForm" class="p-6 space-y-6">

        <fieldset class="border rounded-lg p-4 space-y-3">
            <legend class="px-2 text-sm font-semibold text-gray-700">基本设置</legend>
            <div class="flex flex-wrap items-center gap-6">
                <label class="inline-flex items-center cursor-pointer">
                    <input type="hidden" name="settings[cs_enabled]" value="0">
                    <input type="checkbox" name="settings[cs_enabled]" value="1" <?php echo $c['enabled']==='1'?'checked':''; ?> class="w-4 h-4 rounded">
                    <span class="ml-2 text-sm">启用在线客服</span>
                </label>
                <label class="inline-flex items-center cursor-pointer">
                    <input type="hidden" name="settings[cs_show_mobile]" value="0">
                    <input type="checkbox" name="settings[cs_show_mobile]" value="1" <?php echo $c['show_mobile']==='1'?'checked':''; ?> class="w-4 h-4 rounded">
                    <span class="ml-2 text-sm">手机端显示</span>
                </label>
                <div class="flex items-center gap-2">
                    <span class="text-sm">位置</span>
                    <select name="settings[cs_position]" class="border rounded px-3 py-1 text-sm">
                        <option value="right" <?php echo $c['position']==='right'?'selected':''; ?>>右侧</option>
                        <option value="left"  <?php echo $c['position']==='left' ?'selected':''; ?>>左侧</option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div>
                    <label class="text-sm block mb-1">收起时按钮文字（竖排）</label>
                    <input type="text" name="settings[cs_button_text]" value="<?php echo e($c['button_text']); ?>" class="w-full border rounded px-3 py-1.5 text-sm">
                </div>
                <div>
                    <label class="text-sm block mb-1">展开后标题</label>
                    <input type="text" name="settings[cs_panel_title]" value="<?php echo e($c['panel_title']); ?>" class="w-full border rounded px-3 py-1.5 text-sm">
                </div>
            </div>
        </fieldset>

        <fieldset class="border rounded-lg p-4">
            <legend class="px-2 text-sm font-semibold text-gray-700">客服通道（可任意增加 / 调整顺序 / 删除）</legend>
            <input type="hidden" name="settings[cs_items]" id="csItemsJson">
            <div id="csItemsList" class="space-y-3"></div>
            <button type="button" onclick="csAdd()" class="mt-3 inline-flex items-center gap-1 text-sm text-blue-600 hover:underline">
                <i class="ti ti-plus text-base"></i>
                添加客服项
            </button>
        </fieldset>

        <div class="flex items-center gap-3">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded">保存</button>
            <a href="/" target="_blank" class="text-sm text-gray-500 hover:text-primary">前台预览 →</a>
            <span class="text-xs text-gray-400 ml-auto">保存后清前台缓存才生效</span>
        </div>
    </form>
</div>

<!-- icon picker modal -->
<div id="csIconModal" class="fixed inset-0 z-50 hidden items-center justify-center" style="background:rgba(0,0,0,.4)">
    <div class="bg-white rounded-lg w-full max-w-md p-5">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold">选择图标</h3>
            <button type="button" onclick="document.getElementById('csIconModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700 text-xl leading-none">&times;</button>
        </div>
        <div class="grid grid-cols-6 gap-2 mb-4" id="csIconGrid"></div>
        <div class="border-t pt-3">
            <label class="text-xs text-gray-500 block mb-1">或上传/输入自定义图标 URL：</label>
            <div class="flex gap-2">
                <input type="text" id="csCustomIconUrl" placeholder="/upload/...png 或外链" class="flex-1 border rounded px-3 py-1.5 text-sm">
                <button type="button" onclick="csPickCustom()" class="bg-blue-500 text-white text-sm px-3 py-1.5 rounded">使用</button>
            </div>
        </div>
    </div>
</div>

<script>
const CS_ICON_SVGS = <?php echo json_encode($iconSvgs, JSON_UNESCAPED_UNICODE); ?>;
const CS_ICON_KEYS = <?php echo json_encode($iconPresets); ?>;
const CS_TYPES = [
    {value: 'qq',         label: 'QQ（点击聊天）',    valueHint: 'QQ 号，如 3460919689'},
    {value: 'wechat-id',  label: '微信号（点击复制）', valueHint: '微信号，如 farflow_wx'},
    {value: 'wechat-qr',  label: '微信二维码',        valueHint: '二维码图片 URL'},
    {value: 'work-wechat',label: '企业微信二维码',     valueHint: '企业微信客服二维码图片 URL'},
    {value: 'phone',      label: '电话',             valueHint: '号码，如 021-58000360'},
    {value: 'mobile',     label: '手机',             valueHint: '号码，如 13601948733'},
    {value: 'email',      label: '邮箱',             valueHint: '邮箱地址'},
    {value: 'link',       label: '自定义链接',       valueHint: '链接 URL'},
    {value: 'text',       label: '纯文本',           valueHint: '只展示文本'},
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
        list.innerHTML = '<div class="text-center text-sm text-gray-400 py-8 border-2 border-dashed rounded">还没有客服项，点击下方按钮添加</div>';
        return;
    }
    csItems.forEach((it, idx) => {
        const row = document.createElement('div');
        row.className = 'border rounded p-3 flex flex-wrap items-center gap-2 bg-gray-50';
        row.innerHTML = `
            <button type="button" title="上移" onclick="csMove(${idx},-1)" class="text-gray-400 hover:text-gray-700 px-1" ${idx===0?'disabled':''}>↑</button>
            <button type="button" title="下移" onclick="csMove(${idx},1)" class="text-gray-400 hover:text-gray-700 px-1" ${idx===csItems.length-1?'disabled':''}>↓</button>
            <button type="button" title="选择图标" onclick="csOpenIcon(${idx})" class="w-9 h-9 bg-white border rounded flex items-center justify-center text-primary">${csRenderIconHtml(it.icon)}</button>
            <select onchange="csUpdate(${idx},'type',this.value); csRender()" class="border rounded px-2 py-1.5 text-sm w-40">
                ${CS_TYPES.map(t=>`<option value="${t.value}" ${it.type===t.value?'selected':''}>${t.label}</option>`).join('')}
            </select>
            <input type="text" placeholder="标签，如「售前 QQ」" value="${(it.label||'').replace(/"/g,'&quot;')}" oninput="csUpdate(${idx},'label',this.value)" class="border rounded px-3 py-1.5 text-sm flex-1 min-w-[120px]">
            <input type="text" placeholder="${(CS_TYPES.find(t=>t.value===it.type)||{}).valueHint||''}" value="${(it.value||'').replace(/"/g,'&quot;')}" oninput="csUpdate(${idx},'value',this.value)" class="border rounded px-3 py-1.5 text-sm flex-[2] min-w-[150px]">
            ${(it.type==='wechat-qr'||it.type==='work-wechat') ? `<button type="button" onclick="csPickValueImage(${idx})" class="text-xs text-blue-600 hover:underline">选图</button>` : ''}
            <label class="inline-flex items-center gap-1 text-xs">
                <input type="checkbox" ${it.enabled?'checked':''} onchange="csUpdate(${idx},'enabled',this.checked)" class="w-3.5 h-3.5"> 启用
            </label>
            <button type="button" onclick="csDel(${idx})" title="删除" class="text-red-400 hover:text-red-600 px-2">×</button>
        `;
        list.appendChild(row);
    });
}

function csAdd(){
    csItems.push({type:'qq', icon:'qq', label:'', value:'', enabled:true});
    csRender();
}
function csDel(idx){
    if (!confirm('确定删除这一项？')) return;
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
    if (!u) return alert('请输入图片 URL');
    csItems[csCurrentIconIdx].icon = u;
    csRender();
    document.getElementById('csIconModal').classList.add('hidden');
    document.getElementById('csIconModal').classList.remove('flex');
}
function csPickValueImage(idx){
    if (typeof openMediaPicker === 'function') {
        openMediaPicker(url => { csItems[idx].value = url; csRender(); });
    } else {
        const u = prompt('粘贴图片 URL：', csItems[idx].value || '');
        if (u !== null) { csItems[idx].value = u; csRender(); }
    }
}

document.getElementById('settingForm').addEventListener('submit', async function(e){
    e.preventDefault();
    document.getElementById('csItemsJson').value = JSON.stringify(csItems);
    const fd = new FormData(this);
    const res = await fetch('', { method:'POST', body: fd });
    const data = await safeJson(res);
    if (data.code === 0) showMessage('保存成功');
    else showMessage(data.msg || '保存失败', 'error');
});

csRender();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
