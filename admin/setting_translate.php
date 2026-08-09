<?php
/**
 * YikaiCMS - 翻译管理
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$settings = new SettingModel();

// 可用语言（zh-CN 为源语言，其它为可翻译目标）
$languages = ['zh-CN' => '中文', 'en' => 'English', 'ja' => '日本語'];
// 仅保留前台启用的目标语言（与 setting.php"前台语言"配置联动；源 zh-CN 始终保留）
$enabledRaw = trim((string) config('enabled_languages', ''));
$enabledList = $enabledRaw !== '' ? json_decode($enabledRaw, true) : null;
if (is_array($enabledList) && $enabledList !== []) {
    $allow = array_flip($enabledList);
    $allow['zh-CN'] = 0;
    $languages = array_intersect_key($languages, $allow);
}
$targetLang = $_GET['lang'] ?? '';
if (!isset($languages[$targetLang]) || $targetLang === 'zh-CN') {
    // 默认指向第一个非源语言
    $firstTarget = 'ja';
    foreach ($languages as $c => $_n) {
        if ($c !== 'zh-CN') { $firstTarget = $c; break; }
    }
    $targetLang = $firstTarget;
}

// 源语言包（中文）
$sourceLangFile = ROOT_PATH . '/lang/zh-CN.php';
$sourceData = file_exists($sourceLangFile) ? require $sourceLangFile : [];

// 目标语言包
$targetLangFile = ROOT_PATH . '/lang/' . $targetLang . '.php';
$targetData = file_exists($targetLangFile) ? require $targetLangFile : [];

// 保存 API 配置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_api') {
    verifyCsrf();
    $settings->set('translate_api', $_POST['api'] ?? 'deepl');
    $settings->set('translate_api_key', $_POST['api_key'] ?? '');
    success([], __('tr_api_config_saved'));
}

// API 翻译单条
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'translate_one') {
    verifyCsrf();
    $text = $_POST['text'] ?? '';
    $result = apiTranslate($text, $targetLang);
    if ($result !== false) {
        success(['translated' => $result]);
    }
    error(__('tr_failed_check_api'));
}

// API 批量翻译未翻译的条目
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'translate_batch') {
    verifyCsrf();
    $untranslated = [];
    foreach ($sourceData as $key => $value) {
        if (!isset($targetData[$key]) || $targetData[$key] === $value) {
            $untranslated[$key] = $value;
        }
    }

    if (empty($untranslated)) {
        success(['count' => 0], __('tr_all_done'));
    }

    $translated = 0;
    foreach ($untranslated as $key => $value) {
        $result = apiTranslate($value, $targetLang);
        if ($result !== false) {
            $targetData[$key] = $result;
            $translated++;
        }
        usleep(100000); // 100ms 间隔，避免 API 限流
    }

    // 保存
    saveLangFile($targetLangFile, $targetLang, $targetData);
    success(['count' => $translated], str_replace(':n', (string) $translated, __('tr_translated_count')));
}

// 已确认"同源即翻译"清单的存放路径
$confirmedFile = ROOT_PATH . '/lang/' . $targetLang . '.confirmed.json';

// 加载已确认清单
function loadConfirmedKeys(string $file): array {
    if (!is_file($file)) return [];
    $arr = json_decode((string) file_get_contents($file), true);
    return is_array($arr) ? $arr : [];
}

// 保存已确认清单
function saveConfirmedKeys(string $file, array $keys): void {
    $unique = array_values(array_unique(array_filter($keys, 'is_string')));
    sort($unique);
    file_put_contents($file, json_encode($unique, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// 确认某 key 的"同源"为正常翻译（追加到 confirmed.json）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_same') {
    verifyCsrf();
    $key = (string) ($_POST['key'] ?? '');
    if ($key === '' || !isset($sourceData[$key])) error(__('tr_invalid_key'));
    $confirmed = loadConfirmedKeys($confirmedFile);
    if (!in_array($key, $confirmed, true)) {
        $confirmed[] = $key;
        saveConfirmedKeys($confirmedFile, $confirmed);
    }
    success([], __('tr_confirmed'));
}

// 取消确认（误点回退）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unconfirm_same') {
    verifyCsrf();
    $key = (string) ($_POST['key'] ?? '');
    $confirmed = loadConfirmedKeys($confirmedFile);
    $confirmed = array_values(array_filter($confirmed, fn($k) => $k !== $key));
    saveConfirmedKeys($confirmedFile, $confirmed);
    success([], __('tr_unconfirmed'));
}

// 批量确认：把当前目标语言里所有"未确认且同源"的 key 一次性加入 confirmed 清单
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_same_all') {
    verifyCsrf();
    $confirmed = loadConfirmedKeys($confirmedFile);
    $confirmedSet = array_flip($confirmed);
    $added = 0;
    foreach ($sourceData as $key => $value) {
        if (!isset($targetData[$key])) continue;
        if (trim((string) $targetData[$key]) === '') continue;
        if ((string) $targetData[$key] !== (string) $value) continue;  // 仅同源
        if (isset($confirmedSet[$key])) continue;                       // 已确认过的跳
        $confirmed[] = $key;
        $confirmedSet[$key] = true;
        $added++;
    }
    saveConfirmedKeys($confirmedFile, $confirmed);
    adminLog('translate', 'confirm_same_all', "批量确认 {$targetLang}: +{$added} 条");
    success(['added' => $added], str_replace(':n', (string) $added, __('tr_confirmed_count')));
}

// 批量保存所有翻译
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCsrf();
    $translations = $_POST['translations'] ?? [];
    $changed = 0;
    foreach ($translations as $key => $value) {
        if (!isset($sourceData[$key])) continue;
        $value = trim((string) $value);
        if ($value === '') continue;
        if (isset($targetData[$key]) && $targetData[$key] === $value) continue;
        $targetData[$key] = $value;
        $changed++;
    }
    if ($changed > 0) saveLangFile($targetLangFile, $targetLang, $targetData);
    adminLog('translate', 'save', "保存翻译 {$targetLang}: 改动 {$changed} 条");
    success(['changed' => $changed], str_replace(':n', (string) $changed, __('tr_save_changed')));
}

// 单行保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_one') {
    verifyCsrf();
    $key   = (string) ($_POST['key']   ?? '');
    $value = trim((string) ($_POST['value'] ?? ''));
    if ($key === '' || !isset($sourceData[$key])) error(__('tr_invalid_key'));
    if ($value === '') error(__('tr_translation_required'));
    $targetData[$key] = $value;
    saveLangFile($targetLangFile, $targetLang, $targetData);
    adminLog('translate', 'save_one', "保存 {$targetLang}/{$key}");
    success([], __('admin_saved'));
}

// 翻译 API 调用
function apiTranslate(string $text, string $targetLang): string|false
{
    $settings = new SettingModel();
    $api = $settings->get('translate_api', 'deepl');
    $apiKey = $settings->get('translate_api_key', '');

    if (empty($apiKey)) return false;

    if ($api === 'deepl') {
        return deeplTranslate($text, $targetLang, $apiKey);
    } elseif ($api === 'google') {
        return googleTranslate($text, $targetLang, $apiKey);
    }
    return false;
}

function deeplTranslate(string $text, string $targetLang, string $apiKey): string|false
{
    $langMap = ['ja' => 'JA', 'zh-CN' => 'ZH'];
    $target = $langMap[$targetLang] ?? strtoupper($targetLang);

    // 判断是免费版还是付费版
    $baseUrl = str_contains($apiKey, ':fx') ? 'https://api-free.deepl.com' : 'https://api.deepl.com';

    $ch = curl_init($baseUrl . '/v2/translate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'text' => $text,
            'source_lang' => 'ZH',
            'target_lang' => $target,
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: DeepL-Auth-Key ' . $apiKey,
            'Content-Type: application/x-www-form-urlencoded',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return false;

    $data = json_decode($response, true);
    return $data['translations'][0]['text'] ?? false;
}

function googleTranslate(string $text, string $targetLang, string $apiKey): string|false
{
    $langMap = ['ja' => 'ja', 'zh-CN' => 'zh-CN'];
    $target = $langMap[$targetLang] ?? $targetLang;

    $url = 'https://translation.googleapis.com/language/translate/v2?' . http_build_query([
        'key' => $apiKey,
        'q' => $text,
        'source' => 'zh-CN',
        'target' => $target,
        'format' => 'text',
    ]);

    $response = @file_get_contents($url);
    if ($response === false) return false;

    $data = json_decode($response, true);
    return $data['data']['translations'][0]['translatedText'] ?? false;
}

function saveLangFile(string $file, string $lang, array $data): void
{
    $langNames = ['ja' => '日本語言語パック', 'zh-CN' => '中文语言包'];
    $content = "<?php\n/**\n * YikaiCMS - " . ($langNames[$lang] ?? $lang) . "\n */\n\nreturn [\n";

    $currentGroup = '';
    foreach ($data as $key => $value) {
        // 按前缀分组加注释
        $prefix = explode('_', $key)[0] ?? '';
        if ($prefix !== $currentGroup) {
            $currentGroup = $prefix;
            $content .= "\n";
        }
        $escapedValue = str_replace("'", "\\'", $value);
        $content .= "    '{$key}' => '{$escapedValue}',\n";
    }

    $content .= "];\n";
    file_put_contents($file, $content);
}

// 已确认"同源即翻译"的 key 集合（这些不再被标存疑）
$confirmedKeys = array_flip(loadConfirmedKeys($confirmedFile));

// 统计：只看 key 在目标语言里有没有非空值；不再用"值 != 源"判定，
// 因为 CJK 共用汉字、品牌名/技术词等场景下故意保持一致是合理的。
$totalKeys = count($sourceData);
$translatedKeys = 0;
$untranslatedKeys = 0;
$sameAsSourceKeys = 0;   // 子集：已翻译且值与源相同 + 未确认（确认过的不计入存疑）
foreach ($sourceData as $key => $value) {
    $hasTarget = isset($targetData[$key]) && trim((string) $targetData[$key]) !== '';
    if ($hasTarget) {
        $translatedKeys++;
        if ($targetData[$key] === $value && !isset($confirmedKeys[$key])) $sameAsSourceKeys++;
    } else {
        $untranslatedKeys++;
    }
}

$filter = $_GET['filter'] ?? 'all'; // all, untranslated, translated

$pageTitle = __('admin_system') . ' - ' . __('tr_title');
$currentMenu = 'translate';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-full">
    <!-- API 配置 -->
    <div class="bg-white rounded-lg shadow-sm p-5 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-gray-800 text-sm"><?php echo e(__('tr_api_config')); ?></h3>
            <button type="button" onclick="saveApiConfig()" class="bg-primary hover:bg-secondary text-white px-4 py-1.5 rounded text-sm"><?php echo e(__('tr_save_config')); ?></button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1"><?php echo e(__('tr_service')); ?></label>
                <select id="apiProvider" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="deepl" <?php echo $settings->get('translate_api', 'deepl') === 'deepl' ? 'selected' : ''; ?>><?php echo e(__('tr_deepl_option')); ?></option>
                    <option value="google" <?php echo $settings->get('translate_api', '') === 'google' ? 'selected' : ''; ?>>Google Translate</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs text-gray-500 mb-1">API Key <a href="https://www.deepl.com/pro-api" target="_blank" class="text-blue-500 hover:underline"><?php echo e(__('tr_deepl_register')); ?></a></label>
                <input type="text" id="apiKey" value="<?php echo e($settings->get('translate_api_key', '')); ?>" placeholder="<?php echo e(__('tr_api_key_placeholder')); ?>"
                       class="w-full border rounded px-3 py-2 text-sm font-mono">
            </div>
        </div>
    </div>

    <!-- 概览 -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm p-4 text-center">
            <div class="text-2xl font-bold text-gray-800"><?php echo $totalKeys; ?></div>
            <div class="text-sm text-gray-500"><?php echo e(__('tr_total')); ?></div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4 text-center">
            <div class="text-2xl font-bold text-green-600"><?php echo $translatedKeys; ?></div>
            <div class="text-sm text-gray-500"><?php echo e(__('tr_translated')); ?></div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4 text-center">
            <div class="text-2xl font-bold text-orange-500"><?php echo $untranslatedKeys; ?></div>
            <div class="text-sm text-gray-500"><?php echo e(__('tr_untranslated')); ?></div>
        </div>
    </div>

    <!-- 工具栏 -->
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <label class="text-sm text-gray-600"><?php echo e(__('tr_target_language')); ?></label>
            <select onchange="location.href='?lang='+this.value+'&filter=<?php echo $filter; ?>'" class="border rounded px-3 py-1.5 text-sm">
                <?php foreach ($languages as $code => $name): if ($code === 'zh-CN') continue; ?>
                <option value="<?php echo $code; ?>" <?php echo $targetLang === $code ? 'selected' : ''; ?>><?php echo $name; ?></option>
                <?php endforeach; ?>
            </select>

            <div class="flex border rounded overflow-hidden text-sm">
                <a href="?lang=<?php echo $targetLang; ?>&filter=all" class="px-3 py-1.5 <?php echo $filter === 'all' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>"><?php echo e(__('tr_all')); ?> <?php echo $totalKeys; ?></a>
                <a href="?lang=<?php echo $targetLang; ?>&filter=untranslated" class="px-3 py-1.5 border-l <?php echo $filter === 'untranslated' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>"><?php echo e(__('tr_untranslated')); ?> <?php echo $untranslatedKeys; ?></a>
                <a href="?lang=<?php echo $targetLang; ?>&filter=translated" class="px-3 py-1.5 border-l <?php echo $filter === 'translated' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>"><?php echo e(__('tr_translated')); ?> <?php echo $translatedKeys; ?></a>
                <a href="?lang=<?php echo $targetLang; ?>&filter=same" class="px-3 py-1.5 border-l <?php echo $filter === 'same' ? 'bg-amber-500 text-white' : 'bg-white text-amber-600 hover:bg-amber-50'; ?>" title="<?php echo e(__('tr_suspect_title')); ?>"><?php echo e(__('tr_suspect')); ?> <?php echo $sameAsSourceKeys; ?></a>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <?php if ($sameAsSourceKeys > 0): ?>
            <button onclick="confirmSameAll()" class="bg-amber-500 hover:bg-amber-600 text-white px-4 py-1.5 rounded text-sm inline-flex items-center gap-1" title="<?php echo e(__('tr_confirm_all_title')); ?>">
                <i class="ti ti-check text-base"></i>
                <?php echo e(str_replace(':n', (string) $sameAsSourceKeys, __('tr_confirm_all'))); ?>
            </button>
            <?php endif; ?>
            <button onclick="batchTranslate()" id="btnBatch" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-1.5 rounded text-sm inline-flex items-center gap-1">
                <i class="ti ti-language text-base"></i>
                <?php echo e(str_replace(':n', (string) $untranslatedKeys, __('tr_api_translate'))); ?>
            </button>
            <button onclick="saveAll()" class="bg-primary hover:bg-secondary text-white px-4 py-1.5 rounded text-sm">
                <?php echo __('admin_save'); ?>
            </button>
        </div>
    </div>

    <!-- 翻译列表 -->
    <form id="translateForm">
        <input type="hidden" name="_token" value="<?php echo csrfToken(); ?>">
        <input type="hidden" name="action" value="save">

        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <table class="w-full table-fixed">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 w-44">Key</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 w-1/4"><?php echo e(__('tr_source_chinese')); ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500"><?php echo $languages[$targetLang]; ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 w-28"><?php echo __('admin_action'); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php
                    foreach ($sourceData as $key => $value):
                        $translated = $targetData[$key] ?? '';
                        $isTranslated = trim((string) $translated) !== '';
                        $isLiterallyEqual = $isTranslated && ((string) $translated === (string) $value);
                        $isConfirmed = isset($confirmedKeys[$key]);
                        // 存疑：值同源 + 未确认。已确认过的同源不再标黄。
                        $isSameAsSource = $isLiterallyEqual && !$isConfirmed;

                        if ($filter === 'untranslated' && $isTranslated) continue;
                        if ($filter === 'translated' && !$isTranslated) continue;
                        if ($filter === 'same' && !$isSameAsSource) continue;
                    ?>
                    <tr class="hover:bg-gray-50" data-key="<?php echo e($key); ?>">
                        <td class="px-4 py-2 text-xs text-gray-400 font-mono">
                            <?php echo e($key); ?>
                            <?php if ($isSameAsSource): ?>
                            <span data-same-status class="ml-1 text-[10px] text-amber-600" title="<?php echo e(__('tr_same_title')); ?>">⚠ <?php echo e(__('tr_same')); ?></span>
                            <?php elseif ($isLiterallyEqual && $isConfirmed): ?>
                            <span class="ml-1 text-[10px] text-green-600" title="<?php echo e(__('tr_confirmed_title')); ?>" onclick="unconfirmSame('<?php echo e($key); ?>', this)" style="cursor:pointer">✓ <?php echo e(__('tr_confirmed_badge')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-i18n-source class="px-4 py-2 text-sm text-gray-700 break-words align-top"><?php echo e($value); ?></td>
                        <td class="px-4 py-2">
                            <input type="text" name="translations[<?php echo e($key); ?>]" value="<?php echo e($translated); ?>"
                                   class="w-full px-2 py-1 border rounded text-sm <?php
                                        if (!$isTranslated) echo 'border-orange-200 bg-orange-50';
                                        elseif ($isSameAsSource) echo 'border-amber-200 bg-amber-50';
                                        else echo 'border-green-200 bg-green-50';
                                   ?>"
                                   placeholder="<?php echo e(__('tr_placeholder')); ?>">
                        </td>
                        <td class="px-4 py-2 text-center">
                            <div class="inline-flex items-center gap-2">
                                <button type="button" onclick="saveOne('<?php echo e($key); ?>', this)"
                                        class="text-primary hover:text-secondary" title="<?php echo e(__('tr_save_row')); ?>">
                                    <i class="ti ti-device-floppy text-base"></i>
                                </button>
                                <button type="button" onclick="translateOne(this, '<?php echo e(addslashes($value)); ?>', '<?php echo e($key); ?>')"
                                        class="text-blue-500 hover:text-blue-700" title="<?php echo e(__('tr_api_row')); ?>">
                                    <i class="ti ti-language text-base"></i>
                                </button>
                                <?php if ($isSameAsSource): ?>
                                <button type="button" onclick="confirmSame('<?php echo e($key); ?>', this)"
                                        class="text-amber-600 hover:text-amber-700" title="<?php echo e(__('tr_confirm_same_title')); ?>">
                                    <i class="ti ti-check text-base"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script>
async function confirmSame(key, btn) {
    var fd = new FormData();
    fd.append('_token', '<?php echo csrfToken(); ?>');
    fd.append('action', 'confirm_same');
    fd.append('key', key);
    btn.disabled = true;
    try {
        var r = await fetch(location.href, { method: 'POST', body: fd });
        var d = await r.json();
        if (d.code === 0) {
            // 不刷新整页 —— 把这一行的 ⚠ 同源 换成 ✓ 已确认，输入框颜色改回正常翻译
            var tr = btn.closest('tr');
            if (tr) {
                var sus = tr.querySelector('[data-same-status]');
                if (sus) {
                    sus.textContent = '✓ ' + <?php echo json_encode(__('tr_confirmed_badge'), JSON_UNESCAPED_UNICODE); ?>;
                    sus.className = 'ml-1 text-[10px] text-green-600';
                    sus.title = <?php echo json_encode(__('tr_confirmed_title'), JSON_UNESCAPED_UNICODE); ?>;
                    sus.style.cursor = 'pointer';
                    sus.onclick = function() { unconfirmSame(key, sus); };
                }
                var input = tr.querySelector('input[name^="translations["]');
                if (input) input.className = input.className
                    .replace('border-amber-200 bg-amber-50', 'border-green-200 bg-green-50');
                btn.remove();
            }
            showToast(d.msg || <?php echo json_encode(__('tr_confirmed'), JSON_UNESCAPED_UNICODE); ?>);
        } else {
            btn.disabled = false;
            showToast(d.msg || <?php echo json_encode(__('tr_operation_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        }
    } catch (e) {
        btn.disabled = false;
        showToast(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

async function unconfirmSame(key, badge) {
    if (!confirm(<?php echo json_encode(__('tr_confirm_cancel'), JSON_UNESCAPED_UNICODE); ?>)) return;
    var fd = new FormData();
    fd.append('_token', '<?php echo csrfToken(); ?>');
    fd.append('action', 'unconfirm_same');
    fd.append('key', key);
    var r = await fetch(location.href, { method: 'POST', body: fd });
    var d = await r.json();
    if (d.code === 0) location.reload();
    else showToast(d.msg || <?php echo json_encode(__('tr_operation_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
}

async function confirmSameAll() {
    if (!confirm(<?php echo json_encode(__('tr_confirm_all_prompt'), JSON_UNESCAPED_UNICODE); ?>)) return;
    var fd = new FormData();
    fd.append('_token', '<?php echo csrfToken(); ?>');
    fd.append('action', 'confirm_same_all');
    var r = await fetch(location.href, { method: 'POST', body: fd });
    var d = await r.json();
    if (d.code === 0) {
        showToast(d.msg || <?php echo json_encode(__('tr_confirmed'), JSON_UNESCAPED_UNICODE); ?>);
        setTimeout(function() { location.reload(); }, 600);
    } else {
        showToast(d.msg || <?php echo json_encode(__('tr_operation_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

function showToast(msg, type) {
    var div = document.createElement('div');
    div.style.cssText = 'position:fixed;top:1rem;right:1rem;z-index:9999;padding:.6rem 1.2rem;border-radius:.5rem;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.15);font-size:.875rem';
    div.style.background = (type === 'error') ? '#ef4444' : '#10b981';
    div.textContent = msg;
    document.body.appendChild(div);
    setTimeout(function() { div.remove(); }, 2500);
}

function translateOne(btn, text, key) {
    btn.innerHTML = '<i class="ti ti-loader-2 text-base animate-spin"></i>';

    var fd = new FormData();
    fd.append('_token', '<?php echo csrfToken(); ?>');
    fd.append('action', 'translate_one');
    fd.append('text', text);

    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.code === 0 && d.data.translated) {
                var row = btn.closest('tr');
                var input = row.querySelector('input[type="text"]');
                input.value = d.data.translated;
                input.className = input.className.replace('border-orange-200 bg-orange-50', 'border-green-200 bg-green-50');
            } else {
                showMessage(d.msg || <?php echo json_encode(__('tr_translate_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            }
        })
        .catch(() => showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error'))
        .finally(() => {
            btn.innerHTML = '<i class="ti ti-language text-base"></i>';
        });
}

function batchTranslate() {
    if (!confirm(<?php echo json_encode(str_replace([':n', ':item'], [(string) $untranslatedKeys, __('tr_untranslated')], __('atr_ai_confirm')), JSON_UNESCAPED_UNICODE); ?>)) return;

    var btn = document.getElementById('btnBatch');
    btn.disabled = true;
    btn.textContent = <?php echo json_encode(__('tr_translating'), JSON_UNESCAPED_UNICODE); ?>;

    var fd = new FormData();
    fd.append('_token', '<?php echo csrfToken(); ?>');
    fd.append('action', 'translate_batch');

    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.code === 0) {
                showMessage(d.msg);
                setTimeout(() => location.reload(), 1500);
            } else {
                showMessage(d.msg, 'error');
            }
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(str_replace(':n', (string) $untranslatedKeys, __('tr_api_translate')), JSON_UNESCAPED_UNICODE); ?>;
        });
}

async function saveAll() {
    var fd = new FormData(document.getElementById('translateForm'));
    try {
        var r = await fetch(location.href, { method: 'POST', body: fd });
        var t = await r.text();
        var d;
        try { d = JSON.parse(t); }
        catch (e) {
            showToast(<?php echo json_encode(__('tr_non_json'), JSON_UNESCAPED_UNICODE); ?> + t.slice(0, 120), 'error');
            return;
        }
        showToast(d.msg || (d.code === 0 ? <?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE); ?> : <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>), d.code === 0 ? 'success' : 'error');
    } catch (e) {
        showToast(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?> + ': ' + e.message, 'error');
    }
}

async function saveOne(key, btn) {
    var input = document.querySelector('input[name="translations[' + key.replace(/"/g, '\\"') + ']"]');
    if (!input) { showToast(<?php echo json_encode(__('tr_input_not_found'), JSON_UNESCAPED_UNICODE); ?>, 'error'); return; }
    var value = input.value.trim();
    if (value === '') { showToast(<?php echo json_encode(__('tr_fill_translation'), JSON_UNESCAPED_UNICODE); ?>, 'error'); input.focus(); return; }
    var fd = new FormData();
    fd.append('_token', '<?php echo csrfToken(); ?>');
    fd.append('action', 'save_one');
    fd.append('key', key);
    fd.append('value', value);
    btn.disabled = true;
    try {
        var r = await fetch(location.href, { method: 'POST', body: fd });
        var d = await r.json();
        if (d.code === 0) {
            // 行高亮闪一下绿色 + 输入框边框转绿
            input.className = input.className
                .replace('border-orange-200 bg-orange-50', 'border-green-200 bg-green-50')
                .replace('border-amber-200 bg-amber-50', 'border-green-200 bg-green-50');
            var tr = btn.closest('tr');
            if (tr) {
                tr.style.transition = 'background-color .4s';
                tr.style.backgroundColor = '#d1fae5';
                setTimeout(function() { tr.style.backgroundColor = ''; }, 800);
            }
            showToast(d.msg || <?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE); ?>);
        } else {
            showToast(d.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        }
    } catch (e) {
        showToast(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?> + ': ' + e.message, 'error');
    } finally {
        btn.disabled = false;
    }
}

function saveApiConfig() {
    var fd = new FormData();
    fd.append('_token', '<?php echo csrfToken(); ?>');
    fd.append('action', 'save_api');
    fd.append('api', document.getElementById('apiProvider').value);
    fd.append('api_key', document.getElementById('apiKey').value);
    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.code === 0) showMessage(d.msg);
            else showMessage(d.msg, 'error');
        });
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
