<?php
/**
 * Yikai CMS - AI 外部访问（MCP 令牌）
 *
 * 让 Claude Code、Codex 等外部 AI 客户端用访问令牌读取本站文章（v2.2 S2：只读）。
 * 与「AI 设置」（本站调用外部模型的供应商密钥）、「开放接口」（公开内容 API Key）是三套不同的凭据。
 * 有 ai_access 权限的账号管理自己的令牌；总开关与连接配置只有超管能动；超管可撤销任何人的令牌。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/ai-access/AiAccessBootstrapConfig.php';

// 启用/重新准备连接配置时，必须在加载私有配置之前记录它的指纹（ADR-001）。这里只读文件，不做任何写入；
// 登录、CSRF、超管校验仍在下面完成，未通过就不会写桥接。
$preparing = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['action'] ?? '', ['enable', 'prepare'], true);
if ($preparing) {
    AiAccessBootstrapConfig::beginPreparation(ROOT_PATH);
}

require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/ai-access/AiAccessTokenStore.php';
require_once ROOT_PATH . '/includes/ai-access/AiAccessLog.php';

checkLogin();
requirePermission('ai_access');

$currentMenu = 'ai_access';
$pageTitle = __('aiax_title');
$adminId = getAdminId();
$isSuper = isSuperAdmin();
$tablesReady = db()->tableExists('api_tokens') && db()->tableExists('api_access_log');
$store = new AiAccessTokenStore(db());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = (string) ($_POST['action'] ?? '');
    $reply = static function (int $code, string $msg, array $data = []): never {
        echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!$tablesReady) $reply(1, __('aiax_need_upgrade'));

    if ($action === 'enable' || $action === 'prepare') {
        requirePermission('*');
        try {
            AiAccessBootstrapConfig::prepare(ROOT_PATH);
        } catch (RuntimeException $e) {
            $reply(1, __('aiax_prepare_failed', ['code' => $e->getMessage()]));
        }
        if ($action === 'enable') settingModel()->set('ai_access_enabled', '1', 'ai_access');
        adminLog('setting', 'ai_access', $action === 'enable' ? 'AI external access enabled' : 'AI external access bridge prepared');
        $reply(0, $action === 'enable' ? __('aiax_enabled_msg') : __('aiax_prepared_msg'));
    }

    if ($action === 'disable') {
        requirePermission('*');
        settingModel()->set('ai_access_enabled', '0', 'ai_access');
        adminLog('setting', 'ai_access', 'AI external access disabled');
        $reply(0, __('aiax_disabled_msg'));
    }

    if ($action === 'issue') {
        $scopes = $_POST['scopes'] ?? [];
        try {
            $issued = $store->issue($adminId, (string) ($_POST['name'] ?? ''), is_array($scopes) ? array_values($scopes) : [], (int) ($_POST['days'] ?? AiAccessTokenStore::DEFAULT_DAYS), time());
        } catch (RuntimeException $e) {
            $reply(1, match ($e->getMessage()) {
                'ai_token_limit_reached' => __('aiax_err_limit', ['n' => AiAccessTokenStore::MAX_ACTIVE_PER_USER]),
                'ai_token_invalid_scope' => __('aiax_err_scope'),
                default => __('aiax_err_input'),
            });
        }
        adminLog('ai_access', 'issue', 'AI access token issued: #' . $issued['id']);
        // 明文只在这一次响应里出现：不写日志、不进 URL、不存浏览器存储。
        $reply(0, __('aiax_issued_msg'), ['secret' => $issued['secret'], 'expires_at' => date('Y-m-d H:i', $issued['expires_at'])]);
    }

    if ($action === 'revoke') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!$store->revoke($id, $adminId, $isSuper, time())) $reply(1, __('aiax_err_revoke'));
        adminLog('ai_access', 'revoke', 'AI access token revoked: #' . $id);
        $reply(0, __('aiax_revoked_msg'));
    }

    $reply(1, 'unknown action');
}

$enabled = config('ai_access_enabled', '0') === '1';
$bridge = 'missing';
if (is_file(ROOT_PATH . '/storage/security/ai-access/bootstrap.json')) {
    try {
        AiAccessBootstrapConfig::read(ROOT_PATH);
        $bridge = 'ok';
    } catch (RuntimeException) {
        $bridge = 'stale';
    }
}
$base = rtrim((string) (config('site_url', '') ?: (defined('SITE_URL') ? SITE_URL : '')), '/');
if ($base === '' && is_string($_SERVER['HTTP_HOST'] ?? null) && $_SERVER['HTTP_HOST'] !== '') {
    // 没填站点地址时按当前后台访问地址拼出完整网址：客户端配置里不能是相对路径 /mcp/。
    $base = (SessionStorage::isHttps($_SERVER) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . BasePath::get();
}
$endpoint = $base . '/mcp/';
$https = str_starts_with(strtolower($base), 'https://');
$mine = $tablesReady ? $store->listTokens($adminId) : [];
$everyone = $tablesReady && $isSuper ? array_values(array_filter($store->listTokens(null), static fn(array $t): bool => (int) $t['admin_id'] !== $adminId)) : [];
$logs = [];
if ($tablesReady) {
    $sql = 'SELECT l.created_at, l.admin_id, u.username, l.client, l.tool, l.target, l.http_status, l.result, l.ip FROM ' . DB_PREFIX . 'api_access_log l'
        . ' LEFT JOIN ' . DB_PREFIX . 'users u ON u.id = l.admin_id';
    $logs = $isSuper
        ? db()->fetchAll($sql . ' ORDER BY l.id DESC LIMIT 30')
        : db()->fetchAll($sql . ' WHERE l.admin_id = ? ORDER BY l.id DESC LIMIT 30', [$adminId]);
}
$now = time();
$tokenState = static fn(array $t): string => (int) $t['revoked_at'] > 0 ? 'revoked' : ((int) $t['expires_at'] <= $now ? 'expired' : 'active');
$scopeLabel = static fn(string $scope): string => __('aiax_scope_' . $scope);

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6 max-w-6xl">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><?php echo e(__('aiax_title')); ?></h1>
        <p class="text-sm text-gray-500 mt-1 max-w-3xl"><?php echo e(__('aiax_intro')); ?></p>
    </div>

    <?php if (!$tablesReady): ?>
    <div class="bg-amber-50 border-l-4 border-amber-400 text-amber-800 text-sm px-4 py-3 mb-6" role="alert"><?php echo e(__('aiax_need_upgrade')); ?></div>
    <?php else: ?>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4" data-testid="aiax-status">
            <div class="text-xs text-gray-500 mb-1"><?php echo e(__('aiax_status')); ?></div>
            <div class="text-xl font-bold <?php echo $enabled ? 'text-green-600' : 'text-gray-400'; ?>"><?php echo e($enabled ? __('aiax_on') : __('aiax_off')); ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4" data-testid="aiax-bridge">
            <div class="text-xs text-gray-500 mb-1"><?php echo e(__('aiax_bridge')); ?></div>
            <div class="text-xl font-bold <?php echo $bridge === 'ok' ? 'text-gray-800' : 'text-amber-600'; ?>"><?php echo e(__('aiax_bridge_' . $bridge)); ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4 min-w-0">
            <div class="text-xs text-gray-500 mb-1"><?php echo e(__('aiax_endpoint')); ?></div>
            <code class="block text-sm text-gray-800 break-all" data-testid="aiax-endpoint"><?php echo e($endpoint); ?></code>
            <?php if (!$https): ?>
            <div class="text-xs text-amber-700 mt-1"><?php echo e(__('aiax_https_warning')); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isSuper): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="font-medium text-gray-800"><?php echo e(__('aiax_switch_title')); ?></div>
        <p class="text-sm text-gray-500 mt-1 mb-4 max-w-3xl"><?php echo e(__('aiax_switch_desc')); ?></p>
        <div class="flex flex-wrap gap-2">
            <?php if ($enabled): ?>
            <button type="button" onclick="aiaxPost('disable')" class="border border-gray-300 hover:bg-gray-50 px-4 py-2 rounded text-red-600"><?php echo e(__('aiax_disable')); ?></button>
            <button type="button" onclick="aiaxPost('prepare')" class="border border-gray-300 hover:bg-gray-50 px-4 py-2 rounded"><?php echo e(__('aiax_reprepare')); ?></button>
            <?php else: ?>
            <button type="button" onclick="aiaxPost('enable')" class="bg-primary hover:opacity-90 text-white px-4 py-2 rounded"><?php echo e(__('aiax_enable')); ?></button>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="font-medium text-gray-800"><?php echo e(__('aiax_new_title')); ?></div>
        <p class="text-sm text-gray-500 mt-1 mb-4 max-w-3xl"><?php echo e(__('aiax_new_desc')); ?></p>
        <form id="aiaxIssue" onsubmit="aiaxIssue(event)" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-w-2xl">
                <label class="block">
                    <span class="block text-sm text-gray-700 mb-1"><?php echo e(__('aiax_name')); ?></span>
                    <input type="text" name="name" maxlength="100" required placeholder="<?php echo e(__('aiax_name_hint')); ?>" class="w-full border rounded px-3 py-2">
                </label>
                <label class="block">
                    <span class="block text-sm text-gray-700 mb-1"><?php echo e(__('aiax_days')); ?></span>
                    <select name="days" class="w-full border rounded px-3 py-2">
                        <?php foreach ([7, 30, 60, 90] as $days): ?>
                        <option value="<?php echo $days; ?>"<?php echo $days === AiAccessTokenStore::DEFAULT_DAYS ? ' selected' : ''; ?>><?php echo e(__('aiax_days_n', ['n' => $days])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <fieldset>
                <legend class="text-sm text-gray-700 mb-1"><?php echo e(__('aiax_scopes')); ?></legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-w-2xl">
                    <?php foreach (AiAccessTokenStore::ISSUABLE_SCOPES as $scope): ?>
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" name="scopes[]" value="<?php echo e($scope); ?>"<?php echo in_array($scope, AiAccessTokenStore::DEFAULT_SCOPES, true) ? ' checked' : ''; ?> class="rounded border-gray-300 text-primary focus:ring-primary">
                        <span><?php echo e($scopeLabel($scope)); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <p class="text-xs text-gray-400 mt-2"><?php echo e(__('aiax_scopes_note')); ?></p>
            </fieldset>
            <button type="submit" class="bg-primary hover:opacity-90 text-white px-5 py-2 rounded"><?php echo e(__('aiax_issue')); ?></button>
        </form>

        <div id="aiaxSecret" class="hidden mt-6 border border-green-200 bg-green-50 rounded p-4" data-testid="aiax-secret" role="status">
            <div class="font-medium text-green-800"><?php echo e(__('aiax_secret_title')); ?></div>
            <p class="text-sm text-green-800 mt-1"><?php echo e(__('aiax_secret_once')); ?></p>
            <div class="flex gap-2 mt-3">
                <input id="aiaxSecretValue" type="text" readonly class="flex-1 min-w-0 border rounded px-3 py-2 bg-white font-mono text-sm">
                <button type="button" onclick="aiaxCopy('aiaxSecretValue')" class="border border-gray-300 bg-white hover:bg-gray-50 px-3 py-2 rounded text-sm"><?php echo e(__('aiax_copy')); ?></button>
            </div>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mt-4">
                <div>
                    <div class="text-xs text-gray-600 mb-1"><?php echo e(__('aiax_cfg_claude')); ?></div>
                    <pre id="aiaxCfgClaude" class="text-xs bg-white border rounded p-3 overflow-x-auto"></pre>
                </div>
                <div>
                    <div class="text-xs text-gray-600 mb-1"><?php echo e(__('aiax_cfg_codex')); ?></div>
                    <pre id="aiaxCfgCodex" class="text-xs bg-white border rounded p-3 overflow-x-auto"></pre>
                </div>
            </div>
            <p class="text-xs text-gray-500 mt-2"><?php echo e(__('aiax_cfg_env')); ?></p>
        </div>
    </div>

    <?php
    $tables = [['title' => __('aiax_mine'), 'rows' => $mine, 'owner' => false, 'testid' => 'aiax-mine']];
    if ($isSuper) $tables[] = ['title' => __('aiax_others'), 'rows' => $everyone, 'owner' => true, 'testid' => 'aiax-others'];
    ?>
    <?php foreach ($tables as $table): ?>
    <div class="bg-white rounded-lg shadow p-6 mb-6" data-testid="<?php echo e($table['testid']); ?>">
        <div class="font-medium text-gray-800 mb-3"><?php echo e($table['title']); ?></div>
        <?php if ($table['rows'] === []): ?>
        <p class="text-sm text-gray-400"><?php echo e(__('aiax_none')); ?></p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-400 border-b">
                    <?php if ($table['owner']): ?><th class="py-2 pr-4"><?php echo e(__('aiax_col_owner')); ?></th><?php endif; ?>
                    <th class="py-2 pr-4"><?php echo e(__('aiax_name')); ?></th>
                    <th class="py-2 pr-4"><?php echo e(__('aiax_col_prefix')); ?></th>
                    <th class="py-2 pr-4"><?php echo e(__('aiax_scopes')); ?></th>
                    <th class="py-2 pr-4"><?php echo e(__('aiax_col_expires')); ?></th>
                    <th class="py-2 pr-4"><?php echo e(__('aiax_col_used')); ?></th>
                    <th class="py-2 pr-4"><?php echo e(__('aiax_col_state')); ?></th>
                    <th class="py-2"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($table['rows'] as $token): $state = $tokenState($token); ?>
                    <tr class="border-b last:border-0 align-top">
                        <?php if ($table['owner']): ?><td class="py-2 pr-4 text-gray-700"><?php echo e((string) ($token['username'] ?? ('#' . $token['admin_id']))); ?></td><?php endif; ?>
                        <td class="py-2 pr-4 text-gray-800"><?php echo e((string) $token['name']); ?></td>
                        <td class="py-2 pr-4 font-mono text-gray-500"><?php echo e((string) $token['token_prefix']); ?>…</td>
                        <td class="py-2 pr-4 text-gray-600"><?php echo e(implode(', ', array_map($scopeLabel, $token['scopes']))); ?></td>
                        <td class="py-2 pr-4 text-gray-600 whitespace-nowrap"><?php echo e(date('Y-m-d', (int) $token['expires_at'])); ?></td>
                        <td class="py-2 pr-4 text-gray-600 whitespace-nowrap"><?php echo (int) $token['last_used_at'] > 0 ? e(date('Y-m-d H:i', (int) $token['last_used_at']) . ' · ' . $token['last_used_ip']) : e(__('aiax_never')); ?></td>
                        <td class="py-2 pr-4 whitespace-nowrap <?php echo $state === 'active' ? 'text-green-700' : 'text-gray-400'; ?>"><?php echo e(__('aiax_state_' . $state)); ?></td>
                        <td class="py-2 text-right whitespace-nowrap">
                            <?php if ($state !== 'revoked'): ?>
                            <button type="button" class="aiax-revoke text-red-600 hover:underline" data-id="<?php echo (int) $token['id']; ?>" onclick="aiaxRevoke(this)"><?php echo e(__('aiax_revoke')); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="bg-white rounded-lg shadow p-6" data-testid="aiax-log">
        <div class="font-medium text-gray-800"><?php echo e(__('aiax_log_title')); ?></div>
        <p class="text-sm text-gray-500 mt-1 mb-3"><?php echo e(__('aiax_log_desc', ['days' => AiAccessLog::KEEP_DAYS])); ?></p>
        <?php if ($logs === []): ?>
        <p class="text-sm text-gray-400"><?php echo e(__('aiax_none')); ?></p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-400 border-b">
                    <th class="py-2 pr-4"><?php echo e(__('aiax_col_time')); ?></th>
                    <th class="py-2 pr-4"><?php echo e(__('aiax_col_owner')); ?></th>
                    <th class="py-2 pr-4"><?php echo e(__('aiax_col_client')); ?></th>
                    <th class="py-2 pr-4"><?php echo e(__('aiax_col_tool')); ?></th>
                    <th class="py-2 pr-4"><?php echo e(__('aiax_col_result')); ?></th>
                    <th class="py-2">IP</th>
                </tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr class="border-b last:border-0">
                        <td class="py-2 pr-4 text-gray-600 whitespace-nowrap"><?php echo e(date('m-d H:i:s', (int) $log['created_at'])); ?></td>
                        <td class="py-2 pr-4 text-gray-700"><?php echo e((string) ($log['username'] ?? '—')); ?></td>
                        <td class="py-2 pr-4 text-gray-600"><?php echo e((string) $log['client']); ?></td>
                        <td class="py-2 pr-4 font-mono text-gray-700"><?php echo e(trim($log['tool'] . ' ' . $log['target'])); ?></td>
                        <td class="py-2 pr-4 <?php echo (int) $log['http_status'] === 200 && $log['result'] === 'ok' ? 'text-green-700' : 'text-amber-700'; ?>"><?php echo e($log['http_status'] . ' ' . $log['result']); ?></td>
                        <td class="py-2 text-gray-500"><?php echo e((string) $log['ip']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
const AIAX_ENDPOINT = <?php echo json_encode($endpoint, JSON_UNESCAPED_SLASHES); ?>;
async function aiaxSend(fd) {
    const r = await fetch('', { method: 'POST', body: fd });
    return r.json();
}
async function aiaxPost(action) {
    const fd = new FormData(); fd.append('action', action);
    const d = await aiaxSend(fd);
    showMessage(d.msg || 'OK', d.code === 0 ? 'success' : 'error');
    if (d.code === 0) setTimeout(() => location.reload(), 600);
}
async function aiaxIssue(event) {
    event.preventDefault();
    const fd = new FormData(event.target); fd.append('action', 'issue');
    const d = await aiaxSend(fd);
    showMessage(d.msg || 'OK', d.code === 0 ? 'success' : 'error');
    if (d.code !== 0) return;
    // 明文只放在页面内存与只读输入框里，不写 localStorage/URL。
    document.getElementById('aiaxSecretValue').value = d.data.secret;
    document.getElementById('aiaxCfgClaude').textContent = JSON.stringify({ mcpServers: { yikaicms: { type: 'http', url: AIAX_ENDPOINT, headers: { Authorization: 'Bearer ${YIKAI_MCP_TOKEN}' } } } }, null, 2);
    document.getElementById('aiaxCfgCodex').textContent = '[mcp_servers.yikaicms]\nurl = "' + AIAX_ENDPOINT + '"\nbearer_token_env_var = "YIKAI_MCP_TOKEN"';
    document.getElementById('aiaxSecret').classList.remove('hidden');
    event.target.reset();
}
async function aiaxRevoke(button) {
    // 两步确认：第一次点击只把按钮变成「确认撤销」，不弹原生对话框。
    if (button.dataset.armed !== '1') {
        button.dataset.armed = '1';
        button.textContent = <?php echo json_encode(__('aiax_revoke_confirm'), JSON_UNESCAPED_UNICODE); ?>;
        return;
    }
    const fd = new FormData(); fd.append('action', 'revoke'); fd.append('id', button.dataset.id);
    const d = await aiaxSend(fd);
    showMessage(d.msg || 'OK', d.code === 0 ? 'success' : 'error');
    if (d.code === 0) setTimeout(() => location.reload(), 600);
}
function aiaxCopy(id) {
    const input = document.getElementById(id);
    input.select();
    navigator.clipboard?.writeText(input.value);
    showMessage(<?php echo json_encode(__('aiax_copied'), JSON_UNESCAPED_UNICODE); ?>, 'success');
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
