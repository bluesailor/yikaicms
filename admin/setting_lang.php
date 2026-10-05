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
// 语言名按后台当前界面语言给出（「Bahasa Melayu」→「马来语 · Bahasa Melayu」），只认本族语名的人也能对上
$langTitle = static fn(string $code, string $native): string => LanguageRegistry::title($code, $native, getLang());
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
        // 只收已安装的语言：语言代码会进 URL 前缀、语言包路径与后台界面
        if (!is_array($selected) || !isset($allLangs[$newDefault]) || !isset(adminLanguages()[$newAdmin])) {
            error(__('admin_bad_params'), 422);
        }
        $selected = array_values(array_intersect(array_keys($allLangs), array_filter($selected, 'is_string')));
        if (!in_array($newDefault, $selected, true)) $selected[] = $newDefault;

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

    // 改服务器规则 / 改访问域名：公开沙盒演示站一律不允许（改坏了所有访客都打不开）
    if (in_array($action, ['save_domains', 'fix_htaccess', 'restore_htaccess'], true) && defined('DEMO_SANDBOX') && DEMO_SANDBOX) {
        error(__('auth_demo_sandbox_protected'));
    }

    // 一键把 .htaccess 里写死语言的旧前缀规则换成通用规则（见 LanguageRouting）。
    // 页面上的脚本改完立刻重测；有语言变得打不开就调 restore_htaccess 撤销。
    if ($action === 'fix_htaccess') {
        $result = LanguageRouting::fixHtaccess(ROOT_PATH, ROOT_PATH . '/storage/backups/htaccess');
        if (!$result['ok']) error(__('slang_routing_fix_failed_' . $result['error']));
        adminLog('setting', 'htaccess_lang_rule', '更新 .htaccess 语言前缀规则，备份 storage/backups/htaccess/' . $result['backup']);
        success(['backup' => $result['backup']], __('slang_routing_fixed'));
    }
    if ($action === 'restore_htaccess') {
        $backup = (string) post('backup');
        if (!LanguageRouting::restoreHtaccess(ROOT_PATH, ROOT_PATH . '/storage/backups/htaccess', $backup)) error(__('slang_routing_restore_failed'));
        adminLog('setting', 'htaccess_lang_rule', '撤销 .htaccess 语言前缀规则更新，还原自 ' . $backup);
        success([], __('slang_routing_rolled_back'));
    }

    // 语言域名：{"en":"en.example.com","de":"example.de"}（见 LanguageDomains）
    if ($action === 'save_domains') {
        $input = is_array($_POST['domains'] ?? null) ? $_POST['domains'] : [];
        $mainHost = LanguageDomains::mainHost();
        $map = [];
        foreach ($input as $lang => $host) {
            $lang = (string) $lang;
            $host = trim((string) $host);
            if ($host === '') continue;
            if ($lang === $defaultLang || !in_array($lang, (array) $enabledLangs, true) || !isset($allLangs[$lang])) continue;
            $name = (string) $allLangs[$lang];
            $normalized = LanguageDomains::normalizeHost($host);
            if ($normalized === null) error(__('slang_domain_invalid', ['lang' => $name]));
            $bare = str_starts_with($normalized, 'www.') ? substr($normalized, 4) : $normalized;
            $mainBare = str_starts_with($mainHost, 'www.') ? substr($mainHost, 4) : $mainHost;
            if ($bare === $mainBare) error(__('slang_domain_is_main', ['lang' => $name]));
            foreach ($map as $other) {
                $otherBare = str_starts_with($other, 'www.') ? substr($other, 4) : $other;
                if ($otherBare === $bare) error(__('slang_domain_duplicate', ['host' => $normalized]));
            }
            $map[$lang] = $normalized;
        }
        // 总开关：关掉时域名照样保存（方便先填好、主机配好后再开），全站回到 /xx/ 前缀
        $switchOn = post('language_domains_enabled') === '1';
        if ($switchOn && $map === []) error(__('slang_domain_need_one'));
        if ($switchOn) {
            if ($mainHost === '') error(__('slang_domain_need_site_url'));
            if (isDynamicUrlMode()) error(__('slang_domain_need_pretty_urls'));
        }
        settingModel()->set('language_domains', $map === [] ? '' : (string) json_encode($map, JSON_UNESCAPED_SLASHES));
        settingModel()->set('language_domains_enabled', $switchOn ? '1' : '0');
        if ($switchOn) {
            // 静态直出分不清主机（见 StaticHtml::enabled）：已生成的文件必须清掉，否则服务器会继续直出
            require_once ROOT_PATH . '/includes/StaticHtml.php';
            StaticHtml::clearAll();
        }
        cacheDelete('sitemap_xml');
        adminLog('setting', 'lang_domains', '更新语言域名（' . ($switchOn ? '启用' : '关闭') . '）：' . ($map === [] ? '（无）' : implode(', ', array_map(static fn($l, $h) => "$l=$h", array_keys($map), $map))));
        success([], __('save_success'));
    }

    // 检测某个语言域名是否已指向本站（服务器回访 /index.php?yk_lang_domain_probe=）
    if ($action === 'probe_domain') {
        $result = LanguageDomains::probe((string) post('host'));
        $message = str_starts_with($result, 'http_')
            ? __('slang_domain_probe_http', ['status' => substr($result, 5)])
            : __('slang_domain_probe_' . $result);
        success(['result' => $result, 'ok' => $result === 'ok', 'message' => $message]);
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

        <?php /* 上排：启用的语言 / 语言配置（桌面下并排，移动下堆叠） */ ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <?php /* 启用的语言 */ ?>
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
                            <span class="font-medium"><?php echo e($langTitle($code, $label)); ?></span>
                            <span class="text-xs text-gray-400 font-mono ms-2"><?php echo e($code); ?></span>
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
                <?php require_once ROOT_PATH . '/admin/includes/s2t_pack_notice.php'; renderS2TPackNotice(in_array('zh-TW', (array) $enabledLangs, true)); ?>
            </div>
        </div>

        <?php /* 默认语言 */ ?>
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
                                <?php echo e($langTitle($code, $label)); ?> (<?php echo e($code); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-400 mt-1"><?php echo e(__('slang_front_default_tip')); ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(__('slang_admin_lang')); ?></label>
                        <select name="admin_lang" class="w-full border rounded px-4 py-2">
                            <?php foreach (adminLanguages() as $code => $label): ?>
                            <option value="<?php echo e($code); ?>" <?php echo $code === $adminLang ? 'selected' : ''; ?>>
                                <?php echo e($langTitle($code, $label)); ?> (<?php echo e($code); ?>)
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
        </div><?php /* /上排 grid */ ?>

        <?php /* 翻译工具（全宽） */ ?>
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

    <?php /* 语言网址前缀检查：浏览器逐个请求 /<代码>/contact.html?探针，看是否被认成该语言 */ ?>
    <?php
    // 兼容地址模式（?yk_route=）不用路径前缀，无需检查
    $routingLangs = isDynamicUrlMode() ? [] : LanguageRouting::prefixLanguages(array_values(array_map('strval', (array) $enabledLangs)), $defaultLang);
    $htaccess = LanguageRouting::htaccessStatus(ROOT_PATH);
    $routingProbes = [];
    foreach ($routingLangs as $code) {
        $routingProbes[] = ['code' => $code, 'name' => (string) ($allLangs[$code] ?? $code), 'url' => BasePath::url('/' . $code . '/contact.html')];
    }
    ?>
    <?php if ($routingProbes !== []): ?>
    <div id="language-routing" class="bg-white rounded-lg shadow mt-6" data-routing-state="<?php echo e($htaccess['state']); ?>">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('slang_routing_title')); ?></h2>
            <p class="text-sm text-gray-500 mt-1"><?php echo e(__('slang_routing_tip')); ?></p>
        </div>
        <div class="p-6 space-y-3">
            <div class="flex flex-wrap gap-2" data-routing-list>
                <?php foreach ($routingProbes as $probe): ?>
                <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full border text-sm text-gray-500" data-routing-item="<?php echo e($probe['code']); ?>">
                    <i class="ti ti-loader-2 animate-spin" data-routing-icon></i><?php echo e($probe['name']); ?>
                    <span class="font-mono text-xs text-gray-400">/<?php echo e($probe['code']); ?>/</span>
                </span>
                <?php endforeach; ?>
            </div>
            <div class="hidden text-sm rounded p-3" data-routing-message></div>
            <div class="hidden" data-routing-fix>
                <button type="button" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded text-sm inline-flex items-center gap-2" data-routing-fix-button>
                    <i class="ti ti-tool text-base"></i><?php echo e(__('slang_routing_fix_btn')); ?>
                </button>
                <p class="text-xs text-gray-400 mt-2"><?php echo e(__('slang_routing_fix_note')); ?></p>
            </div>
            <div class="hidden text-sm text-gray-600 space-y-2" data-routing-manual>
                <p><?php echo e(__($htaccess['state'] !== 'none' ? 'slang_routing_manual_apache' : 'slang_routing_manual_nginx')); ?></p>
                <pre class="bg-gray-50 border rounded p-3 text-xs overflow-x-auto"><?php echo e($htaccess['state'] !== 'none'
                    ? "RewriteCond %{DOCUMENT_ROOT}%{ENV:YK_BASE}lang/\$1.php -f\nRewriteRule ^([a-z]{2}(?:-[A-Z]{2})?)/(.*)\$ %{ENV:YK_BASE}\$2?_lang=\$1 [QSA,L,DPI]"
                    : "if (!-e \$request_filename) {\n    rewrite \"^/[a-z]{2}(?:-[A-Z]{2})?/\" /index.php last;\n}"); ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php /* 语言域名（可选）：某些语言用独立域名，其余照旧用 /xx/ 前缀 */ ?>
    <?php
    $domainLangs = array_filter($allLangs, static fn($label, $code): bool => $code !== $defaultLang && in_array($code, (array) $enabledLangs, true), ARRAY_FILTER_USE_BOTH);
    $domainMap = json_decode((string) config('language_domains', ''), true);
    $domainMap = is_array($domainMap) ? $domainMap : [];
    ?>
    <?php if ($domainLangs !== []): ?>
    <form id="domainForm" class="bg-white rounded-lg shadow mt-6">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_domains">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('slang_domains_title')); ?></h2>
            <p class="text-sm text-gray-500 mt-1"><?php echo e(__('slang_domains_tip')); ?></p>
        </div>
        <div class="p-6 space-y-3">
            <label class="flex items-center gap-3 p-3 rounded-lg border bg-gray-50">
                <input type="checkbox" name="language_domains_enabled" value="1" class="w-4 h-4 rounded"
                       <?php echo (string) config('language_domains_enabled', '0') === '1' ? 'checked' : ''; ?>>
                <div>
                    <span class="font-medium text-gray-700"><?php echo e(__('slang_domains_switch')); ?></span>
                    <p class="text-xs text-gray-400"><?php echo e(__('slang_domains_switch_tip')); ?></p>
                </div>
            </label>
            <?php if (LanguageDomains::mainHost() === ''): ?>
            <p class="text-sm bg-amber-50 border border-amber-200 text-amber-800 rounded p-3"><?php echo e(__('slang_domain_need_site_url')); ?></p>
            <?php elseif (isDynamicUrlMode()): ?>
            <p class="text-sm bg-amber-50 border border-amber-200 text-amber-800 rounded p-3"><?php echo e(__('slang_domain_need_pretty_urls')); ?></p>
            <?php endif; ?>
            <?php foreach ($domainLangs as $code => $label): ?>
            <div class="flex flex-wrap items-center gap-3 p-3 rounded-lg border">
                <div class="w-56 shrink-0">
                    <span class="font-medium"><?php echo e($langTitle($code, $label)); ?></span>
                    <span class="text-xs text-gray-400 font-mono ms-1"><?php echo e($code); ?></span>
                </div>
                <input type="text" name="domains[<?php echo e($code); ?>]" value="<?php echo e((string) ($domainMap[$code] ?? '')); ?>"
                       placeholder="<?php echo e(LanguageRegistry::hreflang($code) === 'en' ? 'en.example.com' : strtolower($code) . '.example.com'); ?>"
                       class="flex-1 min-w-[12rem] border rounded px-3 py-2 font-mono text-sm" autocomplete="off" spellcheck="false" data-domain-input>
                <button type="button" class="px-3 py-2 text-sm rounded border hover:bg-gray-50" data-domain-probe><?php echo e(__('slang_domain_check')); ?></button>
                <span class="text-sm w-full" data-domain-result></span>
            </div>
            <?php endforeach; ?>
            <ul class="text-xs text-gray-500 list-disc pl-5 space-y-1 pt-2">
                <li><?php echo e(__('slang_domain_note_dns')); ?></li>
                <li><?php echo e(__('slang_domain_note_hosting')); ?></li>
                <li><?php echo e(__('slang_domain_note_admin')); ?></li>
                <li><?php echo e(__('slang_domain_note_static')); ?></li>
                <li><?php echo e(__('slang_domain_note_member')); ?></li>
            </ul>
            <div class="flex justify-end">
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-2">
                    <i class="ti ti-check text-base"></i><?php echo e(__('slang_domains_save')); ?>
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php /* 栏目翻译入口 */ ?>
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
(function () {
    var box = document.getElementById('language-routing');
    if (!box) return;
    var probes = <?php echo json_encode($routingProbes ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>;
    var T = <?php echo json_encode([
        'ok' => __('slang_routing_all_ok'), 'bad' => __('slang_routing_bad'), 'fixing' => __('slang_routing_fixing'),
        'fixed' => __('slang_routing_fixed'), 'rolled' => __('slang_routing_rolled_back'), 'still' => __('slang_routing_still_bad'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var state = box.getAttribute('data-routing-state');
    var message = box.querySelector('[data-routing-message]');
    function nonce() {
        var a = new Uint8Array(16); crypto.getRandomValues(a);
        return Array.from(a, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    }
    async function probe(p) {
        try {
            var n = nonce();
            var resp = await fetch(p.url + '?<?php echo LanguageRouting::PROBE_PARAM; ?>=' + n, { cache: 'no-store', redirect: 'manual', credentials: 'omit' });
            var data = await resp.json();
            return data && data.probe === 'yikai-lang-route' && data.nonce === n && data.lang === p.code;
        } catch (e) { return false; }
    }
    function show(text, tone) {
        message.textContent = text;
        message.className = 'text-sm rounded p-3 ' + (tone === 'ok' ? 'bg-green-50 text-green-700' : tone === 'info' ? 'bg-gray-50 text-gray-600' : 'bg-red-50 text-red-700');
    }
    async function runAll() {
        var bad = [];
        for (var i = 0; i < probes.length; i++) {
            var ok = await probe(probes[i]);
            var item = box.querySelector('[data-routing-item="' + probes[i].code + '"]');
            var icon = item.querySelector('[data-routing-icon]');
            icon.className = 'ti ' + (ok ? 'ti-circle-check text-green-600' : 'ti-circle-x text-red-600');
            item.classList.toggle('border-red-200', !ok);
            if (!ok) bad.push(probes[i].name);
        }
        return bad;
    }
    async function post(fields) {
        var fd = new FormData();
        fd.append('<?php echo CSRF_TOKEN_NAME; ?>', <?php echo json_encode(csrfToken()); ?>);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return safeJson(await fetch('', { method: 'POST', body: fd }));
    }
    (async function () {
        var bad = await runAll();
        if (bad.length === 0) { show(T.ok, 'ok'); return; }
        show(T.bad.replace(':langs', bad.join('、')), 'bad');
        if (state === 'legacy') box.querySelector('[data-routing-fix]').classList.remove('hidden');
        else box.querySelector('[data-routing-manual]').classList.remove('hidden');
    })();
    var fixButton = box.querySelector('[data-routing-fix-button]');
    fixButton.addEventListener('click', async function () {
        fixButton.disabled = true;
        show(T.fixing, 'info');
        var res = await post({ action: 'fix_htaccess' });
        if (res.code !== 0) { show(res.msg || '', 'bad'); fixButton.disabled = false; return; }
        var backup = res.data && res.data.backup;
        var bad = await runAll();
        if (bad.length === 0) {
            show(T.fixed, 'ok');
            box.querySelector('[data-routing-fix]').classList.add('hidden');
            return;
        }
        // 改完反而有语言打不开（或仍打不开）：立刻撤销，回到修改前
        var undo = await post({ action: 'restore_htaccess', backup: backup });
        await runAll();
        show((undo.code === 0 ? T.rolled : (undo.msg || '')) + ' ' + T.still.replace(':langs', bad.join('、')), 'bad');
        box.querySelector('[data-routing-fix]').classList.add('hidden');
        box.querySelector('[data-routing-manual]').classList.remove('hidden');
    });
})();

var domainForm = document.getElementById('domainForm');
if (domainForm) {
    domainForm.addEventListener('submit', async function (e) {
        e.preventDefault();
        var resp = await fetch('', { method: 'POST', body: new FormData(this) });
        var data = await safeJson(resp);
        if (data.code === 0) {
            showMessage(data.msg || <?php echo json_encode(__('save_success'), JSON_UNESCAPED_UNICODE); ?>);
            setTimeout(() => location.reload(), 800);
        } else {
            showMessage(data.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        }
    });
    domainForm.querySelectorAll('[data-domain-probe]').forEach(function (button) {
        button.addEventListener('click', async function () {
            var row = button.parentElement;
            var input = row.querySelector('[data-domain-input]');
            var out = row.querySelector('[data-domain-result]');
            if (!input.value.trim()) { input.focus(); return; }
            button.disabled = true;
            out.className = 'text-sm w-full text-gray-500';
            out.textContent = <?php echo json_encode(__('slang_domain_checking'), JSON_UNESCAPED_UNICODE); ?>;
            var fd = new FormData(domainForm);   // 带上 CSRF 字段
            fd.set('action', 'probe_domain');
            fd.set('host', input.value.trim());
            try {
                var data = await safeJson(await fetch('', { method: 'POST', body: fd }));
                var ok = data.code === 0 && data.data && data.data.ok;
                out.className = 'text-sm w-full ' + (ok ? 'text-green-600' : 'text-red-600');
                out.textContent = (data.data && data.data.message) || data.msg || '';
            } finally {
                button.disabled = false;
            }
        });
    });
}

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
