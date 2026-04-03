<?php
/**
 * Yikai CMS - AI 设置
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/AiService.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$currentMenu = 'setting_ai';
$pageTitle = 'AI 设置';
$message = '';
$messageType = '';

$providers = AiService::getProviders();

// 保存设置
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $message = 'CSRF 验证失败';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? 'save';

        if ($action === 'save') {
            settingModel()->set('ai_provider', $_POST['ai_provider'] ?? 'openai');
            $newKey = $_POST['ai_api_key'] ?? '';
            if ($newKey && strpos($newKey, '***') === false) {
                settingModel()->set('ai_api_key', AiService::encryptKey($newKey));
            }
            settingModel()->set('ai_model', $_POST['ai_model'] ?? '');
            settingModel()->set('ai_base_url', $_POST['ai_base_url'] ?? '');
            $message = '设置已保存';
            $messageType = 'success';
            adminLog('setting', 'ai', '更新 AI 设置');
        }

        if ($action === 'test') {
            $testKey = $_POST['ai_api_key'] ?? '';
            // 如果是掩码值，用数据库中的真实 key
            if (!$testKey || strpos($testKey, '***') !== false) {
                $testKey = AiService::decryptKey(config('ai_api_key', ''));
            }
            $testAi = new AiService(
                $_POST['ai_provider'] ?? 'openai',
                $testKey,
                $_POST['ai_model'] ?? ''
            );
            $result = $testAi->chat('请回复"连接成功"四个字。', '你是一个测试助手，只需回复用户要求的内容。', 0.1);

            header('Content-Type: application/json');
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

$currentProvider = config('ai_provider', 'openai');
$rawApiKey = AiService::decryptKey(config('ai_api_key', ''));
$maskedApiKey = $rawApiKey ? (substr($rawApiKey, 0, 4) . str_repeat('*', max(0, strlen($rawApiKey) - 8)) . substr($rawApiKey, -4)) : '';
$currentModel = config('ai_model', '');
$currentBaseUrl = config('ai_base_url', '');

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl">
    <?php if ($message): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
        <?php echo e($message); ?>
    </div>
    <?php endif; ?>

    <form method="post" id="aiForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save">

        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-gray-800">AI 服务配置</h2>
                    <p class="text-sm text-gray-500 mt-1">配置 AI 供应商，用于文章生成、SEO 优化等功能。</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" id="testBtn" onclick="testConnection()" class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded transition text-sm cursor-pointer">
                        测试连接
                    </button>
                    <button type="submit" class="bg-primary hover:bg-secondary text-white px-5 py-2 rounded transition text-sm cursor-pointer">
                        保存设置
                    </button>
                </div>
            </div>

            <div class="p-6 space-y-6">
                <!-- 供应商选择 -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">AI 供应商</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3" id="providerGrid">
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
                    <p class="text-xs text-gray-400 mt-1" id="apiKeyHint">已保存的 Key 以掩码显示，输入新 Key 后保存即替换<?php echo $maskedApiKey ? '' : '，请填写对应供应商的 API Key'; ?></p>
                </div>

                <!-- 模型选择 -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">模型</label>
                    <input type="hidden" name="ai_model" id="aiModelInput" value="<?php echo e($currentModel); ?>">
                    <div id="aiModelGrid" class="flex flex-wrap gap-2"></div>
                    <p class="text-xs text-gray-400 mt-2">点击选择模型，蓝色高亮为当前选中</p>
                </div>

                <!-- 自定义 Base URL -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">自定义 API 地址 <span class="font-normal text-gray-400">（可选）</span></label>
                    <input type="text" name="ai_base_url" id="aiBaseUrl" value="<?php echo e($currentBaseUrl); ?>"
                           class="w-full border rounded-lg px-4 py-2.5 text-sm" placeholder="留空使用官方地址">
                    <p class="text-xs text-gray-400 mt-1">用于代理或私有化部署，如 <code>https://your-proxy.com/v1</code></p>
                </div>
            </div>
        </div>
    </form>

    <!-- 测试结果 -->
    <div id="testResult" class="hidden mt-4 px-4 py-3 rounded-lg text-sm"></div>

    <!-- 用量统计 -->
    <?php
    $logTable = DB_PREFIX . 'ai_logs';
    $hasLogTable = false;
    try { db()->fetchOne("SELECT 1 FROM {$logTable} LIMIT 1"); $hasLogTable = true; } catch (\Throwable $e) {}

    if ($hasLogTable):
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $todayStats = db()->fetchOne("SELECT COUNT(*) as calls, SUM(total_tokens) as tokens, SUM(success) as ok FROM {$logTable} WHERE created_at >= '{$today}'");
        $monthStats = db()->fetchOne("SELECT COUNT(*) as calls, SUM(total_tokens) as tokens, SUM(success) as ok FROM {$logTable} WHERE created_at >= '{$monthStart}'");
        $totalStats = db()->fetchOne("SELECT COUNT(*) as calls, SUM(total_tokens) as tokens, SUM(success) as ok FROM {$logTable}");
        $recentLogs = db()->fetchAll("SELECT * FROM {$logTable} ORDER BY id DESC LIMIT 10");
    ?>
    <div class="mt-6 bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800">用量统计</h2>
            <a href="/admin/ai_usage.php" class="text-sm text-primary hover:underline">用量详情 &raquo;</a>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-3 gap-4 mb-6">
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

            <?php if (!empty($recentLogs)): ?>
            <h4 class="text-sm font-medium text-gray-700 mb-2">最近调用</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left px-3 py-2 font-medium text-gray-500">时间</th>
                            <th class="text-left px-3 py-2 font-medium text-gray-500">供应商</th>
                            <th class="text-left px-3 py-2 font-medium text-gray-500">模型</th>
                            <th class="text-left px-3 py-2 font-medium text-gray-500">操作</th>
                            <th class="text-right px-3 py-2 font-medium text-gray-500">Tokens</th>
                            <th class="text-center px-3 py-2 font-medium text-gray-500">状态</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($recentLogs as $log): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2 text-gray-400 text-xs whitespace-nowrap"><?php echo $log['created_at']; ?></td>
                            <td class="px-3 py-2"><?php echo e($log['provider']); ?></td>
                            <td class="px-3 py-2 text-xs text-gray-500"><?php echo e($log['model']); ?></td>
                            <td class="px-3 py-2 text-xs"><?php echo e($log['action']); ?></td>
                            <td class="px-3 py-2 text-right font-mono text-xs"><?php echo number_format((int)$log['total_tokens']); ?></td>
                            <td class="px-3 py-2 text-center">
                                <?php if ($log['success']): ?>
                                <span class="inline-block w-2 h-2 rounded-full bg-green-400" title="成功"></span>
                                <?php else: ?>
                                <span class="inline-block w-2 h-2 rounded-full bg-red-400" title="<?php echo e($log['error_msg']); ?>"></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 供应商申请指南 -->
    <div class="mt-6 bg-gray-50 rounded-lg p-6 text-sm text-gray-500">
        <h3 class="font-medium text-gray-700 mb-3">API Key 获取方式</h3>
        <div class="space-y-2">
            <p><strong>OpenAI：</strong><a href="https://platform.openai.com/api-keys" target="_blank" class="text-primary hover:underline">platform.openai.com/api-keys</a></p>
            <p><strong>Claude：</strong><a href="https://console.anthropic.com/settings/keys" target="_blank" class="text-primary hover:underline">console.anthropic.com/settings/keys</a></p>
            <p><strong>DeepSeek：</strong><a href="https://platform.deepseek.com/api_keys" target="_blank" class="text-primary hover:underline">platform.deepseek.com/api_keys</a></p>
            <p><strong>通义千问：</strong><a href="https://dashscope.console.aliyun.com/apiKey" target="_blank" class="text-primary hover:underline">dashscope.console.aliyun.com/apiKey</a></p>
            <p><strong>智谱AI：</strong><a href="https://open.bigmodel.cn/usercenter/apikeys" target="_blank" class="text-primary hover:underline">open.bigmodel.cn/usercenter/apikeys</a></p>
        </div>
    </div>
</div>

<script>
var providers = <?php echo json_encode($providers, JSON_UNESCAPED_UNICODE); ?>;
var currentModel = <?php echo json_encode($currentModel); ?>;

function getSelectedProvider() {
    return document.querySelector('input[name="ai_provider"]:checked')?.value || 'openai';
}

function updateProviderStyle() {
    var selected = getSelectedProvider();
    document.querySelectorAll('.provider-card').forEach(function(card) {
        var name = card.querySelector('.provider-name');
        if (card.dataset.provider === selected) {
            card.className = 'provider-card border-2 rounded-lg p-3 text-center transition border-primary bg-blue-50 shadow-md';
            name.className = 'provider-name font-medium text-sm text-primary';
        } else {
            card.className = 'provider-card border-2 rounded-lg p-3 text-center transition border-gray-200 hover:border-gray-300';
            name.className = 'provider-name font-medium text-sm text-gray-800';
        }
    });
}

function selectModel(model) {
    document.getElementById('aiModelInput').value = model;
    document.querySelectorAll('#aiModelGrid .model-btn').forEach(function(btn) {
        if (btn.dataset.model === model) {
            btn.className = 'model-btn px-3 py-1.5 rounded-lg text-sm font-medium cursor-pointer transition border-2 border-primary bg-blue-50 text-primary';
        } else {
            btn.className = 'model-btn px-3 py-1.5 rounded-lg text-sm font-medium cursor-pointer transition border-2 border-gray-200 bg-white text-gray-600 hover:border-gray-300';
        }
    });
}

function onProviderChange() {
    var p = getSelectedProvider();
    var cfg = providers[p];
    var grid = document.getElementById('aiModelGrid');
    var selected = currentModel || '';
    grid.innerHTML = '';

    // 默认按钮
    var defBtn = document.createElement('button');
    defBtn.type = 'button';
    defBtn.dataset.model = '';
    defBtn.textContent = '默认 (' + cfg['default'] + ')';
    defBtn.onclick = function(){ selectModel(''); };
    grid.appendChild(defBtn);

    cfg.models.forEach(function(m) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.dataset.model = m;
        btn.textContent = m;
        btn.onclick = function(){ selectModel(m); };
        grid.appendChild(btn);
    });

    // 给所有按钮加 class 并高亮选中
    grid.querySelectorAll('button').forEach(function(btn) { btn.className = 'model-btn'; });
    selectModel(selected);
    updateProviderStyle();

    var hints = {
        'openai':   '获取地址：platform.openai.com/api-keys',
        'claude':   '获取地址：console.anthropic.com/settings/keys',
        'deepseek': '获取地址：platform.deepseek.com/api_keys',
        'qwen':     '获取地址：dashscope.console.aliyun.com/apiKey',
        'zhipu':    '获取地址：open.bigmodel.cn/usercenter/apikeys'
    };
    document.getElementById('apiKeyHint').textContent = hints[p] || '';
    document.getElementById('aiBaseUrl').placeholder = '留空使用：' + cfg.base_url;
}

function testConnection() {
    var btn = document.getElementById('testBtn');
    var result = document.getElementById('testResult');
    btn.disabled = true;
    btn.textContent = '测试中...';
    result.className = 'mt-4 px-4 py-3 rounded-lg text-sm bg-gray-50 text-gray-500';
    result.textContent = '正在连接 AI 服务...';
    result.classList.remove('hidden');

    var formData = new FormData(document.getElementById('aiForm'));
    formData.set('action', 'test');

    fetch('/admin/setting_ai.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            result.className = 'mt-4 px-4 py-3 rounded-lg text-sm bg-green-50 text-green-700 border border-green-200';
            result.textContent = '连接成功！AI 回复：' + data.content;
        } else {
            result.className = 'mt-4 px-4 py-3 rounded-lg text-sm bg-red-50 text-red-700 border border-red-200';
            result.textContent = '连接失败：' + data.error;
        }
    })
    .catch(function(e) {
        result.className = 'mt-4 px-4 py-3 rounded-lg text-sm bg-red-50 text-red-700 border border-red-200';
        result.textContent = '请求失败：' + e.message;
    })
    .finally(function() {
        btn.disabled = false;
        btn.textContent = '测试连接';
    });
}

// 初始化
onProviderChange();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
