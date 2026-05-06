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
        settingModel()->set('site_lang', $newDefault);
        settingModel()->set('admin_lang', $newAdmin);
        settingModel()->set('show_lang_switcher', post('show_switcher', '0'));

        adminLog('setting', 'lang', '更新多语言设置');
        success([], '保存成功');
    }

    // 批量翻译栏目
    if ($action === 'translate_channels') {
        $targetLang = post('target_lang');
        if (!$targetLang || $targetLang === $defaultLang) {
            error('请选择目标语言');
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
        success([], "翻译完成：创建 {$created} 个栏目，跳过 {$skipped} 个已有栏目");
    }
}

$pageTitle = '多语言设置';
$currentMenu = 'setting_lang';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl">
    <form id="langForm" class="space-y-6">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_lang">

        <!-- 启用的语言 -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800">启用的语言</h2>
                <p class="text-sm text-gray-500 mt-1">勾选要启用的语言，新增语言需先在 <code class="bg-gray-100 px-1 rounded">lang/</code> 目录添加语言包文件</p>
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
                        <span class="text-xs bg-primary text-white px-2 py-0.5 rounded">默认</span>
                        <?php endif; ?>
                        <span class="text-xs text-gray-400">lang/<?php echo e($code); ?>.php</span>
                    </div>
                </label>
                <?php endforeach; ?>

                <?php if (empty($allLangs)): ?>
                <p class="text-gray-400 text-sm text-center py-4">未找到语言包文件</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- 默认语言 -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800">语言配置</h2>
            </div>
            <div class="p-6 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">前台默认语言</label>
                        <select name="default_lang" class="w-full border rounded px-4 py-2">
                            <?php foreach ($allLangs as $code => $label): ?>
                            <option value="<?php echo e($code); ?>" <?php echo $code === $defaultLang ? 'selected' : ''; ?>>
                                <?php echo e($label); ?> (<?php echo e($code); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">访客首次访问时默认显示的语言</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">后台语言</label>
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
                            <span class="font-medium text-gray-700">显示前台语言切换器</span>
                            <p class="text-xs text-gray-400">在前台页面顶部显示语言切换下拉菜单（需启用 2 种以上语言）</p>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!-- 翻译工具 -->
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800">翻译工具</h2>
            </div>
            <div class="p-6 space-y-3">
                <a href="/admin/setting_translate.php" class="flex items-center justify-between p-3 rounded-lg border hover:bg-gray-50 transition">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
                        <div>
                            <span class="font-medium text-gray-700">界面翻译管理</span>
                            <p class="text-xs text-gray-400">编辑语言包中的界面文案翻译</p>
                        </div>
                    </div>
                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>

                <?php
                // 自动扫描所有词典文件
                $dictFiles = glob(ROOT_PATH . '/lang/dict-*.php') ?: [];
                $dictLabels = ['zh-en' => '中英词典', 'zh-ja' => '中日词典', 'zh-ko' => '中韩词典', 'zh-fr' => '中法词典', 'zh-de' => '中德词典', 'zh-es' => '中西词典'];
                foreach ($dictFiles as $df):
                    $dictCode = str_replace(['dict-', '.php'], '', basename($df));
                    $dictLabel = $dictLabels[$dictCode] ?? $dictCode . ' 词典';
                    $dictData = require $df;
                    $dictCount = count($dictData);
                ?>
                <div class="flex items-center justify-between p-3 rounded-lg border bg-gray-50">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                        <div>
                            <span class="font-medium text-gray-700"><?php echo e($dictLabel); ?></span>
                            <p class="text-xs text-gray-400"><?php echo $dictCount; ?> 个词条，「翻译为...」按钮优先查词典</p>
                        </div>
                    </div>
                    <span class="text-xs text-gray-400 font-mono">lang/dict-<?php echo e($dictCode); ?>.php</span>
                </div>
                <?php endforeach; ?>

                <?php if (empty($dictFiles)): ?>
                <div class="p-3 text-sm text-gray-400 text-center">暂无词典文件</div>
                <?php endif; ?>

                <div class="text-xs text-gray-400 mt-2 px-3">
                    <strong>翻译流程：</strong>编辑内容时点击「翻译为 English →」→ 先查词典快速翻译标题 → 未命中调 AI API → 创建英文版本
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                保存设置
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
            <h2 class="font-bold text-gray-800">栏目翻译</h2>
            <p class="text-sm text-gray-500 mt-1">将栏目名称翻译到其他语言，支持手工编辑和 AI 批量翻译</p>
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
                    <span class="text-xs text-green-600 ml-1">✓ <?php echo $existCount; ?> 个栏目</span>
                    <?php else: ?>
                    <span class="text-xs text-gray-400 ml-1">未翻译</span>
                    <?php endif; ?>
                </div>
                <svg class="w-4 h-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
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
        showMessage(data.msg || '保存成功');
        setTimeout(() => location.reload(), 800);
    } else {
        showMessage(data.msg || '保存失败', 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
