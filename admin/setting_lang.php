<?php
/**
 * Yikai CMS - 多语言设置
 * 管理可用语言、默认语言、前端语言切换器开关
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 扫描所有语言包
$allLangs = availableLanguages();
$enabledLangsJson = config('enabled_languages', '');
$enabledLangs = $enabledLangsJson ? json_decode($enabledLangsJson, true) : array_keys($allLangs);
$defaultLang = config('site_lang', 'zh-CN');
$adminLang = config('admin_lang', 'zh-CN');
$showSwitcher = config('show_lang_switcher', '0');

// 保存设置
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'save_lang') {
        $selected = $_POST['enabled'] ?? [];
        // 确保默认语言始终启用
        $newDefault = post('default_lang', 'zh-CN');
        $newAdmin = post('admin_lang', 'zh-CN');
        if (!in_array($newDefault, $selected)) $selected[] = $newDefault;

        settingModel()->set('enabled_languages', json_encode(array_values($selected)));
        // 切默认语言：先做行角色归位（<key>_<新默认> 提升为 base、旧默认内容落后缀），
        // 否则后台表单显示旧语言且保存不生效（前台后缀优先）。见 SettingModel 注释。
        if ($newDefault !== $defaultLang) {
            $__moved = settingModel()->normalizeDefaultLangRows($newDefault, $defaultLang);
            if ($__moved > 0) {
                adminLog('setting', 'lang_normalize', "默认语言 {$defaultLang}→{$newDefault}，归位 {$__moved} 个设置键");
            }
        }
        settingModel()->set('site_lang', $newDefault);
        settingModel()->set('admin_lang', $newAdmin);
        settingModel()->set('show_lang_switcher', post('show_switcher', '0'));

        adminLog('setting', 'lang', '更新多语言设置');
        success([], __('save_success'));
    }

    // 批量翻译栏目
    if ($action === 'translate_channels') {
        $targetLang = post('target_lang');
        if (!$targetLang || $targetLang === $defaultLang) {
            error(__('slang_pick_target'));
        }

        // 获取默认语言的所有栏目
        $srcChannels = db()->fetchAll(
            "SELECT * FROM " . DB_PREFIX . "channels WHERE lang = ? ORDER BY parent_id ASC, sort_order ASC, id ASC",
            [$defaultLang]
        );

        // 检查目标语言已有的栏目（按 translation_group_id 避免重复）
        $existingGroups = [];
        $existingRows = db()->fetchAll(
            "SELECT translation_group_id FROM " . DB_PREFIX . "channels WHERE lang = ? AND translation_group_id > 0",
            [$targetLang]
        );
        foreach ($existingRows as $r) $existingGroups[] = (int)$r['translation_group_id'];

        $created = 0;
        $skipped = 0;
        $idMap = []; // 源ID → 新ID（用于父子关系映射）

        foreach ($srcChannels as $ch) {
            $srcId = (int)$ch['id'];
            $groupId = (int)($ch['translation_group_id'] ?: $srcId);

            // 确保源栏目有 group_id
            if (!$ch['translation_group_id']) {
                db()->execute("UPDATE " . DB_PREFIX . "channels SET translation_group_id = ? WHERE id = ?", [$srcId, $srcId]);
            }

            // 已存在则跳过
            if (in_array($groupId, $existingGroups)) {
                // 查已存在的目标栏目 ID 用于父子映射
                $existRow = db()->fetchOne("SELECT id FROM " . DB_PREFIX . "channels WHERE lang = ? AND translation_group_id = ?", [$targetLang, $groupId]);
                if ($existRow) $idMap[$srcId] = (int)$existRow['id'];
                $skipped++;
                continue;
            }

            // 翻译栏目名
            $translatedName = dictTranslateTo($ch['name'], $targetLang) ?? $ch['name'];

            // 映射父ID
            $newParentId = 0;
            if ($ch['parent_id'] > 0 && isset($idMap[(int)$ch['parent_id']])) {
                $newParentId = $idMap[(int)$ch['parent_id']];
            }

            $newData = $ch;
            unset($newData['id']);
            $newData['lang'] = $targetLang;
            $newData['name'] = $translatedName;
            $newData['parent_id'] = $newParentId;
            $newData['translation_group_id'] = $groupId;
            $newData['created_at'] = time();
            $newData['updated_at'] = time();

            $newId = (int)channelModel()->create($newData);
            $idMap[$srcId] = $newId;
            $created++;
        }

        adminLog('setting', 'translate_channels', "批量翻译栏目到 {$targetLang}: 创建 {$created}, 跳过 {$skipped}");
        success([], str_replace([':c', ':s'], [(string) $created, (string) $skipped], __('slang_translate_done')));
    }
}

$pageTitle = __('slang_title');
$currentMenu = 'setting_lang';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-full">
    <form id="langForm" class="space-y-6">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_lang">

        <!-- 上排：启用的语言 / 语言配置（桌面下并排，移动下堆叠） -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 启用的语言 -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800"><?php echo e(__('slang_enabled')); ?></h2>
                <p class="text-sm text-gray-500 mt-1"><?php echo str_replace(':dir', '<code class="bg-gray-100 px-1 rounded">lang/</code>', e(__('slang_enabled_tip'))); ?></p>
            </div>
            <div class="p-6 space-y-3">
                <?php foreach ($allLangs as $code => $label): ?>
                <label class="flex items-center justify-between p-3 rounded-lg hover:bg-gray-50 cursor-pointer border">
                    <div class="flex items-center gap-3">
                        <input type="checkbox" name="enabled[]" value="<?php echo e($code); ?>"
                               <?php echo in_array($code, $enabledLangs) ? 'checked' : ''; ?>
                               class="w-4 h-4 rounded">
                        <div>
                            <span class="font-medium"><?php echo e($label); ?></span>
                            <span class="text-xs text-gray-400 font-mono ml-2"><?php echo e($code); ?></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <?php if ($code === $defaultLang): ?>
                        <span class="text-xs bg-primary text-white px-2 py-0.5 rounded"><?php echo e(__('slang_default_badge')); ?></span>
                        <?php endif; ?>
                        <span class="text-xs text-gray-400">lang/<?php echo e($code); ?>.php</span>
                    </div>
                </label>
                <?php endforeach; ?>

                <?php if (empty($allLangs)): ?>
                <p class="text-gray-400 text-sm text-center py-4"><?php echo e(__('slang_no_packs')); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 默认语言 -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800"><?php echo e(__('slang_config')); ?></h2>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(__('slang_front_default')); ?></label>
                        <select name="default_lang" class="w-full border rounded px-4 py-2">
                            <?php foreach ($allLangs as $code => $label): ?>
                            <option value="<?php echo e($code); ?>" <?php echo $code === $defaultLang ? 'selected' : ''; ?>>
                                <?php echo e($label); ?> (<?php echo e($code); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-400 mt-1"><?php echo e(__('slang_front_default_tip')); ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(__('slang_admin_lang')); ?></label>
                        <select name="admin_lang" class="w-full border rounded px-4 py-2">
                            <?php foreach ($allLangs as $code => $label): ?>
                            <option value="<?php echo e($code); ?>" <?php echo $code === $adminLang ? 'selected' : ''; ?>>
                                <?php echo e($label); ?> (<?php echo e($code); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="show_switcher" value="1" <?php echo $showSwitcher === '1' ? 'checked' : ''; ?> class="w-4 h-4 rounded">
                        <div>
                            <span class="font-medium text-gray-700"><?php echo e(__('slang_switcher')); ?></span>
                            <p class="text-xs text-gray-400"><?php echo e(__('slang_switcher_tip')); ?></p>
                        </div>
                    </label>
                </div>
            </div>
        </div>
        </div><!-- /上排 grid -->

        <!-- 翻译工具（全宽） -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800"><?php echo e(__('slang_tools')); ?></h2>
            </div>
            <div class="p-6 space-y-3">
                <a href="/admin/setting_translate.php" class="flex items-center justify-between p-3 rounded-lg border hover:bg-gray-50 transition">
                    <div class="flex items-center gap-3">
                        <i class="ti ti-language text-lg text-primary"></i>
                        <div>
                            <span class="font-medium text-gray-700"><?php echo e(__('slang_ui_translate')); ?></span>
                            <p class="text-xs text-gray-400"><?php echo e(__('slang_ui_translate_tip')); ?></p>
                        </div>
                    </div>
                    <i class="ti ti-chevron-right text-base text-gray-400"></i>
                </a>

                <?php
                // 自动扫描所有词典文件
                $dictFiles = glob(ROOT_PATH . '/lang/dict-*.php') ?: [];
                $dictLabels = ['zh-en' => __('slang_dict_zh_en'), 'zh-ja' => __('slang_dict_zh_ja'), 'zh-ko' => __('slang_dict_zh_ko'), 'zh-fr' => __('slang_dict_zh_fr'), 'zh-de' => __('slang_dict_zh_de'), 'zh-es' => __('slang_dict_zh_es')];
                ?>
                <?php if (!empty($dictFiles)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    <?php foreach ($dictFiles as $df):
                        $dictCode = str_replace(['dict-', '.php'], '', basename($df));
                        $dictLabel = $dictLabels[$dictCode] ?? $dictCode . ' ' . __('slang_dict_word');
                        $dictData = require $df;
                        $dictCount = count($dictData);
                    ?>
                    <div class="flex items-center justify-between p-3 rounded-lg border bg-gray-50 min-w-0">
                        <div class="flex items-center gap-3 min-w-0">
                            <i class="ti ti-book text-lg text-blue-500 flex-shrink-0"></i>
                            <div class="min-w-0">
                                <span class="font-medium text-gray-700"><?php echo e($dictLabel); ?></span>
                                <p class="text-xs text-gray-400 truncate"><?php echo str_replace(':n', (string) $dictCount, e(__('slang_dict_entries'))); ?> · lang/dict-<?php echo e($dictCode); ?>.php</p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="p-3 text-sm text-gray-400 text-center"><?php echo e(__('slang_no_dicts')); ?></div>
                <?php endif; ?>

                <div class="text-xs text-gray-400 mt-2 px-3">
                    <strong><?php echo e(__('slang_flow_label')); ?></strong><?php echo e(__('slang_flow_desc')); ?>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-2">
                <i class="ti ti-check text-base"></i>
                <?php echo e(__('save_settings')); ?>
            </button>
        </div>
    </form>

    <!-- 栏目翻译入口 -->
    <?php
    $otherLangs = $allLangs;
    unset($otherLangs[$defaultLang]);
    ?>
    <?php if (!empty($otherLangs)): ?>
    <div class="bg-white rounded-lg shadow mt-6">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('slang_channel_translate')); ?></h2>
            <p class="text-sm text-gray-500 mt-1"><?php echo e(__('slang_channel_translate_tip')); ?></p>
        </div>
        <div class="p-6 flex flex-wrap gap-3">
            <?php foreach ($otherLangs as $lc => $ll):
                $existCount = (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "channels WHERE lang = ?", [$lc]);
            ?>
            <a href="/admin/setting_channel_translate.php?lang=<?php echo e($lc); ?>"
               class="inline-flex items-center gap-3 px-5 py-3 rounded-lg border transition hover:shadow
               <?php echo $existCount > 0 ? 'border-green-300 bg-green-50' : 'border-gray-200 hover:border-primary'; ?>">
                <svg class="w-5 h-5 <?php echo $existCount > 0 ? 'text-green-500' : 'text-gray-400'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
                <div>
                    <span class="font-medium text-gray-700"><?php echo e($ll); ?></span>
                    <?php if ($existCount > 0): ?>
                    <span class="text-xs text-green-600 ml-1">✓ <?php echo str_replace(':n', (string) $existCount, e(__('slang_n_channels'))); ?></span>
                    <?php else: ?>
                    <span class="text-xs text-gray-400 ml-1"><?php echo e(__('slang_untranslated')); ?></span>
                    <?php endif; ?>
                </div>
                <i class="ti ti-chevron-right text-base text-gray-300"></i>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('langForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var fd = new FormData(this);
    var resp = await fetch('', { method: 'POST', body: fd });
    var data = await safeJson(resp);
    if (data.code === 0) {
        showMessage(data.msg || <?php echo json_encode(__('save_success'), JSON_UNESCAPED_UNICODE); ?>);
        setTimeout(() => location.reload(), 800);
    } else {
        showMessage(data.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
