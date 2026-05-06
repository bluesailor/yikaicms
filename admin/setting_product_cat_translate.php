<?php
/**
 * Yikai CMS - 产品分类翻译
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$defaultLang = config('site_lang', 'zh-CN');
$allLangs = availableLanguages();
$otherLangs = $allLangs;
unset($otherLangs[$defaultLang]);

$targetLang = get('lang', array_key_first($otherLangs) ?? 'en');
if (!isset($otherLangs[$targetLang])) $targetLang = array_key_first($otherLangs) ?? 'en';

$catTable = DB_PREFIX . 'product_categories';

// 源分类
$srcCats = db()->fetchAll(
    "SELECT * FROM {$catTable} WHERE lang = ? ORDER BY parent_id ASC, sort_order ASC, id ASC",
    [$defaultLang]
);

// 目标语言已有分类
$targetCats = [];
$targetRows = db()->fetchAll("SELECT * FROM {$catTable} WHERE lang = ?", [$targetLang]);
foreach ($targetRows as $r) {
    if ($r['translation_group_id'] > 0) $targetCats[(int)$r['translation_group_id']] = $r;
}

// POST 处理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'save') {
        $names = $_POST['name'] ?? [];
        $created = 0;
        $updated = 0;

        try {
            $idMap = [];
            foreach ($srcCats as $cat) {
                $catId = (int)$cat['id'];
                $groupId = (int)($cat['translation_group_id'] ?: $catId);
                if (!$cat['translation_group_id']) {
                    db()->execute("UPDATE {$catTable} SET translation_group_id = ? WHERE id = ?", [$catId, $catId]);
                }

                $newName = trim($names[$catId] ?? '');
                if ($newName === '') continue;

                if (isset($targetCats[$groupId])) {
                    db()->execute("UPDATE {$catTable} SET name = ?, lang = ? WHERE id = ?",
                        [$newName, $targetLang, (int)$targetCats[$groupId]['id']]);
                    $idMap[$catId] = (int)$targetCats[$groupId]['id'];
                    $updated++;
                } else {
                    $slug = $cat['slug'] ? $targetLang . '-' . $cat['slug'] : '';
                    if ($slug) {
                        $existSlug = db()->fetchOne("SELECT id FROM {$catTable} WHERE slug = ?", [$slug]);
                        if ($existSlug) {
                            db()->execute("UPDATE {$catTable} SET name = ?, lang = ?, translation_group_id = ? WHERE id = ?",
                                [$newName, $targetLang, $groupId, (int)$existSlug['id']]);
                            $idMap[$catId] = (int)$existSlug['id'];
                            $updated++;
                            continue;
                        }
                    }
                    $parentId = 0;
                    if ($cat['parent_id'] > 0 && isset($idMap[(int)$cat['parent_id']])) {
                        $parentId = $idMap[(int)$cat['parent_id']];
                    }
                    $newId = (int)db()->insert('product_categories', [
                        'parent_id' => $parentId,
                        'name' => $newName,
                        'slug' => $slug,
                        'lang' => $targetLang,
                        'translation_group_id' => $groupId,
                        'image' => $cat['image'] ?? '',
                        'description' => '',
                        'seo_title' => '',
                        'seo_keywords' => '',
                        'seo_description' => '',
                        'status' => (int)($cat['status'] ?? 1),
                        'is_nav' => (int)($cat['is_nav'] ?? 1),
                        'sort_order' => (int)($cat['sort_order'] ?? 0),
                        'created_at' => time(),
                    ]);
                    $idMap[$catId] = $newId;
                    $created++;
                }
            }
        } catch (\Throwable $e) {
            error('保存失败: ' . $e->getMessage());
        }

        adminLog('setting', 'product_cat_translate', "产品分类翻译({$targetLang}): 创建{$created}, 更新{$updated}");
        success([], "保存成功：创建 {$created} 个，更新 {$updated} 个");
    }

    if ($action === 'ai_translate') {
        $toTranslate = [];
        foreach ($srcCats as $cat) {
            $groupId = (int)($cat['translation_group_id'] ?: $cat['id']);
            if (isset($targetCats[$groupId])) continue;
            if (dictTranslateTo($cat['name'], $targetLang)) continue;
            $toTranslate[] = $cat['name'];
        }
        if (empty($toTranslate)) {
            success([], '所有分类已有翻译或词典匹配，无需 AI 翻译');
            exit;
        }
        require_once ROOT_PATH . '/includes/AiService.php';
        $encryptedKey = config('ai_api_key', '');
        $aiKey = $encryptedKey ? AiService::decryptKey($encryptedKey) : '';
        if (!$aiKey) error('请先在 AI 设置中配置 API Key');

        $langName = $allLangs[$targetLang] ?? $targetLang;
        $prompt = "Translate the following Chinese product category names to {$langName}. Return ONLY a JSON object mapping Chinese to translation. No explanation.\n\n" . json_encode($toTranslate, JSON_UNESCAPED_UNICODE);
        $ai = new AiService(config('ai_provider', 'openai'), $aiKey, config('ai_model', 'gpt-4o-mini'));
        $result = $ai->chat($prompt, 'You are a professional translator. Return only valid JSON.', 0.3);
        if (!$result['success']) error('AI 翻译失败: ' . ($result['error'] ?? '未知错误'));

        $content = preg_replace('/^```json\s*|```\s*$/m', '', trim($result['content'] ?? ''));
        $translations = json_decode($content, true);
        if (!is_array($translations)) error('AI 返回格式异常');
        success(['translations' => $translations], 'AI 翻译完成，请确认后保存');
    }
}

// 重新加载
$targetRows = db()->fetchAll("SELECT * FROM {$catTable} WHERE lang = ?", [$targetLang]);
$targetCats = [];
foreach ($targetRows as $r) {
    if ($r['translation_group_id'] > 0) $targetCats[(int)$r['translation_group_id']] = $r;
}

$pageTitle = '产品分类翻译';
$currentMenu = 'setting_product_cat_translate';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-4xl">
    <div class="bg-white rounded-lg shadow mb-6 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span class="text-sm text-gray-500">翻译到：</span>
            <?php foreach ($otherLangs as $lc => $ll): ?>
            <a href="?lang=<?php echo e($lc); ?>"
               class="px-4 py-1.5 rounded-full text-sm border transition <?php echo $lc === $targetLang ? 'bg-primary text-white border-primary' : 'text-gray-600 border-gray-200 hover:border-primary hover:text-primary'; ?>">
                <?php echo e($ll); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <a href="/admin/setting_channel_translate.php?lang=<?php echo e($targetLang); ?>" class="text-sm text-gray-400 hover:text-primary">← 栏目翻译</a>
    </div>

    <form id="translateForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save">

        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b flex items-center justify-between">
                <h2 class="font-bold text-gray-800">
                    <?php echo e($allLangs[$defaultLang]); ?> → <?php echo e($allLangs[$targetLang] ?? $targetLang); ?>
                </h2>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="fillDict()" class="text-xs border rounded px-3 py-1.5 hover:bg-gray-50 transition">词典填充</button>
                    <button type="button" onclick="aiTranslate()" class="text-xs border rounded px-3 py-1.5 hover:bg-gray-50 transition text-primary border-primary">AI 翻译空白项</button>
                </div>
            </div>
            <div class="divide-y">
                <?php if (empty($srcCats)): ?>
                <div class="px-6 py-8 text-center text-gray-400 text-sm">暂无产品分类</div>
                <?php endif; ?>

                <?php foreach ($srcCats as $cat):
                    $catId = (int)$cat['id'];
                    $groupId = (int)($cat['translation_group_id'] ?: $catId);
                    $existing = $targetCats[$groupId] ?? null;
                    $dictResult = dictTranslateTo($cat['name'], $targetLang);
                    $currentValue = $existing ? $existing['name'] : '';
                    $indent = $cat['parent_id'] > 0;
                ?>
                <div class="px-6 py-3 flex items-center gap-4 hover:bg-gray-50" data-src-name="<?php echo e($cat['name']); ?>">
                    <div class="w-1/3 text-sm <?php echo $indent ? 'pl-6' : 'font-medium'; ?>">
                        <?php if ($indent): ?><span class="text-gray-300 mr-1">└</span><?php endif; ?>
                        <?php echo e($cat['name']); ?>
                    </div>
                    <div class="flex-1">
                        <input type="text" name="name[<?php echo $catId; ?>]"
                               value="<?php echo e($currentValue); ?>"
                               placeholder="<?php echo e($dictResult ?? ''); ?>"
                               data-dict="<?php echo e($dictResult ?? ''); ?>"
                               class="w-full border rounded px-3 py-1.5 text-sm focus:ring-2 focus:ring-primary focus:border-primary outline-none <?php echo $currentValue ? 'border-green-300' : ($dictResult ? 'border-blue-200' : 'border-gray-200'); ?>">
                    </div>
                    <div class="w-16 text-center">
                        <?php if ($currentValue): ?>
                        <span class="text-xs text-green-500">✓</span>
                        <?php elseif ($dictResult): ?>
                        <span class="text-xs text-blue-400">词典</span>
                        <?php else: ?>
                        <span class="text-xs text-gray-300">—</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="px-6 py-4 border-t flex items-center justify-between">
                <span class="text-xs text-gray-400">
                    共 <?php echo count($srcCats); ?> 个分类，已翻译 <?php echo count(array_filter($targetCats)); ?> 个
                </span>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    保存翻译
                </button>
            </div>
        </div>
    </form>
</div>

<script>
async function aiTranslate() {
    var empties = [];
    document.querySelectorAll('#translateForm input[type=text]').forEach(function(input) {
        if (!input.value && !input.dataset.dict) {
            var row = input.closest('[data-src-name]');
            if (row) empties.push(row.dataset.srcName);
        }
    });
    if (empties.length === 0) {
        showMessage('没有需要 AI 翻译的空白项（全部已有翻译或词典匹配）');
        return;
    }
    if (!confirm('将调用 AI 翻译 ' + empties.length + ' 个分类名，确定？')) return;

    var fd = new FormData();
    fd.append('action', 'ai_translate');
    fd.append('<?php echo CSRF_TOKEN_NAME; ?>', '<?php echo csrfToken(); ?>');
    try {
        var resp = await fetch(location.href, { method: 'POST', body: fd });
        var data = await safeJson(resp);
        if (data.code === 0 && data.data && data.data.translations) {
            var t = data.data.translations;
            var filled = 0;
            document.querySelectorAll('#translateForm [data-src-name]').forEach(function(row) {
                var name = row.dataset.srcName;
                var input = row.querySelector('input[type=text]');
                if (!input.value && t[name]) {
                    input.value = t[name];
                    input.classList.add('border-purple-300');
                    filled++;
                }
            });
            showMessage('AI 翻译填充 ' + filled + ' 个');
        } else {
            showMessage(data.msg || 'AI 翻译失败', 'error');
        }
    } catch(e) { showMessage('请求失败', 'error'); }
}

function fillDict() {
    var filled = 0;
    document.querySelectorAll('#translateForm input[type=text][data-dict]').forEach(function(input) {
        if (!input.value && input.dataset.dict) {
            input.value = input.dataset.dict;
            input.classList.add('border-blue-300');
            filled++;
        }
    });
    showMessage('词典填充 ' + filled + ' 个');
}

document.getElementById('translateForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var fd = new FormData(this);
    var resp = await fetch(location.href, { method: 'POST', body: fd });
    var data = await safeJson(resp);
    if (data.code === 0) {
        showMessage(data.msg || '保存成功');
        setTimeout(function() { location.reload(); }, 800);
    } else {
        showMessage(data.msg || '保存失败', 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
