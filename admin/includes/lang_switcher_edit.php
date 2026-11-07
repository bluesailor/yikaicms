<?php
/**
 * 编辑页语言切换组件
 *
 * 使用前需定义：
 *   $langSwitcher = [
 *       'table'     => 'contents',          // 表名（不含前缀）
 *       'model'     => contentModel(),       // Model实例
 *       'item'      => $article,             // 当前编辑的记录（null=新增）
 *       'edit_url'  => '/admin/article_edit.php', // 编辑页URL
 *       'type'      => 'article',            // 内容类型（可选）
 *   ];
 */

// 后台编辑页可见 ≠ 前台切换器开关。只要 enabled_languages 启用了 ≥2 种语言，
// 就允许后台填翻译；不依赖 show_lang_switcher（后者只管前台是否露切换器）。
if (empty($langSwitcher)) return;
if (!empty($langSwitcher['item']['id']) && in_array($langSwitcher['table'], ['contents', 'products', 'channels', 'product_categories', 'jobs', 'downloads'], true)) {
    echo '<a class="inline-flex items-center gap-2 mb-4 text-primary hover:underline" target="_blank" rel="noopener" href="'
        . e('/admin/draft_preview.php?type=' . rawurlencode($langSwitcher['table']) . '&id=' . (int) $langSwitcher['item']['id'])
        . '">' . e(__('admin_draft_preview')) . '</a>';
}
$_ls_enabledRaw = trim((string) config('enabled_languages', ''));
$_ls_enabled = $_ls_enabledRaw !== '' ? json_decode($_ls_enabledRaw, true) : null;
if (!is_array($_ls_enabled) || count($_ls_enabled) < 2) return;
unset($_ls_enabledRaw, $_ls_enabled);

$_ls_defaultLang = config('site_lang', 'zh-CN');
$_ls_allLangs = availableLanguages();
$_ls_otherLangs = $_ls_allLangs;
unset($_ls_otherLangs[$_ls_defaultLang]);
if (empty($_ls_otherLangs)) return;

$_ls_item = $langSwitcher['item'];
$_ls_model = $langSwitcher['model'];
$_ls_editUrl = $langSwitcher['edit_url'];
$_ls_table = $langSwitcher['table'];
/** @var array<string, mixed> $langSwitcher 各编辑页自行声明，键集因页而异 */
$_ls_type = $langSwitcher['type'] ?? '';
$_ls_tableName = DB_PREFIX . $_ls_table;
$_ls_editParam = $langSwitcher['edit_param'] ?? 'id';   // channel.php 用 'edit'，其他用 'id'
$_ls_editSep   = strpos($_ls_editUrl, '?') !== false ? '&' : '?';
$_ls_editLink  = function (int $id) use ($_ls_editUrl, $_ls_editParam, $_ls_editSep): string {
    return $_ls_editUrl . $_ls_editSep . $_ls_editParam . '=' . $id;
};

$_ls_currentLang = $_ls_item['lang'] ?? $_ls_defaultLang;
$_ls_groupId = 0;
$_ls_translations = [];

if ($_ls_item) {
    $_ls_groupId = (int)($_ls_item['translation_group_id'] ?: $_ls_item['id']);
    // 查所有翻译版本（兼容 title 或 name 字段）
    $rows = db()->fetchAll("SELECT * FROM {$_ls_tableName} WHERE translation_group_id = ? AND id != ?", [$_ls_groupId, (int)$_ls_item['id']]);
    foreach ($rows as $r) {
        $r['_title'] = $r['title'] ?? $r['name'] ?? '';
        $_ls_translations[$r['lang']] = $r;
    }
    if ($_ls_currentLang !== $_ls_defaultLang) {
        // 源行查找：种子里源行的 translation_group_id 是 0（只有镜像行指过来）。
        // 所以走 "id = group_id AND lang = default" 直接捞源，而不是用
        // translation_group_id 字段（那个对源行是空的）。
        $src = db()->fetchOne(
            "SELECT * FROM {$_ls_tableName} WHERE id = ? AND lang = ?",
            [$_ls_groupId, $_ls_defaultLang]
        );
        if ($src) {
            $src['_title'] = $src['title'] ?? $src['name'] ?? '';
            $_ls_translations[$_ls_defaultLang] = $src;
        }
    }
}

// 译文状态（T04）：与生成/审阅时比，原文改没改、译文有没有人改过、有没有待审的新译文
$_ls_states = [];
$_ls_proposal = null;
$_ls_source = $_ls_currentLang === $_ls_defaultLang ? $_ls_item : ($_ls_translations[$_ls_defaultLang] ?? null);
if ($_ls_item && $_ls_source && db()->tableExists('translation_links')) {
    require_once ROOT_PATH . '/includes/TranslationWrite.php';
    require_once ROOT_PATH . '/includes/content_translation.php';
    $_ls_titleKey = (string) ($langSwitcher['title_field'] ?? 'title');
    $_ls_summaryKey = (string) ($langSwitcher['summary_field'] ?? 'summary');
    $_ls_pageOf = static fn (array $row): ?array => $_ls_table === 'channels' && ($row['type'] ?? '') === 'page'
        ? contentModel()->queryOne('SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? ORDER BY id DESC LIMIT 1', [(int) $row['id']]) : null;
    $_ls_text = static fn (mixed $value): string => is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    // 比较用：JSON 文本（参数表）按解析后的值比，「{}」与「[]」这类写法差异不算改动
    $_ls_same = static function (mixed $a, mixed $b) use ($_ls_text): bool {
        $norm = static function (mixed $value) use ($_ls_text): string {
            $decoded = is_string($value) ? json_decode($value, true) : $value;
            return is_array($decoded) ? (string) json_encode($decoded, JSON_UNESCAPED_UNICODE) : $_ls_text($value);
        };
        return $norm($a) === $norm($b);
    };
    $_ls_sourceFp = TranslationState::rowFingerprint($_ls_source, $_ls_titleKey, $_ls_summaryKey, $_ls_pageOf($_ls_source));
    $_ls_targets = $_ls_translations;
    unset($_ls_targets[$_ls_defaultLang]);
    if ($_ls_currentLang !== $_ls_defaultLang) $_ls_targets[$_ls_currentLang] = $_ls_item;
    $_ls_links = (new TranslationState(db()))->links($_ls_table, array_map(static fn (array $row): int => (int) $row['id'], array_values($_ls_targets)));
    foreach ($_ls_targets as $lc => $row) {
        $page = $_ls_pageOf($row);
        $link = $_ls_links[(int) $row['id']] ?? null;
        $_ls_states[$lc] = TranslationState::status($link, $_ls_sourceFp, TranslationState::rowFingerprint($row, $_ls_titleKey, $_ls_summaryKey, $page));
        $proposed = $lc === $_ls_currentLang && $link !== null ? json_decode((string) ($link['proposal_json'] ?? ''), true) : null;
        if (is_array($proposed)) {
            // 只列与现有译文不同、且这一行确有对应列的字段
            $body = $page ?? $row;
            $current = TranslationWrite::source($row, $_ls_titleKey, $_ls_summaryKey, $body, translationBodyField($body));
            $_ls_proposal = ['at' => (int) $link['proposal_at'], 'fields' => []];
            foreach (TranslationState::TRACKED as $field) {
                if (!array_key_exists($field, $proposed) || !array_key_exists($field, $current)) continue;
                if (!$_ls_same($proposed[$field], $current[$field])) $_ls_proposal['fields'][$field] = [$_ls_text($current[$field]), $_ls_text($proposed[$field])];
            }
        }
    }
}
$_ls_badge = static function (array $state): array {
    if ($state['proposal']) return ['lse_state_proposal', 'bg-blue-100 text-blue-700'];
    if ($state['outdated']) return ['lse_state_outdated', 'bg-amber-100 text-amber-700'];
    if ($state['state'] === 'untracked') return ['lse_state_untracked', 'bg-gray-100 text-gray-600'];
    if ($state['reviewed']) return ['lse_state_reviewed', 'bg-green-100 text-green-700'];
    if ($state['edited']) return ['lse_state_edited', 'bg-green-100 text-green-700'];
    return ['lse_state_current', 'bg-green-100 text-green-700'];
};
?>

<?php if ($_ls_item): ?>
<div class="bg-white rounded-lg shadow mb-4 px-5 py-3 flex items-center gap-3 flex-wrap">
    <i class="ti ti-language text-base text-gray-400"></i>
    <span class="text-sm text-gray-500"><?php echo e(__('lse_versions')); ?></span>

    <?php foreach ($_ls_allLangs as $lc => $ll):
        $isCurrent = ($lc === $_ls_currentLang);
        $hasTranslation = isset($_ls_translations[$lc]);
        $transItem = $_ls_translations[$lc] ?? null;
    ?>
        <?php if ($isCurrent): ?>
        <span class="px-3 py-1 rounded-full text-xs bg-primary text-white"><?php echo e($ll); ?> (<?php echo e(__('lse_current')); ?>)</span>
        <?php elseif ($hasTranslation): ?>
        <a href="<?php echo e($_ls_editLink((int) $transItem['id'])); ?>"
           class="px-3 py-1 rounded-full text-xs bg-green-100 text-green-700 hover:bg-green-200 transition" title="<?php echo e(__('lse_last_edited')); ?>: <?php echo date('Y-m-d H:i', (int)($transItem['updated_at'] ?? 0)); ?>">
            <?php echo e($ll); ?> ✓
        </a>
        <?php if (isset($_ls_states[$lc])): [$_ls_key, $_ls_cls] = $_ls_badge($_ls_states[$lc]); ?>
        <span class="px-2 py-0.5 rounded text-xs <?php echo $_ls_cls; ?>" data-translation-state="<?php echo e($lc); ?>" title="<?php echo e(__($_ls_key . '_hint')); ?>"><?php echo e(__($_ls_key)); ?></span>
        <?php endif; ?>
        <?php if ($lc !== $_ls_defaultLang): ?>
        <button type="button" class="border rounded px-2 py-1 text-xs" onclick="createTranslation('<?php echo e($lc); ?>', '<?php echo e($ll); ?>', this, true)" aria-label="<?php echo e(__('lse_retranslate') . ' ' . $ll); ?>"><?php echo e(__('lse_retranslate')); ?></button>
        <?php endif; ?>
        <?php else: ?>
        <button type="button" onclick="createTranslation('<?php echo e($lc); ?>', '<?php echo e($ll); ?>', this)"
                class="px-3 py-1 rounded-full text-xs bg-gray-100 text-gray-400 hover:bg-amber-100 hover:text-amber-600 transition cursor-pointer" title="<?php echo e(__('lse_create_version')); ?>">
            <?php echo e($ll); ?>
        </button>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($_ls_currentLang !== $_ls_defaultLang): ?>
    <button type="button" class="border rounded px-2 py-1 text-xs" onclick="createTranslation('<?php echo e($_ls_currentLang); ?>', '<?php echo e($_ls_allLangs[$_ls_currentLang]); ?>', this, true)" aria-label="<?php echo e(__('lse_retranslate') . ' ' . $_ls_allLangs[$_ls_currentLang]); ?>"><?php echo e(__('lse_retranslate')); ?></button>
    <span class="text-xs text-amber-500 ml-1"><?php echo str_replace(':lang', e($_ls_allLangs[$_ls_defaultLang]), e(__('lse_translated_from'))); ?></span>
    <?php if (isset($_ls_states[$_ls_currentLang])): $_ls_state = $_ls_states[$_ls_currentLang]; [$_ls_key, $_ls_cls] = $_ls_badge($_ls_state); ?>
    <span class="px-2 py-0.5 rounded text-xs <?php echo $_ls_cls; ?>" data-translation-state="<?php echo e($_ls_currentLang); ?>" title="<?php echo e(__($_ls_key . '_hint')); ?>"><?php echo e(__($_ls_key)); ?></span>
    <?php if (!$_ls_state['reviewed'] || $_ls_state['outdated'] || $_ls_state['edited']): ?>
    <button type="button" class="border rounded px-2 py-1 text-xs" id="lseMarkReviewed" onclick="translationReview('translation_mark_reviewed', [], this)" title="<?php echo e(__('lse_mark_reviewed_hint')); ?>"><?php echo e(__('lse_mark_reviewed')); ?></button>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($_ls_proposal !== null): ?>
<div class="bg-blue-50 border border-blue-200 rounded-lg mb-4 px-5 py-4 text-sm" id="lseProposal">
    <p class="font-medium text-blue-900 mb-1"><?php echo e(__('lse_proposal_title')); ?></p>
    <p class="text-blue-800 mb-3"><?php echo e(str_replace(':time', date('Y-m-d H:i', $_ls_proposal['at']), __('lse_proposal_hint'))); ?></p>
    <?php if ($_ls_proposal['fields'] === []): ?>
    <p class="text-blue-800 mb-3"><?php echo e(__('lse_proposal_same')); ?></p>
    <?php endif; ?>
    <?php foreach ($_ls_proposal['fields'] as $_ls_field => [$_ls_now, $_ls_new]): ?>
    <fieldset class="border-t border-blue-200 py-3">
        <label class="flex items-center gap-2 font-medium mb-2">
            <input type="checkbox" name="lse_fields[]" value="<?php echo e($_ls_field); ?>" checked>
            <?php echo e(in_array($_ls_field, ['title', 'summary', 'content', 'specs', 'seo_title', 'seo_keywords', 'seo_description'], true) ? __('lse_field_' . $_ls_field) : $_ls_field); ?>
        </label>
        <div class="grid gap-2 md:grid-cols-2">
            <div><span class="text-xs text-gray-500"><?php echo e(__('lse_proposal_current')); ?></span>
                <textarea readonly rows="4" class="w-full border rounded p-2 text-xs bg-white"><?php echo e($_ls_now); ?></textarea></div>
            <div><span class="text-xs text-gray-500"><?php echo e(__('lse_proposal_new')); ?></span>
                <textarea readonly rows="4" class="w-full border rounded p-2 text-xs bg-white"><?php echo e($_ls_new); ?></textarea></div>
        </div>
    </fieldset>
    <?php endforeach; ?>
    <div class="flex gap-2 pt-2">
        <?php if ($_ls_proposal['fields'] !== []): ?>
        <button type="button" class="bg-primary text-white px-4 py-1.5 rounded text-sm" id="lseAccept" onclick="translationReview('translation_accept', Array.from(document.querySelectorAll('#lseProposal input[type=checkbox]:checked')).map(function (box) { return box.value; }), this)"><?php echo e(__('lse_proposal_accept')); ?></button>
        <?php endif; ?>
        <button type="button" class="border px-4 py-1.5 rounded text-sm" id="lseDiscard" onclick="translationReview('translation_discard', [], this)"><?php echo e(__('lse_proposal_discard')); ?></button>
    </div>
</div>
<?php endif; ?>

<script>
async function createTranslation(toLang, langName, btn, retranslate = false) {
    if (!btn || btn.disabled) return;
    var originalText = btn.textContent;
    var originalClass = btn.className;
    btn.disabled = true;
    btn.textContent = <?php echo json_encode(__('lse_translating'), JSON_UNESCAPED_UNICODE); ?>;
    btn.className = 'px-3 py-1 rounded-full text-xs bg-amber-100 text-amber-600 animate-pulse';

    var fd = new FormData();
    fd.append('action', 'create_translation');
    fd.append('src_id', <?php echo (int)$_ls_item['id']; ?>);
    fd.append('to_lang', toLang);
    if (retranslate) fd.append('retranslate', '1');
    try {
        var resp = await fetch(location.pathname, { method: 'POST', body: fd });
        var data = await safeJson(resp);
        if (data.code === 0 && data.data && data.data.id) {
            showMessage(data.msg || <?php echo json_encode(__('lse_done'), JSON_UNESCAPED_UNICODE); ?>);
            setTimeout(function() {
                var sep = '<?php echo $_ls_editSep; ?>', p = '<?php echo e($_ls_editParam); ?>';
                location.href = '<?php echo e(BasePath::url($_ls_editUrl)); ?>' + sep + p + '=' + data.data.id;
            }, 800);
        } else {
            btn.disabled = false;
            btn.textContent = originalText;
            btn.className = originalClass;
            btn.title = data.msg || <?php echo json_encode(__('lse_failed'), JSON_UNESCAPED_UNICODE); ?>;
            showMessage(data.msg || <?php echo json_encode(__('lse_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            if (data.data && data.data.translation) showUnappliedTranslation(btn, data.msg, data.data.translation);
        }
    } catch(e) {
        btn.disabled = false;
        btn.textContent = originalText;
        btn.className = originalClass;
        btn.title = <?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>;
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

// 译文审阅（T04）：标为已审 / 采用所选字段 / 放弃待审译文，成功后重载本页
async function translationReview(action, fields, btn) {
    if (!btn || btn.disabled) return;
    if (action === 'translation_accept' && fields.length === 0) {
        showMessage(<?php echo json_encode(__('lse_accept_nothing_selected'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        return;
    }
    btn.disabled = true;
    var fd = new FormData();
    fd.append('action', action);
    fd.append('target_id', <?php echo (int) $_ls_item['id']; ?>);
    fields.forEach(function (field) { fd.append('fields[]', field); });
    try {
        var data = await safeJson(await fetch(location.pathname, { method: 'POST', body: fd }));
        showMessage(data.msg || '', data.code === 0 ? 'success' : 'error');
        if (data.code === 0) setTimeout(function () { location.reload(); }, 600);
        else btn.disabled = false;
    } catch (e) {
        btn.disabled = false;
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

// 写库前发现内容已被改过时，生成的译文没有写入；放在语言条下面供查看、复制
function showUnappliedTranslation(btn, message, translation) {
    var bar = btn.closest('.flex-wrap');
    var panel = document.getElementById('lseUnapplied');
    if (!panel) {
        panel = document.createElement('details');
        panel.id = 'lseUnapplied';
        panel.open = true;
        panel.className = 'bg-amber-50 border border-amber-200 rounded-lg mb-4 px-5 py-3 text-sm';
        bar.insertAdjacentElement('afterend', panel);
    }
    panel.replaceChildren();
    var summary = document.createElement('summary');
    summary.className = 'cursor-pointer text-amber-800';
    summary.textContent = message + ' ' + <?php echo json_encode(__('lse_conflict_view'), JSON_UNESCAPED_UNICODE); ?>;
    var text = document.createElement('textarea');
    text.readOnly = true;
    text.rows = 10;
    text.className = 'w-full mt-2 border rounded p-2 font-mono text-xs';
    text.value = [translation.title, translation.summary, translation.content].filter(Boolean).join('\n\n');
    panel.append(summary, text);
}
</script>
<?php endif; ?>
