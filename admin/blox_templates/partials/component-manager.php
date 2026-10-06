<?php
/**
 * YIKAI_BLOX_AI_ACCESS_NOTICE
 * AI-assisted reading or modification requires explicit task-scoped authorization.
 * Policy: docs/blox-commercialization/CORE-ACCESS.md
 * This collaboration notice is not access control and does not replace licenses.
 */
/* 组件管理（v2.1，模板页「组件」分类）：用量与使用位置、归档、修订恢复、全部脱离。数据走 admin/blox_component_api.php */
$componentManagerText = [
    'failed' => __('admin_failed'),
    'usedIn' => __('blox_component_used_in'),
    'notUsed' => __('blox_component_not_used'),
    'restoreConfirm' => __('blox_component_restore_confirm'),
    'restored' => __('blox_component_restored'),
    'detachAllConfirm' => __('blox_component_detach_all_confirm'),
    'detachAllDone' => __('blox_component_detach_all_done'),
];
?>
<section class="border-y border-gray-200 bg-white" data-testid="blox-component-manager"
         x-data="bloxComponentManager(<?php echo e(json_encode($componentManagerText, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT)); ?>)" x-init="load()">
    <div class="px-5 py-4 border-b border-gray-200 flex flex-wrap items-center gap-3">
        <div class="min-w-0 flex-1">
            <h2 class="font-medium text-gray-900 inline-flex items-center gap-1.5"><i class="ti ti-components text-violet-500"></i><?php echo e(__('blox_component_manager')); ?></h2>
            <p class="mt-1 text-xs text-gray-500"><?php echo e(__('blox_component_manager_hint')); ?></p>
        </div>
        <label class="inline-flex items-center gap-1.5 text-xs text-gray-600">
            <input type="checkbox" x-model="includeArchived" @change="load()"><?php echo e(__('blox_component_show_archived')); ?>
        </label>
    </div>
    <p x-show="error" x-text="error" class="px-5 py-3 text-sm text-red-600"></p>
    <div class="divide-y divide-gray-100">
        <template x-for="card in components" :key="card.uuid">
            <div class="px-5 py-3" :data-testid="'blox-component-row-' + card.uuid">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="font-medium text-gray-900" x-text="card.name"></span>
                    <span class="text-xs text-gray-400" x-text="'v' + card.version"></span>
                    <span x-show="card.archived" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500"><?php echo e(__('blox_component_archived')); ?></span>
                    <button type="button" class="text-xs text-blue-600 hover:underline" @click="toggleUsage(card)"
                            x-text="text.usedIn.replace(':docs', card.docs).replace(':n', card.refs)"></button>
                    <span class="ml-auto inline-flex items-center gap-3 text-xs">
                        <a :href="'blox_editor.php?template=' + card.id" class="text-blue-600 hover:underline"><?php echo e(__('blox_component_edit_master')); ?></a>
                        <button type="button" class="text-gray-600 hover:underline" @click="toggleRevisions(card)"><?php echo e(__('blox_component_revisions')); ?></button>
                        <button type="button" class="text-gray-600 hover:underline" :data-testid="'blox-component-archive-' + card.uuid"
                                @click="archive(card)" x-text="card.archived ? <?php echo e(json_encode(__('blox_component_unarchive'))); ?> : <?php echo e(json_encode(__('blox_component_archive'))); ?>"></button>
                    </span>
                </div>
                <template x-if="open[card.uuid] === 'usage'">
                    <ul class="mt-2 space-y-1 text-xs text-gray-600">
                        <template x-for="place in (usage[card.uuid] || [])" :key="place.doc_key">
                            <li><a x-show="place.edit_url" :href="place.edit_url" class="text-blue-600 hover:underline" x-text="place.label"></a>
                                <span x-show="!place.edit_url" x-text="place.label"></span></li>
                        </template>
                        <li x-show="usage[card.uuid] && usage[card.uuid].length === 0" class="text-gray-400" x-text="text.notUsed"></li>
                    </ul>
                </template>
                <template x-if="open[card.uuid] === 'revisions'">
                    <ul class="mt-2 space-y-1 text-xs text-gray-600">
                        <template x-for="rev in (revisions[card.uuid] || [])" :key="rev.version">
                            <li class="flex items-center gap-3">
                                <span class="font-mono" x-text="'v' + rev.version"></span>
                                <span class="text-gray-400" x-text="new Date(rev.created_at * 1000).toLocaleString()"></span>
                                <button type="button" x-show="rev.version !== card.version" class="text-blue-600 hover:underline" @click="restore(card, rev.version)"><?php echo e(__('blox_component_restore')); ?></button>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </template>
        <p x-show="loaded && components.length === 0" class="px-5 py-6 text-center text-sm text-gray-400"><?php echo e(__('blox_component_empty')); ?></p>
    </div>
    <?php if (hasPermission('*')): ?>
    <div class="px-5 py-4 border-t border-gray-200 bg-amber-50/50">
        <h3 class="text-sm font-medium text-amber-800"><?php echo e(__('blox_component_detach_all')); ?></h3>
        <p class="mt-1 text-xs leading-relaxed text-amber-700"><?php echo e(__('blox_component_detach_all_hint')); ?></p>
        <button type="button" @click="detachAll()" :disabled="busy" data-testid="blox-component-detach-all"
                class="mt-2 rounded border border-amber-300 bg-white px-3 py-1.5 text-xs text-amber-800 hover:bg-amber-100 disabled:opacity-50"><?php echo e(__('blox_component_detach_all')); ?></button>
    </div>
    <?php endif; ?>
</section>
<script>
function bloxComponentManager(text) {
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    function api(action, fields) {
        var body = new URLSearchParams(Object.assign({ action: action, _token: token }, fields || {}));
        return fetch((window.YK_BASE || '') + '/admin/blox_component_api.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || Number(res.code) !== 0) throw new Error((res && res.msg) || text.failed);
                return res.data || {};
            });
    }
    return {
        text: text, components: [], loaded: false, error: '', includeArchived: false, busy: false,
        open: {}, usage: {}, revisions: {},
        load: function () {
            var self = this;
            api('list', { include_archived: this.includeArchived ? '1' : '' })
                .then(function (data) { self.components = data.components || []; self.loaded = true; })
                .catch(function (e) { self.error = e.message; });
        },
        toggleUsage: function (card) {
            var self = this;
            if (this.open[card.uuid] === 'usage') { this.open[card.uuid] = ''; return; }
            this.open[card.uuid] = 'usage';
            api('usage', { uuid: card.uuid }).then(function (data) { self.usage[card.uuid] = data.places || []; })
                .catch(function (e) { self.error = e.message; });
        },
        toggleRevisions: function (card) {
            var self = this;
            if (this.open[card.uuid] === 'revisions') { this.open[card.uuid] = ''; return; }
            this.open[card.uuid] = 'revisions';
            api('revisions', { uuid: card.uuid }).then(function (data) { self.revisions[card.uuid] = data.revisions || []; })
                .catch(function (e) { self.error = e.message; });
        },
        restore: function (card, version) {
            if (!window.confirm(this.text.restoreConfirm.replace(':v', version))) return;
            var self = this;
            api('restore_revision', { uuid: card.uuid, version: version }).then(function (data) {
                window.alert(self.text.restored);
                window.location.href = data.editor_url;
            }).catch(function (e) { self.error = e.message; });
        },
        archive: function (card) {
            var self = this;
            api(card.archived ? 'unarchive' : 'archive', { uuid: card.uuid }).then(function () { self.load(); })
                .catch(function (e) { self.error = e.message; });
        },
        detachAll: function () {
            if (!window.confirm(this.text.detachAllConfirm)) return;
            var self = this;
            this.busy = true;
            api('detach_all').then(function (data) {
                window.alert(self.text.detachAllDone.replace(':docs', data.documents).replace(':n', data.instances));
                self.load();
            }).catch(function (e) { self.error = e.message; }).finally(function () { self.busy = false; });
        }
    };
}
</script>
