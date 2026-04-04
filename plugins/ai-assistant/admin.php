<?php
/**
 * AI 助手插件 - 设置页面
 * 通过 /admin/plugin_page.php?plugin=ai-assistant 访问
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/AiService.php';

$providers = AiService::getProviders();

// 保存 / 测试
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        settingModel()->set('ai_provider', $_POST['ai_provider'] ?? 'openai');
        $newKey = $_POST['ai_api_key'] ?? '';
        if ($newKey && strpos($newKey, '***') === false) {
            settingModel()->set('ai_api_key', AiService::encryptKey($newKey));
        }
        settingModel()->set('ai_model', $_POST['ai_model'] ?? '');
        settingModel()->set('ai_base_url', $_POST['ai_base_url'] ?? '');
        adminLog('plugin', 'ai', '更新 AI 设置');
        echo json_encode(['code' => 0, 'msg' => '设置已保存']);
        exit;
    }

    if ($action === 'test') {
        $testKey = $_POST['ai_api_key'] ?? '';
        if (!$testKey || strpos($testKey, '***') !== false) {
            $testKey = AiService::decryptKey(config('ai_api_key', ''));
        }
        $testAi = new AiService($_POST['ai_provider'] ?? 'openai', $testKey, $_POST['ai_model'] ?? '');
        $result = $testAi->chat('请回复"连接成功"四个字。', '你是一个测试助手，只需回复用户要求的内容。', 0.1);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$currentProvider = config('ai_provider', 'openai');
$rawApiKey = AiService::decryptKey(config('ai_api_key', ''));
$maskedApiKey = $rawApiKey ? (substr($rawApiKey, 0, 4) . str_repeat('*', max(0, strlen($rawApiKey) - 8)) . substr($rawApiKey, -4)) : '';
$currentModel = config('ai_model', '');
$currentBaseUrl = config('ai_base_url', '');

$pageTitle = 'AI 助手设置';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl mx-auto">
    <div class="mb-4">
        <a href="/admin/plugin.php" class="text-sm text-gray-500 hover:text-primary inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            返回插件管理
        </a>
    </div>

    <form id="aiForm">
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-gray-800">AI 服务配置</h2>
                    <p class="text-sm text-gray-500 mt-1">配置 AI 供应商，用于文章生成、SEO 优化等。</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" id="testBtn" onclick="testAiConn()" class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded transition text-sm cursor-pointer">测试连接</button>
                    <button type="button" onclick="saveAiSettings()" class="bg-primary hover:bg-secondary text-white px-5 py-2 rounded transition text-sm cursor-pointer">保存设置</button>
                </div>
            </div>

            <div class="p-6 space-y-6">
                <!-- 供应商 -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">AI 供应商</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <?php foreach ($providers as $key => $p): ?>
                        <label class="relative cursor-pointer">
                            <input type="radio" name="ai_provider" value="<?php echo $key; ?>" <?php echo $currentProvider === $key ? 'checked' : ''; ?> class="peer sr-only" onchange="onProviderChange()">
                            <div class="provider-card border-2 rounded-lg p-3 text-center transition hover:border-gray-300" data-provider="<?php echo $key; ?>">
                                <div class="provider-name font-medium text-sm"><?php echo e($p['name']); ?></div>
                                <div class="text-xs text-gray-400 mt-1"><?php echo e($p['default']); ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- API Key -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">API Key</label>
                    <input type="text" name="ai_api_key" id="aiApiKey" value="<?php echo e($maskedApiKey); ?>"
                           class="w-full border rounded-lg px-4 py-2.5 text-sm font-mono tracking-wide" placeholder="sk-..."
                           onfocus="if(this.value.indexOf('***')!==-1){this.value='';this.style.color=''}">
                    <p class="text-xs text-gray-400 mt-1" id="apiKeyHint"><?php echo $maskedApiKey ? '已保存，输入新 Key 后保存即替换' : '请填写 API Key'; ?></p>
                </div>

                <!-- 模型 -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">模型</label>
                    <input type="hidden" name="ai_model" id="aiModelInput" value="<?php echo e($currentModel); ?>">
                    <div id="aiModelGrid" class="flex flex-wrap gap-2"></div>
                </div>

                <!-- Base URL -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">自定义 API 地址 <span class="font-normal text-gray-400">（可选）</span></label>
                    <input type="text" name="ai_base_url" id="aiBaseUrl" value="<?php echo e($currentBaseUrl); ?>"
                           class="w-full border rounded-lg px-4 py-2.5 text-sm" placeholder="留空使用官方地址">
                </div>
            </div>
        </div>
    </form>

    <div id="testResult" class="hidden mt-4 px-4 py-3 rounded-lg text-sm"></div>

    <!-- 用量统计 -->
    <?php
    $logTable = DB_PREFIX . 'ai_logs';
    $hasLogTable = false;
    try { db()->fetchOne("SELECT 1 FROM {$logTable} LIMIT 1"); $hasLogTable = true; } catch (\Throwable $e) {}
    if ($hasLogTable):
        $today = date('Y-m-d'); $monthStart = date('Y-m-01');
        $todayStats = db()->fetchOne("SELECT COUNT(*) as calls, SUM(total_tokens) as tokens FROM {$logTable} WHERE created_at >= '{$today}'");
        $monthStats = db()->fetchOne("SELECT COUNT(*) as calls, SUM(total_tokens) as tokens FROM {$logTable} WHERE created_at >= '{$monthStart}'");
        $totalStats = db()->fetchOne("SELECT COUNT(*) as calls, SUM(total_tokens) as tokens FROM {$logTable}");
    ?>
    <div class="mt-6 bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800">用量统计</h2>
            <a href="/admin/ai_usage.php" class="text-sm text-primary hover:underline">用量详情 &raquo;</a>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-blue-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-blue-600"><?php echo number_format((int)($todayStats['calls'] ?? 0)); ?></p>
                    <p class="text-xs text-gray-500 mt-1">今日调用</p>
                    <p class="text-xs text-blue-400"><?php echo number_format((int)($todayStats['tokens'] ?? 0)); ?> tokens</p>
                </div>
                <div class="bg-green-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-green-600"><?php echo number_format((int)($monthStats['calls'] ?? 0)); ?></p>
                    <p class="text-xs text-gray-500 mt-1">本月调用</p>
                    <p class="text-xs text-green-400"><?php echo number_format((int)($monthStats['tokens'] ?? 0)); ?> tokens</p>
                </div>
                <div class="bg-purple-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-purple-600"><?php echo number_format((int)($totalStats['calls'] ?? 0)); ?></p>
                    <p class="text-xs text-gray-500 mt-1">累计调用</p>
                    <p class="text-xs text-purple-400"><?php echo number_format((int)($totalStats['tokens'] ?? 0)); ?> tokens</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- API Key 获取 -->
    <div class="mt-6 bg-gray-50 rounded-lg p-6 text-sm text-gray-500">
        <h3 class="font-medium text-gray-700 mb-3">API Key 获取方式</h3>
        <div class="space-y-2">
            <p><strong>OpenAI：</strong><a href="https://platform.openai.com/api-keys" target="_blank" class="text-primary hover:underline">platform.openai.com</a></p>
            <p><strong>Claude：</strong><a href="https://console.anthropic.com/settings/keys" target="_blank" class="text-primary hover:underline">console.anthropic.com</a></p>
            <p><strong>DeepSeek：</strong><a href="https://platform.deepseek.com/api_keys" target="_blank" class="text-primary hover:underline">platform.deepseek.com</a></p>
            <p><strong>通义千问：</strong><a href="https://dashscope.console.aliyun.com/apiKey" target="_blank" class="text-primary hover:underline">dashscope.console.aliyun.com</a></p>
            <p><strong>智谱AI：</strong><a href="https://open.bigmodel.cn/usercenter/apikeys" target="_blank" class="text-primary hover:underline">open.bigmodel.cn</a></p>
        </div>
    </div>
</div>

<script>
var providers = <?php echo json_encode($providers, JSON_UNESCAPED_UNICODE); ?>;
var currentModel = <?php echo json_encode($currentModel); ?>;

function getProvider() { return document.querySelector('input[name="ai_provider"]:checked')?.value || 'openai'; }

function updateProviderStyle() {
    var sel = getProvider();
    document.querySelectorAll('.provider-card').forEach(function(c) {
        var n = c.querySelector('.provider-name');
        if (c.dataset.provider === sel) { c.className = 'provider-card border-2 rounded-lg p-3 text-center transition border-primary bg-blue-50 shadow-md'; n.className = 'provider-name font-medium text-sm text-primary'; }
        else { c.className = 'provider-card border-2 rounded-lg p-3 text-center transition border-gray-200 hover:border-gray-300'; n.className = 'provider-name font-medium text-sm text-gray-800'; }
    });
}

function selectModel(m) {
    document.getElementById('aiModelInput').value = m;
    document.querySelectorAll('#aiModelGrid .model-btn').forEach(function(b) {
        b.className = 'model-btn px-3 py-1.5 rounded-lg text-sm font-medium cursor-pointer transition border-2 ' +
            (b.dataset.model === m ? 'border-primary bg-blue-50 text-primary' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300');
    });
}

function onProviderChange() {
    var p = getProvider(), cfg = providers[p];
    var grid = document.getElementById('aiModelGrid'); grid.innerHTML = '';
    var defBtn = document.createElement('button'); defBtn.type = 'button'; defBtn.dataset.model = '';
    defBtn.textContent = '默认 (' + cfg['default'] + ')'; defBtn.onclick = function(){ selectModel(''); }; grid.appendChild(defBtn);
    cfg.models.forEach(function(m) {
        var b = document.createElement('button'); b.type = 'button'; b.dataset.model = m;
        b.textContent = m; b.onclick = function(){ selectModel(m); }; grid.appendChild(b);
    });
    grid.querySelectorAll('button').forEach(function(b){ b.className = 'model-btn'; });
    selectModel(currentModel);
    updateProviderStyle();
    document.getElementById('aiBaseUrl').placeholder = '留空使用：' + cfg.base_url;
}
onProviderChange();

function saveAiSettings() {
    var fd = new FormData(document.getElementById('aiForm')); fd.append('action', 'save');
    fetch('', { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){ alert(d.msg || '已保存'); });
}

function testAiConn() {
    var btn = document.getElementById('testBtn'), result = document.getElementById('testResult');
    btn.disabled = true; btn.textContent = '测试中...';
    result.className = 'mt-4 px-4 py-3 rounded-lg text-sm bg-gray-50 text-gray-500';
    result.textContent = '正在连接...'; result.classList.remove('hidden');
    var fd = new FormData(document.getElementById('aiForm')); fd.append('action', 'test');
    fetch('', { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
        result.className = 'mt-4 px-4 py-3 rounded-lg text-sm ' + (d.success ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200');
        result.textContent = d.success ? '连接成功！回复：' + d.content : '失败：' + d.error;
    }).catch(function(){ result.textContent = '请求失败'; }).finally(function(){ btn.disabled = false; btn.textContent = '测试连接'; });
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
