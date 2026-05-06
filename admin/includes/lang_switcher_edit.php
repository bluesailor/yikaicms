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

if (empty($langSwitcher) || !isMultiLangEnabled($langSwitcher['table']) || config('show_lang_switcher', '0') !== '1') return;

$_ls_defaultLang = config('site_lang', 'zh-CN');
$_ls_allLangs = availableLanguages();
$_ls_otherLangs = $_ls_allLangs;
unset($_ls_otherLangs[$_ls_defaultLang]);
if (empty($_ls_otherLangs)) return;

$_ls_item = $langSwitcher['item'];
$_ls_model = $langSwitcher['model'];
$_ls_editUrl = $langSwitcher['edit_url'];
$_ls_table = $langSwitcher['table'];
$_ls_type = $langSwitcher['type'] ?? '';
$_ls_tableName = DB_PREFIX . $_ls_table;

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
        $src = db()->fetchOne("SELECT * FROM {$_ls_tableName} WHERE translation_group_id = ? AND lang = ?", [$_ls_groupId, $_ls_defaultLang]);
        if ($src) {
            $src['_title'] = $src['title'] ?? $src['name'] ?? '';
            $_ls_translations[$_ls_defaultLang] = $src;
        }
    }
}
?>

<?php if ($_ls_item): ?>
<div class="bg-white rounded-lg shadow mb-4 px-5 py-3 flex items-center gap-3 flex-wrap">
    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
    <span class="text-sm text-gray-500">语言版本：</span>

    <?php foreach ($_ls_allLangs as $lc => $ll):
        $isCurrent = ($lc === $_ls_currentLang);
        $hasTranslation = isset($_ls_translations[$lc]);
        $transItem = $_ls_translations[$lc] ?? null;
    ?>
        <?php if ($isCurrent): ?>
        <span class="px-3 py-1 rounded-full text-xs bg-primary text-white"><?php echo e($ll); ?> (当前)</span>
        <?php elseif ($hasTranslation): ?>
        <a href="<?php echo e($_ls_editUrl); ?>?id=<?php echo $transItem['id']; ?>"
           class="px-3 py-1 rounded-full text-xs bg-green-100 text-green-700 hover:bg-green-200 transition" title="最后编辑: <?php echo date('Y-m-d H:i', (int)($transItem['updated_at'] ?? 0)); ?>">
            <?php echo e($ll); ?> ✓
        </a>
        <?php else: ?>
        <button type="button" onclick="createTranslation('<?php echo e($lc); ?>', '<?php echo e($ll); ?>')"
                class="px-3 py-1 rounded-full text-xs bg-gray-100 text-gray-400 hover:bg-amber-100 hover:text-amber-600 transition cursor-pointer" title="创建翻译版本">
            <?php echo e($ll); ?>
        </button>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($_ls_currentLang !== $_ls_defaultLang): ?>
    <span class="text-xs text-amber-500 ml-1">翻译自 <?php echo e($_ls_allLangs[$_ls_defaultLang]); ?></span>
    <?php endif; ?>
</div>

<script>
async function createTranslation(toLang, langName) {
    if (!confirm('从当前内容创建 ' + langName + ' 翻译版本？\n将 AI 翻译标题和摘要，正文保留原文可手动调整。')) return;

    var btn = event.target;
    btn.disabled = true;
    btn.textContent = '翻译中...';
    btn.className = 'px-3 py-1 rounded-full text-xs bg-amber-100 text-amber-600 animate-pulse';

    var fd = new FormData();
    fd.append('action', 'create_translation');
    fd.append('src_id', <?php echo (int)$_ls_item['id']; ?>);
    fd.append('to_lang', toLang);
    try {
        var resp = await fetch(location.pathname, { method: 'POST', body: fd });
        var data = await safeJson(resp);
        if (data.code === 0 && data.data && data.data.id) {
            showMessage(data.msg || '翻译完成，正在跳转...');
            setTimeout(function() {
                location.href = '<?php echo e($_ls_editUrl); ?>?id=' + data.data.id;
            }, 800);
        } else {
            btn.disabled = false;
            btn.textContent = langName;
            btn.className = 'px-3 py-1 rounded-full text-xs bg-gray-100 text-gray-400 hover:bg-amber-100 hover:text-amber-600 transition cursor-pointer';
            showMessage(data.msg || '翻译失败', 'error');
        }
    } catch(e) {
        btn.disabled = false;
        btn.textContent = langName;
        btn.className = 'px-3 py-1 rounded-full text-xs bg-gray-100 text-gray-400 hover:bg-amber-100 hover:text-amber-600 transition cursor-pointer';
        showMessage('请求失败', 'error');
    }
}
</script>
<?php endif; ?>
