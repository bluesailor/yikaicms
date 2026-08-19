<?php
/**
 * YikaiCMS - 安全设置
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', 'save');

    // 清理日志
    if ($action === 'clear_logs') {
        verifyCsrf();
        $days = (int)post('days', 90);
        if ($days < 7) $days = 7;
        $before = time() - ($days * 86400);
        $deleted = adminLogModel()->clearBefore($before);
        adminLog('setting', 'clear_logs', '清理' . $days . '天前的日志，删除' . $deleted . '条');
        success([], str_replace(':n', (string) $deleted, __('sec_logs_cleaned')));
    }

    // 清理登录限流文件
    if ($action === 'clear_throttle') {
        verifyCsrf();
        $dir = STORAGE_PATH . '/login_throttle/';
        $count = 0;
        if (is_dir($dir)) {
            foreach (glob($dir . '*.json') as $f) {
                @unlink($f);
                $count++;
            }
        }
        $dir2 = STORAGE_PATH . '/form_throttle/';
        if (is_dir($dir2)) {
            foreach (glob($dir2 . '*.json') as $f) {
                @unlink($f);
                $count++;
            }
        }
        adminLog('setting', 'clear_throttle', '清除限流记录' . $count . '条');
        success([], str_replace(':n', (string) $count, __('sec_throttle_cleared')));
    }

    $settings = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];

    foreach (['trusted_proxies', 'admin_ip_whitelist'] as $ruleKey) {
        if (!array_key_exists($ruleKey, $settings)) {
            continue;
        }
        $parsed = ClientIpResolver::parseRules($settings[$ruleKey]);
        if ($parsed['invalid'] !== []) {
            error(__('sec_ip_rule_invalid', ['rule' => $parsed['invalid'][0]]));
        }
        $settings[$ruleKey] = implode("\n", $parsed['rules']);
    }

    // 同时按“将要保存”的代理配置解析当前请求，避免保存后把管理员自己锁在门外。
    if (array_key_exists('trusted_proxies', $settings) || array_key_exists('admin_ip_whitelist', $settings)) {
        $futureTrusted = $settings['trusted_proxies'] ?? config('trusted_proxies', '');
        $futureWhitelist = $settings['admin_ip_whitelist'] ?? config('admin_ip_whitelist', '');
        $futureClientIp = ClientIpResolver::resolve($_SERVER, $futureTrusted);
        if (!AdminIpPolicy::isAllowed($futureClientIp, $futureWhitelist)) {
            error(__('sec_ip_whitelist_lockout', ['ip' => $futureClientIp]));
        }
    }
    settingModel()->saveBatch($settings);

    adminLog('setting', 'update', '更新安全设置');
    success();
}

$tab = $_GET['tab'] ?? 'login';

// 获取当前设置
$secConfig = [
    'login_max_attempts'    => config('login_max_attempts', '5'),
    'login_lock_minutes'    => config('login_lock_minutes', '15'),
    'session_timeout'       => config('session_timeout', '30'),
    'trusted_proxies'       => config('trusted_proxies', ''),
    'admin_ip_whitelist'    => config('admin_ip_whitelist', ''),
    'upload_max_size_mb'    => config('upload_max_size_mb', '10'),
    'upload_image_types'    => config('upload_image_types', 'jpg,jpeg,png,gif,webp,svg'),
    'upload_file_types'     => config('upload_file_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z'),
    'form_max_submits'      => config('form_max_submits', '5'),
    'form_throttle_minutes' => config('form_throttle_minutes', '5'),
    'password_min_length'   => config('password_min_length', '6'),
];

// 登录记录
$loginLogs = [];
$loginPage = 1;
$loginTotal = 0;
$loginPerPage = 20;
if ($tab === 'login_logs') {
    $loginPage = max(1, getInt('page', 1));
    $loginOffset = ($loginPage - 1) * $loginPerPage;
    $loginTotal = (int)db()->fetchColumn(
        "SELECT COUNT(*) FROM " . DB_PREFIX . "admin_logs WHERE module = 'auth'");
    $loginLogs = db()->fetchAll(
        "SELECT * FROM " . DB_PREFIX . "admin_logs WHERE module = 'auth' ORDER BY id DESC LIMIT ? OFFSET ?",
        [$loginPerPage, $loginOffset]);
}

// 日志统计
$logTotal = 0;
$logOldest = '';
$throttleCount = 0;
if ($tab === 'logs') {
    $logTotal = (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "admin_logs");
    $oldest = db()->fetchColumn("SELECT MIN(created_at) FROM " . DB_PREFIX . "admin_logs");
    $logOldest = $oldest ? date('Y-m-d', (int)$oldest) : '-';

    // 统计限流文件数
    $dir1 = STORAGE_PATH . '/login_throttle/';
    $dir2 = STORAGE_PATH . '/form_throttle/';
    if (is_dir($dir1)) $throttleCount += count(glob($dir1 . '*.json'));
    if (is_dir($dir2)) $throttleCount += count(glob($dir2 . '*.json'));
}

$pageTitle = __('sec_title');
$currentMenu = 'setting_security';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b overflow-x-auto">
        <a href="/admin/setting_security.php" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'login' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('sec_login_security'); ?></a>
        <a href="/admin/setting_security.php?tab=login_logs" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'login_logs' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('sec_login_history'); ?></a>
        <a href="/admin/setting_security.php?tab=upload" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'upload' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('sec_upload_security'); ?></a>
        <a href="/admin/setting_security.php?tab=logs" class="px-6 py-3 text-sm font-medium border-b-2 whitespace-nowrap <?php echo $tab === 'logs' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('sec_log_management'); ?></a>
    </div>
</div>

<?php if ($tab === 'login'): ?>
<!-- ==================== 登录安全 ==================== -->
<form id="settingForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('sec_login_protection'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('sec_trusted_proxies')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo e(__('sec_trusted_proxies_tip')); ?></span>
                </label>
                <div class="md:col-span-3">
                    <textarea name="settings[trusted_proxies]" rows="4" data-testid="trusted-proxies-input"
                              placeholder="<?php echo e(__('sec_trusted_proxies_placeholder')); ?>"
                              class="w-full border rounded px-4 py-2 font-mono text-sm"><?php echo e($secConfig['trusted_proxies']); ?></textarea>
                    <div class="text-xs text-gray-400 mt-1">
                        <?php echo e(__('sec_trusted_proxies_hint', ['ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')])); ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('sec_max_attempts'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('sec_lock_ip_after'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[login_max_attempts]"
                           value="<?php echo e($secConfig['login_max_attempts']); ?>"
                           min="3" max="20"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1"><?php echo __('sec_max_attempts_hint'); ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('sec_lock_duration'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('sec_lock_duration_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[login_lock_minutes]"
                           value="<?php echo e($secConfig['login_lock_minutes']); ?>"
                           min="5" max="1440"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1"><?php echo __('sec_lock_duration_hint'); ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('sec_session_timeout'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('sec_session_timeout_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[session_timeout]"
                           value="<?php echo e($secConfig['session_timeout']); ?>"
                           min="5" max="480"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1"><?php echo __('sec_session_timeout_hint'); ?></div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('sec_password_min_length'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('sec_password_min_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[password_min_length]"
                           value="<?php echo e($secConfig['password_min_length']); ?>"
                           min="4" max="32"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1"><?php echo __('sec_password_min_hint'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('sec_access_control'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('sec_ip_whitelist'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('sec_ip_whitelist_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <textarea name="settings[admin_ip_whitelist]" rows="4" data-testid="admin-ip-whitelist-input"
                              placeholder="<?php echo e(__('sec_ip_whitelist_placeholder')); ?>"
                              class="w-full border rounded px-4 py-2 font-mono text-sm"><?php echo e($secConfig['admin_ip_whitelist']); ?></textarea>
                    <div class="text-xs text-gray-400 mt-1">
                        <?php echo __('sec_ip_whitelist_hint'); ?>
                        <strong class="text-orange-500"><?php echo __('sec_ip_whitelist_warn_prefix'); ?></strong><?php echo sprintf(__('sec_ip_whitelist_warn'), e(getClientIp())); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('sec_form_throttle'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('sec_max_submissions'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('sec_max_submissions_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[form_max_submits]"
                           value="<?php echo e($secConfig['form_max_submits']); ?>"
                           min="1" max="100"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('sec_time_window'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('sec_time_window_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[form_throttle_minutes]"
                           value="<?php echo e($secConfig['form_throttle_minutes']); ?>"
                           min="1" max="60"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1"><?php echo __('sec_form_throttle_hint'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" data-testid="security-settings-save" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition"><?php echo __('admin_save'); ?></button>
    </div>
</form>

<?php elseif ($tab === 'login_logs'): ?>
<!-- ==================== 登录记录 ==================== -->
<div class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo __('sec_login_history'); ?></h2>
            <span class="text-sm text-gray-400"><?php echo str_replace(':n', number_format($loginTotal), e(__('admin_total_n'))); ?></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 text-left">
                        <th class="px-6 py-3 font-medium text-gray-500"><?php echo __('admin_created_at'); ?></th>
                        <th class="px-6 py-3 font-medium text-gray-500"><?php echo e(__('admin_user')); ?></th>
                        <th class="px-6 py-3 font-medium text-gray-500"><?php echo __('admin_action'); ?></th>
                        <th class="px-6 py-3 font-medium text-gray-500"><?php echo e(__('sec_ip')); ?></th>
                        <th class="px-6 py-3 font-medium text-gray-500"><?php echo e(__('sec_browser')); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($loginLogs)): ?>
                    <tr><td colspan="5" class="px-6 py-8 text-center text-gray-400"><?php echo e(__('sec_no_logins')); ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($loginLogs as $log): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-3 whitespace-nowrap text-gray-600">
                            <?php echo date('Y-m-d H:i:s', (int)$log['created_at']); ?>
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap">
                            <span class="font-medium text-gray-800"><?php echo e($log['admin_name']); ?></span>
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap">
                            <?php
                            $action = $log['action'] ?? '';
                            $desc = $log['description'] ?? '';
                            if ($action === 'login'): ?>
                                <span class="inline-flex items-center gap-1 text-green-600">
                                    <i class="ti ti-check text-base"></i>
                                    <?php echo e(__('sec_login_ok')); ?>
                                </span>
                            <?php elseif ($action === 'logout'): ?>
                                <span class="inline-flex items-center gap-1 text-gray-500">
                                    <i class="ti ti-logout text-base"></i>
                                    <?php echo e(__('sec_logout')); ?>
                                </span>
                            <?php elseif ($action === 'login_fail'): ?>
                                <span class="inline-flex items-center gap-1 text-red-500">
                                    <i class="ti ti-x text-base"></i>
                                    <?php echo e(__('sec_login_fail')); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-gray-600"><?php echo e($desc ?: $action); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-3 whitespace-nowrap">
                            <code class="bg-gray-100 px-2 py-0.5 rounded text-xs"><?php echo e($log['ip']); ?></code>
                        </td>
                        <td class="px-6 py-3 text-gray-400 text-xs max-w-xs truncate" title="<?php echo e($log['user_agent']); ?>">
                            <?php
                            $ua = $log['user_agent'] ?? '';
                            // 简化 UA 显示
                            if (preg_match('/Chrome\/[\d.]+/', $ua, $m)) echo $m[0];
                            elseif (preg_match('/Firefox\/[\d.]+/', $ua, $m)) echo $m[0];
                            elseif (preg_match('/Safari\/[\d.]+/', $ua, $m)) echo $m[0];
                            elseif (preg_match('/Edge\/[\d.]+/', $ua, $m)) echo $m[0];
                            else echo mb_substr($ua, 0, 30);
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($loginTotal > $loginPerPage): ?>
        <div class="px-6 py-4 border-t flex items-center justify-between">
            <div class="text-sm text-gray-500">
                <?php echo str_replace([':p', ':t'], [(string) $loginPage, (string) (int) ceil($loginTotal / $loginPerPage)], e(__('admin_page_of'))); ?>
            </div>
            <div class="flex gap-2">
                <?php if ($loginPage > 1): ?>
                <a href="?tab=login_logs&page=<?php echo $loginPage - 1; ?>" class="px-3 py-1 border rounded text-sm hover:bg-gray-50"><?php echo __('list_prev_page'); ?></a>
                <?php endif; ?>
                <?php if ($loginPage * $loginPerPage < $loginTotal): ?>
                <a href="?tab=login_logs&page=<?php echo $loginPage + 1; ?>" class="px-3 py-1 border rounded text-sm hover:bg-gray-50"><?php echo __('list_next_page'); ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'upload'): ?>
<!-- ==================== 上传安全 ==================== -->
<form id="settingForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('sec_upload_limits')); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('sec_max_filesize')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo e(__('sec_max_filesize_tip')); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[upload_max_size_mb]"
                           value="<?php echo e($secConfig['upload_max_size_mb']); ?>"
                           min="1" max="100"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">
                        <?php echo str_replace([':upload', ':post'], [(string) ini_get('upload_max_filesize'), (string) ini_get('post_max_size')], e(__('sec_max_filesize_note'))); ?>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('sec_image_types')); ?>
                    <span class="text-gray-400 text-sm block">英文逗号分隔</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[upload_image_types]"
                           value="<?php echo e($secConfig['upload_image_types']); ?>"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">默认：jpg,jpeg,png,gif,webp,svg</div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e(__('sec_file_types')); ?>
                    <span class="text-gray-400 text-sm block">英文逗号分隔</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[upload_file_types]"
                           value="<?php echo e($secConfig['upload_file_types']); ?>"
                           class="w-full border rounded px-4 py-2">
                    <div class="text-xs text-gray-400 mt-1">默认：pdf,doc,docx,xls,xlsx,ppt,pptx,zip,rar,7z</div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('sec_mechanisms')); ?></h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <h4 class="font-bold text-green-700 mb-2"><?php echo e(__('sec_enabled_protections')); ?></h4>
                    <ul class="text-green-600 space-y-1">
                        <li>&#10003; 文件扩展名白名单校验</li>
                        <li>&#10003; MIME 类型验证（防伪造扩展名）</li>
                        <li>&#10003; 图片文件 getimagesize 验证</li>
                        <li>&#10003; 上传文件自动随机重命名</li>
                        <li>&#10003; uploads 目录禁止执行 PHP</li>
                    </ul>
                </div>
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <h4 class="font-bold text-blue-700 mb-2">全站安全特性</h4>
                    <ul class="text-blue-600 space-y-1">
                        <li>&#10003; PDO 预处理语句防 SQL 注入</li>
                        <li>&#10003; CSRF Token 防跨站请求伪造</li>
                        <li>&#10003; XSS 过滤（htmlspecialchars）</li>
                        <li>&#10003; HttpOnly + SameSite Cookie</li>
                        <li>&#10003; X-Frame-Options 防点击劫持</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition"><?php echo __('admin_save'); ?></button>
    </div>
</form>

<?php elseif ($tab === 'logs'): ?>
<!-- ==================== 日志管理 ==================== -->
<div class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('sec_log_stats')); ?></h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-primary"><?php echo number_format($logTotal); ?></div>
                    <div class="text-gray-500 text-sm mt-1"><?php echo e(__('sec_log_total')); ?></div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-gray-700"><?php echo $logOldest; ?></div>
                    <div class="text-gray-500 text-sm mt-1"><?php echo e(__('sec_log_earliest')); ?></div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-orange-500"><?php echo $throttleCount; ?></div>
                    <div class="text-gray-500 text-sm mt-1"><?php echo e(__('sec_throttle_files')); ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('sec_log_cleanup')); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                <label class="text-gray-700">
                    <?php echo e(__('sec_clean_logs')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo e(__('sec_clean_logs_tip')); ?></span>
                </label>
                <div class="md:col-span-3 flex items-center gap-4">
                    <span class="text-gray-500"><?php echo e(__('sec_clean_prefix')); ?></span>
                    <select id="clearDays" class="border rounded px-4 py-2">
                        <option value="90"><?php echo str_replace(':n', '90', e(__('sec_n_days'))); ?></option>
                        <option value="60"><?php echo str_replace(':n', '60', e(__('sec_n_days'))); ?></option>
                        <option value="30"><?php echo str_replace(':n', '30', e(__('sec_n_days'))); ?></option>
                        <option value="7"><?php echo str_replace(':n', '7', e(__('sec_n_days'))); ?></option>
                    </select>
                    <span class="text-gray-500"><?php echo e(__('sec_clean_suffix')); ?></span>
                    <button type="button" onclick="clearLogs()"
                            class="border border-red-300 text-red-600 hover:bg-red-500 hover:border-red-500 hover:text-white px-6 py-2 rounded transition">
                        <?php echo e(__('sec_run_cleanup')); ?>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                <label class="text-gray-700">
                    <?php echo e(__('sec_clear_throttle')); ?>
                    <span class="text-gray-400 text-sm block"><?php echo e(__('sec_throttle_tip')); ?></span>
                </label>
                <div class="md:col-span-3">
                    <button type="button" onclick="clearThrottle()"
                            class="border border-gray-300 text-gray-700 hover:bg-gray-100 px-6 py-2 rounded transition">
                        <?php echo str_replace(':n', (string) $throttleCount, e(__('sec_clear_all_throttle'))); ?>
                    </button>
                    <span class="text-xs text-gray-400 ml-2"><?php echo e(__('sec_throttle_unlock_tip')); ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <a href="/admin/system.php?tab=log" class="text-primary hover:underline">
            <?php echo e(__('sec_view_logs')); ?> &rarr;
        </a>
    </div>
</div>

<?php endif; ?>

<script>
document.getElementById('settingForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage('<?php echo __('admin_saved'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
});

// 清理日志
async function clearLogs() {
    const days = document.getElementById('clearDays')?.value || 90;
    if (!confirm(<?php echo json_encode(__('sec_clean_confirm'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', days))) return;

    const formData = new FormData();
    formData.append('action', 'clear_logs');
    formData.append('days', days);
    formData.append('<?php echo CSRF_TOKEN_NAME; ?>', '<?php echo csrfToken(); ?>');
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage(data.msg);
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

// 清除限流记录
async function clearThrottle() {
    if (!confirm(<?php echo json_encode(__('sec_clear_throttle_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;

    const formData = new FormData();
    formData.append('action', 'clear_throttle');
    formData.append('<?php echo CSRF_TOKEN_NAME; ?>', '<?php echo csrfToken(); ?>');
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage(data.msg);
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
