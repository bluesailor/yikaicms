<?php
/**
 * 产品导入 2.0：导入包向导（选包 → 预览校验 → 分批导入 / 撤销）。接口见 package_handler.php。
 */

if (!defined('ROOT_PATH')) exit;

$pimpText = array_filter((array) ($GLOBALS['_LANG_DATA'] ?? []), static fn ($key): bool => str_starts_with((string) $key, 'pimp_'), ARRAY_FILTER_USE_KEY);
$pimpBytes = static function (string $value): int {
    $value = trim($value);
    $number = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 ** 3, 'm' => $number * 1024 ** 2, 'k' => $number * 1024, default => $number,
    };
};
$pimpLimits = array_filter([$pimpBytes((string) ini_get('upload_max_filesize')), $pimpBytes((string) ini_get('post_max_size'))]);
$pimpConfig = [
    'csrf' => function_exists('csrfToken') ? csrfToken() : '',
    'text' => $pimpText,
    'uploadLimit' => $pimpLimits === [] ? 0 : min($pimpLimits),
    'lang' => (string) config('site_lang', 'zh-CN'),
    'languages' => enabledLanguages(),
    'job' => preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/D', (string) ($_GET['job'] ?? '')) ? (string) $_GET['job'] : '',
];
?>
<div x-data="pimpWizard(<?php echo e(json_encode($pimpConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)); ?>)" class="max-w-6xl space-y-6" data-pimp-wizard>

    <?php /* 步骤 */ ?>
    <ol class="flex items-center gap-3 text-sm">
        <template x-for="(s, i) in ['choose', 'preview', 'run']" :key="s">
            <li class="flex items-center gap-2" :class="step === s ? 'text-primary font-medium' : 'text-gray-400'">
                <span class="w-6 h-6 rounded-full inline-flex items-center justify-center text-xs border-2"
                      :class="step === s ? 'border-primary bg-primary text-white' : 'border-gray-300'" x-text="i + 1"></span>
                <span x-text="t('pimp_step_' + s)"></span>
                <span x-show="i < 2" class="w-8 h-px bg-gray-200"></span>
            </li>
        </template>
    </ol>

    <div x-show="error" x-cloak class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 text-sm" data-pimp-error x-text="error"></div>

    <?php /* ① 选择导入包 */ ?>
    <section x-show="step === 'choose'" class="bg-white rounded-lg shadow p-6 space-y-5">
        <p class="text-sm text-gray-600" x-text="t('pimp_choose_help')"></p>
        <p class="text-xs text-gray-500" x-text="t('pimp_table_columns')"></p>

        <div class="flex flex-wrap items-center gap-3">
            <label class="text-sm text-gray-700" for="pimpLang" x-text="t('pimp_lang')"></label>
            <select id="pimpLang" x-model="lang" class="border rounded px-3 py-1.5 text-sm">
                <template x-for="(name, code) in languages" :key="code">
                    <option :value="code" x-text="name + ' (' + code + ')'"></option>
                </template>
            </select>
        </div>

        <div class="border-2 border-dashed rounded-lg p-10 text-center transition cursor-pointer"
             :class="dragOver ? 'border-primary bg-blue-50' : 'border-gray-300 hover:border-gray-400'"
             @dragover.prevent="dragOver = true" @dragleave="dragOver = false"
             @drop.prevent="dragOver = false; upload($event.dataTransfer.files[0])"
             @click="$refs.pimpFile.click()">
            <input type="file" x-ref="pimpFile" class="hidden" accept=".zip,.xlsx,.csv,.json" data-pimp-file
                   @change="upload($event.target.files[0]); $event.target.value = ''">
            <i class="ti ti-package text-4xl text-gray-400"></i>
            <p class="text-gray-600 mt-2" x-text="busy ? busy : t('pimp_drop_hint')"></p>
            <p class="text-xs text-gray-400 mt-1" x-show="uploadLimit > 0"
               x-text="t('pimp_upload_limit', { size: size(uploadLimit), dir: incomingDir })"></p>
        </div>

        <div class="space-y-2">
            <div class="flex items-center justify-between">
                <h3 class="font-medium text-gray-800 text-sm" x-text="t('pimp_incoming_title')"></h3>
                <button type="button" class="text-sm text-primary" @click="loadIncoming()"><i class="ti ti-refresh"></i> <span x-text="t('pimp_refresh')"></span></button>
            </div>
            <p x-show="incoming.length === 0" class="text-sm text-gray-400" x-text="t('pimp_incoming_empty', { dir: incomingDir })"></p>
            <ul class="divide-y border rounded" x-show="incoming.length > 0">
                <template x-for="item in incoming" :key="item.name">
                    <li class="flex items-center justify-between px-4 py-2 text-sm">
                        <span class="flex items-center gap-2">
                            <i class="ti" :class="item.type === 'zip' ? 'ti-file-zip' : 'ti-folder'"></i>
                            <span x-text="item.name"></span>
                            <span class="text-xs text-gray-400" x-text="date(item.modified)"></span>
                        </span>
                        <button type="button" class="text-primary hover:underline disabled:opacity-50" :disabled="!!busy"
                                @click="useIncoming(item.name)" x-text="t('pimp_use')"></button>
                    </li>
                </template>
            </ul>
        </div>

        <details class="border rounded-lg" @toggle="$event.target.open && loadCategories()" data-pimp-export>
            <summary class="px-4 py-3 cursor-pointer font-medium text-gray-800 text-sm"><i class="ti ti-file-export"></i> <span x-text="t('pimp_export_title')"></span></summary>
            <div class="px-4 pb-4 space-y-3">
                <p class="text-xs text-gray-500" x-text="t('pimp_export_help')"></p>
                <div class="max-h-72 overflow-y-auto border rounded divide-y text-sm">
                    <template x-for="c in categoryTree()" :key="c.id">
                        <label class="flex items-center gap-2 px-3 py-1.5" :style="{ paddingInlineStart: (12 + c.depth * 20) + 'px' }">
                            <input type="checkbox" :value="c.id" x-model.number="exportIds" :data-pimp-export-category="c.slug">
                            <span x-text="c.name"></span>
                            <span class="text-xs text-gray-400" x-text="c.slug + ' · ' + c.products"></span>
                        </label>
                    </template>
                </div>
                <p class="text-xs text-gray-400" x-show="uncategorized > 0" x-text="t('pimp_uncategorized', { n: uncategorized })"></p>
                <div class="flex items-center gap-3">
                    <a class="bg-primary hover:bg-secondary text-white px-5 py-2 rounded text-sm" :href="exportUrl()" data-pimp-export-link
                       x-text="t('pimp_export_button')"></a>
                    <span class="text-xs text-gray-500" x-text="exportIds.length ? t('pimp_export_selected', { n: exportIds.length }) : t('pimp_filter_all')"></span>
                </div>
            </div>
        </details>
    </section>

    <?php /* ② 预览与校验 */ ?>
    <section x-show="step === 'preview' && plan" x-cloak class="space-y-5" data-pimp-preview>
        <div class="bg-white rounded-lg shadow p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="text-sm text-gray-600">
                    <span x-text="t('pimp_package')"></span>：<b x-text="pkg && pkg.name"></b>
                    · <span x-text="t('pimp_lang')"></span>：<b x-text="lang"></b>
                </div>
                <button type="button" class="text-sm text-gray-600 border rounded px-3 py-1.5" @click="reset()" x-text="t('pimp_back')"></button>
            </div>

            <div x-show="plan && plan.errors.length > 0" class="bg-red-50 border border-red-200 text-red-700 rounded p-4 text-sm">
                <p x-text="t('pimp_package_errors')"></p>
                <ul class="list-disc ps-5 mt-1">
                    <template x-for="(e, i) in (plan ? plan.errors : [])" :key="i"><li x-text="issue(e, 'pimp_err_')"></li></template>
                </ul>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm" x-show="plan">
                <template x-for="card in summaryCards()" :key="card.key">
                    <button type="button" class="rounded-lg border p-3 text-start" :class="card.cls" @click="filter = card.filter" :data-pimp-summary="card.key">
                        <div class="text-2xl font-bold" x-text="card.value"></div>
                        <div class="text-xs mt-1" x-text="t(card.label)"></div>
                    </button>
                </template>
            </div>
        </div>

        <details class="bg-white rounded-lg shadow" x-show="plan && plan.categories.length > 0">
            <summary class="px-6 py-4 cursor-pointer font-medium text-gray-800">
                <span x-text="t('pimp_categories')"></span> (<span x-text="plan ? plan.categories.length : 0"></span>)
            </summary>
            <div class="overflow-x-auto px-6 pb-5">
                <table class="w-full text-sm">
                    <thead><tr class="text-start text-gray-500 border-b">
                        <th class="py-2 pe-3" x-text="t('pimp_col_title')"></th><th class="py-2 pe-3">key</th>
                        <th class="py-2 pe-3" x-text="t('pimp_col_parent')"></th><th class="py-2 pe-3" x-text="t('pimp_col_action')"></th>
                        <th class="py-2" x-text="t('pimp_col_issues')"></th>
                    </tr></thead>
                    <tbody>
                        <template x-for="c in (plan ? plan.categories : [])" :key="c.index">
                            <tr class="border-b align-top">
                                <td class="py-2 pe-3 flex items-center gap-2">
                                    <img x-show="c.image" :src="c.image ? thumb(c.image) : ''" class="w-8 h-8 object-cover rounded border" loading="lazy" alt="">
                                    <span x-text="c.name"></span>
                                </td>
                                <td class="py-2 pe-3 text-gray-500 font-mono text-xs" x-text="c.key"></td>
                                <td class="py-2 pe-3 text-gray-500 text-xs" x-text="c.parent"></td>
                                <td class="py-2 pe-3" x-text="c.errors.length ? t('pimp_action_error') : t(c.action === 'existing' ? 'pimp_action_existing' : 'pimp_action_create')"></td>
                                <td class="py-2 text-xs">
                                    <template x-for="(e, i) in c.errors" :key="'e' + i"><p class="text-red-600" x-text="issue(e)"></p></template>
                                    <template x-for="(w, i) in c.warnings" :key="'w' + i"><p class="text-amber-600" x-text="issue(w)"></p></template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </details>

        <div class="bg-white rounded-lg shadow p-6 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-medium text-gray-800"><span x-text="t('pimp_products')"></span> (<span x-text="visibleProducts().length"></span>)</h3>
                <div class="flex gap-1 text-sm">
                    <template x-for="f in ['all', 'error', 'warning', 'create', 'update', 'unchanged']" :key="f">
                        <button type="button" class="px-3 py-1 rounded border" :class="filter === f ? 'bg-primary text-white border-primary' : 'text-gray-600'"
                                @click="filter = f; limit = 100" :data-pimp-filter="f"
                                x-text="t(['create', 'update'].includes(f) ? 'pimp_action_' + f : 'pimp_filter_' + f)"></button>
                    </template>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-start text-gray-500 border-b">
                        <th class="py-2 pe-3 w-10">#</th><th class="py-2 pe-3 w-16" x-text="t('pimp_col_cover')"></th>
                        <th class="py-2 pe-3" x-text="t('pimp_col_title')"></th><th class="py-2 pe-3" x-text="t('pimp_col_category')"></th>
                        <th class="py-2 pe-3" x-text="t('pimp_col_issues')"></th><th class="py-2 w-28" x-text="t('pimp_col_action')"></th>
                    </tr></thead>
                    <tbody>
                        <template x-for="p in visibleProducts().slice(0, limit)" :key="p.index">
                            <tr class="border-b align-top" :class="p.errors.length ? 'bg-red-50/50' : (p.warnings.length ? 'bg-amber-50/40' : '')" :data-pimp-row="p.index">
                                <td class="py-2 pe-3 text-gray-400 text-xs" x-text="p.index + 1"></td>
                                <td class="py-2 pe-3">
                                    <img x-show="p.images.cover.length" :src="p.images.cover.length ? thumb(p.images.cover[0].ref) : ''"
                                         class="w-12 h-12 object-cover rounded border bg-gray-50" loading="lazy" alt="">
                                </td>
                                <td class="py-2 pe-3">
                                    <div class="text-gray-800" x-text="p.title"></div>
                                    <div class="text-xs text-gray-400">
                                        <span x-show="p.key" x-text="p.key"></span>
                                        <span x-show="p.match" x-text="p.match ? ' · ' + t('pimp_matched_' + p.match.by, { id: p.match.id }) : ''"></span>
                                    </div>
                                    <div class="text-xs text-gray-400" x-text="t('pimp_images_count', { n: p.images.images.length, m: p.images.param_images.length })"></div>
                                    <div class="text-xs" x-show="p.changes" :class="p.changes && p.changes.length ? 'text-blue-600' : 'text-gray-400'" :data-pimp-changes="p.index"
                                         x-text="p.changes ? (p.changes.length ? t('pimp_changed', { fields: p.changes.map(f => t('pimp_field_' + f)).join(t('pimp_list_sep')) }) : t('pimp_unchanged')) : ''"></div>
                                </td>
                                <td class="py-2 pe-3 text-xs text-gray-500" x-text="p.category"></td>
                                <td class="py-2 pe-3 text-xs">
                                    <template x-for="(e, i) in p.errors" :key="'e' + i"><p class="text-red-600" x-text="issue(e)"></p></template>
                                    <template x-for="(w, i) in p.warnings" :key="'w' + i"><p class="text-amber-600" x-text="issue(w)"></p></template>
                                </td>
                                <td class="py-2">
                                    <span x-show="p.action === 'error'" class="text-red-600 text-xs" x-text="t('pimp_action_error')"></span>
                                    <select x-show="p.action !== 'error'" x-model="decisions[p.index]" class="border rounded px-2 py-1 text-sm w-full" :data-pimp-decision="p.index">
                                        <option value="create" x-text="t('pimp_action_create')"></option>
                                        <option value="update" x-show="p.match" :disabled="!p.match" x-text="t('pimp_action_update')"></option>
                                        <option value="skip" x-text="t('pimp_action_skip')"></option>
                                    </select>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <button type="button" x-show="visibleProducts().length > limit" class="text-sm text-primary" @click="limit += 200" x-text="t('pimp_show_more')"></button>
            <div class="flex justify-end">
                <button type="button" class="bg-primary hover:bg-secondary text-white px-8 py-2.5 rounded transition disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="!!busy || importCount() === 0 || (plan && plan.errors.length > 0)" @click="start()" data-pimp-start
                        x-text="busy ? busy : t('pimp_start', { n: importCount() })"></button>
            </div>
        </div>
    </section>

    <?php /* ③ 导入进度与结果 */ ?>
    <section x-show="step === 'run' && job" x-cloak class="bg-white rounded-lg shadow p-6 space-y-5" data-pimp-job>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-bold text-gray-800" x-text="job ? (job.status === 'done' ? t('pimp_done') : (job.status === 'undone' ? t('pimp_undone') : t('pimp_status_running'))) : ''"></h2>
            <span class="text-xs text-gray-400" x-text="job ? job.name + ' · ' + job.id : ''"></span>
        </div>
        <p x-show="job && ['ready', 'running'].includes(job.status)" class="text-sm text-gray-500" x-text="t('pimp_running')"></p>
        <div class="space-y-2" x-show="job">
            <div class="text-sm text-gray-600" x-text="job ? t('pimp_progress', { done: processed(), total: job.counts.total }) : ''"></div>
            <div class="w-full bg-gray-200 rounded-full h-3 overflow-hidden">
                <div class="bg-primary h-3 rounded-full transition-all duration-300" :style="{ width: (job && job.counts.total ? processed() / job.counts.total * 100 : 0) + '%' }"></div>
            </div>
        </div>
        <div class="grid grid-cols-3 md:grid-cols-7 gap-3 text-sm" x-show="job">
            <template x-for="k in ['created', 'updated', 'skipped', 'error', 'failed', 'categories_created', 'media_created']" :key="k">
                <div class="border rounded-lg p-3 text-center" :data-pimp-count="k">
                    <div class="text-xl font-bold" :class="['error', 'failed'].includes(k) && job && job.counts[k] ? 'text-red-600' : 'text-gray-800'" x-text="job ? (job.counts[k] || 0) : 0"></div>
                    <div class="text-xs text-gray-500 mt-1" x-text="t('pimp_count_' + ({ categories_created: 'categories', media_created: 'media' }[k] || k))"></div>
                </div>
            </template>
        </div>
        <div x-show="job && problemRows().length > 0" class="max-h-64 overflow-y-auto border rounded divide-y text-sm">
            <template x-for="item in problemRows()" :key="item.index">
                <div class="px-4 py-2 flex gap-3">
                    <span class="text-gray-400 font-mono text-xs mt-0.5" x-text="'#' + (item.index + 1)"></span>
                    <span class="text-gray-700" x-text="item.title"></span>
                    <span class="text-red-600 text-xs mt-0.5" x-text="itemMessage(item)"></span>
                </div>
            </template>
        </div>
        <div x-show="job && job.verify" class="rounded p-3 text-sm border" data-pimp-verify
             :class="job && job.verify && job.verify.problems.length ? 'bg-red-50 border-red-200 text-red-700' : 'bg-green-50 border-green-200 text-green-700'">
            <p x-text="job && job.verify ? (job.verify.problems.length ? t('pimp_verify_problems', { n: job.verify.problems.length }) : t('pimp_verify_ok', { n: job.verify.checked })) : ''"></p>
            <template x-for="v in (job && job.verify ? job.verify.problems.slice(0, 50) : [])" :key="v.product_id">
                <p class="text-xs" x-text="'#' + v.product_id + ' ' + v.title + ' · ' + t('pimp_verify_missing', { list: v.missing.join(', ') })"></p>
            </template>
        </div>
        <div x-show="undoConflicts.length > 0" class="bg-amber-50 border border-amber-200 rounded p-3 text-sm text-amber-800">
            <p x-text="t('pimp_undo_conflicts')"></p>
            <template x-for="c in undoConflicts" :key="c.id"><p x-text="'#' + c.id + ' ' + c.title"></p></template>
        </div>
        <div class="flex flex-wrap gap-3 pt-2" x-show="job">
            <button type="button" x-show="job && ['ready', 'running'].includes(job.status) && !running" class="bg-primary text-white px-5 py-2 rounded"
                    @click="runLoop()" x-text="t('pimp_continue')"></button>
            <button type="button" x-show="job && job.status === 'done' && job.counts.failed > 0" class="border px-4 py-2 rounded text-gray-700"
                    :disabled="running" @click="retry()" x-text="t('pimp_retry_failed')"></button>
            <a x-show="job && (job.counts.failed > 0 || job.counts.error > 0)" class="border px-4 py-2 rounded text-gray-700"
               :href="'?plugin=product-import&handler=job_errors&job=' + (job ? job.id : '')" x-text="t('pimp_download_errors')"></a>
            <a x-show="job && job.status === 'done'" href="/admin/product.php" class="bg-primary text-white px-5 py-2 rounded" x-text="t('pimp_view_products')"></a>
            <button type="button" x-show="job && job.status === 'done'" class="border border-red-300 text-red-600 px-4 py-2 rounded" data-pimp-undo
                    :disabled="running" @click="undo()" x-text="t('pimp_undo')"></button>
            <button type="button" class="border px-4 py-2 rounded text-gray-700" @click="reset()" x-text="t('pimp_new_import')"></button>
        </div>
    </section>

    <?php /* 导入历史 */ ?>
    <section class="bg-white rounded-lg shadow p-6 space-y-3" data-pimp-history>
        <h3 class="font-medium text-gray-800" x-text="t('pimp_history')"></h3>
        <p x-show="jobs.length === 0" class="text-sm text-gray-400" x-text="t('pimp_history_empty')"></p>
        <table class="w-full text-sm" x-show="jobs.length > 0">
            <tbody>
                <template x-for="j in jobs" :key="j.id">
                    <tr class="border-b">
                        <td class="py-2 pe-3" x-text="j.name || j.id"></td>
                        <td class="py-2 pe-3 text-xs text-gray-500" x-text="date(j.created_at)"></td>
                        <td class="py-2 pe-3 text-xs" x-text="t('pimp_status_' + j.status)"></td>
                        <td class="py-2 pe-3 text-xs text-gray-500"
                            x-text="j.counts ? t('pimp_count_created') + ' ' + j.counts.created + ' · ' + t('pimp_count_updated') + ' ' + j.counts.updated + ' · ' + t('pimp_count_failed') + ' ' + j.counts.failed : ''"></td>
                        <td class="py-2 text-end"><button type="button" class="text-primary" @click="openJob(j.id)" x-text="t('pimp_open')"></button></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </section>
</div>

<script>
function pimpWizard(config) {
    return {
        text: config.text, csrf: config.csrf, uploadLimit: config.uploadLimit, languages: config.languages,
        lang: config.lang, incomingDir: 'storage/product-import/incoming/',
        step: 'choose', busy: '', error: '', dragOver: false, running: false,
        incoming: [], jobs: [], pkg: null, plan: null, decisions: {}, filter: 'all', limit: 100, job: null, undoConflicts: [],
        categories: [], categoriesLang: '', uncategorized: 0, exportIds: [],

        init() {
            this.loadIncoming();
            this.loadJobs();
            if (config.job) this.openJob(config.job);
            this.$watch('lang', () => { if (this.categoriesLang) { this.exportIds = []; this.loadCategories(); } });
        },
        t(key, params) {
            let s = this.text[key] !== undefined ? this.text[key] : key;
            Object.entries(params || {}).forEach(([k, v]) => { s = s.split('{' + k + '}').join(String(v)); });
            return s;
        },
        issue(e, prefix) {
            const key = (prefix || 'pimp_issue_') + e.code;
            return this.text[key] !== undefined ? this.t(key, e.params || {}) : e.code;
        },
        itemMessage(item) {
            return String(item.message || '').split(',').filter(Boolean).map(code => this.issue({ code: code })).join(this.t('pimp_list_sep'));
        },
        size(bytes) {
            return bytes >= 1048576 ? Math.round(bytes / 1048576) + ' MB' : Math.round(bytes / 1024) + ' KB';
        },
        date(ts) {
            return ts ? new Date(ts * 1000).toLocaleString() : '';
        },
        async api(handler, body) {
            const opts = { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrf } };
            if (body instanceof FormData) {
                opts.method = 'POST';
                opts.body = body;
            } else if (body !== undefined) {
                opts.method = 'POST';
                opts.headers['Content-Type'] = 'application/json';
                opts.body = JSON.stringify(body);
            }
            let data;
            try {
                const resp = await fetch('?plugin=product-import&handler=' + handler, opts);
                data = await resp.json();
            } catch (e) {
                throw new Error(this.t('pimp_network_error', { msg: e.message }));
            }
            if (data.code !== 0) throw new Error(data.msg || data.error || 'error');
            return data.data;
        },
        async loadIncoming() {
            try {
                const data = await this.api('pkg_incoming');
                this.incoming = data.items;
                this.incomingDir = data.dir;
            } catch (e) { this.error = e.message; }
        },
        async loadJobs() {
            try { this.jobs = (await this.api('jobs')).jobs; } catch (e) { this.error = e.message; }
        },
        async upload(file) {
            if (!file || this.busy) return;
            const form = new FormData();
            form.append('file', file);
            await this.choose(() => this.api('pkg_upload', form));
        },
        async useIncoming(name) {
            if (this.busy) return;
            await this.choose(() => this.api('pkg_use_incoming', { name: name }));
        },
        async choose(call) {
            this.error = '';
            this.busy = this.t('pimp_uploading');
            try {
                this.pkg = (await call()).package;
                await this.loadPlan();
            } catch (e) { this.error = e.message; }
            this.busy = '';
        },
        async loadPlan() {
            this.busy = this.t('pimp_planning');
            const data = await this.api('pkg_plan', { package: this.pkg.id, lang: this.lang });
            this.plan = data.plan;
            this.lang = data.lang;
            this.decisions = {};
            // 与本站完全相同的更新行默认跳过，只同步真有变化的
            this.plan.products.forEach(p => {
                if (p.action !== 'error') this.decisions[p.index] = p.changes && p.changes.length === 0 ? 'skip' : p.action;
            });
            this.filter = this.plan.summary.error > 0 ? 'error' : 'all';
            this.limit = 100;
            this.step = 'preview';
        },
        thumb(ref) {
            return '?plugin=product-import&handler=pkg_image&package=' + encodeURIComponent(this.pkg ? this.pkg.id : '') + '&ref=' + encodeURIComponent(ref);
        },
        summaryCards() {
            const s = this.plan ? this.plan.summary : {};
            return [
                { key: 'categories_new', label: 'pimp_sum_categories_new', value: s.categories_new || 0, filter: 'all', cls: 'text-gray-700' },
                { key: 'create', label: 'pimp_sum_create', value: s.create || 0, filter: 'create', cls: 'text-green-700 border-green-200 bg-green-50' },
                { key: 'update', label: 'pimp_sum_update', value: s.update || 0, filter: 'update', cls: 'text-blue-700 border-blue-200 bg-blue-50' },
                { key: 'error', label: 'pimp_sum_error', value: s.error || 0, filter: 'error', cls: 'text-red-700 border-red-200 bg-red-50' },
                { key: 'warnings', label: 'pimp_sum_warnings', value: s.warnings || 0, filter: 'warning', cls: 'text-amber-700 border-amber-200 bg-amber-50' },
                { key: 'unchanged', label: 'pimp_sum_unchanged', value: s.unchanged || 0, filter: 'unchanged', cls: 'text-gray-600 border-gray-200 bg-gray-50' },
            ];
        },
        visibleProducts() {
            if (!this.plan) return [];
            const f = this.filter;
            return this.plan.products.filter(p => f === 'all'
                || (f === 'error' && p.errors.length > 0)
                || (f === 'warning' && p.warnings.length > 0)
                || ((f === 'create' || f === 'update') && p.action === f)
                || (f === 'unchanged' && Array.isArray(p.changes) && p.changes.length === 0));
        },
        async loadCategories() {
            if (this.categoriesLang === this.lang) return;
            try {
                const data = await this.api('pkg_categories&lang=' + encodeURIComponent(this.lang));
                this.categories = data.categories;
                this.uncategorized = data.uncategorized;
                this.categoriesLang = data.lang;
            } catch (e) { this.error = e.message; }
        },
        categoryTree() {
            const byParent = {};
            this.categories.forEach(c => { (byParent[c.parent_id] = byParent[c.parent_id] || []).push(c); });
            const ids = new Set(this.categories.map(c => c.id));
            const out = [];
            const walk = (parent, depth) => (byParent[parent] || []).forEach(c => { out.push({ ...c, depth: depth }); walk(c.id, depth + 1); });
            walk(0, 0);
            // 上级不在本语言里的分类也列出来
            this.categories.forEach(c => { if (c.parent_id && !ids.has(c.parent_id)) { out.push({ ...c, depth: 0 }); walk(c.id, 1); } });
            return out;
        },
        exportUrl() {
            return '?plugin=product-import&handler=pkg_export&lang=' + encodeURIComponent(this.lang) + '&categories=' + this.exportIds.join(',');
        },
        importCount() {
            return this.plan ? this.plan.products.filter(p => p.action !== 'error' && this.decisions[p.index] !== 'skip').length : 0;
        },
        async start() {
            this.error = '';
            this.busy = this.t('pimp_planning');
            try {
                this.job = (await this.api('job_create', { package: this.pkg.id, lang: this.lang, decisions: this.decisions })).job;
                this.undoConflicts = [];
                this.step = 'run';
            } catch (e) { this.error = e.message; }
            this.busy = '';
            if (this.job) await this.runLoop();
        },
        async runLoop(handler) {
            if (this.running) return;
            this.running = true;
            this.error = '';
            try {
                let first = handler || 'job_run';
                while (this.job && (first !== 'job_run' || ['ready', 'running'].includes(this.job.status))) {
                    this.job = (await this.api(first, { job: this.job.id })).job;
                    first = 'job_run';
                }
            } catch (e) { this.error = e.message; }
            this.running = false;
            this.loadJobs();
        },
        retry() {
            return this.runLoop('job_retry');
        },
        async undo() {
            if (!this.job || !confirm(this.t('pimp_undo_confirm'))) return;
            this.running = true;
            try {
                this.job = (await this.api('job_undo', { job: this.job.id })).job;
                this.undoConflicts = this.job.undo_conflicts || [];
            } catch (e) { this.error = e.message; }
            this.running = false;
            this.loadJobs();
        },
        async openJob(id) {
            this.error = '';
            try {
                this.job = (await this.api('job_get&job=' + encodeURIComponent(id))).job;
                this.undoConflicts = this.job.undo_conflicts || [];
                this.step = 'run';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } catch (e) { this.error = e.message; }
        },
        processed() {
            if (!this.job) return 0;
            const c = this.job.counts;
            return c.total - (c.pending || 0);
        },
        problemRows() {
            return this.job ? this.job.items.filter(i => i.status === 'failed' || i.status === 'error').slice(0, 300) : [];
        },
        reset() {
            this.step = 'choose';
            this.plan = null;
            this.pkg = null;
            this.job = null;
            this.error = '';
            this.undoConflicts = [];
            this.loadIncoming();
        },
    };
}
</script>
