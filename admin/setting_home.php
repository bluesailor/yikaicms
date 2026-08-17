<?php
/**
 * YikaiCMS - 首页设置（区块拖拽排序）
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');
require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/HomeSettingsLanguageDefaults.php';

// ============== 多语言视图（settings 表无 lang 列，per-lang 用 <key>_<lang> 后缀约定） ==============
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];

// 哪些 key 走 per-lang（文案 + 客户评价 JSON）；剩下（区块顺序/开关/样式/图片/数字/图标/颜色）全局共享
$LANG_KEYS = HomeSettingsLanguageDefaults::keys();
$HOME_DEFAULTS = getDefaults('home');
$HOME_STORED_ROWS = settingModel()->getByGroup('home');
$HOME_STORED_BY_KEY = [];
foreach ($HOME_STORED_ROWS as $row) {
    $HOME_STORED_BY_KEY[(string) $row['key']] = $row;
}

// 自定义版块：从预设复制一份 section 并重生成各级唯一 id（对齐构建器 freshSection）
function homeFreshSection(array $s): array
{
    return [
        'id'       => uniqid('s_'),
        'settings' => is_array($s['settings'] ?? null) ? $s['settings'] : [],
        'columns'  => array_map(function ($c) {
            $col = [
                'id'       => uniqid('c_'),
                'elements' => array_map(
                    fn($e) => ['id' => uniqid('e_'), 'type' => (string) ($e['type'] ?? 'text'), 'data' => is_array($e['data'] ?? null) ? $e['data'] : []],
                    is_array($c['elements'] ?? null) ? $c['elements'] : []
                ),
            ];
            if (isset($c['card_bg']) && (string) $c['card_bg'] !== '') $col['card_bg'] = (string) $c['card_bg'];
            return $col;
        }, is_array($s['columns'] ?? null) ? $s['columns'] : []),
    ];
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 经典首页只转换为 Blox 草稿；前台继续使用经典首页，直到用户在 Blox 中明确发布。
    if (($_POST['action'] ?? '') === 'convert_to_blox') {
        if (!bloxAdvancedFeaturesEnabled()) {
            error(__('blox_feature_disabled'));
        }
        $hadDraft = HomeBloxDocument::hasDraft();
        $document = HomeBloxDocument::createDraftFromLegacy();
        adminLog(
            'home',
            $hadDraft ? 'continue_blox_draft' : 'convert_to_blox',
            $hadDraft ? '继续编辑首页 Blox 草稿' : '经典首页转换为 Blox 草稿'
        );
        redirect('/admin/blox_editor.php?home=1&classic_import=' . ($hadDraft ? 'existing' : 'created'));
    }

    // ── 新建自定义版块（从预设库或空白）──
    if (($_POST['action'] ?? '') === 'add_custom') {
        require_once ROOT_PATH . '/includes/builder/presets.php';
        $presetKey = (string) ($_POST['preset'] ?? '');
        $blocks = null;
        $cTitle = __('shome_custom_block');
        if ($presetKey === 'blank') {
            $blocks = [homeFreshSection([
                'settings' => ['padding' => 'lg', 'max_width' => 'default', 'gap' => 'lg', 'justify_items' => 'center'],
                'columns'  => [['elements' => [
                    ['type' => 'heading', 'data' => ['text' => __('shome_new_block_title'), 'level' => 'h2']],
                    ['type' => 'text', 'data' => ['html' => '<p style="text-align:center">' . __('shome_new_block_body') . '</p>']],
                ]]],
            ])];
        } else {
            foreach ((builderPresets()['sections'] ?? []) as $p) {
                if (($p['key'] ?? '') === $presetKey && !empty($p['sections'])) {
                    $blocks = array_map('homeFreshSection', $p['sections']);
                    $cTitle = (string) ($p['label'] ?? $cTitle);
                    break;
                }
            }
        }
        if ($blocks) {
            $cfg = json_decode((string) config('home_blocks_config', ''), true) ?: [];
            $maxN = 0;
            foreach ($cfg as $b) {
                if (str_starts_with((string) ($b['type'] ?? ''), 'custom:')) $maxN = max($maxN, (int) substr((string) $b['type'], 7));
            }
            $N = $maxN + 1;
            settingModel()->set('home_custom_' . $N, json_encode(['title' => $cTitle, 'blocks' => $blocks], JSON_UNESCAPED_UNICODE));
            $cfg[] = ['type' => 'custom:' . $N, 'enabled' => true];
            settingModel()->set('home_blocks_config', json_encode($cfg, JSON_UNESCAPED_UNICODE));
        }
        redirect('/admin/setting_home.php');
    }

    // ── 删除自定义版块 ──
    // 前台就地操作会带 ajax=1：返回 JSON 而不是跳转，供悬停浮条调用。
    // 鉴权与 CSRF 已由 admin/includes/auth.php 统一处理（含演示模式拦截），此处不再重复。
    $__ajax = ($_POST['ajax'] ?? '') === '1';
    $__done = static function (string $msg = 'ok') use ($__ajax): void {
        if ($__ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        }
        redirect('/admin/setting_home.php');
    };

    if (($_POST['action'] ?? '') === 'del_custom') {
        $N = (int) ($_POST['n'] ?? 0);
        if ($N > 0) {
            $cfg = json_decode((string) config('home_blocks_config', ''), true) ?: [];
            $cfg = array_values(array_filter($cfg, fn($b) => (string) ($b['type'] ?? '') !== 'custom:' . $N));
            settingModel()->set('home_blocks_config', json_encode($cfg, JSON_UNESCAPED_UNICODE));
            settingModel()->set('home_custom_' . $N, '');
        }
        $__done();
    }

    // 显示/隐藏任意首页版块（系统自带与自定义通用）。
    // 只改 enabled 标志，不删数据——隐藏后仍能在首页设置里点回来。
    if (($_POST['action'] ?? '') === 'toggle_block') {
        $type = trim((string) ($_POST['type'] ?? ''));
        if ($type !== '') {
            $cfg = json_decode((string) config('home_blocks_config', ''), true) ?: [];
            $hit = false;
            foreach ($cfg as &$b) {
                if ((string) ($b['type'] ?? '') === $type) {
                    $b['enabled'] = ($_POST['enabled'] ?? '') === '1';
                    $hit = true;
                    break;
                }
            }
            unset($b);
            // 配置里没有该类型（如 index.php 运行时补进来的 partners）→ 追加一条
            if (!$hit) {
                $cfg[] = ['type' => $type, 'enabled' => ($_POST['enabled'] ?? '') === '1'];
            }
            settingModel()->set('home_blocks_config', json_encode($cfg, JSON_UNESCAPED_UNICODE));
        }
        $__done();
    }

    $settings = $_POST['settings'] ?? [];

    // 按视图 lang 把 lang-able key 重定向到 <key>_<lang>
    $remapped = [];
    $deleteSyntheticKeys = [];
    foreach ($settings as $k => $v) {
        $k = (string) $k;
        $isLangAble = in_array($k, $LANG_KEYS, true);
        $storedRow = $HOME_STORED_BY_KEY[$k] ?? null;
        if ($isLangAble
            && $_viewLang === $_defaultLang
            && HomeSettingsLanguageDefaults::shouldSkipSyntheticWrite(
                $k,
                $v,
                $_defaultLang,
                $storedRow !== null,
                $storedRow === null ? null : (string) $storedRow['value'],
                $HOME_DEFAULTS
            )
        ) {
            if ($storedRow !== null) {
                $deleteSyntheticKeys[] = $k;
            }
            continue;
        }
        $targetKey = ($isLangAble && $_viewLang !== $_defaultLang) ? ($k . '_' . $_viewLang) : $k;
        $remapped[$targetKey] = $v;
    }
    settingModel()->saveBatch($remapped);
    foreach ($deleteSyntheticKeys as $key) {
        db()->delete('settings', '`key` = ?', [$key]);
    }

    adminLog('setting', 'update', '更新首页设置 (' . $_viewLang . ')');
    success();
}

// lang-aware 读取：非默认语言时优先 <key>_<lang>，空则回退到 base
$readLang = function (string $base, string $default = '') use ($LANG_KEYS, $_viewLang, $_defaultLang): string {
    if (in_array($base, $LANG_KEYS, true) && $_viewLang !== $_defaultLang) {
        $v = (string) config($base . '_' . $_viewLang, '');
        if ($v !== '') return $v;
    }
    return (string) config($base, $default);
};

// 获取全部 home 组设置
$allSettings = $HOME_STORED_ROWS;

// 构建 key => row 映射：先用 defaults.php 兜底（保证新增的默认设置项也出现在表单，
// 即使 DB 尚无该行），再用 DB 已存的值覆盖。
$settingsMap = [];
foreach ($HOME_DEFAULTS as $key => $def) {
    $value = (string) ($def['value'] ?? '');
    $storedRow = $HOME_STORED_BY_KEY[$key] ?? null;
    if (!in_array($_defaultLang, ['zh-CN', 'zh-TW'], true)
        && in_array($key, $LANG_KEYS, true)
        && ($storedRow === null || HomeSettingsLanguageDefaults::isLeakedFactoryValue(
            $key,
            (string) $storedRow['value'],
            $_defaultLang,
            $HOME_DEFAULTS
        ))
    ) {
        $value = HomeSettingsLanguageDefaults::localizedValue($key, $_defaultLang, $HOME_DEFAULTS);
    }
    $settingsMap[$key] = [
        'key'     => $key,
        'value'   => $value,
        'type'    => $def['type'] ?? 'text',
        'name'    => $def['name'] ?? $key,
        'tip'     => $def['tip'] ?? '',
        'options' => isset($def['options'])
            ? (is_array($def['options']) ? json_encode($def['options'], JSON_UNESCAPED_UNICODE) : $def['options'])
            : null,
    ];
}
foreach ($allSettings as $item) {
    $settingsMap[$item['key']] = $item;
}

// 把 lang-able key 的 value 替换成视图 lang 对应的存储值（没有则保持原 zh-CN base）
if ($_viewLang !== $_defaultLang) {
    foreach ($LANG_KEYS as $lk) {
        if (!isset($settingsMap[$lk])) continue;
        $langVal = (string) config($lk . '_' . $_viewLang, '');
        if ($langVal !== '') {
            $settingsMap[$lk]['value'] = $langVal;
        } else {
            // 非默认语言下：该 key 没有翻译值时显示空，让用户填写新翻译
            $settingsMap[$lk]['value'] = '';
        }
    }
}

// 获取首页展示的栏目（用于动态生成区块）—— 按视图语言过滤
$homeChannelRows = channelModel()->query(
    "SELECT id, name, translation_group_id FROM " . channelModel()->tableName() . "
     WHERE is_home = 1 AND parent_id = 0 AND status = 1 AND lang = ?
     ORDER BY sort_order ASC",
    [$_viewLang]
);
// blocks_config 全局只有一份，统一用 translation_group_id 作为区块 key，
// 这样 zh/en/ja 视图的渲染都能对齐到同一个区块定义。源记录的 group_id == 自身 id，
// 故 zh-CN 现有数据兼容；en/ja 视图自动拿到对应翻译行的 name 显示。
foreach ($homeChannelRows as &$_hcRow) {
    $_hcRow['_key_id'] = (int) ($_hcRow['translation_group_id'] ?: $_hcRow['id']);
}
unset($_hcRow);

// 区块配置（home_blocks_config 是全局共享：区块顺序/开关/样式三种语言共用，不分语言）
$blocksConfig = json_decode((string) config('home_blocks_config', ''), true);
if (!$blocksConfig) {
    $blocksConfig = [
        ['type' => 'banner', 'enabled' => true],
        ['type' => 'about', 'enabled' => true],
        ['type' => 'stats', 'enabled' => true],
        ['type' => 'channels', 'enabled' => true],
        ['type' => 'testimonials', 'enabled' => true],
        ['type' => 'advantage', 'enabled' => true],
        ['type' => 'cta', 'enabled' => true],
    ];
}

// 迁移旧的 channels 整块为独立的 channel:{group_id} 区块
$channelIdsInConfig = [];
$migratedConfig = [];
foreach ($blocksConfig as $b) {
    if ($b['type'] === 'channels') {
        foreach ($homeChannelRows as $hcRow) {
            $migratedConfig[] = ['type' => 'channel:' . $hcRow['_key_id'], 'enabled' => $b['enabled'] ?? true];
            $channelIdsInConfig[] = (int)$hcRow['_key_id'];
        }
    } else {
        $migratedConfig[] = $b;
        if (str_starts_with($b['type'], 'channel:')) {
            $channelIdsInConfig[] = (int)substr($b['type'], 8);
        }
    }
}
// 新增的首页栏目自动追加
foreach ($homeChannelRows as $hcRow) {
    if (!in_array((int)$hcRow['_key_id'], $channelIdsInConfig)) {
        $migratedConfig[] = ['type' => 'channel:' . $hcRow['_key_id'], 'enabled' => true];
    }
}
$blocksConfig = $migratedConfig;

// 合作伙伴接入区块系统：缺失则追加（启用状态沿用旧的 home_show_links）
$__hasPartners = false;
foreach ($blocksConfig as $b) { if (($b['type'] ?? '') === 'partners') { $__hasPartners = true; break; } }
if (!$__hasPartners) {
    $blocksConfig[] = ['type' => 'partners', 'enabled' => (string) config('home_show_links', '0') === '1'];
}
// 禁用（隐藏）的区块统一沉到列表底部，各自保持相对顺序
$__enBlocks = []; $__disBlocks = [];
foreach ($blocksConfig as $b) { if (!empty($b['enabled'])) $__enBlocks[] = $b; else $__disBlocks[] = $b; }
$blocksConfig = array_merge($__enBlocks, $__disBlocks);

// 客户评价数据（lang-able JSON：home_testimonials_<lang> 优先）
$testimonialsRaw = '';
if ($_viewLang !== $_defaultLang) {
    $testimonialsRaw = (string) config('home_testimonials_' . $_viewLang, '');
}
if ($testimonialsRaw === '') {
    $testimonialsRaw = (string) ($settingsMap['home_testimonials']['value'] ?? '[]');
}
$testimonialsData = json_decode($testimonialsRaw ?: '[]', true) ?: [];

// 栏目图标 SVG path
$channelIconPath = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>';

// 区块元数据
$blockMeta = [
    'banner' => [
        'title' => __('shome_blk_banner'),
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>',
        'tip'   => str_replace(':link', '<a href="/admin/banner.php" class="text-primary hover:underline">' . __('admin_banner') . '</a>', __('shome_tip_banner')),
        'keys'  => [],
    ],
    'product_categories' => [
        'title' => __('shome_blk_pcat'),
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>',
        'tip'   => str_replace(':link', '<a href="/admin/product_category.php" class="text-primary hover:underline">' . __('admin_product_category') . '</a>', __('shome_tip_pcat')),
        'keys'  => [],
    ],
    'about' => [
        'title' => __('shome_blk_about'),
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
        'bg_default' => '#ffffff',
        'keys'  => ['home_about_title', 'home_about_layout', 'home_about_content', 'home_about_image', 'home_about_tag_title', 'home_about_tag_desc'],
    ],
    'stats' => [
        'title' => __('shome_blk_stats'),
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>',
        'bg_default' => '#111827',
        'keys'  => ['home_stat_bg', 'home_stat_1_num', 'home_stat_1_text', 'home_stat_2_num', 'home_stat_2_text', 'home_stat_3_num', 'home_stat_3_text', 'home_stat_4_num', 'home_stat_4_text'],
    ],
    'testimonials' => [
        'title'  => __('shome_blk_testimonials'),
        'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>',
        'bg_default' => '#f9fafb',
        'editor' => 'testimonials',
        'keys'   => ['home_testimonials_title', 'home_testimonials_desc'],
    ],
    'advantage' => [
        'title' => __('shome_blk_advantage'),
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
        'bg_default' => '#1f2937',
        'text_light' => true,
        'keys'  => ['home_advantage_title', 'home_advantage_desc', 'home_adv_1_icon', 'home_adv_1_title', 'home_adv_1_desc', 'home_adv_2_icon', 'home_adv_2_title', 'home_adv_2_desc', 'home_adv_3_icon', 'home_adv_3_title', 'home_adv_3_desc', 'home_adv_4_icon', 'home_adv_4_title', 'home_adv_4_desc'],
    ],
    'cta' => [
        'title' => __('shome_blk_cta'),
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>',
        'bg_default' => config('primary_color', '#3B82F6'),
        'text_light' => true,
        'keys'  => ['home_cta_title', 'home_cta_desc'],
    ],
    'partners' => [
        'title' => __('shome_blk_partners'),
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a3 3 0 10-3-3"></path>',
        'bg_default' => '#f9fafb',
        'tip'   => str_replace(':link', '<a href="/admin/link.php" class="text-primary hover:underline">' . __('admin_link') . '</a>', __('shome_tip_partners')),
        'keys'  => ['home_links_title'],
    ],
];

// 动态添加各栏目区块的元数据（key 用 translation_group_id，跨语言对齐）
foreach ($homeChannelRows as $hcRow) {
    $blockMeta['channel:' . $hcRow['_key_id']] = [
        'title' => $hcRow['name'],
        'icon'  => $channelIconPath,
        'bg_default' => '#f9fafb',
        'tip'   => str_replace(':link', '<a href="/admin/channel.php?action=edit&id=' . (int)$hcRow['id'] . '" class="text-primary hover:underline">' . __('admin_channel') . '</a>', __('shome_tip_channel')),
        'keys'  => [],
    ];
}

// 健壮性：home_blocks_config 里已挂的 channel 块，即使栏目 is_home=0（"首页显示"被关）也补上元数据，
// 让它仍显示在首页设置里可管理，避免"配置里有、却因 is_home=0 而凭空消失"的幽灵块。
foreach ($blocksConfig as $__cb) {
    $__t = (string) ($__cb['type'] ?? '');
    if (!str_starts_with($__t, 'channel:') || isset($blockMeta[$__t])) continue;
    $__gid = (int) substr($__t, 8);
    // 按视图语言取该翻译组的栏目（源记录 group_id == 自身 id）
    $__rows = channelModel()->query(
        "SELECT id, name FROM " . channelModel()->tableName() .
        " WHERE (translation_group_id = ? OR id = ?) AND lang = ? ORDER BY (id = ?) DESC LIMIT 1",
        [$__gid, $__gid, $_viewLang, $__gid]
    );
    $__ch = $__rows[0] ?? null;
    if (!$__ch) continue;
    $blockMeta[$__t] = [
        'title' => $__ch['name'] . __('shome_channel_off_suffix'),
        'icon'  => $channelIconPath,
        'bg_default' => '#f9fafb',
        'tip'   => str_replace(':link', '<a href="/admin/channel.php?action=edit&id=' . (int)$__ch['id'] . '" class="text-primary hover:underline">' . __('admin_channel') . '</a>', __('shome_tip_channel_off')),
        'keys'  => [],
    ];
}

// 自定义版块元数据（title 取自 home_custom_<N>.title；section 供轻量编辑器渲染字段）
foreach ($blocksConfig as $__cb) {
    $__ct = (string) ($__cb['type'] ?? '');
    if (!str_starts_with($__ct, 'custom:')) continue;
    $__cn = substr($__ct, 7);
    $__cd = json_decode((string) config('home_custom_' . $__cn, ''), true);
    $blockMeta[$__ct] = [
        'title'    => (string) ($__cd['title'] ?? (__('shome_custom_block') . ' ' . $__cn)),
        'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1H5a1 1 0 01-1-1v-4zM14 5a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM14 15a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z"></path>',
        'bg_default' => '#ffffff',
        'editor'   => 'custom',
        'custom_n' => $__cn,
        'sections' => is_array($__cd['blocks'] ?? null) ? $__cd['blocks'] : [],
        'keys'     => [],
    ];
}

// ── 插件扩展点：允许插件注册自定义首页版块类型 ──
// 插件在 home_block_types 过滤器里追加 $meta（含 'title'/'icon'/'plugin'=>true 等），
// 即可让自己的版块出现在首页设置里；配 home_block_config_ui(后台配置) + home_block_render(前台)。
$blockMeta = apply_filters('home_block_types', $blockMeta, ['lang' => $_viewLang, 'config' => $blocksConfig]);

// 插件版块：已注册类型但 config 里还没有的，以「禁用」追加到列表末尾，管理员开启后即生效
foreach ($blockMeta as $__bt => $__bm) {
    if (empty($__bm['plugin'])) continue;
    $__inCfg = false;
    foreach ($blocksConfig as $__b) { if (($__b['type'] ?? '') === $__bt) { $__inCfg = true; break; } }
    if (!$__inCfg) { $blocksConfig[] = ['type' => $__bt, 'enabled' => false]; }
}
unset($__bt, $__bm, $__b, $__inCfg);

// Banner 版块提示：动态列出所有轮播图分组 + 各自短码 + 张数（首页用 home 组）
try {
    $__bGroups = bannerGroupModel()->all();
} catch (\Throwable $e) {
    $__bGroups = [];
}
if ($__bGroups) {
    $__bTip = str_replace(':link', '<a href="/admin/banner.php" class="text-primary hover:underline">' . __('admin_banner') . '</a>', __('shome_tip_banner')) . ' '
            . '<div class="mt-2 text-xs text-gray-500">' . __('shome_banner_shortcodes') . '</div>'
            . '<div class="mt-1.5 space-y-1">';
    foreach ($__bGroups as $__bg) {
        $__slug = (string) ($__bg['slug'] ?? '');
        if ($__slug === '') continue;
        try { $__cnt = bannerGroupModel()->getBannerCount($__slug); } catch (\Throwable $e) { $__cnt = 0; }
        $__isHome = ($__slug === 'home');
        $__bTip .= '<div class="flex items-center flex-wrap gap-2 text-xs">'
                 . '<code class="px-1.5 py-0.5 bg-white border border-gray-200 rounded text-primary select-all cursor-text">[banner-' . e($__slug) . ']</code>'
                 . '<span class="text-gray-700">' . e((string) ($__bg['name'] ?? $__slug)) . '</span>'
                 . '<span class="text-gray-400">· ' . str_replace(':n', (string) (int) $__cnt, __('shome_n_images')) . '</span>'
                 . ($__isHome ? '<span class="px-1.5 py-0.5 bg-primary/10 text-primary rounded-full">' . __('shome_used_on_home') . '</span>' : '')
                 . '</div>';
    }
    $__bTip .= '</div>';
    $blockMeta['banner']['tip'] = $__bTip;
}

$pageTitle = __('shome_title');
$currentMenu = 'setting_home';
$homeBloxAvailable = bloxAdvancedFeaturesEnabled();
$homeBloxHasDraft = $homeBloxAvailable && HomeBloxDocument::hasDraft();
$homeBloxActive = $homeBloxAvailable && HomeBloxDocument::isActive() && HomeBloxDocument::hasPublished();

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
require_once ROOT_PATH . '/admin/includes/header.php';

echo renderAdminLangSwitcher($_viewLang, str_replace(':key', 'key_' . $_viewLang, __('shome_lang_tip')));
?>

<div class="mb-6">
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <p class="text-gray-500"><?php echo __('home_editor_hint'); ?></p>
    </div>
</div>

<?php if ($homeBloxAvailable): ?>
<div data-testid="classic-home-blox-state" class="mb-5 border rounded-lg p-4 <?php echo $homeBloxActive ? 'border-blue-200 bg-blue-50' : ($homeBloxHasDraft ? 'border-amber-200 bg-amber-50' : 'border-gray-200 bg-white'); ?>">
    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div class="min-w-0">
            <div class="flex items-center gap-2 font-medium <?php echo $homeBloxActive ? 'text-blue-800' : ($homeBloxHasDraft ? 'text-amber-800' : 'text-gray-800'); ?>">
                <i class="ti <?php echo $homeBloxActive ? 'ti-circle-check' : ($homeBloxHasDraft ? 'ti-file-pencil' : 'ti-wand'); ?> text-lg"></i>
                <?php echo e($homeBloxActive ? __('shome_blox_active_title') : ($homeBloxHasDraft ? __('shome_blox_draft_title') : __('shome_blox_convert_title'))); ?>
            </div>
            <p class="mt-1 text-sm <?php echo $homeBloxActive ? 'text-blue-700' : ($homeBloxHasDraft ? 'text-amber-700' : 'text-gray-500'); ?>">
                <?php echo e($homeBloxActive ? __('shome_blox_active_tip') : ($homeBloxHasDraft ? __('shome_blox_draft_tip') : __('shome_blox_convert_tip'))); ?>
            </p>
        </div>
        <?php if ($homeBloxHasDraft): ?>
            <a href="/admin/blox_editor.php?home=1" data-testid="classic-home-blox-open"
               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-primary text-white text-sm hover:bg-secondary transition">
                <i class="ti ti-stack-2 text-base"></i><?php echo e($homeBloxActive ? __('shome_blox_open') : __('shome_blox_continue')); ?>
            </a>
        <?php else: ?>
            <form method="post" action="/admin/setting_home.php">
                <input type="hidden" name="_token" value="<?php echo e(csrfToken()); ?>">
                <input type="hidden" name="action" value="convert_to_blox">
                <button type="submit" data-testid="classic-home-blox-convert"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-primary text-white text-sm hover:bg-secondary transition cursor-pointer">
                    <i class="ti ti-wand text-base"></i><?php echo e(__('shome_blox_convert_action')); ?>
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<form id="settingForm">
    <?php echo adminLangField(); ?>
    <input type="hidden" name="settings[home_blocks_config]" id="blocksConfigJson">
    <input type="hidden" name="settings[home_testimonials]" id="testimonialsJson">

    <!-- 全局只控制标题文字；装饰由 Blox 各版块独立设置 -->
    <div class="bg-white rounded-lg shadow p-5 mb-3">
        <div class="flex items-center gap-3 flex-wrap">
            <label class="font-medium text-gray-800 whitespace-nowrap"><?php echo e(__('shome_title_style')); ?></label>
            <select name="settings[home_title_style]" class="border rounded px-3 py-1.5 text-sm bg-white">
                <?php
                $__ts = config('home_title_style', 'underline') === 'split' ? 'split' : 'underline';
                foreach ([
                    'underline' => __('shome_title_style_uniform'),
                    'split'     => __('shome_title_style_split'),
                ] as $k => $label): ?>
                <option value="<?php echo $k; ?>" <?php echo $__ts === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
            <span class="text-xs text-gray-400"><?php echo e(__('shome_title_style_scope')); ?></span>
        </div>
    </div>

    <!-- 添加自定义版块（从预设库） -->
    <?php require_once ROOT_PATH . '/includes/builder/presets.php'; $__presets = builderPresets()['sections'] ?? []; ?>
    <div class="mb-3" x-data="{ openAdd: false }">
        <button type="button" @click="openAdd = true"
                class="inline-flex items-center gap-1.5 px-4 py-2 border border-dashed border-primary/50 text-primary rounded-lg text-sm hover:bg-primary/5 cursor-pointer">
            <i class="ti ti-plus text-base"></i> <?php echo e(__('shome_add_block')); ?>
        </button>
        <div x-show="openAdd" x-cloak class="fixed inset-0 z-[60] bg-black/40 flex items-center justify-center p-4" @click.self="openAdd = false">
            <div class="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[82vh] overflow-y-auto">
                <div class="flex items-center justify-between px-5 py-3 border-b sticky top-0 bg-white">
                    <h3 class="font-semibold text-gray-800"><?php echo e(__('shome_add_from_presets')); ?></h3>
                    <button type="button" @click="openAdd = false" class="text-gray-400 hover:text-gray-600"><i class="ti ti-x text-lg"></i></button>
                </div>
                <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php foreach ($__presets as $p): ?>
                    <button type="button" @click="addCustom('<?php echo e($p['key']); ?>')"
                            class="text-left border border-gray-200 rounded-lg p-3 hover:border-primary hover:shadow-sm transition cursor-pointer">
                        <div class="font-medium text-gray-800 text-sm"><?php echo e($p['label']); ?></div>
                        <div class="text-xs text-gray-500 mt-0.5"><?php echo e($p['desc'] ?? ''); ?></div>
                    </button>
                    <?php endforeach; ?>
                    <button type="button" @click="addCustom('blank')"
                            class="text-left border border-dashed border-gray-300 rounded-lg p-3 hover:border-primary transition cursor-pointer">
                        <div class="font-medium text-gray-800 text-sm"><?php echo e(__('shome_blank_block')); ?></div>
                        <div class="text-xs text-gray-500 mt-0.5"><?php echo e(__('shome_blank_block_tip')); ?></div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="blocksContainer" class="space-y-3">
        <?php foreach ($blocksConfig as $block):
            $type = $block['type'] ?? '';
            $meta = $blockMeta[$type] ?? null;
            if (!$meta) continue;
            $enabled = !empty($block['enabled']);
        ?>
        <div class="block-card bg-white rounded-lg shadow" data-type="<?php echo $type; ?>" x-data="{ expanded: false }">
            <!-- 卡片头部 -->
            <div class="flex items-center gap-3 px-4 py-3">
                <!-- 拖拽手柄 -->
                <div class="block-drag-handle cursor-grab text-gray-300 hover:text-gray-500 flex-shrink-0" title="<?php echo e(__('shome_drag_sort')); ?>" @click.stop>
                    <i class="ti ti-grip-vertical text-lg"></i>
                </div>
                <!-- 图标 + 标题：整块可点，与右侧箭头等效（拖拽手柄与开关各自 stop） -->
                <button type="button" class="flex items-center gap-3 flex-1 min-w-0 text-left cursor-pointer group"
                        @click="expanded = !expanded"
                        :aria-expanded="expanded ? 'true' : 'false'">
                    <svg class="w-5 h-5 text-gray-400 group-hover:text-gray-600 flex-shrink-0 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?php echo $meta['icon']; ?>
                    </svg>
                    <span class="font-medium text-gray-800 group-hover:text-primary truncate transition"><?php echo $meta['title']; ?></span>
                </button>
                <!-- 开关 -->
                <label class="inline-flex items-center cursor-pointer flex-shrink-0" @click.stop>
                    <input type="checkbox" class="block-toggle sr-only peer" <?php echo $enabled ? 'checked' : ''; ?>>
                    <div class="relative w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary"></div>
                </label>
                <!-- 展开/折叠 -->
                <button type="button" class="text-gray-400 hover:text-gray-600 flex-shrink-0 p-1 transition" @click="expanded = !expanded"
                        :aria-expanded="expanded ? 'true' : 'false'" title="<?php echo e(__('admin_expand_collapse')); ?>">
                    <i class="ti ti-chevron-down text-lg transition-transform" :class="expanded && 'rotate-180'"></i>
                </button>
            </div>

            <!-- 展开内容 -->
            <div x-show="expanded" x-collapse class="border-t">
                <div class="p-5 space-y-4">
                    <?php if (!empty($meta['tip'])): ?>
                    <div class="text-sm text-gray-500 bg-gray-50 rounded px-4 py-3">
                        <?php echo $meta['tip']; ?>
                    </div>
                    <?php endif; ?>

                    <!-- 背景设置 -->
                    <?php if ($type !== 'banner'):
                        $blockBgColor = $block['bg_color'] ?? '';
                        $bgDefault = $meta['bg_default'] ?? '';
                        $bgPickerVal = preg_match('/^#[0-9a-fA-F]{3,8}$/', $blockBgColor) ? $blockBgColor : ($bgDefault ?: '#ffffff');
                        $blockTextLight = isset($block['text_light']) ? !empty($block['text_light']) : !empty($meta['text_light']);
                        $blockLayout = $block['layout'] ?? 'container';
                        // 渐变色解析
                        $isGradient = str_starts_with($blockBgColor, 'linear-gradient');
                        $bgMode = $isGradient ? 'gradient' : 'solid';
                        $gradFrom = '#3B82F6'; $gradTo = '#8B5CF6'; $gradDir = 'to right';
                        if ($isGradient && preg_match('/linear-gradient\(\s*([^,]+)\s*,\s*(#[0-9a-fA-F]{3,8})\s*,\s*(#[0-9a-fA-F]{3,8})\s*\)/', $blockBgColor, $gm)) {
                            $gradDir = trim($gm[1]); $gradFrom = $gm[2]; $gradTo = $gm[3];
                        }
                    ?>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-3"><?php echo e(__('shome_block_style')); ?></h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4">
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo __('home_bg_image'); ?></label>
                                <div class="flex gap-1">
                                    <input type="text" class="block-bg-image flex-1 min-w-0 border rounded px-2 py-1.5 text-xs"
                                           value="<?php echo e($block['bg_image'] ?? ''); ?>" placeholder="<?php echo e(__('shome_image_url')); ?>">
                                    <button type="button" class="block-bg-media shrink-0 bg-blue-50 hover:bg-blue-100 text-blue-500 px-1.5 rounded" title="<?php echo __('admin_media_library'); ?>">
                                        <i class="ti ti-photo text-base"></i>
                                    </button>
                                    <button type="button" class="block-bg-upload shrink-0 bg-gray-100 hover:bg-gray-200 text-gray-500 px-1.5 rounded" title="<?php echo e(__('admin_upload')); ?>">
                                        <i class="ti ti-upload text-base"></i>
                                    </button>
                                </div>
                            </div>
                            <div x-data="{ bgMode: '<?php echo $bgMode; ?>' }" class="sm:col-span-2">
                                <input type="hidden" class="block-bg-mode" :value="bgMode">
                                <div class="flex items-center gap-2 mb-1">
                                    <label class="text-xs text-gray-500"><?php echo e(__('blox_bg_color')); ?></label>
                                    <div class="flex gap-0.5 ml-auto">
                                        <button type="button" @click="bgMode='solid'"
                                                :class="bgMode==='solid' ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500 hover:bg-gray-300'"
                                                class="text-[10px] leading-none px-1.5 py-1 rounded transition"><?php echo e(__('shome_solid')); ?></button>
                                        <button type="button" @click="bgMode='gradient'"
                                                :class="bgMode==='gradient' ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500 hover:bg-gray-300'"
                                                class="text-[10px] leading-none px-1.5 py-1 rounded transition"><?php echo e(__('shome_gradient')); ?></button>
                                    </div>
                                </div>
                                <!-- 预置背景（含放射渐变）：点击直接套用，写入背景色原始 CSS 值 -->
                                <div class="flex flex-wrap gap-1.5 mb-2">
                                    <?php
                                    // 精选预置：Tailwind 单色 + uiGradients/GradientCraft 风格渐变 + 放射渐变
                                    $bgPresets = [
                                        // 单色（Tailwind）
                                        '#ffffff', '#f8fafc', '#f1f5f9', '#0f172a', '#111827',
                                        // 线性渐变（Tailwind 配色）
                                        'linear-gradient(135deg,#6366f1,#a855f7)',
                                        'linear-gradient(135deg,#3b82f6,#06b6d4)',
                                        'linear-gradient(135deg,#0ea5e9,#6dd5fa)',
                                        'linear-gradient(135deg,#10b981,#14b8a6)',
                                        'linear-gradient(135deg,#f43f5e,#f97316)',
                                        'linear-gradient(135deg,#f59e0b,#ef4444)',
                                        'linear-gradient(135deg,#ec4899,#8b5cf6)',
                                        // 线性渐变（uiGradients 经典）
                                        'linear-gradient(135deg,#cc2b5e,#753a88)',
                                        'linear-gradient(135deg,#1a2980,#26d0ce)',
                                        'linear-gradient(135deg,#4ca1af,#2c3e50)',
                                        'linear-gradient(135deg,#0f2027,#2c5364)',
                                        'linear-gradient(135deg,#f09819,#edde5d)',
                                        // 放射渐变
                                        'radial-gradient(circle at 30% 30%,#60a5fa,#1e3a8a)',
                                        'radial-gradient(circle,#a78bfa,#4c1d95)',
                                        'radial-gradient(circle at 70% 20%,#fda4af,#7f1d1d)',
                                        'radial-gradient(circle at 50% 0%,#34d399,#065f46)',
                                    ];
                                    foreach ($bgPresets as $preset):
                                    ?>
                                    <button type="button" title="<?php echo e($preset); ?>"
                                            @click="bgMode='solid'; applyBgPreset($el, '<?php echo e($preset); ?>')"
                                            class="w-6 h-6 rounded border border-gray-200 hover:ring-2 hover:ring-primary/50 transition"
                                            style="background:<?php echo $preset; ?>"></button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="flex gap-1" x-show="bgMode==='solid'">
                                    <input type="color" class="block-bg-picker w-8 h-[30px] border rounded cursor-pointer p-0.5"
                                           value="<?php echo e($bgPickerVal); ?>">
                                    <input type="text" class="block-bg-color flex-1 min-w-0 border rounded px-2 py-1.5 text-xs"
                                           value="<?php echo e($isGradient ? '' : $blockBgColor); ?>" placeholder="<?php echo $bgDefault ? e(__('shome_default_prefix')) . e($bgDefault) : '#hex 或渐变'; ?>">
                                </div>
                                <div x-show="bgMode==='gradient'">
                                    <div class="flex gap-1 items-center">
                                        <input type="color" class="block-grad-from w-8 h-[30px] border rounded cursor-pointer p-0.5"
                                               value="<?php echo e($gradFrom); ?>">
                                        <span class="text-gray-300 text-xs">→</span>
                                        <input type="color" class="block-grad-to w-8 h-[30px] border rounded cursor-pointer p-0.5"
                                               value="<?php echo e($gradTo); ?>">
                                        <select class="block-grad-dir flex-1 border rounded px-1 py-1.5 text-xs bg-white">
                                            <option value="to right" <?php echo $gradDir === 'to right' ? 'selected' : ''; ?>><?php echo e(__('shome_grad_h')); ?></option>
                                            <option value="to bottom" <?php echo $gradDir === 'to bottom' ? 'selected' : ''; ?>><?php echo e(__('shome_grad_v')); ?></option>
                                            <option value="to bottom right" <?php echo $gradDir === 'to bottom right' ? 'selected' : ''; ?>><?php echo e(__('shome_grad_d')); ?></option>
                                            <option value="135deg" <?php echo $gradDir === '135deg' ? 'selected' : ''; ?>><?php echo e(__('shome_grad_ad')); ?></option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('shome_opacity')); ?></label>
                                <div class="flex items-center gap-2">
                                    <input type="range" class="block-bg-opacity flex-1 accent-primary" min="0" max="100"
                                           value="<?php echo (int)($block['bg_opacity'] ?? 100); ?>">
                                    <span class="block-bg-opacity-val text-xs text-gray-500 w-8 text-right"><?php echo (int)($block['bg_opacity'] ?? 100); ?>%</span>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('shome_text_color')); ?></label>
                                <label class="inline-flex items-center gap-2 cursor-pointer mt-1">
                                    <input type="checkbox" class="block-text-light sr-only peer" <?php echo $blockTextLight ? 'checked' : ''; ?>>
                                    <div class="relative w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary"></div>
                                    <span class="text-xs text-gray-500"><?php echo e(__('shome_light_text')); ?></span>
                                </label>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('shome_content_width')); ?></label>
                                <select class="block-layout w-full border rounded px-2 py-1.5 text-xs bg-white">
                                    <option value="container" <?php echo $blockLayout === 'container' ? 'selected' : ''; ?>><?php echo e(__('shome_centered')); ?></option>
                                    <option value="full" <?php echo $blockLayout === 'full' ? 'selected' : ''; ?>><?php echo e(__('shome_fullwidth')); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php // 栏目区块（产品/文章/案例等）：每行个数 / 显示数量 / 排序
                    if (str_starts_with($type, 'channel:')):
                        $cPerRow = (int)($block['per_row'] ?? 4);
                        $cLimit  = (int)($block['limit'] ?? 8);
                        $cSort   = $block['sort'] ?? 'recommend';
                    ?>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-3"><?php echo e(__('shome_display_settings')); ?></h4>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4">
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('shome_per_row')); ?> <span class="text-gray-300">(1-8)</span></label>
                                <input type="number" min="1" max="8" class="block-perrow w-full border rounded px-2 py-1.5 text-xs"
                                       value="<?php echo $cPerRow; ?>">
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('shome_count')); ?></label>
                                <input type="number" min="1" max="100" class="block-limit w-full border rounded px-2 py-1.5 text-xs"
                                       value="<?php echo $cLimit; ?>">
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('sort_order')); ?></label>
                                <select class="block-sort w-full border rounded px-2 py-1.5 text-xs bg-white">
                                    <option value="recommend" <?php echo $cSort === 'recommend' ? 'selected' : ''; ?>><?php echo e(__('shome_sort_recommend')); ?></option>
                                    <option value="latest" <?php echo $cSort === 'latest' ? 'selected' : ''; ?>><?php echo e(__('shome_sort_latest')); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php // 产品分类树：标题 / 每行列数 / 搜索框
                    if ($type === 'product_categories'):
                        $pcT = (string)($block['title'] ?? '');
                        $pcC = (int)($block['cols'] ?? 3);
                        $pcS = !empty($block['show_search']);
                        $pcL = (int)($block['limit'] ?? 30);
                    ?>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-3"><?php echo e(__('shome_display_settings')); ?></h4>
                        <div class="grid grid-cols-1 sm:grid-cols-4 gap-x-6 gap-y-4">
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('shome_block_title')); ?></label>
                                <input type="text" class="block-pc-title w-full border rounded px-2 py-1.5 text-xs"
                                       value="<?php echo e($pcT); ?>" placeholder="<?php echo e(__('admin_product_category')); ?>">
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('shome_cols')); ?> <span class="text-gray-300">(1-4)</span></label>
                                <input type="number" min="1" max="4" class="block-pc-cols w-full border rounded px-2 py-1.5 text-xs"
                                       value="<?php echo $pcC; ?>">
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('shome_cat_count')); ?> <span class="text-gray-300">(1-60)</span></label>
                                <input type="number" min="1" max="60" class="block-pc-limit w-full border rounded px-2 py-1.5 text-xs"
                                       value="<?php echo $pcL; ?>">
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1"><?php echo e(__('shome_search_box')); ?></label>
                                <label class="flex items-center gap-2 text-xs text-gray-600 mt-1.5">
                                    <input type="checkbox" class="block-pc-search w-4 h-4 accent-blue-500" <?php echo $pcS ? 'checked' : ''; ?>>
                                    <?php echo e(__('shome_show_product_search')); ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php // 插件版块的后台配置 UI 扩展点：插件按 $type 输出自己的配置字段 ?>
                    <?php do_action('home_block_config_ui', $type, $block, $meta); ?>

                    <?php // 数据统计：4 项图标（Tabler 图标名，留空/none 则不显示）
                    if ($type === 'stats'):
                        $statIconDefaults = ['award', 'users', 'briefcase', 'thumb-up'];
                    ?>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-3"><?php echo e(__('shome_stat_icons')); ?></h4>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <?php for ($si = 1; $si <= 4; $si++):
                                $sIcon = config('home_stat_' . $si . '_icon', $statIconDefaults[$si - 1]);
                            ?>
                            <div class="flex items-center gap-2" x-data="{ ic: '<?php echo e($sIcon); ?>' }">
                                <span class="w-9 h-9 shrink-0 border rounded flex items-center justify-center bg-white text-gray-600">
                                    <i class="ti text-xl" :class="'ti-' + (ic || 'point')"></i>
                                </span>
                                <input type="text" name="settings[home_stat_<?php echo $si; ?>_icon]" x-model="ic"
                                       placeholder="<?php echo e(__('shome_icon_ph')); ?>" class="flex-1 min-w-0 border rounded px-2 py-1.5 text-xs">
                            </div>
                            <?php endfor; ?>
                        </div>
                        <p class="text-xs text-gray-400 mt-2"><?php echo str_replace(':site', '<a href="https://tabler.io/icons" target="_blank" class="text-primary hover:underline">tabler.io/icons</a>', e(__('shome_icon_hint'))); ?></p>
                    </div>
                    <?php endif; ?>

                    <?php // 渲染普通设置项 ?>
                    <?php foreach ($meta['keys'] as $settingKey):
                        $item = $settingsMap[$settingKey] ?? null;
                        if (!$item) continue;
                    ?>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                        <label class="text-gray-700 text-sm pt-2">
                            <?php echo e(settingLabel($item['key'], (string) $item['name'])); ?>
                            <?php
                            // tip 本地化与 label 同规则：lang 键优先，DB/defaults 的中文作兜底
                            $__tip = settingTip($item['key'], (string) $item['tip']);
                            ?>
                            <?php if ($__tip !== ''): ?>
                            <span class="text-gray-400 text-xs block"><?php echo e($__tip); ?></span>
                            <?php endif; ?>
                        </label>
                        <div class="md:col-span-3">
                            <?php if ($item['type'] === 'textarea'): ?>
                            <textarea name="settings[<?php echo e($item['key']); ?>]" rows="3"
                                      class="w-full border rounded px-3 py-2 text-sm"><?php echo e($item['value']); ?></textarea>

                            <?php elseif ($item['type'] === 'select' && !empty($item['options'])): ?>
                            <?php $opts = json_decode($item['options'], true) ?: []; ?>
                            <select name="settings[<?php echo e($item['key']); ?>]"
                                    class="w-full border rounded px-3 py-2 text-sm bg-white">
                                <?php foreach ($opts as $optVal => $optLabel):
                                    $__ol = settingOptionLabel($item['key'], (string) $optVal, (string) $optLabel);
                                ?>
                                <option value="<?php echo e($optVal); ?>" <?php echo $item['value'] === $optVal ? 'selected' : ''; ?>>
                                    <?php echo e($__ol); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <?php elseif ($item['type'] === 'icon'): ?>
                            <?php $allIcons = getAdvantageIcons(); $curIcon = $item['value'] ?: 'check-circle'; ?>
                            <div class="icon-picker flex flex-wrap gap-1.5">
                                <?php foreach ($allIcons as $iKey => $iData): ?>
                                <button type="button"
                                        class="icon-pick-btn w-9 h-9 flex items-center justify-center rounded border transition <?php echo $curIcon === $iKey ? 'border-primary bg-blue-50 text-primary' : 'border-gray-200 text-gray-500 hover:border-gray-300'; ?>"
                                        data-icon="<?php echo $iKey; ?>" title="<?php echo e($iData['label']); ?>">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><?php echo $iData['svg']; ?></svg>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="settings[<?php echo e($item['key']); ?>]" class="icon-pick-value" value="<?php echo e($curIcon); ?>">

                            <?php elseif ($item['type'] === 'image'): ?>
                            <div class="flex gap-2 items-center">
                                <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                                       value="<?php echo e($item['value']); ?>"
                                       id="input_<?php echo e($item['key']); ?>"
                                       class="flex-1 border rounded px-3 py-2 text-sm">
                                <button type="button" onclick="uploadImage('<?php echo e($item['key']); ?>')"
                                        class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm inline-flex items-center gap-1">
                                    <i class="ti ti-upload text-base"></i>
                                    <?php echo e(__('admin_upload')); ?>
                                </button>
                                <button type="button" onclick="pickFromMedia('<?php echo e($item['key']); ?>')"
                                        class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm inline-flex items-center gap-1">
                                    <i class="ti ti-photo text-base"></i>
                                    <?php echo __("admin_media_library"); ?>
                                </button>
                            </div>
                            <?php if ($item['value']): ?>
                            <img src="<?php echo e($item['value']); ?>" class="h-16 mt-2 rounded" id="preview_<?php echo e($item['key']); ?>">
                            <?php endif; ?>

                            <?php else: ?>
                            <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                                   value="<?php echo e($item['value']); ?>"
                                   class="w-full border rounded px-3 py-2 text-sm">
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php // 客户评价编辑器 ?>
                    <?php if (($meta['editor'] ?? '') === 'custom'):
                        $__cn   = $meta['custom_n'] ?? '';
                        $__secs = is_array($meta['sections'] ?? null) ? $meta['sections'] : [];
                        $__hasEl = false; foreach ($__secs as $__s) { if (!empty($__s['columns'])) { $__hasEl = true; break; } }
                    ?>
                    <div class="border-t pt-4 mt-2" data-custom-editor="<?php echo e((string)$__cn); ?>">
                        <div class="mb-3">
                            <label class="text-xs text-gray-400 block mb-1"><?php echo e(__('shome_block_name')); ?></label>
                            <input type="text" class="cb-title w-full border rounded px-3 py-2 text-sm" value="<?php echo e($meta['title']); ?>">
                        </div>
                        <?php if ($__hasEl): ?>
                        <?php // section 级标题/副标题（对齐全页构建器；留空则该区块不显示居中大标题） ?>
                        <div class="space-y-2 mb-3">
                            <?php foreach ($__secs as $si => $__sec): if (empty($__sec['columns'])) continue; $__ss = $__sec['settings'] ?? []; ?>
                            <div class="cb-sec p-3 border border-blue-100 rounded-lg bg-blue-50/40" data-sec="<?php echo (int)$si; ?>">
                                <div class="text-[11px] uppercase tracking-wide text-blue-400 mb-2"><?php echo str_replace(':n', (string) ((int)$si + 1), e(__('shome_section_n'))); ?> · <?php echo e(__('shome_section_title_opt')); ?>（居中大标题 + 装饰条）</div>
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="text" class="cb-sec-field border rounded px-2 py-1.5 text-sm" data-sec-field="title" value="<?php echo e($__ss['title'] ?? ''); ?>" placeholder="<?php echo e(__('shome_ph_sec_title')); ?>">
                                    <input type="text" class="cb-sec-field border rounded px-2 py-1.5 text-sm" data-sec-field="subtitle" value="<?php echo e($__ss['subtitle'] ?? ''); ?>" placeholder="<?php echo e(__('blox_field_subtitle')); ?>">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="space-y-2">
                            <?php foreach ($__secs as $si => $__sec): foreach (($__sec['columns'] ?? []) as $ci => $col): foreach (($col['elements'] ?? []) as $ei => $el):
                                $eType = (string)($el['type'] ?? ''); $d = is_array($el['data'] ?? null) ? $el['data'] : [];
                            ?>
                            <div class="cb-el p-3 border rounded-lg bg-gray-50" data-sec="<?php echo (int)$si; ?>" data-col="<?php echo (int)$ci; ?>" data-el="<?php echo (int)$ei; ?>">
                                <div class="text-[11px] uppercase tracking-wide text-gray-400 mb-2"><?php echo e($eType); ?></div>
                                <?php if ($eType === 'heading'): ?>
                                    <div class="flex gap-2">
                                        <input type="text" class="cb-field flex-1 border rounded px-2 py-1.5 text-sm" data-field="text" value="<?php echo e($d['text'] ?? ''); ?>" placeholder="<?php echo e(__('shome_ph_title_text')); ?>">
                                        <select class="cb-field border rounded px-2 py-1.5 text-sm bg-white" data-field="align" title="<?php echo e(__('shome_align')); ?>">
                                            <?php $__al = $d['align'] ?? 'left'; foreach (['left' => __('blox_align_left'), 'center' => __('blox_align_center'), 'right' => __('blox_align_right')] as $__av => $__aln): ?>
                                            <option value="<?php echo $__av; ?>" <?php echo $__al === $__av ? 'selected' : ''; ?>><?php echo $__aln; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                <?php elseif ($eType === 'text'): ?>
                                    <textarea class="cb-field w-full border rounded px-2 py-1.5 text-sm" data-field="html" rows="3" placeholder="<?php echo e(__('shome_ph_html')); ?>"><?php echo e($d['html'] ?? ''); ?></textarea>
                                <?php elseif ($eType === 'button'): ?>
                                    <div class="grid grid-cols-2 gap-2">
                                        <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="text" value="<?php echo e($d['text'] ?? ''); ?>" placeholder="<?php echo e(__('shome_f_btn_text')); ?>">
                                        <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="url" value="<?php echo e($d['url'] ?? ''); ?>" placeholder="<?php echo e(__('shome_ph_link_url')); ?>">
                                    </div>
                                <?php elseif ($eType === 'icon-box'): ?>
                                    <div class="grid grid-cols-3 gap-2">
                                        <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="icon" value="<?php echo e($d['icon'] ?? ''); ?>" placeholder="<?php echo e(__('shome_f_icon')); ?>">
                                        <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="title" value="<?php echo e($d['title'] ?? ''); ?>" placeholder="<?php echo e(__('label_title')); ?>">
                                        <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="text" value="<?php echo e($d['text'] ?? ''); ?>" placeholder="<?php echo e(__('shome_f_desc')); ?>">
                                    </div>
                                <?php elseif ($eType === 'card'): ?>
                                    <div class="space-y-2">
                                        <input type="text" class="cb-field w-full border rounded px-2 py-1.5 text-sm" data-field="image" value="<?php echo e($d['image'] ?? ''); ?>" placeholder="<?php echo e(__('shome_image_url')); ?>">
                                        <div class="grid grid-cols-2 gap-2">
                                            <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="title" value="<?php echo e($d['title'] ?? ''); ?>" placeholder="<?php echo e(__('label_title')); ?>">
                                            <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="link" value="<?php echo e($d['link'] ?? ''); ?>" placeholder="<?php echo e(__('shome_ph_link_opt')); ?>">
                                        </div>
                                        <input type="text" class="cb-field w-full border rounded px-2 py-1.5 text-sm" data-field="text" value="<?php echo e($d['text'] ?? ''); ?>" placeholder="<?php echo e(__('shome_f_desc')); ?>">
                                    </div>
                                <?php elseif ($eType === 'image'): ?>
                                    <div class="grid grid-cols-2 gap-2">
                                        <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="src" value="<?php echo e($d['src'] ?? ''); ?>" placeholder="<?php echo e(__('shome_image_url')); ?>">
                                        <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="alt" value="<?php echo e($d['alt'] ?? ''); ?>" placeholder="<?php echo e(__('shome_ph_alt')); ?>">
                                    </div>
                                <?php elseif ($eType === 'cta'): ?>
                                    <div class="space-y-2">
                                        <input type="text" class="cb-field w-full border rounded px-2 py-1.5 text-sm" data-field="title" value="<?php echo e($d['title'] ?? ''); ?>" placeholder="<?php echo e(__('label_title')); ?>">
                                        <input type="text" class="cb-field w-full border rounded px-2 py-1.5 text-sm" data-field="text" value="<?php echo e($d['text'] ?? ''); ?>" placeholder="<?php echo e(__('shome_f_desc')); ?>">
                                        <div class="grid grid-cols-2 gap-2">
                                            <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="btn_text" value="<?php echo e($d['btn_text'] ?? ''); ?>" placeholder="<?php echo e(__('shome_f_btn_text')); ?>">
                                            <input type="text" class="cb-field border rounded px-2 py-1.5 text-sm" data-field="btn_url" value="<?php echo e($d['btn_url'] ?? ''); ?>" placeholder="<?php echo e(__('shome_f_btn_url')); ?>">
                                        </div>
                                    </div>
                                <?php elseif ($eType === 'accordion'): ?>
                                    <div class="space-y-2">
                                        <textarea class="cb-field w-full border rounded px-2 py-1.5 text-sm font-mono" data-field="items" rows="6" placeholder="<?php echo e(__('shome_ph_faq_items')); ?>"><?php echo e($d['items'] ?? ''); ?></textarea>
                                        <p class="text-xs text-gray-400 -mt-1"><?php echo str_replace(':sep', '<code>|</code>', e(__('shome_faq_hint'))); ?></p>
                                        <div class="flex gap-4 text-xs text-gray-600">
                                            <label class="inline-flex items-center gap-1"><input type="checkbox" class="cb-field" data-field="open_first" <?php echo !empty($d['open_first']) ? 'checked' : ''; ?>><?php echo e(__('shome_faq_open_first')); ?></label>
                                            <label class="inline-flex items-center gap-1"><input type="checkbox" class="cb-field" data-field="seo_schema" <?php echo !empty($d['seo_schema']) ? 'checked' : ''; ?>><?php echo e(__('shome_faq_schema')); ?></label>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <p class="text-xs text-gray-400"><?php echo str_replace(':type', e($eType), e(__('shome_edit_in_builder'))); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; endforeach; endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-sm text-gray-400"><?php echo e(__('shome_no_editable')); ?></p>
                        <?php endif; ?>
                        <input type="hidden" class="cb-json" value="<?php echo e(json_encode($__secs, JSON_UNESCAPED_UNICODE)); ?>">
                        <input type="hidden" name="settings[home_custom_<?php echo e((string)$__cn); ?>]" class="cb-output">
                        <div class="mt-3 text-right">
                            <button type="button" onclick="delCustom(<?php echo (int)$__cn; ?>)" class="text-xs text-red-400 hover:text-red-600"><?php echo e(__('shome_del_this_block')); ?></button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (($meta['editor'] ?? '') === 'testimonials'): ?>
                    <div class="border-t pt-4 mt-2">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-sm font-medium text-gray-700"><?php echo e(__('shome_testimonial_list')); ?></h4>
                            <button type="button" onclick="addTestimonial()" class="text-primary hover:underline text-sm">+ <?php echo e(__('shome_add_testimonial')); ?></button>
                        </div>
                        <div id="testimonialsEditor" class="space-y-3">
                            <?php for ($ti = 0; $ti < max(count($testimonialsData), 1); $ti++):
                                $tm = $testimonialsData[$ti] ?? null;
                            ?>
                            <div class="tm-row p-3 border rounded-lg bg-gray-50">
                                <div class="flex gap-3 items-start">
                                    <span class="text-gray-300 text-sm pt-2 w-5 flex-shrink-0"><?php echo $ti + 1; ?></span>
                                    <div class="flex-shrink-0">
                                        <label class="text-xs text-gray-400 block mb-1"><?php echo e(__('shome_avatar')); ?></label>
                                        <div class="flex items-center gap-1">
                                            <input type="text" class="tm-avatar border rounded px-2 py-1.5 text-xs w-28" placeholder="<?php echo e(__('shome_image_url')); ?>" value="<?php echo e($tm['avatar'] ?? ''); ?>">
                                            <button type="button" class="tm-upload-btn text-gray-400 hover:text-primary" title="<?php echo e(__('shome_upload_avatar')); ?>">
                                                <i class="ti ti-photo text-base"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="w-24 flex-shrink-0">
                                        <label class="text-xs text-gray-400 block mb-1"><?php echo __('home_testimonial_name'); ?></label>
                                        <input type="text" class="tm-name w-full border rounded px-2 py-1.5 text-sm" value="<?php echo e($tm['name'] ?? ''); ?>">
                                    </div>
                                    <div class="w-32 flex-shrink-0">
                                        <label class="text-xs text-gray-400 block mb-1"><?php echo __('home_testimonial_company'); ?></label>
                                        <input type="text" class="tm-company w-full border rounded px-2 py-1.5 text-sm" value="<?php echo e($tm['company'] ?? ''); ?>">
                                    </div>
                                    <div class="flex-1">
                                        <label class="text-xs text-gray-400 block mb-1"><?php echo __('home_testimonial_content'); ?></label>
                                        <input type="text" class="tm-content w-full border rounded px-2 py-1.5 text-sm" value="<?php echo e($tm['content'] ?? ''); ?>">
                                    </div>
                                    <button type="button" class="tm-remove text-gray-300 hover:text-red-400 pt-5 flex-shrink-0" title="<?php echo __('admin_delete'); ?>">
                                        <i class="ti ti-x text-base"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="pt-1 flex justify-end">
                        <button type="button" class="block-save-btn bg-primary hover:bg-secondary text-white text-sm px-4 py-1.5 rounded inline-flex items-center gap-1 transition">
                            <i class="ti ti-check text-base"></i><?php echo e(__('save_settings')); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <i class="ti ti-check text-base"></i>
            <?php echo __('btn_save_settings'); ?>
        </button>
    </div>
</form>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// SortableJS 拖拽排序
new Sortable(document.getElementById('blocksContainer'), {
    handle: '.block-drag-handle',
    animation: 200,
    ghostClass: 'opacity-30',
    chosenClass: 'shadow-lg'
});

// 关闭某版块时，卡片实时沉到列表底部（保存时也会统一把禁用项排到末尾）
document.getElementById('blocksContainer').addEventListener('change', function (e) {
    if (!e.target.classList.contains('block-toggle')) return;
    if (!e.target.checked) {
        var card = e.target.closest('.block-card');
        if (card) this.appendChild(card);
    }
});

// 收集区块配置
function collectBlocksConfig() {
    var cards = document.querySelectorAll('#blocksContainer .block-card');
    var config = [];
    cards.forEach(function(card) {
        var item = {
            type: card.dataset.type,
            enabled: card.querySelector('.block-toggle').checked
        };
        var bgImage = card.querySelector('.block-bg-image');
        var bgOpacity = card.querySelector('.block-bg-opacity');
        var textLight = card.querySelector('.block-text-light');
        if (bgImage && bgImage.value.trim()) item.bg_image = bgImage.value.trim();
        // 背景色：读取模式标记判断单色/渐变
        var bgModeInput = card.querySelector('.block-bg-mode');
        var bgColorVal = '';
        if (bgModeInput && bgModeInput.value === 'gradient') {
            var gf = card.querySelector('.block-grad-from').value;
            var gt = card.querySelector('.block-grad-to').value;
            var gd = card.querySelector('.block-grad-dir').value;
            bgColorVal = 'linear-gradient(' + gd + ', ' + gf + ', ' + gt + ')';
        } else {
            var bgColor = card.querySelector('.block-bg-color');
            if (bgColor) bgColorVal = bgColor.value.trim();
        }
        if (bgColorVal) item.bg_color = bgColorVal;
        if (bgOpacity) item.bg_opacity = parseInt(bgOpacity.value);
        if (textLight) item.text_light = textLight.checked;
        var layout = card.querySelector('.block-layout');
        if (layout) item.layout = layout.value;
        // 栏目区块展示设置：每行个数 / 显示数量 / 排序
        var perRow = card.querySelector('.block-perrow');
        var limit  = card.querySelector('.block-limit');
        var sort   = card.querySelector('.block-sort');
        if (perRow) item.per_row = parseInt(perRow.value);
        if (limit)  item.limit   = parseInt(limit.value);
        if (sort)   item.sort    = sort.value;
        var pcTitle  = card.querySelector('.block-pc-title');
        var pcCols   = card.querySelector('.block-pc-cols');
        var pcSearch = card.querySelector('.block-pc-search');
        if (pcTitle)  item.title       = pcTitle.value;
        if (pcCols)   item.cols        = parseInt(pcCols.value) || 3;
        if (pcSearch) item.show_search = pcSearch.checked;
        var pcLimit = card.querySelector('.block-pc-limit');
        if (pcLimit) item.limit = parseInt(pcLimit.value) || 30;
        // 插件版块：调用插件注册的采集器补齐自定义字段（见 window.homeBlockCollectors）
        if (window.homeBlockCollectors && typeof window.homeBlockCollectors[item.type] === 'function') {
            try { window.homeBlockCollectors[item.type](card, item); } catch (e) { console.error(e); }
        }
        config.push(item);
    });
    // 禁用（隐藏）的区块统一沉到底部，各自保持相对顺序
    var enabledCfg = config.filter(function (c) { return c.enabled; });
    var disabledCfg = config.filter(function (c) { return !c.enabled; });
    document.getElementById('blocksConfigJson').value = JSON.stringify(enabledCfg.concat(disabledCfg));
}

// 收集客户评价数据
function collectTestimonials() {
    var rows = document.querySelectorAll('#testimonialsEditor .tm-row');
    var data = [];
    rows.forEach(function(row) {
        var name = row.querySelector('.tm-name').value.trim();
        var content = row.querySelector('.tm-content').value.trim();
        if (!name && !content) return;
        data.push({
            avatar: row.querySelector('.tm-avatar').value.trim(),
            name: name,
            company: row.querySelector('.tm-company').value.trim(),
            content: content
        });
    });
    document.getElementById('testimonialsJson').value = JSON.stringify(data);
}

// 添加评价行
function addTestimonial() {
    var editor = document.getElementById('testimonialsEditor');
    var rows = editor.querySelectorAll('.tm-row');
    if (rows.length >= 10) { showMessage(<?php echo json_encode(__('shome_max_testimonials'), JSON_UNESCAPED_UNICODE); ?>, 'error'); return; }

    var idx = rows.length + 1;
    var div = document.createElement('div');
    div.className = 'tm-row p-3 border rounded-lg bg-gray-50';
    div.innerHTML = '<div class="flex gap-3 items-start">' +
        '<span class="text-gray-300 text-sm pt-2 w-5 flex-shrink-0">' + idx + '</span>' +
        '<div class="flex-shrink-0"><label class="text-xs text-gray-400 block mb-1">' + <?php echo json_encode(__('shome_avatar'), JSON_UNESCAPED_UNICODE); ?> + '</label>' +
        '<div class="flex items-center gap-1"><input type="text" class="tm-avatar border rounded px-2 py-1.5 text-xs w-28" placeholder="<?php echo e(__('shome_image_url')); ?>">' +
        '<button type="button" class="tm-upload-btn text-gray-400 hover:text-primary" title="<?php echo e(__('shome_upload_avatar')); ?>">' +
        '<i class="ti ti-photo text-base"></i>' +
        '</button></div></div>' +
        '<div class="w-24 flex-shrink-0"><label class="text-xs text-gray-400 block mb-1"><?php echo __('home_testimonial_name'); ?></label>' +
        '<input type="text" class="tm-name w-full border rounded px-2 py-1.5 text-sm"></div>' +
        '<div class="w-32 flex-shrink-0"><label class="text-xs text-gray-400 block mb-1"><?php echo __('home_testimonial_company'); ?></label>' +
        '<input type="text" class="tm-company w-full border rounded px-2 py-1.5 text-sm"></div>' +
        '<div class="flex-1"><label class="text-xs text-gray-400 block mb-1"><?php echo __('home_testimonial_content'); ?></label>' +
        '<input type="text" class="tm-content w-full border rounded px-2 py-1.5 text-sm"></div>' +
        '<button type="button" class="tm-remove text-gray-300 hover:text-red-400 pt-5 flex-shrink-0" title="<?php echo __('admin_delete'); ?>">' +
        '<i class="ti ti-x text-base"></i>' +
        '</button></div>';
    editor.appendChild(div);
}

// 删除评价行（事件委托）
document.getElementById('testimonialsEditor')?.addEventListener('click', function(e) {
    var btn = e.target.closest('.tm-remove');
    if (btn) {
        btn.closest('.tm-row').remove();
        // 重新编号
        var rows = this.querySelectorAll('.tm-row');
        rows.forEach(function(row, i) {
            row.querySelector('span').textContent = i + 1;
        });
    }
});

// 图标选择器
document.querySelectorAll('.icon-picker').forEach(function(picker) {
    picker.addEventListener('click', function(e) {
        var btn = e.target.closest('.icon-pick-btn');
        if (!btn) return;
        picker.querySelectorAll('.icon-pick-btn').forEach(function(b) {
            b.classList.remove('border-primary', 'bg-blue-50', 'text-primary');
            b.classList.add('border-gray-200', 'text-gray-500');
        });
        btn.classList.remove('border-gray-200', 'text-gray-500');
        btn.classList.add('border-primary', 'bg-blue-50', 'text-primary');
        var input = picker.nextElementSibling;
        if (input && input.classList.contains('icon-pick-value')) {
            input.value = btn.dataset.icon;
        }
    });
});

// 背景色色板同步 + 渐变控件 + 透明度滑块
document.getElementById('blocksContainer').addEventListener('input', function(e) {
    if (e.target.classList.contains('block-bg-picker')) {
        var textInput = e.target.closest('.block-card').querySelector('.block-bg-color');
        if (textInput) textInput.value = e.target.value;
    }
    if (e.target.classList.contains('block-bg-opacity')) {
        var valSpan = e.target.parentNode.querySelector('.block-bg-opacity-val');
        if (valSpan) valSpan.textContent = e.target.value + '%';
    }
});
// 渐变方向 select 的 change 事件也需要监听（select 不触发 input）
document.getElementById('blocksContainer').addEventListener('change', function(e) {
    if (e.target.classList.contains('block-grad-dir')) {
        // 无需额外操作，collectBlocksConfig 保存时直接读取
    }
});

// 图片上传
var currentImageKey = '';
function uploadImage(key) {
    currentImageKey = key;
    document.getElementById('imageFileInput').click();
}

// 套用预置背景（把原始 CSS 值写入背景色输入，单色模式下按原值渲染，支持放射渐变）
function applyBgPreset(btnEl, css) {
    var card = btnEl.closest('.block-card');
    var input = card && card.querySelector('.block-bg-color');
    if (input) {
        input.value = css;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }
}

// 区块背景图上传 / 从媒体库选择（事件委托）
var blockBgUploadTarget = null;
document.getElementById('blocksContainer').addEventListener('click', function(e) {
    var upBtn = e.target.closest('.block-bg-upload');
    if (upBtn) {
        blockBgUploadTarget = upBtn.closest('.block-card').querySelector('.block-bg-image');
        currentImageKey = '__block_bg__';
        document.getElementById('imageFileInput').click();
        return;
    }
    var mediaBtn = e.target.closest('.block-bg-media');
    if (mediaBtn) {
        var target = mediaBtn.closest('.block-card').querySelector('.block-bg-image');
        openMediaPicker(function(url) {
            target.value = url;
            target.dispatchEvent(new Event('input', { bubbles: true }));  // 触发预览/配置收集
        });
    }
});

// 评价头像上传（事件委托）
var tmUploadTarget = null;
document.getElementById('testimonialsEditor')?.addEventListener('click', function(e) {
    var btn = e.target.closest('.tm-upload-btn');
    if (btn) {
        tmUploadTarget = btn.closest('.tm-row').querySelector('.tm-avatar');
        currentImageKey = '__tm_avatar__';
        document.getElementById('imageFileInput').click();
    }
});

document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    var formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');

    try {
        var response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        var data = await safeJson(response);

        if (data.code === 0) {
            if (currentImageKey === '__block_bg__' && blockBgUploadTarget) {
                blockBgUploadTarget.value = data.data.url;
                blockBgUploadTarget = null;
            } else if (currentImageKey === '__tm_avatar__' && tmUploadTarget) {
                tmUploadTarget.value = data.data.url;
                tmUploadTarget = null;
            } else {
                document.getElementById('input_' + currentImageKey).value = data.data.url;
                var preview = document.getElementById('preview_' + currentImageKey);
                if (!preview) {
                    preview = document.createElement('img');
                    preview.id = 'preview_' + currentImageKey;
                    preview.className = 'h-16 mt-2 rounded';
                    document.getElementById('input_' + currentImageKey).parentNode.parentNode.appendChild(preview);
                }
                preview.src = data.data.url;
            }
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
    }

    this.value = '';
});

function pickFromMedia(key) {
    openMediaPicker(function(url) {
        document.getElementById('input_' + key).value = url;
        var preview = document.getElementById('preview_' + key);
        if (!preview) {
            preview = document.createElement('img');
            preview.id = 'preview_' + key;
            preview.className = 'h-16 mt-2 rounded';
            document.getElementById('input_' + key).parentNode.parentNode.appendChild(preview);
        }
        preview.src = url;
    });
}

// 表单提交
function saveHomeSettings() {
    collectBlocksConfig();
    collectTestimonials();
    collectCustomBlocks();
    adminSave(document.getElementById('settingForm'), { successMsg: '<?php echo __('admin_saved'); ?>' });
}

// 自定义版块：把轻量编辑器里的字段回填进各自的 section JSON
function collectCustomBlocks() {
    document.querySelectorAll('[data-custom-editor]').forEach(function (box) {
        var raw = box.querySelector('.cb-json');
        if (!raw) return;
        var blocks;
        try { blocks = JSON.parse(raw.value); } catch (e) { return; }
        if (!Array.isArray(blocks)) return;
        box.querySelectorAll('.cb-el').forEach(function (elBox) {
            var si = parseInt(elBox.dataset.sec, 10), ci = parseInt(elBox.dataset.col, 10), ei = parseInt(elBox.dataset.el, 10);
            var sec = blocks[si];
            if (!sec || !sec.columns || !sec.columns[ci] || !sec.columns[ci].elements || !sec.columns[ci].elements[ei]) return;
            var el = sec.columns[ci].elements[ei];
            el.data = el.data || {};
            elBox.querySelectorAll('.cb-field').forEach(function (f) {
                el.data[f.dataset.field] = (f.type === 'checkbox') ? f.checked : f.value;
            });
        });
        // section 级标题/副标题 → 写回 blocks[si].settings
        box.querySelectorAll('.cb-sec').forEach(function (secBox) {
            var si = parseInt(secBox.dataset.sec, 10);
            var sec = blocks[si];
            if (!sec) return;
            sec.settings = sec.settings || {};
            secBox.querySelectorAll('.cb-sec-field').forEach(function (f) {
                sec.settings[f.dataset.secField] = f.value.trim();
            });
        });
        var titleEl = box.querySelector('.cb-title');
        var out = box.querySelector('.cb-output');
        if (out) out.value = JSON.stringify({ title: titleEl ? titleEl.value : '', blocks: blocks });
    });
}

// 从预设库添加自定义版块（服务端新建后刷新）
async function addCustom(preset) {
    var body = new URLSearchParams();
    body.set('action', 'add_custom');
    body.set('preset', preset);
    try { await fetch(location.pathname + location.search, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body }); } catch (e) {}
    location.reload();
}

// 删除自定义版块
async function delCustom(n) {
    if (!confirm(<?php echo json_encode(__('shome_del_block_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
    var body = new URLSearchParams();
    body.set('action', 'del_custom');
    body.set('n', n);
    try { await fetch(location.pathname + location.search, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: body }); } catch (e) {}
    location.reload();
}
document.getElementById('settingForm').addEventListener('submit', function (e) {
    e.preventDefault();
    saveHomeSettings();
});
// 各区块内的「保存」按钮：与底部保存等效（提交整份配置），方便就地保存不必滚到底部
document.getElementById('blocksContainer').addEventListener('click', function (e) {
    if (e.target.closest('.block-save-btn')) { e.preventDefault(); saveHomeSettings(); }
});

// 前台就地编辑深链：?focus=type → 展开、滚动并高亮对应区块卡片
(function () {
    var focus = new URLSearchParams(location.search).get('focus');
    if (!focus) return;
    setTimeout(function () {
        var card = document.querySelector('.block-card[data-type="' + focus.replace(/"/g, '') + '"]');
        if (!card) return;
        // 展开该卡片（点击折叠按钮）
        var toggle = card.querySelector('button[type="button"] .ti-chevron-down');
        if (toggle && !card.classList.contains('yk-expanded')) { toggle.closest('button').click(); }
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        card.style.transition = 'box-shadow .3s';
        card.style.boxShadow = '0 0 0 4px rgba(37,99,235,.35)';
        setTimeout(function () { card.style.boxShadow = ''; }, 2400);
    }, 300);
})();
</script>

<?php // 插件版块的后台 JS 扩展点：插件在此注入采集器(window.homeBlockCollectors[type]=fn) 及选择器脚本 ?>
<?php do_action('home_settings_footer'); ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
