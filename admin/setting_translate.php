<?php
/**
 * ikaiCMS - 翻译管理
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

// 可用语言
$languages = ['zh-CN' => '中文', 'ja' => '日本語'];
$targetLang = $_GET['lang'] ?? 'ja';
if (!isset($languages[$targetLang]) || $targetLang === 'zh-CN') {
    $targetLang = 'ja';
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
    success([], 'API 配置已保存');
}

// API 翻译单条
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'translate_one') {
    verifyCsrf();
    $text = $_POST['text'] ?? '';
    $result = apiTranslate($text, $targetLang);
    if ($result !== false) {
        success(['translated' => $result]);
    }
    error('翻译失败，请检查 API Key 配置');
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
        success(['count' => 0], '所有条目已翻译');
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
    success(['count' => $translated], "已翻译 {$translated} 条");
}

// 手动保存翻译
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCsrf();
    $translations = $_POST['translations'] ?? [];
    foreach ($translations as $key => $value) {
        if (isset($sourceData[$key]) && !empty(trim($value))) {
            $targetData[$key] = trim($value);
        }
    }
    saveLangFile($targetLangFile, $targetLang, $targetData);
    adminLog('translate', 'save', '保存翻译: ' . $targetLang);
    success([], '保存成功');
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
    $content = "<?php\n/**\n * ikaiCMS - " . ($langNames[$lang] ?? $lang) . "\n */\n\nreturn [\n";

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

// 统计
$totalKeys = count($sourceData);
$translatedKeys = 0;
$untranslatedKeys = 0;
foreach ($sourceData as $key => $value) {
    if (isset($targetData[$key]) && $targetData[$key] !== $value) {
        $translatedKeys++;
    } else {
        $untranslatedKeys++;
    }
}

$filter = $_GET['filter'] ?? 'all'; // all, untranslated, translated

$pageTitle = __('admin_system') . ' - 翻译管理';
$currentMenu = 'translate';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-5xl">
    <!-- API 配置 -->
    <div class="bg-white rounded-lg shadow-sm p-5 mb-6">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-gray-800 text-sm">翻译 API 配置</h3>
            <button type="button" onclick="saveApiConfig()" class="bg-primary hover:bg-secondary text-white px-4 py-1.5 rounded text-sm">保存配置</button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1">翻译服务</label>
                <select id="apiProvider" class="w-full border rounded px-3 py-2 text-sm">
                    <option value="deepl" <?php echo $settings->get('translate_api', 'deepl') === 'deepl' ? 'selected' : ''; ?>>DeepL（推荐，日语最佳）</option>
                    <option value="google" <?php echo $settings->get('translate_api', '') === 'google' ? 'selected' : ''; ?>>Google Translate</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs text-gray-500 mb-1">API Key <a href="https://www.deepl.com/pro-api" target="_blank" class="text-blue-500 hover:underline">免费注册 DeepL API →</a></label>
                <input type="text" id="apiKey" value="<?php echo e($settings->get('translate_api_key', '')); ?>" placeholder="填入 API Key 后即可使用一键翻译"
                       class="w-full border rounded px-3 py-2 text-sm font-mono">
            </div>
        </div>
    </div>

    <!-- 概览 -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow-sm p-4 text-center">
            <div class="text-2xl font-bold text-gray-800"><?php echo $totalKeys; ?></div>
            <div class="text-sm text-gray-500">总条目</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4 text-center">
            <div class="text-2xl font-bold text-green-600"><?php echo $translatedKeys; ?></div>
            <div class="text-sm text-gray-500">已翻译</div>
        </div>
        <div class="bg-white rounded-lg shadow-sm p-4 text-center">
            <div class="text-2xl font-bold text-orange-500"><?php echo $untranslatedKeys; ?></div>
            <div class="text-sm text-gray-500">未翻译</div>
        </div>
    </div>

    <!-- 工具栏 -->
    <div class="bg-white rounded-lg shadow-sm p-4 mb-6 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <label class="text-sm text-gray-600">目标语言：</label>
            <select onchange="location.href='?lang='+this.value+'&filter=<?php echo $filter; ?>'" class="border rounded px-3 py-1.5 text-sm">
                <?php foreach ($languages as $code => $name): if ($code === 'zh-CN') continue; ?>
                <option value="<?php echo $code; ?>" <?php echo $targetLang === $code ? 'selected' : ''; ?>><?php echo $name; ?></option>
                <?php endforeach; ?>
            </select>

            <div class="flex border rounded overflow-hidden text-sm">
                <a href="?lang=<?php echo $targetLang; ?>&filter=all" class="px-3 py-1.5 <?php echo $filter === 'all' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">全部</a>
                <a href="?lang=<?php echo $targetLang; ?>&filter=untranslated" class="px-3 py-1.5 border-l <?php echo $filter === 'untranslated' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">未翻译</a>
                <a href="?lang=<?php echo $targetLang; ?>&filter=translated" class="px-3 py-1.5 border-l <?php echo $filter === 'translated' ? 'bg-primary text-white' : 'bg-white text-gray-600 hover:bg-gray-50'; ?>">已翻译</a>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button onclick="batchTranslate()" id="btnBatch" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-1.5 rounded text-sm inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"></path></svg>
                API一键翻译 (<?php echo $untranslatedKeys; ?>条)
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
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 w-40">Key</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">中文原文</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500"><?php echo $languages[$targetLang]; ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 w-20"><?php echo __('admin_action'); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php
                    foreach ($sourceData as $key => $value):
                        $translated = $targetData[$key] ?? '';
                        $isTranslated = !empty($translated) && $translated !== $value;

                        if ($filter === 'untranslated' && $isTranslated) continue;
                        if ($filter === 'translated' && !$isTranslated) continue;
                    ?>
                    <tr class="hover:bg-gray-50" data-key="<?php echo e($key); ?>">
                        <td class="px-4 py-2 text-xs text-gray-400 font-mono"><?php echo e($key); ?></td>
                        <td class="px-4 py-2 text-sm text-gray-700"><?php echo e($value); ?></td>
                        <td class="px-4 py-2">
                            <input type="text" name="translations[<?php echo e($key); ?>]" value="<?php echo e($translated); ?>"
                                   class="w-full px-2 py-1 border rounded text-sm <?php echo $isTranslated ? 'border-green-200 bg-green-50' : 'border-orange-200 bg-orange-50'; ?>"
                                   placeholder="未翻译">
                        </td>
                        <td class="px-4 py-2 text-center">
                            <button type="button" onclick="translateOne(this, '<?php echo e(addslashes($value)); ?>', '<?php echo e($key); ?>')"
                                    class="text-blue-500 hover:text-blue-700" title="API翻译">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"></path></svg>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>

<script>
function translateOne(btn, text, key) {
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';

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
                showMessage(d.msg || '翻译失败', 'error');
            }
        })
        .catch(() => showMessage('请求失败', 'error'))
        .finally(() => {
            btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"></path></svg>';
        });
}

function batchTranslate() {
    if (!confirm('将调用翻译API翻译 <?php echo $untranslatedKeys; ?> 条未翻译内容，确定继续？')) return;

    var btn = document.getElementById('btnBatch');
    btn.disabled = true;
    btn.textContent = '翻译中...';

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
            btn.textContent = 'API一键翻译';
        });
}

function saveAll() {
    var fd = new FormData(document.getElementById('translateForm'));
    fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.code === 0) showMessage(d.msg);
            else showMessage(d.msg, 'error');
        });
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
