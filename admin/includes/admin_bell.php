<?php
/**
 * 后台右上角提醒铃铛（2.0.6）：原控制台顶部的提醒卡片收在这里（AdminNotices），每页可见；只读本地状态，不请求外网。
 * 由 admin/includes/header.php 在顶栏引入。关闭沿用各提醒原有的处理动作。
 */
if (!defined('ROOT_PATH') || !function_exists('hasPermission') || !hasPermission('*')) {
    return;
}
require_once ROOT_PATH . '/includes/AdminNotices.php';
$__notices = AdminNotices::collect();
$__bellInit = [
    'count' => count($__notices),
    'token' => csrfToken(),
    'text' => [
        'failed' => __('admin_request_failed'),
        'mailDone' => __('upgrade_mail_prompt_done'),
        'mailInvalid' => __('upgrade_mail_invalid'),
        'updateTitle' => __('notice_update_title'),
        'updateGo' => __('dashboard_update_go'),
    ],
];
?>
<div class="relative" x-data="adminBell(<?php echo e(json_encode($__bellInit, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>)" @keydown.escape.window="open = false" @yk-update-found.window="addUpdate($event.detail)" data-testid="admin-bell">
    <button type="button" @click="open = !open" :aria-expanded="open.toString()" aria-haspopup="true"
            data-testid="admin-bell-button"
            class="relative flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-50 hover:text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
            :aria-label="count ? <?php echo e(json_encode(__('notice_bell_count'), JSON_UNESCAPED_UNICODE)); ?>.replace(':n', count) : <?php echo e(json_encode(__('notice_bell'), JSON_UNESCAPED_UNICODE)); ?>"
            title="<?php echo e(__('notice_bell')); ?>">
        <i class="ti ti-bell text-xl" aria-hidden="true"></i>
        <span x-show="count > 0" x-cloak x-text="count > 9 ? '9+' : count" data-testid="admin-bell-count"
              class="absolute -right-0.5 -top-0.5 inline-flex min-w-[18px] items-center justify-center rounded-full bg-red-500 px-1 text-[10px] font-semibold leading-[18px] text-white"></span>
    </button>
    <div x-show="open" x-cloak @click.away="open = false" data-testid="admin-bell-panel"
         class="absolute right-0 z-50 mt-2 w-[22rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-lg bg-white shadow-lg ring-1 ring-gray-200">
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5">
            <span class="text-sm font-semibold text-gray-800"><?php echo e(__('notice_bell')); ?></span>
            <a href="/admin/upgrade_online.php" class="text-xs text-gray-500 hover:text-primary">
                <?php echo e(__('notice_current_version', ['version' => defined('CMS_VERSION') ? CMS_VERSION : '?'])); ?>
            </a><?php echo adminLocalBuildBadge(); ?>
        </div>
        <ul x-ref="list" class="max-h-[70vh] divide-y divide-gray-100 overflow-y-auto">
            <?php foreach ($__notices as $__n): ?>
            <li class="px-4 py-3" data-notice="<?php echo e($__n['id']); ?>" data-testid="admin-notice-<?php echo e($__n['id']); ?>">
                <div class="flex items-start gap-3">
                    <i class="ti ti-<?php echo e($__n['icon']); ?> mt-0.5 text-lg <?php echo $__n['critical'] ? 'text-red-500' : 'text-amber-500'; ?>" aria-hidden="true"></i>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-800"><?php echo e($__n['title']); ?></p>
                        <?php if ($__n['body'] !== ''): ?><p class="mt-0.5 text-xs leading-5 text-gray-500"><?php echo e($__n['body']); ?></p><?php endif; ?>
                        <?php if ($__n['form'] === 'mail'): ?>
                        <form class="mt-2 flex gap-2" @submit.prevent="subscribe($event)">
                            <input type="email" name="email" value="<?php echo e($__n['email']); ?>" required maxlength="254" autocomplete="email" aria-label="<?php echo e(__('upgrade_mail_email')); ?>"
                                   class="min-w-0 flex-1 rounded border border-gray-300 px-2 py-1 text-xs">
                            <button type="submit" class="shrink-0 rounded bg-primary px-3 py-1 text-xs font-medium text-white hover:opacity-90"><?php echo e(__('upgrade_mail_subscribe')); ?></button>
                        </form>
                        <?php endif; ?>
                        <div class="mt-1.5 flex items-center gap-4 text-xs">
                            <?php if ($__n['url'] !== ''): ?>
                            <a href="<?php echo e($__n['url']); ?>" class="font-medium text-primary hover:underline"<?php echo $__n['external'] ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>><?php echo e($__n['action']); ?></a>
                            <?php endif; ?>
                            <?php if ($__n['dismiss'] !== null): ?>
                            <button type="button" class="text-gray-400 hover:text-gray-600 hover:underline"
                                    data-testid="admin-notice-dismiss-<?php echo e($__n['id']); ?>"
                                    @click="dismiss($event, <?php echo e(json_encode($__n['dismiss'], JSON_UNESCAPED_SLASHES)); ?>)"><?php echo e(__('notice_dismiss')); ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <p x-show="count === 0" class="px-4 py-6 text-center text-sm text-gray-400" data-testid="admin-bell-empty"><?php echo e(__('notice_empty')); ?></p>
    </div>
</div>
<script>
function adminBell(init) {
    return {
        open: false, count: init.count, token: init.token, text: init.text,
        post: async function (endpoint, fields) {
            var body = new FormData();
            body.set('_token', this.token);
            Object.keys(fields).forEach(function (k) { body.set(k, fields[k]); });
            var response = await fetch((window.YK_BASE || '') + endpoint, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            var result = await response.json();
            if (!response.ok || Number(result.code) !== 0) throw new Error(result.msg || this.text.failed);
            return result;
        },
        // 控制台后台检查发现新版本：铃铛当场加一条（服务端也已记下，下次页面加载照常显示）
        addUpdate: function (detail) {
            if (!detail || !detail.version || this.$refs.list.querySelector('[data-notice="update"]')) return;
            var li = document.createElement('li');
            li.className = 'px-4 py-3';
            li.setAttribute('data-notice', 'update');
            li.setAttribute('data-testid', 'admin-notice-update');
            var row = document.createElement('div');
            row.className = 'flex items-start gap-3';
            var icon = document.createElement('i');
            icon.className = 'ti ti-cloud-download mt-0.5 text-lg text-amber-500';
            icon.setAttribute('aria-hidden', 'true');
            var body = document.createElement('div');
            body.className = 'min-w-0 flex-1';
            var title = document.createElement('p');
            title.className = 'text-sm font-medium text-gray-800';
            title.textContent = this.text.updateTitle.replace(':version', detail.version);
            var link = document.createElement('a');
            link.className = 'mt-1.5 inline-block text-xs font-medium text-primary hover:underline';
            link.href = (window.YK_BASE || '') + '/admin/upgrade_online.php';
            link.textContent = this.text.updateGo;
            body.appendChild(title);
            body.appendChild(link);
            row.appendChild(icon);
            row.appendChild(body);
            li.appendChild(row);
            var firstNormal = Array.prototype.find.call(this.$refs.list.children, function (el) { return !el.querySelector('.text-red-500'); });
            this.$refs.list.insertBefore(li, firstNormal || null);
            this.count++;
        },
        remove: function (el) {
            var item = el.closest('[data-notice]');
            if (item) item.remove();
            this.count = Math.max(0, this.count - 1);
        },
        dismiss: async function (event, spec) {
            var button = event.currentTarget;
            button.disabled = true;
            try {
                await this.post(spec.endpoint, { action: spec.action });
                this.remove(button);
            } catch (error) {
                button.disabled = false;
                if (typeof showMessage === 'function') showMessage(this.text.failed, 'error');
            }
        },
        subscribe: async function (event) {
            var form = event.target;
            try {
                var result = await this.post('/admin/upgrade.php', { action: 'save_update_mail', subscribe: '1', email: form.email.value });
                if (typeof showMessage === 'function') showMessage(result.msg || this.text.mailDone);
                this.remove(form);
            } catch (error) {
                if (typeof showMessage === 'function') showMessage(error.message || this.text.mailInvalid, 'error');
            }
        }
    };
}
</script>
