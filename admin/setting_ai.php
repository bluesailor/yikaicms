<?php
/**
 * YikaiCMS - AI 设置
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
if (!class_exists('AiService')) {
    require_once ROOT_PATH . '/includes/AiService.php';
}
require_once ROOT_PATH . '/includes/AiProfiles.php';

checkLogin();
requirePermission('*');

$currentMenu = 'setting_ai';
$pageTitle = __('ai_setting_title');
$providers = AiService::getProviders();

// 保存 / 测试 / 多套配置管理（2.1.2）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? 'save';
    $profileId = (int) ($_POST['profile_id'] ?? 0);
    // 配置操作的错误以语言键抛出，这里统一转成提示（不弹原生对话框，页面用 showMessage 显示）
    $profileAction = static function (callable $run, string $okKey, string $log) use ($profileId): void {
        try {
            $id = $run();
            adminLog('setting', 'ai_profile', $log . '#' . (is_int($id) && $id > 0 ? $id : $profileId));
            echo json_encode(['code' => 0, 'msg' => __($okKey), 'id' => is_int($id) && $id > 0 ? $id : $profileId]);
        } catch (InvalidArgumentException $e) {
            echo json_encode(['code' => 1, 'msg' => __($e->getMessage())]);
        }
        exit;
    };

    if ($action === 'save') {
        $profileAction(static fn(): int => AiProfiles::save($profileId, [
            'name' => (string) ($_POST['profile_name'] ?? ''),
            'provider' => (string) ($_POST['ai_provider'] ?? ''),
            'base_url' => (string) ($_POST['ai_base_url'] ?? ''),
            'model' => (string) ($_POST['ai_model'] ?? ''),
            'translation_model' => (string) ($_POST['ai_translation_model'] ?? ''),
        ], (string) ($_POST['ai_api_key'] ?? '')), 'ai_profile_saved', '保存 AI 配置');
    }
    if ($action === 'set_primary') {
        $profileAction(static fn() => AiProfiles::setActive($profileId), 'ai_profile_primary_set', '设为主 API');
    }
    if ($action === 'archive') {
        $profileAction(static fn() => AiProfiles::archive($profileId), 'ai_profile_archived_done', '归档 AI 配置');
    }
    if ($action === 'restore') {
        $profileAction(static fn() => AiProfiles::restore($profileId), 'ai_profile_restored', '恢复 AI 配置');
    }
    if ($action === 'delete') {
        $profileAction(static fn() => AiProfiles::delete($profileId), 'ai_profile_deleted', '删除 AI 配置');
    }

    if ($action === 'sync_models') {
        $url = 'https://update.yikaicms.com/api/ai-models.php';
        $json = '';
        $errMsg = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'YikaiCMS-AI-Sync',
            ]);
            $json = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errMsg = curl_error($ch);
            curl_close($ch);
            if ($json === '' || $code >= 400) {
                echo json_encode(['code' => 1, 'msg' => str_replace(':err', $errMsg ?: ('HTTP ' . $code), __('ai_sync_fetch_failed'))]);
                exit;
            }
        } else {
            $json = (string) @file_get_contents($url);
            if ($json === '') { echo json_encode(['code' => 1, 'msg' => __('ai_sync_no_http')]); exit; }
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data['providers']) || !is_array($data['providers'])) {
            echo json_encode(['code' => 1, 'msg' => __('ai_sync_bad_source')]);
            exit;
        }
        settingModel()->set('ai_models_override', json_encode($data['providers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        settingModel()->set('ai_models_synced_at', (string) time());
        $cnt = count($data['providers']);
        adminLog('setting', 'ai_sync', "同步AI模型：{$cnt} 个厂商");
        echo json_encode(['code' => 0, 'msg' => str_replace(':n', (string) $cnt, __('ai_sync_done')), 'updated_at' => $data['updated_at'] ?? '']);
        exit;
    }

    if ($action === 'test') {
        // 测试的是表单当前内容，不保存、不改主 API。密钥留空或是脱敏值时用「正在编辑的这套」已存密钥，
        // 新建配置没有已存密钥，不会借用主 API 的密钥。
        $testKey = (string) ($_POST['ai_api_key'] ?? '');
        if ($testKey === '' || str_contains($testKey, '***')) {
            $testKey = $profileId > 0 ? AiProfiles::plainKey($profileId) : '';
        }
        $testProvider = (string) ($_POST['ai_provider'] ?? '');
        if (!array_key_exists($testProvider, $providers)) {
            echo json_encode(['success' => false, 'content' => '', 'error' => __('ai_profile_bad_provider')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($testKey === '' && $testProvider !== 'yikai') {
            echo json_encode(['success' => false, 'content' => '', 'error' => __('ai_profile_test_no_key')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $testAi = new AiService($testProvider, $testKey, (string) ($_POST['ai_model'] ?? ''), trim((string) ($_POST['ai_base_url'] ?? '')));
        $result = $testAi->chat('Reply "OK".', 'You are a test assistant. Just reply with what the user asks.', 0.1);
        echo json_encode(['success' => (bool) $result['success'], 'content' => (string) $result['content'], 'error' => (string) $result['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 配置列表（只给页面脱敏视图，密文不进浏览器）与正在编辑的那一套
$profiles = array_map([AiProfiles::class, 'publicView'], AiProfiles::all());
$primaryId = AiProfiles::activeId();
$editParam = (string) ($_GET['profile'] ?? '');
$editing = null;
if ($editParam !== 'new') {
    $wantId = $editParam !== '' ? (int) $editParam : $primaryId;
    foreach ($profiles as $p) {
        if ($p['id'] === $wantId) $editing = $p;
    }
}
$editingId = $editing['id'] ?? 0;
$editingIsPrimary = $editingId > 0 && $editingId === $primaryId;
$currentProvider = (string) ($editing['provider'] ?? '');
if (!array_key_exists($currentProvider, $providers)) $currentProvider = $editing === null ? 'deepseek' : $currentProvider;
// 只露末四位（统一密钥存储约定）；含 *** 的值提交时视为「不修改」
$maskedApiKey = (string) ($editing['key_masked'] ?? '');
$currentModel = (string) ($editing['model'] ?? '');
$currentBaseUrl = (string) ($editing['base_url'] ?? '');
$currentTranslationModel = (string) ($editing['translation_model'] ?? '');
$activeProfiles = array_values(array_filter($profiles, static fn(array $p): bool => $p['status'] === AiProfiles::STATUS_ACTIVE));
$archivedProfiles = array_values(array_filter($profiles, static fn(array $p): bool => $p['status'] === AiProfiles::STATUS_ARCHIVED));
$profileMeta = static function (array $p) use ($providers): string {
    $parts = [(string) ($providers[$p['provider']]['name'] ?? $p['provider'])];
    $parts[] = $p['model'] !== '' ? $p['model'] : __('ai_profile_default_model');
    if ($p['translation_model'] !== '') $parts[] = str_replace(':model', $p['translation_model'], __('ai_profile_translation_model'));
    if ($p['base_url'] !== '') $parts[] = (string) (parse_url($p['base_url'], PHP_URL_HOST) ?: $p['base_url']);
    return implode(' · ', $parts);
};

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div>
    <?php /* 多套 API 配置：列表 + 主 API（2.1.2） */ ?>
    <div class="bg-white rounded-lg shadow mb-6" data-testid="ai-profiles">
        <div class="px-6 py-4 border-b flex items-start justify-between gap-4">
            <div>
                <h2 class="font-bold text-gray-800"><?php echo e(__('ai_profiles_title')); ?></h2>
                <p class="text-sm text-gray-500 mt-1"><?php echo e(__('ai_profiles_desc')); ?></p>
            </div>
            <a href="?profile=new" class="shrink-0 border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded transition text-sm inline-flex items-center gap-1" data-testid="ai-profile-add">
                <i class="ti ti-plus text-base" aria-hidden="true"></i><?php echo e(__('ai_profile_add')); ?></a>
        </div>
        <?php if ($profiles === []): ?>
        <p class="px-6 py-5 text-sm text-gray-500"><?php echo e(__('ai_profile_empty')); ?></p>
        <?php else: ?>
        <ul class="divide-y" data-testid="ai-profile-list">
            <?php foreach ($activeProfiles as $p): $isPrimary = $p['id'] === $primaryId; $isEditing = $p['id'] === $editingId; ?>
            <li class="px-6 py-4 flex flex-wrap items-center gap-x-4 gap-y-2 <?php echo $isEditing ? 'bg-blue-50/60' : ''; ?>" data-profile-id="<?php echo $p['id']; ?>" data-testid="ai-profile-row">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-medium text-gray-800"><?php echo e($p['name']); ?></span>
                        <?php if ($isPrimary): ?><span class="text-xs px-2 py-0.5 rounded-full bg-primary text-white" data-testid="ai-profile-primary-badge"><?php echo e(__('ai_profile_primary')); ?></span><?php endif; ?>
                        <?php if ($isEditing): ?><span class="text-xs text-primary"><?php echo e(__('ai_profile_editing')); ?></span><?php endif; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-1 break-all"><?php echo e($profileMeta($p)); ?></p>
                    <p class="text-xs mt-0.5 <?php echo $p['key_masked'] !== '' ? 'text-gray-400 font-mono' : 'text-amber-600'; ?>"><?php echo e($p['key_masked'] !== '' ? $p['key_masked'] : __('ai_profile_no_key')); ?></p>
                </div>
                <div class="flex items-center gap-2 text-sm">
                    <a href="?profile=<?php echo $p['id']; ?>" class="text-primary hover:underline"><?php echo e(__('ai_profile_edit')); ?></a>
                    <?php if (!$isPrimary): ?>
                    <button type="button" class="border border-primary text-primary hover:bg-blue-50 px-3 py-1 rounded cursor-pointer" onclick="profileAction('set_primary', <?php echo $p['id']; ?>, this)" data-testid="ai-profile-set-primary"><?php echo e(__('ai_profile_set_primary')); ?></button>
                    <button type="button" class="text-gray-500 hover:text-gray-700 px-2 py-1 cursor-pointer" onclick="profileAction('archive', <?php echo $p['id']; ?>, this)"><?php echo e(__('ai_profile_archive')); ?></button>
                    <?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($archivedProfiles !== []): ?>
        <details class="border-t" data-testid="ai-profile-archived">
            <summary class="px-6 py-3 text-sm text-gray-500 cursor-pointer select-none"><?php echo e(str_replace(':n', (string) count($archivedProfiles), __('ai_profile_archived_group'))); ?></summary>
            <ul class="divide-y bg-gray-50">
                <?php foreach ($archivedProfiles as $p): ?>
                <li class="px-6 py-3 flex flex-wrap items-center gap-x-4 gap-y-2" data-profile-id="<?php echo $p['id']; ?>">
                    <div class="min-w-0 flex-1">
                        <span class="text-gray-600"><?php echo e($p['name']); ?></span>
                        <p class="text-xs text-gray-400 mt-1 break-all"><?php echo e($profileMeta($p)); ?></p>
                    </div>
                    <div class="flex items-center gap-2 text-sm">
                        <button type="button" class="text-primary hover:underline cursor-pointer" onclick="profileAction('restore', <?php echo $p['id']; ?>, this)"><?php echo e(__('ai_profile_restore')); ?></button>
                        <button type="button" class="text-red-600 hover:underline cursor-pointer" data-confirm-label="<?php echo e(__('ai_profile_delete_confirm')); ?>" onclick="profileDelete(<?php echo $p['id']; ?>, this)"><?php echo e(__('ai_profile_delete')); ?></button>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <form id="aiForm">
        <input type="hidden" name="profile_id" value="<?php echo $editingId; ?>">
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-gray-800" data-testid="ai-profile-form-title"><?php echo e($editing === null ? __('ai_profile_new_title') : str_replace(':name', (string) $editing['name'], __('ai_profile_edit_title'))); ?></h2>
                    <p class="text-sm text-gray-500 mt-1"><?php echo e($editing === null ? ($profiles === [] ? __('ai_config_desc') : __('ai_profile_other_note')) : ($editingIsPrimary ? __('ai_profile_primary_note') : __('ai_profile_other_note'))); ?></p>
                </div>
                <div class="flex gap-2">
                    <button type="button" id="testBtn" onclick="testAiConn()" class="border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded transition text-sm cursor-pointer" title="<?php echo e(__('ai_profile_test_hint')); ?>"><?php echo __('ai_test_connection'); ?></button>
                    <button type="button" onclick="saveAiSettings()" class="bg-primary hover:bg-secondary text-white px-5 py-2 rounded transition text-sm cursor-pointer" data-testid="ai-profile-save"><?php echo __('btn_save_settings'); ?></button>
                </div>
            </div>
            <div class="p-6 space-y-6">
                <div>
                    <label for="aiProfileName" class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('ai_profile_name')); ?></label>
                    <input type="text" name="profile_name" id="aiProfileName" maxlength="60" value="<?php echo e((string) ($editing['name'] ?? '')); ?>"
                           class="w-full border rounded-lg px-4 py-2.5 text-sm" placeholder="<?php echo e(__('ai_profile_name_ph')); ?>">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('ai_provider_label'); ?></label>
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
                <div id="apiKeyField">
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('ai_api_key'); ?></label>
                    <input type="text" name="ai_api_key" id="aiApiKey" value="<?php echo e($maskedApiKey); ?>"
                           class="w-full border rounded-lg px-4 py-2.5 text-sm font-mono tracking-wide" placeholder="sk-..."
                           onfocus="if(this.value.indexOf('***')!==-1){this.value='';this.style.color=''}">
                    <p class="text-xs text-gray-400 mt-1"><?php echo $maskedApiKey ? __('ai_key_saved') : __('ai_api_key_hint'); ?></p>
                    <?php if (!SecretStore::available()): // 2.1.1：加密不可用时明说会存明文，原因在站点健康里 ?>
                    <p class="text-xs text-amber-700 mt-1" data-testid="secret-store-plaintext-notice"><i class="ti ti-alert-triangle" aria-hidden="true"></i> <?php echo e(__('secret_store_plaintext_notice')); ?></p>
                    <?php endif; ?>
                </div>
                <?php // 官方接口用授权码调用，无需填 Key；这里显示授权状态代替输入框 ?>
                <div id="yikaiKeyInfo" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo __('ai_official_credential'); ?></label>
                    <?php
                    $_licOk  = function_exists('license_valid') && license_valid();
                    $_licKey = function_exists('license_key') ? license_key() : '';
                    ?>
                    <div class="border rounded-lg px-4 py-2.5 text-sm bg-gray-50 flex items-center gap-2">
                        <i class="ti <?php echo $_licOk ? 'ti-circle-check text-green-600' : 'ti-alert-circle text-amber-600'; ?>"></i>
                        <span class="<?php echo $_licOk ? 'text-green-700' : 'text-amber-700'; ?>">
                            <?php echo $_licKey !== '' ? e(substr($_licKey, 0, 8)) . '••••' : __('ai_official_no_license'); ?>
                        </span>
                        <a href="/admin/license.php" class="ml-auto text-primary hover:underline text-xs"><?php echo __('admin_license'); ?> &raquo;</a>
                    </div>
                    <p class="text-xs text-gray-400 mt-1"><?php echo __('ai_official_hint'); ?></p>
                </div>
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium text-gray-700"><?php echo __('ai_model_label'); ?></label>
                        <div class="flex items-center gap-2">
                            <?php $syncedAt = (int) config('ai_models_synced_at', 0); ?>
                            <span class="text-xs text-gray-400"><?php echo $syncedAt ? str_replace(':time', date('Y-m-d H:i', $syncedAt), __('ai_synced_at')) : __('ai_never_synced'); ?></span>
                            <button type="button" onclick="syncModels(this)" class="text-xs border rounded px-3 py-1.5 text-gray-600 hover:bg-gray-50 inline-flex items-center gap-1">
                                <i class="ti ti-refresh text-sm"></i>
                                <?php echo e(__('ai_sync_models')); ?>
                            </button>
                        </div>
                    </div>
                    <input type="text" name="ai_model" id="aiModelInput" value="<?php echo e($currentModel); ?>" aria-label="<?php echo e(__('ai_model_label')); ?>" class="w-full border rounded-lg px-4 py-2.5 text-sm" oninput="selectModel(this.value)">
                    <div id="aiModelGrid" class="flex flex-wrap gap-2"></div>
                </div>
                <div>
                    <label for="aiTranslationModel" class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('ai_translation_model_label')); ?></label>
                    <input type="text" name="ai_translation_model" id="aiTranslationModel" value="<?php echo e($currentTranslationModel); ?>" class="w-full border rounded-lg px-4 py-2.5 text-sm">
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('ai_translation_model_hint')); ?></p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__('ai_custom_base_url')); ?> <span class="font-normal text-gray-400"><?php echo e(__('admin_optional_paren')); ?></span></label>
                    <input type="text" name="ai_base_url" id="aiBaseUrl" value="<?php echo e($currentBaseUrl); ?>"
                           class="w-full border rounded-lg px-4 py-2.5 text-sm" placeholder="<?php echo e(__('ai_base_url_ph')); ?>">
                </div>
            </div>
        </div>
    </form>

    <div id="testResult" class="hidden mt-4 px-4 py-3 rounded-lg text-sm"></div>

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
            <h2 class="font-bold text-gray-800"><?php echo __('ai_usage_stats'); ?></h2>
            <a href="/admin/ai_usage.php" class="text-sm text-primary hover:underline"><?php echo e(__('aiu_title')); ?> &raquo;</a>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-blue-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-blue-600"><?php echo number_format((int)($todayStats['calls'] ?? 0)); ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo __('ai_today_calls'); ?></p>
                    <p class="text-xs text-blue-400"><?php echo number_format((int)($todayStats['tokens'] ?? 0)); ?> tokens</p>
                </div>
                <div class="bg-green-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-green-600"><?php echo number_format((int)($monthStats['calls'] ?? 0)); ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo __('ai_month_calls'); ?></p>
                    <p class="text-xs text-green-400"><?php echo number_format((int)($monthStats['tokens'] ?? 0)); ?> tokens</p>
                </div>
                <div class="bg-purple-50 rounded-lg p-4 text-center">
                    <p class="text-2xl font-bold text-purple-600"><?php echo number_format((int)($totalStats['calls'] ?? 0)); ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?php echo __('ai_total_calls'); ?></p>
                    <p class="text-xs text-purple-400"><?php echo number_format((int)($totalStats['tokens'] ?? 0)); ?> tokens</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="mt-6 bg-gray-50 rounded-lg p-6 text-sm text-gray-500">
        <h3 class="font-medium text-gray-700 mb-3"><?php echo __('ai_get_key'); ?></h3>
        <div class="space-y-2">
            <p><strong>OpenAI：</strong><a href="https://platform.openai.com/api-keys" target="_blank" class="text-primary hover:underline">platform.openai.com</a></p>
            <p><strong>Claude：</strong><a href="https://console.anthropic.com/settings/keys" target="_blank" class="text-primary hover:underline">console.anthropic.com</a></p>
            <p><strong>DeepSeek：</strong><a href="https://platform.deepseek.com/api_keys" target="_blank" class="text-primary hover:underline">platform.deepseek.com</a></p>
            <p><strong><?php echo e(__('ai_provider_qwen')); ?></strong><a href="https://dashscope.console.aliyun.com/apiKey" target="_blank" class="text-primary hover:underline">dashscope.console.aliyun.com</a></p>
            <p><strong><?php echo e(__('ai_provider_zhipu')); ?></strong><a href="https://open.bigmodel.cn/usercenter/apikeys" target="_blank" class="text-primary hover:underline">open.bigmodel.cn</a></p>
        </div>
    </div>
</div>

<script>
var providers = <?php echo json_encode($providers, JSON_UNESCAPED_UNICODE); ?>;
var currentModel = <?php echo json_encode($currentModel); ?>;
var previousProvider = null;
var modelDrafts = Object.create(null);

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
    if (!cfg) return;
    if (previousProvider !== null) modelDrafts[previousProvider] = document.getElementById('aiModelInput').value;
    var selectedModel = previousProvider === null ? currentModel : (modelDrafts[p] || '');
    previousProvider = p;
    var grid = document.getElementById('aiModelGrid'); grid.innerHTML = '';
    var defBtn = document.createElement('button'); defBtn.type = 'button'; defBtn.dataset.model = '';
    defBtn.textContent = <?php echo json_encode(__('ai_default_model'), JSON_UNESCAPED_UNICODE); ?>.replace(':name', cfg['default']); defBtn.onclick = function(){ selectModel(''); }; grid.appendChild(defBtn);
    cfg.models.slice(0, 4).forEach(function(m) {
        var b = document.createElement('button'); b.type = 'button'; b.dataset.model = m;
        b.textContent = m; b.onclick = function(){ selectModel(m); }; grid.appendChild(b);
    });
    grid.querySelectorAll('button').forEach(function(b){ b.className = 'model-btn'; });
    selectModel(selectedModel); updateProviderStyle();
    document.getElementById('aiBaseUrl').placeholder = <?php echo json_encode(__('ai_base_url_default'), JSON_UNESCAPED_UNICODE); ?>.replace(':url', cfg.base_url);
}
onProviderChange();

function saveAiSettings() {
    var fd = new FormData(document.getElementById('aiForm')); fd.append('action', 'save');
    fetch(location.pathname, { method: 'POST', body: fd }).then(safeJson).then(function(d){
        showMessage(d.msg || <?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE); ?>, d.code === 0 ? 'success' : 'error');
        // 保存后回到这套配置，列表里的模型、密钥状态随之刷新
        if (d.code === 0 && d.id) setTimeout(function(){ location.href = location.pathname + '?profile=' + d.id; }, 600);
    }).catch(function(){ showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error'); });
}

// 设为主 API / 归档 / 恢复 / 删除：结果用页内提示，不弹原生确认框
function profileAction(action, id, btn) {
    if (btn) btn.disabled = true;
    var fd = new FormData(); fd.append('action', action); fd.append('profile_id', id);
    fetch(location.pathname, { method: 'POST', body: fd }).then(safeJson).then(function(d){
        showMessage(d.msg, d.code === 0 ? 'success' : 'error');
        if (d.code === 0) setTimeout(function(){ location.reload(); }, 600);
        else if (btn) btn.disabled = false;
    }).catch(function(){ showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error'); if (btn) btn.disabled = false; });
}
// 删除只对已归档的配置开放，按钮第一次点击只换成「再点一次删除」，4 秒内再点才提交
function profileDelete(id, btn) {
    if (btn.dataset.armed === '1') { profileAction('delete', id, btn); return; }
    btn.dataset.armed = '1'; btn.dataset.label = btn.textContent; btn.textContent = btn.dataset.confirmLabel; btn.classList.add('font-semibold');
    setTimeout(function(){ if (!btn.disabled) { btn.dataset.armed = ''; btn.textContent = btn.dataset.label; btn.classList.remove('font-semibold'); } }, 4000);
}

function testAiConn() {
    var btn = document.getElementById('testBtn'), result = document.getElementById('testResult');
    btn.disabled = true; btn.textContent = <?php echo json_encode(__('ai_testing'), JSON_UNESCAPED_UNICODE); ?>;
    result.className = 'mt-4 px-4 py-3 rounded-lg text-sm bg-gray-50 text-gray-500';
    result.textContent = <?php echo json_encode(__('ai_connecting'), JSON_UNESCAPED_UNICODE); ?>; result.classList.remove('hidden');
    var fd = new FormData(document.getElementById('aiForm')); fd.append('action', 'test');
    fetch('', { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
        result.className = 'mt-4 px-4 py-3 rounded-lg text-sm ' + (d.success ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200');
        result.textContent = d.success ? <?php echo json_encode(__('ai_conn_ok'), JSON_UNESCAPED_UNICODE); ?>.replace(':reply', d.content) : <?php echo json_encode(__('ai_conn_fail'), JSON_UNESCAPED_UNICODE); ?>.replace(':err', d.error);
    }).catch(function(){ result.textContent = <?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>; }).finally(function(){ btn.disabled = false; btn.textContent = '<?php echo __('ai_test_connection'); ?>'; });
}

// 从 update.yikaicms 同步最新模型清单
function syncModels(btn) {
    var old = btn.innerHTML;
    btn.disabled = true; btn.textContent = <?php echo json_encode(__('ai_syncing'), JSON_UNESCAPED_UNICODE); ?>;
    var fd = new FormData(); fd.append('action', 'sync_models');
    fetch('', { method: 'POST', body: fd }).then(function(r){ return r.json(); }).then(function(d){
        if (d.code === 0) {
            showMessage(d.msg + (d.updated_at ? <?php echo json_encode(__('ai_sync_source_at'), JSON_UNESCAPED_UNICODE); ?>.replace(':time', d.updated_at) : ''));
            setTimeout(function(){ location.reload(); }, 800);
        } else {
            showMessage(d.msg || <?php echo json_encode(__('ai_sync_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            btn.disabled = false; btn.innerHTML = old;
        }
    }).catch(function(){ showMessage(<?php echo json_encode(__('ai_sync_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error'); btn.disabled = false; btn.innerHTML = old; });
}

// 官方接口不需要 API Key：切到 yikai 时隐藏 Key 输入、显示授权状态
(function () {
    var selectors = document.querySelectorAll('[name="ai_provider"]');
    if (!selectors.length) return;
    function sync() {
        var isOfficial = getProvider() === 'yikai';
        var kf = document.getElementById('apiKeyField');
        var yi = document.getElementById('yikaiKeyInfo');
        if (kf) kf.classList.toggle('hidden', isOfficial);
        if (yi) yi.classList.toggle('hidden', !isOfficial);
    }
    selectors.forEach(function(sel) { sel.addEventListener('change', sync); });
    sync();
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
