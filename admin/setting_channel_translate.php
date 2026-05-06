<?php
/**
 * Yikai CMS - 栏目翻译管理
 * 逐条翻译栏目名到目标语言，支持手工编辑 + AI 批量翻译
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

// 自动修正：slug 以语言前缀开头但 lang 值不匹配的记录
foreach ($otherLangs as $lc => $ll) {
    db()->execute(
        "UPDATE " . DB_PREFIX . "channels SET lang = ? WHERE slug LIKE ? AND lang != ?",
        [$lc, $lc . '-%', $lc]
    );
}

// 源栏目（仅默认语言且非翻译栏目）
$srcChannels = db()->fetchAll(
    "SELECT * FROM " . DB_PREFIX . "channels WHERE lang = ? ORDER BY parent_id ASC, sort_order ASC, id ASC",
    [$defaultLang]
);

// 目标语言已有栏目（按 translation_group_id 索引）
$targetChannels = [];
$targetRows = db()->fetchAll(
    "SELECT * FROM " . DB_PREFIX . "channels WHERE lang = ?",
    [$targetLang]
);
foreach ($targetRows as $r) {
    if ($r['translation_group_id'] > 0) {
        $targetChannels[(int)$r['translation_group_id']] = $r;
    }
}

// POST 处理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    // 保存手工编辑
    if ($action === 'save') {
        // 保存系统固定项到语言包
        $sysItems = $_POST['sys'] ?? [];
        if (!empty($sysItems)) {
            $langFile = ROOT_PATH . '/lang/' . $targetLang . '.php';
            if (file_exists($langFile)) {
                $langData = require $langFile;
                foreach ($sysItems as $k => $v) {
                    $v = trim($v);
                    if ($v !== '') $langData[$k] = $v;
                }
                $export = "<?php\nreturn " . var_export($langData, true) . ";\n";
                file_put_contents($langFile, $export);
            }
        }

        $names = $_POST['name'] ?? [];
        $created = 0;
        $updated = 0;
        $idMap = [];

        try {
            foreach ($srcChannels as $ch) {
                $srcId = (int)$ch['id'];
                $groupId = (int)($ch['translation_group_id'] ?: $srcId);
                if (!$ch['translation_group_id']) {
                    db()->execute("UPDATE " . DB_PREFIX . "channels SET translation_group_id = ? WHERE id = ?", [$srcId, $srcId]);
                }

                $newName = trim($names[$srcId] ?? '');
                if ($newName === '') continue;

                if (isset($targetChannels[$groupId])) {
                    db()->execute("UPDATE " . DB_PREFIX . "channels SET name = ?, lang = ? WHERE id = ?",
                        [$newName, $targetLang, (int)$targetChannels[$groupId]['id']]);
                    $idMap[$srcId] = (int)$targetChannels[$groupId]['id'];
                    $updated++;
                } else {
                    $parentId = 0;
                    if ($ch['parent_id'] > 0 && isset($idMap[(int)$ch['parent_id']])) {
                        $parentId = $idMap[(int)$ch['parent_id']];
                    }
                    $slug = $ch['slug'] ?? '';
                    if ($slug) $slug = $targetLang . '-' . $slug;

                    // Check if slug already exists, update it instead of inserting
                    if ($slug) {
                        $existing = db()->fetchOne("SELECT id FROM " . DB_PREFIX . "channels WHERE slug = ?", [$slug]);
                        if ($existing) {
                            db()->execute("UPDATE " . DB_PREFIX . "channels SET name = ?, lang = ?, translation_group_id = ?, updated_at = ? WHERE id = ?",
                                [$newName, $targetLang, $groupId, time(), (int)$existing['id']]);
                            $idMap[$srcId] = (int)$existing['id'];
                            $updated++;
                            continue;
                        }
                    }

                    $newData = [
                        'parent_id'            => $parentId,
                        'name'                 => $newName,
                        'slug'                 => $slug,
                        'type'                 => $ch['type'] ?? 'list',
                        'lang'                 => $targetLang,
                        'translation_group_id' => $groupId,
                        'album_id'             => (int)($ch['album_id'] ?? 0),
                        'icon'                 => $ch['icon'] ?? '',
                        'image'                => $ch['image'] ?? '',
                        'description'          => $ch['description'] ?? '',
                        'content'              => $ch['content'] ?? '',
                        'link_url'             => $ch['link_url'] ?? '',
                        'link_target'          => $ch['link_target'] ?? '_self',
                        'redirect_type'        => $ch['redirect_type'] ?? 'auto',
                        'redirect_url'         => $ch['redirect_url'] ?? '',
                        'seo_title'            => '',
                        'seo_keywords'         => '',
                        'seo_description'      => '',
                        'is_nav'               => (int)($ch['is_nav'] ?? 1),
                        'is_home'              => (int)($ch['is_home'] ?? 0),
                        'status'               => (int)($ch['status'] ?? 1),
                        'is_system'            => 0,
                        'sort_order'           => (int)($ch['sort_order'] ?? 0),
                        'created_at'           => time(),
                        'updated_at'           => time(),
                    ];
                    $newId = (int)db()->insert('channels', $newData);
                    $idMap[$srcId] = $newId;
                    $created++;
                }
            }
        } catch (\Throwable $e) {
            error('保存失败: ' . $e->getMessage());
        }

        adminLog('setting', 'channel_translate', "栏目翻译({$targetLang}): 创建{$created}, 更新{$updated}");
        success([], "保存成功：创建 {$created} 个，更新 {$updated} 个");
    }

    // AI 批量翻译（填充空白项）
    if ($action === 'ai_translate') {
        // 收集未翻译的栏目名
        $toTranslate = [];
        foreach ($srcChannels as $ch) {
            $groupId = (int)($ch['translation_group_id'] ?: $ch['id']);
            if (isset($targetChannels[$groupId])) continue;
            $dictResult = dictTranslateTo($ch['name'], $targetLang);
            if ($dictResult) continue;
            $toTranslate[] = $ch['name'];
        }

        if (empty($toTranslate)) {
            success([], '所有栏目已有翻译或词典匹配，无需 AI 翻译');
            exit;
        }

        // 调用 AI 翻译
        require_once ROOT_PATH . '/includes/AiService.php';
        $encryptedKey = config('ai_api_key', '');
        $aiKey = $encryptedKey ? AiService::decryptKey($encryptedKey) : '';
        if (!$aiKey) {
            error('请先在 AI 设置中配置 API Key');
        }

        $langName = $allLangs[$targetLang] ?? $targetLang;
        $prompt = "Translate the following Chinese website menu/category names to {$langName}. Return ONLY a JSON object mapping Chinese to translation. No explanation.\n\n" . json_encode($toTranslate, JSON_UNESCAPED_UNICODE);

        $ai = new AiService(
            config('ai_provider', 'openai'),
            $aiKey,
            config('ai_model', 'gpt-4o-mini')
        );
        $result = $ai->chat($prompt, 'You are a professional translator. Return only valid JSON.', 0.3);

        if (!$result['success']) {
            error('AI 翻译失败: ' . ($result['error'] ?? '未知错误'));
        }

        $content = $result['content'] ?? '';
        $content = preg_replace('/^```json\s*|```\s*$/m', '', trim($content));
        $translations = json_decode($content, true);

        if (!is_array($translations)) {
            error('AI 返回格式异常');
        }

        success(['translations' => $translations], 'AI 翻译完成，请确认后保存');
    }
}

// 重新加载目标栏目（可能刚保存过）
$targetRows = db()->fetchAll("SELECT * FROM " . DB_PREFIX . "channels WHERE lang = ?", [$targetLang]);
$targetChannels = [];
foreach ($targetRows as $r) {
    if ($r['translation_group_id'] > 0) $targetChannels[(int)$r['translation_group_id']] = $r;
}


$pageTitle = '栏目翻译';
$currentMenu = 'setting_channel_translate';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-4xl">
    <!-- 语言选择 -->
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
        <a href="/admin/setting_lang.php" class="text-sm text-gray-400 hover:text-primary">← 多语言设置</a>
    </div>

    <!-- 翻译表格 -->
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
                <?php
                // 系统固定导航项（非数据库栏目，通过语言包翻译）
                $sysNavItems = [
                    'nav_home' => '首页',
                    'nav_contact' => '联系我们',
                    'footer_privacy' => '隐私政策',
                    'footer_terms' => '服务条款',
                    'footer_contact' => '联系方式',
                    'footer_follow' => '关注我们',
                    'footer_copyright' => '版权所有',
                ];
                // 加载目标语言包
                $targetLangFile = ROOT_PATH . '/lang/' . $targetLang . '.php';
                $targetLangData = file_exists($targetLangFile) ? require $targetLangFile : [];
                ?>
                <?php foreach ($sysNavItems as $langKey => $zhName):
                    $currentVal = $targetLangData[$langKey] ?? '';
                    $dictVal = dictTranslateTo($zhName, $targetLang) ?? '';
                ?>
                <div class="px-6 py-3 flex items-center gap-4 bg-blue-50/50" data-src-name="<?php echo e($zhName); ?>">
                    <div class="w-1/3 text-sm font-medium">
                        <?php echo e($zhName); ?>
                        <span class="text-xs text-blue-400 ml-1">系统</span>
                    </div>
                    <div class="flex-1">
                        <input type="text" name="sys[<?php echo e($langKey); ?>]"
                               value="<?php echo e($currentVal); ?>"
                               placeholder="<?php echo e($dictVal); ?>"
                               data-dict="<?php echo e($dictVal); ?>"
                               class="w-full border rounded px-3 py-1.5 text-sm focus:ring-2 focus:ring-primary focus:border-primary outline-none <?php echo $currentVal ? 'border-green-300' : ($dictVal ? 'border-blue-200' : 'border-gray-200'); ?>">
                    </div>
                    <div class="w-16 text-center">
                        <?php if ($currentVal): ?>
                        <span class="text-xs text-green-500">✓</span>
                        <?php elseif ($dictVal): ?>
                        <span class="text-xs text-blue-400">词典</span>
                        <?php else: ?>
                        <span class="text-xs text-gray-300">—</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php foreach ($srcChannels as $ch):
                    $srcId = (int)$ch['id'];
                    $groupId = (int)($ch['translation_group_id'] ?: $srcId);
                    $existing = $targetChannels[$groupId] ?? null;
                    $dictResult = dictTranslateTo($ch['name'], $targetLang);
                    $currentValue = $existing ? $existing['name'] : '';
                    $indent = $ch['parent_id'] > 0;
                ?>
                <div class="px-6 py-3 flex items-center gap-4 hover:bg-gray-50" data-src-id="<?php echo $srcId; ?>" data-src-name="<?php echo e($ch['name']); ?>">
                    <div class="w-1/3 text-sm <?php echo $indent ? 'pl-6' : 'font-medium'; ?>">
                        <?php if ($indent): ?><span class="text-gray-300 mr-1">└</span><?php endif; ?>
                        <?php echo e($ch['name']); ?>
                    </div>
                    <div class="flex-1">
                        <input type="text" name="name[<?php echo $srcId; ?>]"
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
            <div class="px-6 py-4 border-t">
                <span class="text-xs text-gray-400">
                    共 <?php echo count($srcChannels); ?> 个栏目，
                    已翻译 <?php echo count(array_filter($targetChannels)); ?> 个
                </span>
            </div>
        </div>

            <div class="px-6 py-4 border-t flex items-center justify-between">
                <span class="text-xs text-gray-400">
                    共 <?php echo count($srcChannels); ?> 个栏目，已翻译 <?php echo count(array_filter($targetChannels)); ?> 个
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
// 词典填充：把 placeholder 里的词典值填入空白输入框
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

// AI 翻译空白项
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
    if (!confirm('将调用 AI 翻译 ' + empties.length + ' 个栏目名，确定？')) return;

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

// 表单提交
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
