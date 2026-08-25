<?php
/**
 * Blox 全屏可视化编辑器（实验）—— 对标 Bricks 的三栏画布界面。
 *
 * 隔离设计：不套后台 header/footer 外壳，自渲染全屏 shell；画布预览、页面草稿与发布
 * 分别走 Blox 专用端点。旧高级构建器仅复用通用画布响应 helper，不再承担 Blox 请求。
 *
 * 三栏分工与 Bricks 一致：左=元素库↔设置（选中自动切换，libOpen 强制回元素库）、
 * 中=画布、右=结构树常驻（图层式）。
 *
 * 演进路线（blox 分期）：①画布点选(已) → ②画布拖拽排序 → ③元素拖入 → ④文字内联编辑。
 * 当前版本：三栏 + 画布双向点选 + 区块/元素设置（内容/样式页签、设置搜索、只看已修改、
 * 元素重命名）+ 保存。
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

// checkLogin() 不能省：它会 refreshAdminIdentity()，让「停用某人 / 收紧角色」
// 对已登录会话立即生效——只调 requirePermission 用的是登录那一刻的权限快照。
checkLogin();
requirePermission('edit_page');

// 首页、页面与受支持栏目文档均属于基础编辑能力。模板类型在读取记录后再分级：
// section/page 可免费编辑，Header/Footer/Popup 仍需高级授权。
if (!bloxPageEditorEnabled()) {
    header('Location: /admin/page.php');
    exit;
}
$advancedBloxEnabled = bloxAdvancedFeaturesEnabled();
$initialPanel = in_array((string) get('open', ''), ['design', 'templates'], true)
    ? (string) get('open', '')
    : '';

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

/** JS 字面量内联文案：json_encode(__(key))，供 Alpine data() 里 this 不可用的位置使用。 */
$jt = static fn (string $key): string => (string) json_encode(__($key), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

$isHomeBlox = (string) ($_GET['home'] ?? '') === '1';
// 返回目的地（白名单）：从首页编辑器画布跳来编辑页头/页尾时，顶栏返回键
// 指回首页编辑器而不是模板列表页——否则改完页头就迷路（2026-08-22 走查主断点）
$editorBackTo = BloxAreaEditorTarget::isAllowedBack((string) ($_GET['back'] ?? ''))
    ? (string) $_GET['back']
    : '';
$id = getInt('id');
$templateId = getInt('template'); // 模板模式：编辑 blox_templates 草稿（section/page/header/footer/popup）
$isCurrentThemeHeaderEdit = false;
$templateStoredDraft = '';
if ($isHomeBlox) {
    requirePermission('*');
}
$areaCtxOptions = []; // 头尾模板的预览上下文选项（首页/单页/栏目），仅 header/footer 模板非空
$documentIdentity = '';
$homeBannerSeeds = [];
$pageHasPublished = false;
$pageHasUnpublishedChanges = false;
$pageLanguageVersions = [];
$isContactBlox = false;
$isProductBlox = false;
$isContentListBlox = false;
$contactCards = [];
$contactForm = [
    'title' => '',
    'description' => '',
    'success_message' => '',
    'captcha' => false,
    'fields' => [],
];
$contactFormVisual = false;
$contactFormCanEdit = false;
if ($isHomeBlox) {
    foreach (getBanners('home', HomeBloxBlockSchema::MAX_ITEMS) as $banner) {
        if (is_array($banner)) {
            $homeBannerSeeds[] = HomeBannerItemElement::fromLegacy($banner);
        }
    }
}
// 无 id 时默认落到 blox 沙盒页，方便直接进来试
if (!$id && !$isHomeBlox && !$templateId) {
    $sandbox = channelModel()->findWhere(['slug' => 'blox-sandbox', 'type' => 'page']);
    if ($sandbox) {
        header('Location: /admin/blox_editor.php?id=' . (int) $sandbox['id']);
        exit;
    }
    header('Location: /admin/page.php');
    exit;
}

if ($isHomeBlox) {
    $homeDocument = HomeBloxDocument::load();
    $page = [
        'id' => 0,
        'name' => __('home'),
        'slug' => '',
        'description' => __('blox_home_draft_desc'),
    ];
    $initBlocks = json_encode([
        'schema' => $homeDocument['schema'],
        'settings' => $homeDocument['settings'],
        'sections' => $homeDocument['sections'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
    $documentIdentity = 'home';
    $saveEndpoint = '/admin/blox_home_api.php';
    // 首页预览必须走首页上下文，不能借用沙盒页，否则 home-block 只会显示占位卡。
    $previewEndpoint = '/admin/blox_preview.php?home=1';
} elseif ($templateId) {
    $templateRow = bloxTemplateModel()->findForExport($templateId);
    if (!$templateRow) {
        header('Location: /admin/blox_templates.php');
        exit;
    }
    $templateType = (string) $templateRow['type'];
    if (!in_array($templateType, ['section', 'page'], true) && !$advancedBloxEnabled) {
        header('Location: /admin/page.php');
        exit;
    }
    requireBloxTemplateTypePermission($templateType);
    $page = [
        'id' => 0,
        'name' => (string) $templateRow['name'],
        'slug' => '',
        'description' => '',
    ];
    $templateStoredDraft = trim((string) ($templateRow['draft_data'] ?? '')) !== ''
        ? (string) $templateRow['draft_data']
        : '[]';
    $initBlocks = $templateStoredDraft;
    $wantsCurrentThemeHeader = (string) get('current_header', '') === '1';
    if ($wantsCurrentThemeHeader
        && $templateType === 'header'
        && BloxAreaEditorTarget::isThemeFallbackTemplate($templateRow, 'header')) {
        $currentThemeHeader = BloxThemeHeaderDocument::current('theme-header');
        $initBlocks = $currentThemeHeader['json'];
        $isCurrentThemeHeaderEdit = true;
        $page['name'] = __('blox_current_header_title');
        $page['description'] = __('blox_current_header_desc');
    }
    if (!is_array(json_decode($initBlocks, true))) {
        $initBlocks = '[]';
    }
    $saveEndpoint = '/admin/blox_template_api.php';
    $documentIdentity = $isCurrentThemeHeaderEdit
        ? 'current-theme-header:' . (string) config('current_theme', 'default')
        : 'template:' . $templateId;
    if (in_array($templateType, ['header', 'footer'], true)) {
        // 头尾模板：预览 = 可编辑模板段 + 正文只读上下文（Bricks 的「可编辑区+上下文」模型）。
        // r9：上下文可切换（首页/单页/栏目），服务端按所选上下文渲染正文并用前台同一套
        // Resolver 报告命中模板——预览命中即线上命中。
        $previewEndpoint = '/admin/blox_preview.php?home=1&template_area=' . $templateType;
        $areaCtxOptions[] = ['value' => 'home', 'label' => __('blox_ctx_home')];
        foreach (channelModel()->where(['status' => 1]) as $ctxCh) {
            $ctxChType = (string) ($ctxCh['type'] ?? '');
            if ($ctxChType === 'redirect') {
                continue;
            }
            $areaCtxOptions[] = [
                'value' => ($ctxChType === 'page' ? 'page:' : 'channel:') . (int) $ctxCh['id'],
                'label' => (string) $ctxCh['name'],
            ];
        }
    } else {
        // section/page 模板：纯段落预览，借沙盒页通道
        $sandbox = channelModel()->findWhere(['slug' => 'blox-sandbox', 'type' => 'page']);
        $previewEndpoint = '/admin/blox_preview.php?id=' . (int) ($sandbox['id'] ?? 0);
    }
} else {
    $page = channelModel()->find($id);
    $pageType = (string) ($page['type'] ?? '');
    if (!$page || !in_array($pageType, ['page', 'product', 'list'], true)
        || (in_array($pageType, ['product', 'list'], true) && (int) ($page['parent_id'] ?? 0) !== 0)) {
        header('Location: /admin/page.php');
        exit;
    }
    $isProductBlox = $pageType === 'product';
    $isContentListBlox = $pageType === 'list';

    // 发展历程由 timelines 表和专属布局渲染，普通 Blox 正文不会出现在前台。
    // 直接访问旧链接时也收口到真实编辑器，避免保存一份无效内容。
    if (!$isProductBlox && !$isContentListBlox) {
        $primaryEditUrl = pagePrimaryEditUrl($page);
        if (!str_starts_with($primaryEditUrl, '/admin/blox_editor.php?')) {
            header('Location: ' . $primaryEditUrl);
            exit;
        }
    }

    $isContactBlox = !$isProductBlox && !$isContentListBlox && (string) ($page['slug'] ?? '') === 'contact';
    if (!$isProductBlox && !$isContentListBlox && !$isContactBlox && !empty($page['translation_group_id'])) {
        $sourcePage = channelModel()->find((int) $page['translation_group_id']);
        $isContactBlox = (string) ($sourcePage['slug'] ?? '') === 'contact';
    }
    if ($isContactBlox) {
        require_once ROOT_PATH . '/includes/contact_parts.php';
        $pageLang = trim((string) ($page['lang'] ?? ''));
        $contactCards = contactCardsDataForLang($pageLang !== '' ? $pageLang : null);
        foreach ($contactCards as $contactCardIndex => &$contactCard) {
            $contactCard['_key'] = 'contact-card-' . $contactCardIndex;
        }
        unset($contactCard);

        $contactFormCanEdit = hasPermission('form');
        $contactTemplate = formTemplateModel()->findBySlug('contact');
        if ($contactTemplate) {
            $localizedLang = $pageLang !== '' && in_array($pageLang, ['en', 'ja'], true)
                ? $pageLang
                : '';
            $fieldsColumn = $localizedLang !== '' && array_key_exists('fields_' . $localizedLang, $contactTemplate)
                ? 'fields_' . $localizedLang
                : 'fields';
            $successColumn = $localizedLang !== '' && array_key_exists('success_message_' . $localizedLang, $contactTemplate)
                ? 'success_message_' . $localizedLang
                : 'success_message';
            $fieldsRaw = trim((string) ($contactTemplate[$fieldsColumn] ?? ''));
            if ($fieldsRaw === '' && $fieldsColumn !== 'fields') {
                $fieldsRaw = trim((string) ($contactTemplate['fields'] ?? ''));
            }
            if (isJsonFields($fieldsRaw)) {
                $decodedFields = json_decode($fieldsRaw, true);
                $normalizedFields = normalizeContactFormFields($decodedFields);
                $contactFormVisual = is_array($decodedFields) && $decodedFields !== []
                    && count($decodedFields) === count($normalizedFields);
                if ($contactFormVisual) {
                    foreach ($normalizedFields as $fieldIndex => &$field) {
                        $field['_key'] = 'contact-form-field-' . $fieldIndex;
                    }
                    unset($field);
                    $contactForm['fields'] = $normalizedFields;
                }
            }

            $titleKey = contactSettingKey('contact_form_title', $pageLang !== '' ? $pageLang : null);
            $descriptionKey = contactSettingKey('contact_form_desc', $pageLang !== '' ? $pageLang : null);
            $contactForm['title'] = (string) config($titleKey, '');
            $contactForm['description'] = (string) config($descriptionKey, '');
            if ($contactForm['title'] === '' && $titleKey !== 'contact_form_title') {
                $contactForm['title'] = (string) config('contact_form_title', __('contact_form_title'));
            }
            if ($contactForm['description'] === '' && $descriptionKey !== 'contact_form_desc') {
                $contactForm['description'] = (string) config('contact_form_desc', '');
            }
            $contactForm['success_message'] = trim((string) ($contactTemplate[$successColumn] ?? ''));
            if ($contactForm['success_message'] === '' && $successColumn !== 'success_message') {
                $contactForm['success_message'] = (string) ($contactTemplate['success_message'] ?? '');
            }
            $contactForm['captcha'] = !empty($contactTemplate['captcha']);
        }
    }

    $pageDocument = $isContentListBlox
        ? ChannelBloxDocument::load($id)
        : PageBloxDocument::load($id);
    $initBlocks = $pageDocument['document_json'];
    $pageHasPublished = $pageDocument['has_published'];
    $pageHasUnpublishedChanges = $pageDocument['has_unpublished_changes'];
    $saveEndpoint = '/admin/blox_page_api.php?id=' . $id;
    $previewEndpoint = $saveEndpoint;
    $documentIdentity = ($isContentListBlox ? 'content-list:' : ($isProductBlox ? 'product:' : 'page:')) . $id;

    $enabledPageLanguages = enabledLanguages();
    if (count($enabledPageLanguages) > 1) {
        $translationGroupId = (int) ($page['translation_group_id'] ?: $page['id']);
        $translationRows = db()->fetchAll(
            'SELECT id, lang, name, updated_at FROM ' . DB_PREFIX . 'channels'
            . ' WHERE type = ? AND (id = ? OR translation_group_id = ?) ORDER BY id ASC',
            [$pageType, $translationGroupId, $translationGroupId]
        );
        $rowsByLanguage = [];
        foreach ($translationRows as $translationRow) {
            $languageCode = (string) ($translationRow['lang'] ?? '');
            if ($languageCode !== '') {
                $rowsByLanguage[$languageCode] = $translationRow;
            }
        }
        foreach ($enabledPageLanguages as $languageCode => $languageLabel) {
            $translationRow = $rowsByLanguage[$languageCode] ?? null;
            $hasBlox = false;
            if ($translationRow) {
                $translatedPageId = (int) $translationRow['id'];
                $publishedBlocks = contentModel()->queryOne(
                    'SELECT blocks_data FROM ' . contentModel()->tableName()
                    . ' WHERE channel_id = ? AND status = 1 AND deleted_at IS NULL'
                    . ' ORDER BY is_top DESC, id DESC LIMIT 1',
                    [$translatedPageId]
                );
                $hasBlox = trim((string) ($publishedBlocks['blocks_data'] ?? '')) !== ''
                    || (db()->tableExists('blox_page_drafts')
                        && bloxPageDraftModel()->findByPageId($translatedPageId) !== null);
            }
            $pageLanguageVersions[] = [
                'code' => $languageCode,
                'short' => match ($languageCode) {
                    'zh-CN' => 'ZH',
                    'en' => 'EN',
                    'ja' => 'JA',
                    default => strtoupper(substr($languageCode, 0, 3)),
                },
                'label' => $languageLabel,
                'id' => (int) ($translationRow['id'] ?? 0),
                'current' => $languageCode === (string) ($page['lang'] ?? siteLang()),
                'has_blox' => $hasBlox,
            ];
        }
    }
}

// 所有 boot 形态先过统一 schema 门：旧裸数组惰性升级，未来版本直接拒绝，
// 防止旧编辑器把未知字段打开后再以 v1 覆盖保存。
try {
    $bootDoc = $templateId && BloxAreaDocument::isArea($templateType)
        ? BloxAreaDocument::decode($templateType, $initBlocks)
        : ($templateId && $templateType === 'popup'
            ? BloxPopupDocument::decode($initBlocks)
            : BloxDocumentPipeline::decode($initBlocks));
} catch (RuntimeException $documentError) {
    http_response_code(409);
    exit(e($documentError->getMessage()));
}
$revisionDocument = $isCurrentThemeHeaderEdit ? $templateStoredDraft : $initBlocks;
$baseRevision = $templateId && $templateType === 'popup'
    ? BloxPopupDocument::fingerprint($revisionDocument)
    : BloxDocumentPipeline::fingerprint($revisionDocument);
if ($isContactBlox) {
    $bootDoc['sections'] = completeContactSeedSections($bootDoc['sections']);
}
$recoveryKey = 'yikai:blox-recovery:v1:' . (int) ($_SESSION['admin_id'] ?? 0) . ':' . $documentIdentity;
$docSettings = $bootDoc['settings'];
$customHeaderEnabled = (string) config('blox_custom_header_enabled', '1') === '1';
$replaceThemeAreaOnPublish = '';
if ($templateId && isset($templateRow, $templateType)
    && in_array($templateType, ['header', 'footer'], true)
    && BloxAreaEditorTarget::isThemeFallbackTemplate($templateRow, $templateType)) {
    $areaEnabledKey = $templateType === 'header'
        ? 'blox_custom_header_enabled'
        : 'blox_custom_footer_enabled';
    if ($isCurrentThemeHeaderEdit || (string) config($areaEnabledKey, '1') !== '1') {
        $replaceThemeAreaOnPublish = $templateType;
    }
}
$homeHeaderSettingsUrl = '/admin/blox_templates.php?type=header';
if ($isHomeBlox && $advancedBloxEnabled && db()->tableExists('blox_templates')) {
    $homeHeaderSettingsUrl = BloxAreaEditorTarget::url('header', [
        'home' => true,
        'channel_id' => 0,
        'page_id' => 0,
    ], 'home');   // 从首页编辑器出发——页头编辑器顶栏给「返回首页编辑」
}
$initBlocks = json_encode(
    $bootDoc['sections'],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT
) ?: '[]';

/**
 * 插入时的占位内容。
 *
 * 注册表的 defaults 把主内容字段留空（heading.text / text.html / quote.text 都是 ""），
 * 高级构建器照搬即可——它把元素显示成可编辑的卡片，空着也看得见。但 blox 的画布是
 * **渲染后的预览**：插入一个空标题，画布上什么都不会出现，像是没插进去。
 *
 * 所以这里给主内容字段种占位文本，和 Bricks / Elementor 的行为一致——插入即可见，
 * 再去改文字。只覆盖列出的字段，其余仍用注册表的 defaults。
 *
 * ⚠ 这是 blox 与高级构建器**有意**的行为差异（那边插入仍为空）。两者写进同一份
 *   blocks_data，占位文本只是普通内容、不影响渲染一致性。若日后统一，改这里即可。
 */
$bloxPlaceholders = [
    'heading' => ['text' => __('blox_seed_heading')],
    'text'    => ['html' => '<p>' . __('blox_seed_text') . '</p>'],
    'quote'   => ['text' => __('blox_seed_quote'), 'author' => ''],
    'alert'   => ['text' => __('blox_seed_alert')],
    'icon-box' => ['title' => __('blox_field_title_short'), 'text' => __('blox_seed_desc')],
    'cta' => [
        'title' => __('blox_seed_cta_title'),
        'text' => __('blox_seed_cta_text'),
        'btn_text' => __('nav_contact'),
        'btn_url' => '/contact.html',
    ],
    'card' => [
        'title' => __('blox_seed_card_title'),
        'text' => __('blox_seed_card_text'),
        'image' => '',
        'link' => '',
    ],
    'home-block' => ['block_type' => 'banner', 'label' => __('blox_home_block_label'), 'enabled' => true, 'items_mode' => 'inherit', 'children' => []],
    'home-banner-item' => ['title' => __('blox_home_banner_item')],
];

$registryContext = $isHomeBlox
    ? 'home'
    : ($isContentListBlox ? 'content-list' : ($isProductBlox ? 'product' : ($isContactBlox ? 'contact' : 'page')));
$registryMeta = BuilderRegistry::meta($registryContext);
// code 元素 = 前台任意 HTML/脚本输出，独立 blox_code 权限（默认仅超管）。
// 这里只是藏 UI；真正的闸在 BloxElementPolicy（保存管线按会话能力拒绝提交）。
if (!hasPermission('blox_code') && isset($registryMeta['code'])) {
    $registryMeta['code']['paletteVisible'] = false;
}
$advancedQueryLoopEnabled = BloxQueryLoopPolicy::advancedEnabled();
$displayConditionChannels = [];
foreach (channelModel()->getFlatList() as $conditionChannel) {
    $conditionType = (string) ($conditionChannel['type'] ?? '');
    if (empty($conditionChannel['status']) || in_array($conditionType, ['page', 'link', 'redirect'], true)) {
        continue;
    }
    $conditionId = (int) ($conditionChannel['id'] ?? 0);
    if ($conditionId < 1) {
        continue;
    }
    $displayConditionChannels[] = [
        'value' => $conditionId,
        'label' => str_repeat('— ', max(0, min(4, (int) ($conditionChannel['_level'] ?? 0))))
            . ((string) ($conditionChannel['name'] ?? '') ?: ('#' . $conditionId)),
    ];
}
$contactManageActions = [
    'contact_cards' => ['url' => '/admin/setting_contact.php', 'label' => __('page_contact_manage_cards'), 'icon' => 'address-book'],
    'contact_form' => ['url' => '/admin/form_design.php', 'label' => __('page_contact_manage_form'), 'icon' => 'forms'],
    'contact_map' => ['url' => '/admin/setting_contact.php#map', 'label' => __('page_contact_manage_map'), 'icon' => 'map-pin'],
];
$contactCardIconOptions = [
    ['value' => '', 'label' => __('none'), 'icon' => 'ban'],
    ['value' => 'phone', 'label' => __('contact_icon_phone'), 'icon' => 'phone'],
    ['value' => 'email', 'label' => __('contact_icon_email'), 'icon' => 'mail'],
    ['value' => 'location', 'label' => __('contact_icon_location'), 'icon' => 'map-pin'],
    ['value' => 'clock', 'label' => __('contact_icon_clock'), 'icon' => 'clock'],
    ['value' => 'fax', 'label' => __('contact_icon_fax'), 'icon' => 'printer'],
    ['value' => 'wechat', 'label' => __('contact_icon_wechat'), 'icon' => 'brand-wechat'],
    ['value' => 'building', 'label' => __('contact_icon_building'), 'icon' => 'building'],
    ['value' => 'globe', 'label' => __('contact_icon_globe'), 'icon' => 'world'],
    ['value' => 'qq', 'label' => 'QQ', 'icon' => 'brand-qq'],
];
$contactFormFieldTypes = [
    ['value' => 'text', 'label' => __('fd_tag_text')],
    ['value' => 'email', 'label' => __('fd_tag_email')],
    ['value' => 'tel', 'label' => __('fd_tag_tel')],
    ['value' => 'textarea', 'label' => __('fd_tag_textarea')],
    ['value' => 'number', 'label' => __('fd_tag_number')],
    ['value' => 'date', 'label' => __('fd_tag_date')],
    ['value' => 'url', 'label' => __('blox_contact_form_type_url')],
];

// 元素库（对齐 Bricks：布局组里 区块/容器 并排为瓦片）。__section 是合成项，
// 点击走 addSection(1)——它不是注册表元素，只是「插区块」在库里的入口。
$elementLib = [[
    'type'     => '__section',
    'label'    => __('blox_section_label'),
    'category' => 'layout',
    'icon'     => 'crop-landscape',
    'defaults' => [],
    'paletteVisible' => true,
    'deprecated' => false,
]];
foreach ($registryMeta as $type => $m) {
    $defaults = $m['defaults'];
    foreach ($bloxPlaceholders[$type] ?? [] as $k => $v) {
        $defaults[$k] = $v;
    }
    $elementLib[] = [
        'type'     => $type,
        'label'    => $m['label'],
        'category' => $m['category'],
        'icon'     => $m['icon'],
        'defaults' => $defaults,
        'paletteVisible' => $m['paletteVisible'],
        'deprecated' => $m['deprecated'],
    ];
}

/**
 * 元素 schema（全量注册元素，不受插入白名单限制）。
 *
 * 设置面板要能编辑**页面里已有的任意元素**——同一份 blocks_data 也会被高级构建器
 * 编辑，页面里完全可能存在 image / card / 轮播这些暂不开放插入的类型。
 * 只带白名单的话，选中它们设置面板会一片空白，像是坏了。
 */
$elementSchemas = [];
foreach ($registryMeta as $type => $m) {
    $controls = $advancedQueryLoopEnabled
        ? array_values($m['controls'])
        : array_values(array_filter(
            $m['controls'],
            static fn(array $control): bool => empty($control['advanced'])
        ));
    $allowedChildren = !$advancedQueryLoopEnabled && $type === 'list-dynamic'
        ? []
        : $m['allowedChildren'];
    $elementSchemas[$type] = [
        'label'    => $m['label'],
        'icon'     => $m['icon'],
        'controls' => $controls,
        'dynamic'  => $m['dynamic'],
        // 注册表原始默认值：设置面板「只看已修改」按它对比（注意元素库插入时
        // 种了占位文本，占位字段会被视为已修改——它确实改了）
        'defaults' => $m['defaults'],
        // 容器标记：结构树嵌套显示、插入目标判定用
        'container' => $m['container'],
        'paletteVisible' => $m['paletteVisible'],
        'allowedChildren' => $allowedChildren,
        'childRules' => $m['childRules'],
        'genericChild' => $m['genericChild'],
        'supportsBoxStyles' => $m['supportsBoxStyles'],
        'scripts' => $m['scripts'],
        'styles' => $m['styles'],
        'missing' => $m['missing'],
        'plugin' => $m['plugin'],
        'treeLabelField' => $m['treeLabelField'],
        'deprecated' => $m['deprecated'],
    ];
}
// 分类显示名：category 是机器值，界面要中文
$catLabels = ['basic' => __('blox_cat_basic'), 'media' => __('blox_cat_media'), 'layout' => __('blox_layout'), 'advanced' => __('blox_cat_advanced'), 'dynamic' => __('blox_cat_dynamic')];
$homeEditorBlueprints = $isHomeBlox ? HomeBloxBlockSchema::editorBlueprints() : [];
$homeFieldSeeds = $isHomeBlox ? [
    'stats' => ['stats_items' => HomeBloxBlockSchema::statsSeedItems()],
    'advantage' => ['advantage_items' => HomeBloxBlockSchema::advantageSeedItems()],
] : [];
if ($isHomeBlox) {
    foreach (array_keys(HomeBloxBlockSchema::sourceOptions()) as $homeSourceType) {
        if (!str_starts_with($homeSourceType, 'custom:')) {
            continue;
        }
        $customData = json_decode(configJsonLang('home_custom_' . substr($homeSourceType, 7)), true);
        $customSettings = is_array($customData['blocks'][0]['settings'] ?? null)
            ? $customData['blocks'][0]['settings'] : [];
        $customContract = HomeBloxBlockSchema::customEditorContract(
            $homeSourceType,
            is_array($customData['blocks'] ?? null) ? $customData['blocks'] : []
        );
        $homeFieldSeeds[$homeSourceType] = array_merge([
            'custom_title' => (string) ($customSettings['title'] ?? ''),
            'custom_subtitle' => (string) ($customSettings['subtitle'] ?? ''),
        ], $customContract['seeds']);
    }
}

/**
 * 图标名全集从本地字体 CSS 提取。旧值无前缀时仍属于 Tabler；Bootstrap 值保存为 bi:<name>，
 * 避免同名图标冲突。选择器最多只渲染 96 个匹配结果。
 */
$tablerIcons = [];
if (preg_match_all('/\.ti-([a-z0-9-]+):before/', (string) @file_get_contents(ROOT_PATH . '/assets/tabler/tabler-icons.min.css'), $_tiM)) {
    $tablerIcons = array_values(array_unique($_tiM[1]));
}
$bootstrapIcons = [];
if (preg_match_all('/\.bi-([a-z0-9-]+)::before/', (string) @file_get_contents(ROOT_PATH . '/assets/bootstrap-icons/bootstrap-icons.min.css'), $_biM)) {
    $bootstrapIcons = array_map(static fn (string $name): string => 'bi:' . $name, array_values(array_unique($_biM[1])));
}
$bloxDesignSystem = BloxDesignSystem::snapshot();
$canManageBloxDesign = hasPermission('*');
?>
<!doctype html>
<html lang="<?php echo htmlspecialchars(siteLang()); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?php echo e(__('blox_editor_title') . ' · ' . ($isHomeBlox ? __('home') : $page['name'])); ?></title>
    <?php // 后台自带 head 的页面也要有标签页图标：客户设过用客户的，否则回落随包的品牌图标。
          // 与 admin/includes/header.php 同一口径——不这么做，登录页和编辑器在浏览器标签里就是空白图标。 ?>
    <link rel="icon" href="<?php echo e((function_exists('siteFaviconUrl') ? siteFaviconUrl() : '') ?: '/assets/img/admin-favicon.ico'); ?>">
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">
    <link rel="stylesheet" href="/assets/bootstrap-icons/bootstrap-icons.min.css">
    <script defer src="/assets/alpinejs/collapse.min.js"></script>
    <script defer src="/assets/alpinejs/alpine.min.js"></script>
    <script src="/assets/sortable/Sortable.min.js"></script>
    <script src="/assets/js/blox-template-library.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-template-library.js') ?>"></script>
    <script src="/assets/js/blox-media-client.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-media-client.js') ?>"></script>
    <script src="/assets/js/blox-dialog-focus.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-dialog-focus.js') ?>"></script>
    <script src="/assets/js/blox-preview-client.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-preview-client.js') ?>"></script>
    <script src="/assets/js/blox-canvas-bridge.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-canvas-bridge.js') ?>"></script>
    <script src="/assets/js/blox-history-store.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-history-store.js') ?>"></script>
    <script src="/assets/js/blox-draft-recovery.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-draft-recovery.js') ?>"></script>
    <script src="/assets/js/blox-command-runner.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-command-runner.js') ?>"></script>
    <script src="/assets/js/blox-control-rules.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-control-rules.js') ?>"></script>
    <script src="/assets/js/blox-responsive.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-responsive.js') ?>"></script>
    <script src="/assets/js/blox-icon-utils.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-icon-utils.js') ?>"></script>
    <script src="/assets/js/blox-home-field-store.js?v=<?= (int) filemtime(ROOT_PATH . '/assets/js/blox-home-field-store.js') ?>"></script>
    <?php // 系统富文本编辑器（richtext 控件的「可视化编辑」弹窗用；按需 init） ?>
    <script src="/assets/tinymce/tinymce.min.js"></script>
    <style>
        html, body { height: 100%; margin: 0; overflow: hidden; }
        [x-cloak] { display: none !important; }
        .blox-scroll { scrollbar-width: thin; }
        .blox-sort-ghost { opacity: .45; border: 1px dashed #3b82f6; background: #eff6ff; }
        .blox-scroll::-webkit-scrollbar { width: 6px; }
        .blox-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        /* 盒模型四边输入框（blox 不加载 admin.css，样式必须内联在此）。
           Tailwind preflight 清了 input 边框，这里补回；边框色由行内 border-* 类给 */
        .yk-box-in {
            width: 2.5rem; flex-shrink: 0; background: #fff;
            border-width: 1px; border-style: solid; border-radius: 0.25rem;
            padding: 2px 1px; font-family: ui-monospace, monospace;
            font-size: 10px; text-align: center; color: #374151;
        }
        .yk-box-in:focus { outline: none; border-color: #3b82f6 !important; }
        .yk-box-in::placeholder { color: #d1d5db; }
        .blox-editor-header :is(a, button, summary):focus-visible,
        .blox-mobile-toolbar button:focus-visible,
        .blox-mobile-actions-menu :is(a, button):focus-visible {
            outline: 2px solid #60a5fa;
            outline-offset: 2px;
        }
        .blox-mobile-toolbar,
        .blox-mobile-backdrop,
        .blox-mobile-actions { display: none; }
        .blox-mobile-actions-menu {
            position: fixed;
            top: 3rem;
            right: .5rem;
            z-index: 70;
            display: flex;
            width: 13rem;
            flex-direction: column;
            gap: .25rem;
            padding: .5rem;
            color: #374151;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: .5rem;
            box-shadow: 0 12px 32px rgba(15, 23, 42, .2);
        }
        .blox-mobile-actions-menu > button,
        .blox-mobile-actions-menu > a {
            display: flex;
            width: 100%;
            min-height: 2.75rem;
            align-items: center;
            gap: .5rem;
            padding: .375rem .625rem;
            border-radius: .375rem;
            font-size: .75rem;
            text-align: left;
        }
        .blox-mobile-actions-menu > button:hover,
        .blox-mobile-actions-menu > a:hover { background: #f3f4f6; }
        .blox-mobile-actions-menu > button:disabled { opacity: .4; cursor: not-allowed; }
        @media (max-width: 1439px) {
            .blox-mobile-panel { display: none !important; }
            .blox-mobile-panel.is-open {
                display: flex !important;
                position: fixed;
                top: 3.5rem;
                right: .5rem;
                bottom: calc(3.5rem + env(safe-area-inset-bottom));
                left: .5rem;
                width: auto !important;
                z-index: 50;
                box-shadow: 0 12px 32px rgba(15, 23, 42, .2);
            }
            .blox-mobile-backdrop {
                display: block;
                position: fixed;
                top: 3.5rem;
                right: 0;
                bottom: calc(3.5rem + env(safe-area-inset-bottom));
                left: 0;
                z-index: 40;
                background: rgba(15, 23, 42, .38);
            }
            .blox-mobile-toolbar {
                display: grid;
                position: fixed;
                right: 0;
                bottom: 0;
                left: 0;
                min-height: 3.5rem;
                z-index: 60;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                background: #fff;
                border-top: 1px solid #e5e7eb;
                box-shadow: 0 -4px 16px rgba(15, 23, 42, .08);
                padding-bottom: env(safe-area-inset-bottom);
            }
            .blox-mobile-toolbar button {
                display: inline-flex;
                min-width: 0;
                align-items: center;
                justify-content: center;
                gap: .25rem;
                color: #6b7280;
                font-size: .7rem;
            }
            .blox-mobile-toolbar button.is-active {
                color: #2563eb;
                background: #eff6ff;
            }
            .blox-mobile-toolbar button:disabled {
                color: #d1d5db;
                cursor: not-allowed;
            }
            main { padding: .75rem .5rem calc(4.25rem + env(safe-area-inset-bottom)) !important; }
        }
        @media (max-width: 1023px) {
            .blox-editor-header { padding-right: .5rem; padding-left: .5rem; gap: .25rem; }
            .blox-header-brand { gap: 0; }
            .blox-header-brand-copy,
            .blox-header-page,
            .blox-header-legacy,
            .blox-header-languages,
            .blox-header-actions { display: none !important; }
            .blox-mobile-actions { display: block; }
        }
        @media (max-width: 639px) {
            .blox-mobile-actions-menu {
                top: 3.5rem;
                right: max(.5rem, env(safe-area-inset-right));
                left: max(.5rem, env(safe-area-inset-left));
                width: auto;
            }
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800" x-data="bloxEditor()" x-init="init()" x-cloak
      data-blox-advanced="<?php echo $advancedBloxEnabled ? '1' : '0'; ?>"
      data-blox-recovery-key="<?= e($recoveryKey) ?>"
      data-blox-base-revision="<?= e($baseRevision) ?>">

    <?php require __DIR__ . '/blox_editor/partials/header.php'; ?>
    <?php require __DIR__ . '/blox_editor/partials/workspace.php'; ?>
    <?php require __DIR__ . '/blox_editor/partials/overlays.php'; ?>

    <script>
    function bloxEditor() {
        return {
            sections: <?php echo $initBlocks; ?>,
            selectedSi: -1,
            previewDevice: "desktop",
            headerPreviewState: "normal",
            canvasViewportTick: 0,
            previewLoading: false,
            saving: false,
            cacheClearing: false,
            dirty: false,
            _ready: false,
            toastMsg: "",
            _previewClient: null,
            _canvasBridge: null,
            _canvasResizeObserver: null,
            _historyStore: null,
            _historyApplying: false,
            _commandRunner: null,
            _insertAt: null,   // 画布插入轨道的定点覆盖位（任何文档变化后由 watcher 失效）
            _savedSnapshot: "",
            _savedDocumentSnapshot: "",
            _draftRecovery: null,
            recoveryOpen: false,
            recoveryDraft: null,
            conflictOpen: false,
            baseRevision: <?php echo json_encode($baseRevision, JSON_UNESCAPED_SLASHES); ?>,
            recoveryKey: <?php echo json_encode($recoveryKey, JSON_UNESCAPED_SLASHES); ?>,
            clipboard: null,
            _tt: null,
            revisionOpen: false,
            revisionLoading: false,
            revisionRestoring: false,
            revisions: [],
            activeRev: null,
            revisionPreview: "",
            _sortables: [],
            _treeSortableTimer: null,
            csrf: "<?php echo csrfToken(); ?>",
            endpoint: "<?php echo $saveEndpoint; ?>",
            previewEndpoint: "<?php echo $previewEndpoint; ?>",
            previewContext: "home",
            ctxHit: null,
            advancedMode: <?php echo $advancedBloxEnabled ? 'true' : 'false'; ?>,
            headerTemplateMode: <?php echo $templateId && $templateType === 'header' ? 'true' : 'false'; ?>,
            currentThemeHeaderMode: <?php echo $isCurrentThemeHeaderEdit ? 'true' : 'false'; ?>,
            initialPanel: <?php echo json_encode($initialPanel); ?>,
            canManageDesign: <?php echo $canManageBloxDesign ? 'true' : 'false'; ?>,
            designSystem: <?php echo json_encode($bloxDesignSystem, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            designUsage: { tokens: {}, styles: {} },
            designOpen: false,
            designTab: "colors",
            designBusy: false,
            newToken: { name: "", category: "brand", value: "#3b82f6" },
            newStyle: { name: "", category: "general", color: "", background: "", border_color: "", radius: "none" },
            designText: <?php echo json_encode([
                'title' => __('blox_design_system'),
                'colors' => __('blox_design_colors'),
                'styles' => __('blox_design_styles'),
                'custom' => __('blox_design_custom'),
                'noStyle' => __('blox_design_no_style'),
                'archived' => __('blox_design_archived'),
                'saved' => __('blox_design_saved'),
                'failed' => __('blox_save_failed'),
                'usedCount' => __('blox_design_used_count'),
                'archiveUsed' => __('blox_design_archive_used_confirm'),
                'usageDraft' => __('blox_design_usage_draft'),
                'usagePublished' => __('blox_design_usage_published'),
                'usageCurrent' => __('blox_design_usage_current'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            conditionChannels: <?php echo json_encode($displayConditionChannels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            conditionText: <?php echo json_encode([
                'empty' => __('blox_display_conditions_empty'),
                'hint' => __('blox_display_conditions_hint'),
                'group' => __('blox_display_conditions_group'),
                'and' => __('blox_display_conditions_and'),
                'or' => __('blox_display_conditions_or'),
                'addGroup' => __('blox_display_conditions_add_group'),
                'addRule' => __('blox_display_conditions_add_rule'),
                'login' => __('blox_display_condition_login'),
                'date' => __('blox_display_condition_date'),
                'channel' => __('blox_display_condition_channel'),
                'url' => __('blox_display_condition_url'),
                'is' => __('blox_display_operator_is'),
                'isNot' => __('blox_display_operator_is_not'),
                'before' => __('blox_display_operator_before'),
                'on' => __('blox_display_operator_on'),
                'after' => __('blox_display_operator_after'),
                'equals' => __('blox_display_operator_equals'),
                'notEquals' => __('blox_display_operator_not_equals'),
                'contains' => __('blox_display_operator_contains'),
                'notContains' => __('blox_display_operator_not_contains'),
                'startsWith' => __('blox_display_operator_starts_with'),
                'loggedIn' => __('blox_display_value_logged_in'),
                'loggedOut' => __('blox_display_value_logged_out'),
                'selectChannel' => __('blox_display_select_channel'),
                'urlPlaceholder' => __('blox_display_url_placeholder'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            docSettings: <?php echo json_encode((object) $docSettings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            homeMode: <?php echo $isHomeBlox ? 'true' : 'false'; ?>,
            homePublished: <?php echo $isHomeBlox && HomeBloxDocument::isActive() && HomeBloxDocument::hasPublished() ? 'true' : 'false'; ?>,
            homeActionBusy: false,
            pageMode: <?php echo !$isHomeBlox && !$templateId ? 'true' : 'false'; ?>,
            pagePublished: <?php echo $pageHasPublished ? 'true' : 'false'; ?>,
            pageHasUnpublishedChanges: <?php echo $pageHasUnpublishedChanges ? 'true' : 'false'; ?>,
            pageActionBusy: false,
            contactManage: <?php echo json_encode($contactManageActions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            contactEndpoint: "/admin/blox_contact_api.php?id=<?php echo (int) $id; ?>",
            contactCards: <?php echo json_encode($contactCards, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            contactCardIconOptions: <?php echo json_encode($contactCardIconOptions, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            contactCardsChanged: false,
            contactCardsSaving: false,
            contactCardsText: <?php echo json_encode([
                'limit' => __('blox_contact_cards_limit'),
                'incomplete' => __('blox_contact_cards_incomplete'),
                'saved' => __('blox_contact_cards_saved'),
                'failed' => __('blox_save_failed'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            contactForm: <?php echo json_encode($contactForm, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            contactFormVisual: <?php echo $contactFormVisual ? 'true' : 'false'; ?>,
            contactFormCanEdit: <?php echo $contactFormCanEdit ? 'true' : 'false'; ?>,
            contactFormFieldTypes: <?php echo json_encode($contactFormFieldTypes, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            contactFormChanged: false,
            contactFormSaving: false,
            contactFormText: <?php echo json_encode([
                'limit' => __('blox_contact_form_limit'),
                'invalid' => __('blox_contact_form_invalid'),
                'needEnabled' => __('blox_contact_form_need_enabled'),
                'saved' => __('blox_contact_form_saved'),
                'failed' => __('blox_save_failed'),
                'newField' => __('blox_contact_form_new_field'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            homeEditorBlueprints: <?php echo json_encode($homeEditorBlueprints, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            homeFieldSeeds: <?php echo json_encode($homeFieldSeeds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            homeText: <?php echo json_encode([
                'publishConfirm' => __('blox_publish_confirm'),
                'rollbackConfirm' => __('blox_rollback_confirm'),
                'publishRequiresSaved' => __('blox_publish_requires_saved'),
                'publishDone' => __('blox_publish_done'),
                'rollbackDone' => __('blox_rollback_done'),
                'actionFailed' => __('blox_action_failed'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            pageText: <?php echo json_encode([
                'publishConfirm' => __('blox_page_publish_confirm'),
                'publishDone' => __('blox_page_publish_done'),
                'actionFailed' => __('blox_action_failed'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            orgText: <?php echo json_encode([
                'name' => __('blox_org_node_name'),
                'title' => __('blox_org_node_title'),
                'addChild' => __('blox_org_add_child'),
                'addSibling' => __('blox_org_add_sibling'),
                'deleteNode' => __('blox_org_delete_node'),
                'deleteConfirm' => __('blox_org_delete_confirm'),
                'root' => __('blox_org_root'),
                'level' => __('blox_org_level'),
                'newName' => __('blox_org_new_name'),
                'newTitle' => __('blox_org_new_title'),
                'limit' => __('blox_org_limit'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            homeDynamicText: <?php echo json_encode([
                'liveData' => __('blox_home_live_data'),
                'disabled' => __('blox_home_disabled'),
                'inherit' => __('blox_home_inherit'),
                'limit' => __('blox_home_limit'),
                'sort' => __('blox_home_sort'),
                'columns' => __('blox_home_columns'),
                'items' => __('blox_home_banner_items_unit'),
                'adoptItems' => __('blox_home_banner_adopt'),
                'createItem' => __('blox_home_banner_create'),
                'restoreConfirm' => __('blox_home_banner_restore_confirm'),
                'customItems' => __('blox_home_banner_custom'),
                'newItemTitle' => __('blox_home_banner_new_title'),
                'replaceImage' => __('blox_home_banner_replace_image'),
                'slide' => __('blox_home_banner_slide'),
                'noImage' => __('blox_home_banner_no_image'),
                'imageUrl' => __('blox_home_banner_image_url_field'),
                'aboutTwoColumns' => __('blox_home_about_two_columns'),
                'aboutTextColumn' => __('blox_home_about_text_column'),
                'aboutImageColumn' => __('blox_home_about_image_column'),
                'aboutRatio' => __('blox_home_about_ratio'),
                'swapColumns' => __('blox_columns_swap'),
                'iconLibrary' => __('blox_icon_library'),
                'iconLibraryClose' => __('blox_icon_library_close'),
                'iconSearch' => __('blox_icon_search_placeholder'),
                'iconRecommended' => __('blox_icon_recommended'),
                'faqQuestion' => __('blox_home_faq_question'),
                'faqAnswer' => __('blox_home_faq_answer'),
                'faqAdd' => __('blox_home_faq_add'),
                'faqDelete' => __('blox_home_faq_delete'),
                'faqRestore' => __('blox_home_faq_restore'),
                'faqRestoreConfirm' => __('blox_home_faq_restore_confirm'),
                'faqNewQuestion' => __('blox_home_faq_new_question'),
                'faqNewAnswer' => __('blox_home_faq_new_answer'),
                'faqLimit' => __('blox_home_faq_limit'),
                'planAdd' => __('blox_home_plan_add'),
                'planDuplicate' => __('blox_home_plan_duplicate'),
                'planDelete' => __('blox_home_plan_delete'),
                'planRestore' => __('blox_home_plan_restore'),
                'planRestoreConfirm' => __('blox_home_plan_restore_confirm'),
                'planLimit' => __('blox_home_plan_limit'),
                'planMinimum' => __('blox_home_plan_minimum'),
                'customCardBackground' => __('blox_home_custom_card_background'),
                'customTitle' => __('blox_field_title_short'),
                'customBody' => __('blox_ctl_body'),
                'customButtonText' => __('blox_ctl_btn_text'),
                'customLinkUrl' => __('blox_ctl_link_url'),
                'customColumn' => __('blox_col_word'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            historyText: <?php echo json_encode([
                'undoDone' => __('blox_undo_done'),
                'redoDone' => __('blox_redo_done'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            mobileText: <?php echo json_encode([
                'library' => __('blox_mobile_library'),
                'canvas' => __('blox_mobile_canvas'),
                'structure' => __('blox_mobile_structure'),
                'settings' => __('blox_mobile_settings'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            ctxText: <?php echo json_encode([
                'subtitle' => __('blox_field_subtitle'),
                'sectionTitle' => __('blox_field_section_title'),
                'editPrefix' => __('blox_edit_prefix'),
                'editText' => __('blox_edit_text'),
                'editTitle' => __('blox_prompt_edit_title'),
                'editButtonText' => __('blox_prompt_edit_button_text'),
                'elementSelected' => __('blox_toast_element_selected'),
                'settings' => __('blox_ctx_settings'),
                'saveAsTemplate' => __('blox_ctx_save_as_template'),
                'addSection' => __('blox_ctx_add_section'),
                'addSectionAfter' => __('blox_ctx_add_section_after'),
                'addLayout2' => __('blox_ctx_add_layout_2'),
                'addLayout3' => __('blox_ctx_add_layout_3'),
                'addElement' => __('blox_ctx_add_element'),
                'addChild' => __('blox_ctx_add_child'),
                'clearText' => __('blox_ctx_clear_text'),
                'moveUp' => __('blox_ctx_move_up'),
                'moveDown' => __('blox_ctx_move_down'),
                'duplicateSection' => __('blox_ctx_duplicate_section'),
                'deleteSection' => __('blox_ctx_delete_section'),
                'deleteEmptySection' => __('blox_ctx_delete_empty_section'),
                'duplicateItem' => __('blox_ctx_duplicate_item'),
                'deleteItem' => __('blox_ctx_delete_item'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            uiText: <?php echo json_encode([
                'mediaFailed' => __('blox_media_failed'),
                'uploadedSelected' => __('blox_uploaded_selected'),
                'uploadedOptimized' => __('blox_uploaded_optimized'),
                'uploadFailed' => __('blox_upload_failed'),
                'confirmDeleteContainer' => __('blox_confirm_delete_container'),
                'confirmDeleteSection' => __('blox_confirm_delete_section'),
                'previewFailed' => __('blox_preview_failed'),
                'layoutApplied' => __('blox_layout_applied'),
                'createSectionFailed' => __('blox_create_section_failed'),
                'historyFailed' => __('blox_history_failed'),
                'restoredReloading' => __('blox_restored_reloading'),
                'restoreFailed' => __('blox_restore_failed'),
                'saved' => __('blox_saved'),
                'saveFailed' => __('blox_save_failed'),
                'saveFailedMsg' => __('blox_save_failed_msg'),
                'revisionLoading' => __('loading'),
                'revisionPreviewFailed' => __('blox_revision_preview_failed'),
                'iconHintDefault' => __('blox_icon_hint_default'),
                'iconHintNone' => __('blox_icon_hint_none'),
                'iconHintMany' => __('blox_icon_hint_many'),
                'iconHintCount' => __('blox_icon_hint_count'),
                'mediaLoadFailed' => __('blox_media_load_failed'),
                'uploadFailedShort' => __('blox_upload_failed_short'),
                'settingsWord' => __('blox_ctx_settings'),
                'settingsOf' => __('blox_settings_of'),
                'sectionWord' => __('blox_section_word'),
                'colWord' => __('blox_col_word'),
                'containerWord' => __('blox_tree_container'),
                'elementWord' => __('blox_element_label'),
                'ofSection' => __('blox_of_section'),
                'layoutInserted' => __('blox_layout_inserted'),
                'nColInserted' => __('blox_n_col_inserted'),
                'insertAtEnd' => __('blox_insert_at_end'),
                'insertAfterSection' => __('blox_insert_after_section'),
                'insertedContainer' => __('blox_inserted_container'),
                'inserted' => __('blox_inserted'),
                'insertedCol' => __('blox_inserted_col'),
                'insertedBefore' => __('blox_inserted_before'),
                'insertedAfter' => __('blox_inserted_after'),
                'historyLoadFailed' => __('blox_history_load_failed'),
                'restoreConfirmDirty' => __('blox_restore_confirm_dirty'),
                'restoreConfirm' => __('blox_restore_confirm'),
                'tplPublishConfirm' => __('blox_tpl_publish_confirm'),
                'tplPublishReplaceConfirm' => __('blox_tpl_publish_replace_confirm'),
                'tplPublished' => __('blox_tpl_published_toast'),
                'tplPublishedAndUsed' => __('blox_tpl_published_and_used_toast'),
                'saveConflict' => __('blox_save_conflict'),
                'noNestedContainer' => __('blox_no_nested_container'),
                'dropRestricted' => __('blox_drop_restricted'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            recoveryText: <?php echo json_encode([
                'title' => __('blox_recovery_title'),
                'desc' => __('blox_recovery_desc'),
                'restore' => __('blox_recovery_restore'),
                'discard' => __('blox_recovery_discard'),
                'conflictTitle' => __('blox_conflict_title'),
                'conflictDesc' => __('blox_conflict_desc'),
                'reload' => __('blox_conflict_reload'),
                'copy' => __('blox_conflict_copy'),
                'continueEditing' => __('blox_conflict_continue'),
                'copied' => __('blox_conflict_copied'),
                'copyFailed' => __('blox_conflict_copy_failed'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            templateText: <?php echo json_encode([
                'title' => __('blox_template_library'),
                'saveAsPrompt' => __('blox_tpl_save_as_prompt'),
                'saveAsNameRequired' => __('blox_tpl_name_required'),
                'saveAsDone' => __('blox_tpl_save_as_done'),
                'close' => __('blox_template_close'),
                'search' => __('blox_template_search'),
                'all' => __('blox_template_filter_all'),
                'category' => __('blox_template_category'),
                'categoryAll' => __('blox_template_category_all'),
                'categoryLanding' => __('blox_template_category_landing'),
                'categoryMarketing' => __('blox_template_category_marketing'),
                'categoryContent' => __('blox_template_category_content'),
                'categoryPage' => __('blox_template_category_page'),
                'categoryBusiness' => __('blox_template_category_business'),
                'resultCount' => __('blox_template_result_count'),
                'localLibrary' => __('blox_template_tab_local'),
                'remoteLibrary' => __('blox_template_tab_remote'),
                'section' => __('blox_template_type_section'),
                'page' => __('blox_template_type_page'),
                'reload' => __('blox_template_reload'),
                'loading' => __('blox_template_loading'),
                'empty' => __('blox_template_empty'),
                'emptyLocal' => __('blox_template_empty_local'),
                'emptyRemote' => __('blox_template_empty_remote'),
                'manage' => __('blox_template_manage'),
                'countUnit' => __('blox_template_count_unit'),
                'local' => __('blox_template_source_local'),
                'plugin' => __('blox_template_source_plugin'),
                'remote' => __('blox_template_source_remote'),
                'premium' => __('blox_template_premium'),
                'lockedLicense' => __('blox_template_locked_license'),
                'lockedExpired' => __('blox_template_locked_expired'),
                'lockedModule' => __('blox_template_locked_module'),
                'manageLicense' => __('blox_template_manage_license'),
                'loadFailed' => __('blox_template_load_failed'),
                'insert' => __('blox_template_insert'),
                'downloadImport' => __('blox_template_download_import'),
                'edit' => __('blox_tpl_open_editor'),
                'insertFailed' => __('blox_template_insert_failed'),
                'inserted' => __('blox_template_inserted'),
                'appendConfirm' => __('blox_template_append_confirm'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            clipboardText: <?php echo json_encode([
                'copy' => __('blox_copy'),
                'cut' => __('blox_cut'),
                'paste' => __('blox_paste'),
                'copyDone' => __('blox_copy_done'),
                'cutDone' => __('blox_cut_done'),
                'pasteDone' => __('blox_paste_done'),
                'empty' => __('blox_clipboard_empty'),
                'invalid' => __('blox_clipboard_invalid'),
                'sourceMissing' => __('blox_cut_source_missing'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            ctx: { open: false, x: 0, y: 0, kind: "", target: null },
            devices: [
                { key: "desktop", label: <?= $jt('blox_device_desktop') ?>, icon: "ti-device-desktop" },
                { key: "tablet",  label: <?= $jt('blox_device_tablet') ?>, icon: "ti-device-tablet" },
                { key: "mobile",  label: <?= $jt('blox_device_mobile') ?>, icon: "ti-device-mobile" },
            ],
            responsiveText: <?php echo json_encode([
                'override' => __('blox_responsive_override'),
                'inheritsDesktop' => __('blox_responsive_inherits_desktop'),
                'inheritsTablet' => __('blox_responsive_inherits_tablet'),
                'resetInherit' => __('blox_responsive_reset_inherit'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            padOptions: [
                { k: "none", label: <?= $jt('blox_spacing_none') ?> }, { k: "sm", label: <?= $jt('blox_spacing_sm') ?> }, { k: "md", label: <?= $jt('blox_spacing_md') ?> },
                { k: "lg", label: <?= $jt('blox_spacing_lg') ?> }, { k: "xl", label: <?= $jt('blox_spacing_xl') ?> },
            ],
            // 取值与 BlockRenderer 的 ALIGN_ITEMS_MAP / JUSTIFY_ITEMS_MAP 对齐；
            // stretch 是默认值（渲染器映射表里没有它 = 不加对齐类，即拉伸）。
            // 图标按钮组显示，label 只做悬停提示——按轴分两组图标
            alignVOptions: [
                { k: "stretch", label: <?= $jt('blox_align_stretch_fill') ?>, icon: "arrows-vertical" },
                { k: "start", label: <?= $jt('blox_align_top') ?>, icon: "layout-align-top" },
                { k: "center", label: <?= $jt('blox_align_vcenter') ?>, icon: "layout-align-middle" },
                { k: "end", label: <?= $jt('blox_align_bottom') ?>, icon: "layout-align-bottom" },
            ],
            alignHOptions: [
                { k: "stretch", label: <?= $jt('blox_align_stretch_fill') ?>, icon: "arrows-horizontal" },
                { k: "start", label: <?= $jt('blox_align_left') ?>, icon: "layout-align-left" },
                { k: "center", label: <?= $jt('blox_align_hcenter') ?>, icon: "layout-align-center" },
                { k: "end", label: <?= $jt('blox_align_right') ?>, icon: "layout-align-right" },
            ],

            // ── 左栏（Bricks 式：元素库 ↔ 设置）───────────────
            containerDirectionOptions: [
                { k: "column", label: <?= $jt('blox_dir_column') ?>, short: <?= $jt('blox_dir_column_short') ?>, icon: "layout-list" },
                { k: "row", label: <?= $jt('blox_dir_row') ?>, short: <?= $jt('blox_dir_row_short') ?>, icon: "layout-columns" },
            ],
            containerWrapOptions: <?php echo json_encode([
                ['k' => 'auto', 'label' => __('blox_flex_wrap_auto'), 'short' => __('blox_flex_wrap_auto')],
                ['k' => 'wrap', 'label' => __('blox_flex_wrap_on'), 'short' => __('blox_flex_wrap_on')],
                ['k' => 'nowrap', 'label' => __('blox_flex_wrap_off'), 'short' => __('blox_flex_wrap_off')],
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            containerSizeOptions: [
                { k: "none", label: <?= $jt('blox_spacing_none') ?> }, { k: "sm", label: <?= $jt('blox_spacing_sm') ?> },
                { k: "md", label: <?= $jt('blox_spacing_md') ?> }, { k: "lg", label: <?= $jt('blox_spacing_lg') ?> },
                { k: "xl", label: <?php echo json_encode(__('blox_spacing_xl'), JSON_UNESCAPED_UNICODE); ?> },
            ],
            containerRadiusOptions: [
                { k: "none", label: <?= $jt('blox_spacing_none') ?> }, { k: "md", label: <?= $jt('blox_spacing_md') ?> }, { k: "xl", label: <?= $jt('blox_spacing_lg') ?> },
            ],
            containerAlignOptions: [
                { k: "stretch", label: <?= $jt('blox_align_stretch') ?>, icon: "arrows-vertical" },
                { k: "start", label: <?= $jt('blox_align_start') ?>, icon: "layout-align-top" },
                { k: "center", label: <?= $jt('blox_align_center') ?>, icon: "layout-align-middle" },
                { k: "end", label: <?= $jt('blox_align_end') ?>, icon: "layout-align-bottom" },
                { k: "baseline", label: <?php echo json_encode(__('blox_flex_align_baseline'), JSON_UNESCAPED_UNICODE); ?>, icon: "align-box-bottom-center" },
            ],
            containerJustifyOptions: [
                { k: "start", label: <?= $jt('blox_align_start') ?>, icon: "align-left" },
                { k: "center", label: <?= $jt('blox_align_center') ?>, icon: "align-center" },
                { k: "end", label: <?= $jt('blox_align_end') ?>, icon: "align-right" },
                { k: "between", label: <?= $jt('blox_align_between') ?>, icon: "align-justified" },
                { k: "around", label: <?php echo json_encode(__('blox_flex_around'), JSON_UNESCAPED_UNICODE); ?>, icon: "spacing-horizontal" },
                { k: "evenly", label: <?php echo json_encode(__('blox_flex_evenly'), JSON_UNESCAPED_UNICODE); ?>, icon: "space" },
            ],
            mobilePanel: "",            // 窄屏下的面板抽屉：library / structure / settings / 空=画布
            mobileActionsOpen: false,
            libOpen: false,             // true = 有选中项时仍显示元素库（「＋ 元素」按钮）
            panelTab: "content",        // 设置面板页签：content | style | condition
            boxOpen: { margin: false, padding: false },
            boxExactOpen: { margin: false, padding: false },
            boxText: <?php echo json_encode([
                'showSides' => __('blox_side_overrides'),
                'hideSides' => __('blox_hide_side_overrides'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            boxKinds: <?php echo json_encode([
                ['key' => 'margin', 'label' => __('blox_margin'), 'allowAuto' => true],
                ['key' => 'padding', 'label' => __('blox_padding'), 'allowAuto' => false],
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            boxSides: <?php echo json_encode([
                ['key' => 'top', 'label' => __('blox_side_top')],
                ['key' => 'right', 'label' => __('blox_side_right')],
                ['key' => 'bottom', 'label' => __('blox_side_bottom')],
                ['key' => 'left', 'label' => __('blox_side_left')],
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            boxSpacingBase: <?php echo json_encode([
                ['k' => '', 'label' => __('blox_spacing_default'), 'short' => '-'],
                ['k' => 'none', 'label' => __('blox_spacing_none'), 'short' => '0'],
                ['k' => 'xs', 'label' => __('blox_spacing_xs'), 'short' => 'XS'],
                ['k' => 'sm', 'label' => __('blox_spacing_sm'), 'short' => 'S'],
                ['k' => 'md', 'label' => __('blox_spacing_md'), 'short' => 'M'],
                ['k' => 'lg', 'label' => __('blox_spacing_lg'), 'short' => 'L'],
                ['k' => 'xl', 'label' => __('blox_spacing_xl'), 'short' => 'XL'],
                ['k' => 'auto', 'label' => __('blox_spacing_auto'), 'short' => 'A'],
                ['k' => 'exact', 'label' => __('blox_spacing_exact'), 'short' => '#'],
                ['k' => 'custom', 'label' => __('blox_spacing_custom'), 'short' => '✎'],
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            ctrlQuery: "",              // 设置搜索关键词（仅元素设置）
            modifiedOnly: false,        // 只看已修改的设置项
            libQuery: "",
            layoutPresets: [
                { label: <?= $jt('blox_layout_1col') ?>, spans: [12] },
                { label: <?= $jt('blox_layout_2equal') ?>, spans: [6, 6] },
                { label: <?= $jt('blox_layout_3equal') ?>, spans: [4, 4, 4] },
                { label: <?= $jt('blox_layout_4equal') ?>, spans: [3, 3, 3, 3] },
                { label: "1/3 + 2/3", spans: [4, 8] },
                { label: "2/3 + 1/3", spans: [8, 4] },
                { label: "1/4 + 3/4", spans: [3, 9] },
                { label: "3/4 + 1/4", spans: [9, 3] },
                { label: <?= $jt('blox_layout_side_content_side') ?>, spans: [3, 6, 3] },
                { label: <?= $jt('blox_layout_narrow_wide_narrow') ?>, spans: [2, 8, 2] },
            ],
            aboutRatioOptions: [
                { value: "1_2", label: "1:2", spans: [1, 2] },
                { value: "5_7", label: "5:7", spans: [5, 7] },
                { value: "1_1", label: "1:1", spans: [1, 1] },
                { value: "7_5", label: "7:5", spans: [7, 5] },
                { value: "2_1", label: "2:1", spans: [2, 1] },
            ],

            // ── 图标选择器（icon 控件） ─────────────────────
            tablerIcons: <?php echo json_encode($tablerIcons); ?>,
            bootstrapIcons: <?php echo json_encode($bootstrapIcons); ?>,
            iconPick: "",               // 当前展开选择器的控件 key（"" = 都收起）
            iconQuery: "",
            iconProvider: "tabler",
            // 无搜索词时的常用集（各图库值使用统一存储格式）
            iconCommon: {
                tabler: ["star","heart","circle-check","phone","mail","map-pin","clock","shield","bolt","award","world","users","home","settings","camera","bell","bookmark","calendar","folder","gift","link","lock","search","tag","trending-up","thumb-up","eye","download","upload","share","code","coffee","feather","flag","info-circle","lifebuoy","microphone","device-desktop","music","package","pencil","printer","send","server","mood-smile","sun","target","terminal","truck","device-tv","umbrella","wifi"],
                bootstrap: ["bi:star","bi:heart","bi:check-circle","bi:telephone","bi:envelope","bi:geo-alt","bi:clock","bi:shield","bi:lightning","bi:award","bi:globe","bi:people","bi:house","bi:gear","bi:camera","bi:bell","bi:bookmark","bi:calendar3","bi:folder","bi:gift","bi:link-45deg","bi:lock","bi:search","bi:tag","bi:graph-up","bi:hand-thumbs-up","bi:eye","bi:download","bi:upload","bi:share","bi:code-slash","bi:cup-hot","bi:flag","bi:info-circle","bi:life-preserver","bi:mic","bi:display","bi:music-note-beamed","bi:box-seam","bi:pencil","bi:printer","bi:send","bi:server","bi:emoji-smile","bi:sun","bi:crosshair","bi:terminal","bi:truck","bi:tv","bi:umbrella","bi:wifi"],
            },

            iconProviderForValue(value) {
                return String(value || "").toLowerCase().startsWith("bi:") ? "bootstrap" : "tabler";
            },

            iconClass(value) {
                return window.BloxIconUtils.className(value);
            },

            toggleIconPicker(key, value) {
                if (this.iconPick === key) {
                    this.iconPick = "";
                    return;
                }
                this.iconPick = key;
                this.iconQuery = "";
                this.iconProvider = this.iconProviderForValue(value);
            },

            setIconProvider(provider) {
                this.iconProvider = provider === "bootstrap" ? "bootstrap" : "tabler";
                this.iconQuery = "";
            },

            iconLibrary() {
                return this.iconProvider === "bootstrap" ? this.bootstrapIcons : this.tablerIcons;
            },

            /** 选择器网格内容：无词=常用集；有词=全量包含匹配，最多 96 个防卡 */
            iconMatches() {
                var q = this.iconQuery.trim().toLowerCase();
                if (!q) return this.iconCommon[this.iconProvider] || [];
                var icons = this.iconLibrary();
                var out = [];
                for (var i = 0; i < icons.length && out.length < 96; i++) {
                    if (icons[i].indexOf(q) !== -1) out.push(icons[i]);
                }
                return out;
            },

            iconHint() {
                var q = this.iconQuery.trim();
                var icons = this.iconLibrary();
                if (!q) return this.uiText.iconHintDefault.replace(":n", icons.length);
                var n = this.iconMatches().length;
                if (n === 0) return this.uiText.iconHintNone.replace(":q", q);
                return n >= 96 ? this.uiText.iconHintMany : this.uiText.iconHintCount.replace(":n", n);
            },

            // ── Site-wide design tokens and named style presets ──
            activeColorTokens() {
                return (this.designSystem.tokens || []).filter(function (token) {
                    return token.status !== "archived";
                });
            },

            archivedColorTokens() {
                return (this.designSystem.tokens || []).filter(function (token) {
                    return token.status === "archived";
                });
            },

            colorTokenOptions(currentValue) {
                var items = this.activeColorTokens();
                var id = this.colorTokenId(currentValue);
                if (!id || items.some(function (token) { return token.id === id; })) return items;
                var archived = (this.designSystem.tokens || []).find(function (token) { return token.id === id; });
                return archived ? items.concat([archived]) : items;
            },

            colorTokenLabel(token) {
                return token.status === "archived"
                    ? token.name + " · " + this.designText.archived
                    : token.name;
            },

            activeGlobalStyles() {
                return (this.designSystem.styles || []).filter(function (style) {
                    return style.status !== "archived";
                });
            },

            archivedGlobalStyles() {
                return (this.designSystem.styles || []).filter(function (style) {
                    return style.status === "archived";
                });
            },

            globalStyleOptions(currentId) {
                var items = this.activeGlobalStyles();
                currentId = String(currentId || "");
                if (!currentId || items.some(function (style) { return style.id === currentId; })) return items;
                var archived = (this.designSystem.styles || []).find(function (style) { return style.id === currentId; });
                return archived ? items.concat([archived]) : items;
            },

            globalStyleLabel(style) {
                return style.status === "archived"
                    ? style.name + " · " + this.designText.archived
                    : style.name;
            },

            colorTokenRef(id) {
                return /^[a-z][a-z0-9_-]{0,47}$/.test(String(id || ""))
                    ? "var(--yk-color-" + id + ")" : "";
            },

            colorTokenId(value) {
                var match = String(value || "").match(/^var\(--yk-color-([a-z][a-z0-9_-]{0,47})\)$/);
                return match ? match[1] : "";
            },

            colorPickerValue(value, fallback) {
                var id = this.colorTokenId(value);
                if (id) {
                    var token = (this.designSystem.tokens || []).find(function (item) { return item.id === id; });
                    if (token && /^#[0-9a-f]{6}$/i.test(String(token.value || ""))) return token.value;
                }
                return /^#[0-9a-f]{6}$/i.test(String(value || "")) ? value : fallback;
            },

            applyGlobalStyle(id) {
                if (!this.selEl) return;
                id = String(id || "");
                if (!id) {
                    this.selEl.data._global_style = "";
                    this.selEl.data._global_style_snapshot = {};
                    return;
                }
                var style = (this.designSystem.styles || []).find(function (item) { return item.id === id; });
                if (!style) return;
                this.selEl.data._global_style = id;
                this.selEl.data._global_style_snapshot = {
                    color: style.color || "",
                    background: style.background || "",
                    border_color: style.border_color || "",
                    radius: style.radius || "none"
                };
            },

            openDesignSystem(tab) {
                if (!this.canManageDesign) return;
                this.designTab = tab === "styles" && this.advancedMode ? "styles" : "colors";
                this.designOpen = true;
                this.focusDialog(this.$refs.designDialog, "[data-dialog-initial]");
                this.reloadDesignSystem();
            },

            closeDesignSystem() {
                if (!this.designOpen) return;
                var root = this.$refs.designDialog;
                this.designOpen = false;
                this.releaseDialog(root);
            },

            reloadDesignSystem() {
                if (!this.canManageDesign || this.designBusy) return;
                this.designMutation("snapshot", null, false);
                this.reloadDesignUsage();
            },

            designUsageEntry(kind, id) {
                var bucket = kind === "style" ? "styles" : "tokens";
                return (this.designUsage[bucket] && this.designUsage[bucket][id]) || { count: 0, sources: [] };
            },
            designUsageCount(kind, id) { return Number(this.designUsageEntry(kind, id).count || 0); },
            designUsageTitle(kind, id) {
                var entry = this.designUsageEntry(kind, id);
                if (!entry.count) return "";
                var self = this;
                return (entry.sources || []).slice(0, 8).map(function (source) {
                    var label = String(source.label || "").trim() || ("#" + Number(source.id || 0));
                    var state = source.state === "draft" ? self.designText.usageDraft
                        : (source.state === "published" ? self.designText.usagePublished
                            : (source.state === "current" ? self.designText.usageCurrent : ""));
                    return label + (state ? " · " + state : "");
                }).join("\n");
            },
            reloadDesignUsage() {
                if (!this.canManageDesign) return;
                var body = new URLSearchParams({ action: "usage", _token: this.csrf });
                var self = this;
                fetch("/admin/blox_design_api.php", { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (result && Number(result.code) === 0 && result.data) self.designUsage = result.data;
                    });
            },

            addDesignToken() {
                this.designMutation("token_add", this.newToken, true);
            },

            addGlobalStyle() {
                if (!this.advancedMode) return;
                this.designMutation("style_add", this.newStyle, true);
            },

            updateDesignToken(token) { this.designMutation("token_update", token, true); },
            updateGlobalStyle(style) { this.designMutation("style_update", style, true); },
            toggleDesignLock(kind, item) {
                this.designMutation(kind + "_lock", { id: item.id, locked: !item.locked }, true);
            },
            archiveDesignItem(kind, item) {
                var count = this.designUsageCount(kind, item.id);
                if (count > 0 && !window.confirm(this.designText.archiveUsed.replace(":count", String(count)))) return;
                this.designMutation(kind + "_archive", { id: item.id }, true);
            },
            restoreDesignItem(kind, item) { this.designMutation(kind + "_restore", { id: item.id }, true); },

            designMutation(action, item, notify) {
                if (!this.canManageDesign || this.designBusy) return;
                var body = new URLSearchParams();
                body.set("action", action);
                body.set("revision", String(this.designSystem.revision || 0));
                body.set("_token", this.csrf);
                Object.keys(item || {}).forEach(function (key) {
                    if (["system", "status", "version"].indexOf(key) !== -1) return;
                    body.set(key, typeof item[key] === "boolean" ? (item[key] ? "1" : "0") : String(item[key] || ""));
                });
                var self = this;
                this.designBusy = true;
                fetch("/admin/blox_design_api.php", { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result || Number(result.code) !== 0 || !result.data) {
                            throw new Error((result && result.msg) || self.designText.failed);
                        }
                        self.designSystem = result.data;
                        if (action === "token_add") self.newToken = { name: "", category: "brand", value: "#3b82f6" };
                        if (action === "style_add") self.newStyle = { name: "", category: "general", color: "", background: "", border_color: "", radius: "none" };
                        self.refreshPreview();
                        if (notify) self.toast(self.designText.saved);
                    })
                    .catch(function (error) { self.toast(error.message || self.designText.failed); })
                    .finally(function () { self.designBusy = false; });
            },

            // ── 自定义渐变（双色+方向，改动即写入 bg_gradient） ──
            gradA: "#667eea",
            gradB: "#764ba2",
            gradDir: "135",
            applyCustomGrad() {
                if (!this.sel) return;
                this.sel.settings.bg_gradient = "linear-gradient(" + this.gradDir + "deg," + this.gradA + " 0%," + this.gradB + " 100%)";
            },

            setSectionBackgroundImage(url) {
                if (!this.sel || !this.sel.settings) return;
                this.sel.settings.bg_image = url || "";
                if (!url) return;
                if (!Object.prototype.hasOwnProperty.call(this.sel.settings, "bg_overlay_color")) {
                    this.sel.settings.bg_overlay_color = "#000000";
                }
                if (!Object.prototype.hasOwnProperty.call(this.sel.settings, "bg_overlay_opacity")) {
                    this.sel.settings.bg_overlay_opacity = 45;
                }
                if (!Object.prototype.hasOwnProperty.call(this.sel.settings, "bg_position")) {
                    this.sel.settings.bg_position = "center";
                }
            },

            setContainerBackgroundImage(url) {
                var section = this.sel;
                if (!section || !section.settings) return;
                section.settings.container_bg_image = url || "";
                if (!url) return;
                if (!Object.prototype.hasOwnProperty.call(section.settings, "container_bg_overlay_color")) {
                    section.settings.container_bg_overlay_color = "#000000";
                }
                if (!Object.prototype.hasOwnProperty.call(section.settings, "container_bg_overlay_opacity")) {
                    section.settings.container_bg_overlay_opacity = 45;
                }
            },

            pickContainerBackgroundImage() {
                var self = this;
                this.openMedia(function (url) { self.setContainerBackgroundImage(url); });
            },

            setColumnBackgroundImage(url) {
                var column = this.selectedCol();
                if (!column) return;
                column.card_bg_image = url || "";
                if (!url) return;
                if (!Object.prototype.hasOwnProperty.call(column, "card_bg_overlay_color")) {
                    column.card_bg_overlay_color = "#000000";
                }
                if (!Object.prototype.hasOwnProperty.call(column, "card_bg_overlay_opacity")) {
                    column.card_bg_overlay_opacity = 45;
                }
            },

            pickColumnBackgroundImage() {
                var self = this;
                this.openMedia(function (url) { self.setColumnBackgroundImage(url); });
            },

            // ── 渐变背景预置（值原样存 settings.bg_gradient；渲染器有白名单校验） ──
            gradientPresets: [
                { label: <?= $jt('blox_grad_bluepurple') ?>, css: "linear-gradient(135deg,#667eea 0%,#764ba2 100%)" },
                { label: <?= $jt('blox_grad_ocean') ?>, css: "linear-gradient(135deg,#2193b0 0%,#6dd5ed 100%)" },
                { label: <?= $jt('blox_grad_verdant') ?>, css: "linear-gradient(135deg,#11998e 0%,#38ef7d 100%)" },
                { label: <?= $jt('blox_grad_sunset') ?>, css: "linear-gradient(135deg,#f6d365 0%,#fda085 100%)" },
                { label: <?= $jt('blox_grad_crimson') ?>, css: "linear-gradient(135deg,#eb3349 0%,#f45c43 100%)" },
                { label: <?= $jt('blox_grad_sakura') ?>, css: "linear-gradient(135deg,#fbc2eb 0%,#a6c1ee 100%)" },
                { label: <?= $jt('blox_grad_night') ?>, css: "linear-gradient(135deg,#141e30 0%,#243b55 100%)" },
                { label: <?= $jt('blox_grad_stone') ?>, css: "linear-gradient(135deg,#8e9eab 0%,#eef2f3 100%)" },
            ],
            bgPositionOptions: [
                { key: "top-left", label: <?= $jt('blox_bg_position_top_left') ?> },
                { key: "top", label: <?= $jt('blox_bg_position_top') ?> },
                { key: "top-right", label: <?= $jt('blox_bg_position_top_right') ?> },
                { key: "left", label: <?= $jt('blox_bg_position_left') ?> },
                { key: "center", label: <?= $jt('blox_bg_position_center') ?> },
                { key: "right", label: <?= $jt('blox_bg_position_right') ?> },
                { key: "bottom-left", label: <?= $jt('blox_bg_position_bottom_left') ?> },
                { key: "bottom", label: <?= $jt('blox_bg_position_bottom') ?> },
                { key: "bottom-right", label: <?= $jt('blox_bg_position_bottom_right') ?> },
            ],

            // ── 模板库：目录轻量加载，正文仅在插入时请求 ─────────
            templateOpen: false,
            templateLoading: false,
            templateLoaded: false,
            templateReloadPending: false,
            templateInserting: "",
            templateItems: [],
            templateQuery: "",
            templateFilter: "all",
            templateCategory: "all",
            templateScope: "local",
            templateError: "",
            templateRemoteError: "",
            templateFilters: [
                { key: "all", label: <?php echo json_encode(__('blox_template_filter_all'), JSON_UNESCAPED_UNICODE); ?> },
                { key: "section", label: <?php echo json_encode(__('blox_template_type_section'), JSON_UNESCAPED_UNICODE); ?> },
                { key: "page", label: <?php echo json_encode(__('blox_template_type_page'), JSON_UNESCAPED_UNICODE); ?> },
            ],
            focusDialog(root, initialSelector) {
                var self = this;
                this.$nextTick(function () {
                    if (window.BloxDialogFocus) window.BloxDialogFocus.open(root, initialSelector || "");
                });
            },

            releaseDialog(root) {
                this.$nextTick(function () {
                    if (window.BloxDialogFocus) window.BloxDialogFocus.close(root);
                });
            },

            dialogKeydown(event, root, onEscape) {
                if (window.BloxDialogFocus) window.BloxDialogFocus.keydown(event, root, onEscape);
            },

            /**
             * 关闭模板面板 = 取消未消费的定点插入意图（审计 r17-1：Esc/遮罩/关闭按钮
             * 此前只收面板不清 _insertAt，取消后从常规入口添加会落到旧边界）。
             * 成功插入路径不经此处（insertTemplate 的应用段 → watcher 清位）。
             */
            closeTemplates() {
                if (!this.templateOpen) return;
                var root = this.$refs.templateDialog;
                this.templateOpen = false;
                this._insertAt = null;
                this.releaseDialog(root);
            },

            openTemplates() {
                var alreadyOpen = this.templateOpen;
                this.templateOpen = true;
                if (!alreadyOpen) this.focusDialog(this.$refs.templateDialog, "[data-dialog-initial]");
                if (!this.templateLoaded) this.loadTemplates();
            },

            loadTemplates(force) {
                if (this.templateLoading) {
                    if (force) this.templateReloadPending = true;
                    return;
                }
                if (this.templateLoaded && !force) return;
                var self = this;
                this.templateLoading = true;
                this.templateError = "";
                this.templateRemoteError = "";
                var context = this.homeMode ? "home" : "page";
                window.BloxTemplateLibrary.list(
                    "/admin/blox_template_api.php",
                    context,
                    this.templateText.loadFailed,
                    !!force
                )
                    .then(function (items) {
                        self.templateItems = items;
                        self.templateRemoteError = String(items.remoteError || "");
                        self.templateLoaded = true;
                    })
                    .catch(function (error) {
                        // 刷新失败时保留已显示的本地目录，尤其不能抹掉刚另存成功的模板。
                        if (!self.templateLoaded) {
                            self.templateItems = [];
                            self.templateRemoteError = "";
                        }
                        self.templateError = error.message || self.templateText.loadFailed;
                    })
                    .finally(function () {
                        self.templateLoading = false;
                        if (self.templateReloadPending) {
                            self.templateReloadPending = false;
                            self.loadTemplates(true);
                        }
                    });
            },

            filteredTemplates() {
                return window.BloxTemplateLibrary.filter(
                    this.scopedTemplates(),
                    this.templateQuery,
                    this.templateFilter,
                    "all",
                    this.templateCategory
                );
            },

            scopedTemplates() {
                return window.BloxTemplateLibrary.scope(this.templateItems, this.templateScope);
            },

            templateScopeCount(scope) {
                return window.BloxTemplateLibrary.scopeCount(this.templateItems, scope);
            },

            templateCategoryOptions() {
                return window.BloxTemplateLibrary.categories(this.scopedTemplates());
            },

            templateCategoryLabel(category) {
                return window.BloxTemplateLibrary.categoryLabel(category, this.templateText);
            },

            templateTypeLabel(type) {
                return type === "page" ? this.templateText.page : this.templateText.section;
            },

            templateProviderLabel(item) {
                return window.BloxTemplateLibrary.providerLabel(item, this.templateText);
            },

            canEditLocalTemplate(item) {
                return window.BloxTemplateLibrary.canEditLocal(item);
            },

            localTemplateEditUrl(item) {
                return window.BloxTemplateLibrary.localEditUrl(item);
            },

            templateLockLabel(item) {
                return window.BloxTemplateLibrary.lockLabel(item, this.templateText);
            },

            anchorIdValid(value) {
                return /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(String(value || "").replace(/^#/, ""));
            },

            anchorIdDuplicate(value) {
                var current = String(value || "").replace(/^#/, "").toLowerCase();
                if (!current || !this.anchorIdValid(current)) return false;
                var count = this.sections.filter(function (section) {
                    return String(section && section.settings && section.settings.anchor_id || "")
                        .replace(/^#/, "").toLowerCase() === current;
                }).length;
                return count > 1;
            },

            hasLockedTemplates() {
                return window.BloxTemplateLibrary.hasLockedRemote(this.templateItems);
            },


            insertTemplate(item) {
                if (!item || item.locked || this.templateInserting) return;
                if (item.type === "page" && this.sections.length > 0
                    && !window.confirm(this.templateText.appendConfirm)) return;
                var self = this;
                this.templateInserting = item.key;
                var context = this.homeMode ? "home" : "page";
                window.BloxTemplateLibrary.resolve(
                    "/admin/blox_template_api.php",
                    context,
                    item.key,
                    this.templateText.insertFailed,
                    this.csrf
                )
                    .then(function (template) {
                        // 应用段走命令层：中途异常回滚整组插入，不留半截模板（silent：提示走下方 catch）
                        var applied = self.commandRunner().execute("insert-template", function () {
                            var sections = template.sections;
                            if (!sections.length) throw new Error(self.templateText.insertFailed);
                            var fresh = window.BloxTemplateLibrary.freshSections(
                                sections,
                                function (prefix) { return self.uid(prefix); }
                            );
                            var at = self.insertIndex();
                            self.sections.splice.apply(self.sections, [at, 0].concat(fresh));
                            self.selectedSi = at;
                            self.selectedCi = -1;
                            self.selectedEi = -1;
                            self.selectedSubEi = -1;
                            self.selLayer = "sec";
                            self.closeTemplates();
                            self.toast((template.name || item.name) + self.templateText.inserted);
                        }, { silent: true });
                        if (!applied.ok) throw (applied.error || new Error(self.templateText.insertFailed));
                    })
                    .catch(function (error) {
                        self.templateError = error.message || self.templateText.insertFailed;
                    })
                    .finally(function () { self.templateInserting = ""; });
            },

            // ── 媒体库选择器（复用 media_api.php，与后台其它页的选图弹窗同一数据源） ──
            mediaOpen: false,
            mediaItems: [],
            mediaPage: 1,
            mediaPages: 1,
            mediaTotal: 0,
            mediaKeyword: "",
            mediaLoading: false,
            _mediaTarget: null,   // 选中回调：拿到 url 写进哪个字段

            openMedia(setter) {
                this._mediaTarget = setter;
                this.mediaOpen = true;
                this.mediaKeyword = "";
                this.focusDialog(this.$refs.mediaDialog, "[data-dialog-initial]");
                this.loadMedia(1);
            },

            closeMedia() {
                if (!this.mediaOpen) return;
                var root = this.$refs.mediaDialog;
                this.mediaOpen = false;
                this._mediaTarget = null;
                this.releaseDialog(root);
            },

            loadMedia(page) {
                var self = this;
                this.mediaLoading = true;
                this.mediaPage = page;
                window.BloxMediaClient.list("/admin/media_api.php", page, this.mediaKeyword)
                    .then(function (result) {
                        if (result.ok) {
                            self.mediaItems = result.items;
                            self.mediaPages = result.pages;
                            self.mediaTotal = result.total;
                        } else {
                            self.mediaItems = [];
                            self.toast(result.message || self.uiText.mediaLoadFailed);
                        }
                    })
                    .catch(function () { self.mediaItems = []; self.toast(self.uiText.mediaFailed); })
                    .finally(function () { self.mediaLoading = false; });
            },

            pickMedia(url) {
                if (this._mediaTarget) this._mediaTarget(url);
                this.closeMedia();
            },

            mediaUploading: false,

            // ── 富文本弹窗（系统 TinyMCE，按需初始化一次，多控件共用） ──
            rteOpen: false,
            _rteTarget: null,
            _rteInited: false,

            openRte(getter, setter) {
                this._rteTarget = setter;
                this.rteOpen = true;
                this.focusDialog(this.$refs.rteDialog, "[data-dialog-initial]");
                var initial = getter() || "";
                var self = this;
                this.$nextTick(function () {
                    if (self._rteInited) {
                        var ed = tinymce.get("bloxRte");
                        if (ed) ed.setContent(initial);
                        return;
                    }
                    self._rteInited = true;
                    tinymce.init({
                        selector: "#bloxRte",
                        language: (document.documentElement.lang || "zh-CN") === "ja" ? "ja" : "zh_CN",
                        height: 420,
                        menubar: false,
                        plugins: "autolink lists link image charmap searchreplace visualblocks code codesample insertdatetime media table wordcount",
                        toolbar: "undo redo | styles fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright | bullist numlist | link image media codesample | table | removeformat code",
                        branding: false, promotion: false, convert_urls: false,
                        images_upload_handler: function (blobInfo) {
                            return new Promise(function (resolve, reject) {
                                window.BloxMediaClient.upload("/admin/media_api.php", blobInfo.blob(), {
                                    csrf: self.csrf,
                                    filename: blobInfo.filename(),
                                    maxDimension: <?php echo max(0, (int) config('upload_max_width', 1920)); ?>,
                                    quality: <?php echo max(50, min(95, (int) config('upload_jpeg_quality', 85))) / 100; ?>,
                                })
                                    .then(function (result) { result.ok ? resolve(result.url) : reject(result.message || self.uiText.uploadFailedShort); })
                                    .catch(function () { reject(self.uiText.uploadFailedShort); });
                            });
                        },
                        // 图片对话框的「浏览」→ blox 自己的媒体库弹窗（z-index 已压在 TinyMCE 之上）
                        file_picker_types: "image",
                        file_picker_callback: function (cb, value, meta) {
                            if (meta.filetype === "image") self.openMedia(function (u) { cb(u, { alt: "" }); });
                        },
                        setup: function (ed) {
                            ed.on("init", function () { ed.setContent(initial); });
                        }
                    });
                });
            },

            closeRte() {
                if (!this.rteOpen) return;
                var root = this.$refs.rteDialog;
                this.rteOpen = false;
                this._rteTarget = null;
                this.releaseDialog(root);
            },

            saveRte() {
                var ed = tinymce.get("bloxRte");
                if (ed && this._rteTarget) this._rteTarget(ed.getContent());
                this.closeRte();
            },

            /** 上传成功直接选用（上传的目的就是马上用）；失败提示原因留在弹窗里重试 */
            mediaUploadMessage(result) {
                if (!result || !result.optimized || result.uploadBytes >= result.originalBytes) {
                    return this.uiText.uploadedSelected;
                }
                return this.uiText.uploadedOptimized
                    .replace(":from", window.BloxMediaClient.formatBytes(result.originalBytes))
                    .replace(":to", window.BloxMediaClient.formatBytes(result.uploadBytes));
            },

            uploadMedia(file) {
                if (!file || this.mediaUploading) return;
                var self = this;
                this.mediaUploading = true;
                window.BloxMediaClient.upload("/admin/media_api.php", file, {
                    csrf: this.csrf,
                    maxDimension: <?php echo max(0, (int) config('upload_max_width', 1920)); ?>,
                    quality: <?php echo max(50, min(95, (int) config('upload_jpeg_quality', 85))) / 100; ?>,
                })
                    .then(function (result) {
                        if (result.ok) {
                            self.toast(self.mediaUploadMessage(result));
                            self.pickMedia(result.url);
                        } else {
                            self.toast(result.message || self.uiText.uploadFailedShort);
                        }
                    })
                    .catch(function () { self.toast(self.uiText.uploadFailed); })
                    .finally(function () { self.mediaUploading = false; });
            },
            targetCi: 0,                // 插入到选中区块的第几列
            elementLib: <?php echo json_encode($elementLib, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            catLabels: <?php echo json_encode($catLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            elementSchemas: <?php echo json_encode($elementSchemas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            homeBannerSeeds: <?php echo json_encode($homeBannerSeeds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,

            // 元素选中：-1 表示当前选的是区块本身；selectedSubEi ≥0 = 选的是容器内的子元素
            selectedCi: -1,
            selectedEi: -1,
            selectedSubEi: -1,
            selectedSectionField: "",
            selectedHomeField: "",
            homeFieldRevision: 0,
            selectedHomeColumn: "",
            _emptyCol: {
                card_bg: "",
                card_bg_image: "",
                card_bg_overlay_color: "",
                card_bg_overlay_opacity: 0,
            },
            // 区块的选中层（Bricks 分层树）：sec=全宽背景层，con=内容容器层
            selLayer: "sec",

            get sel() { return this.selectedSi >= 0 && this.sections[this.selectedSi] ? this.sections[this.selectedSi] : null; },

            /** 列级选中元素（容器场景下=容器本身，不下钻子元素） */
            get selTopEl() {
                var s = this.sel;
                if (!s || this.selectedCi < 0 || this.selectedEi < 0) return null;
                var col = s.columns[this.selectedCi];
                return (col && col.elements[this.selectedEi]) ? col.elements[this.selectedEi] : null;
            },

            /** 当前选中的元素对象；子元素选中时下钻到 children（设置面板据此切换显示） */
            get selEl() {
                var el = this.selTopEl;
                if (el && this.selectedSubEi >= 0) {
                    var kids = (el.data && el.data.children) || [];
                    return kids[this.selectedSubEi] || null;
                }
                return el;
            },

            /** 元素 schema。未知类型（插件卸载后残留等）也要给个兜底，不能让设置面板炸掉 */
            elSchema(type) {
                return this.elementSchemas[type] || {
                    label: type, icon: "box", controls: [], container: false,
                    paletteVisible: false, allowedChildren: [], childRules: [],
                    genericChild: false, supportsBoxStyles: false, scripts: [], styles: [],
                    missing: true, plugin: null, deprecated: false
                };
            },
            elIcon(type) { return this.elSchema(type).icon || "box"; },

            isSelectedContainerEl() {
                return !!(this.selEl && ["container", "div"].indexOf(this.selEl.type) !== -1);
            },

            isLoopTemplateHost(el) {
                var node = el || this.selTopEl;
                return !!(node && node.type === "list-dynamic");
            },

            isHomeBlockHost(el) {
                var node = el || this.selTopEl;
                return !!(node && node.type === "home-block");
            },

            isHomeBannerHost(el) {
                var node = el || this.selTopEl;
                return !!(this.isHomeBlockHost(node) && String((node.data || {}).block_type || "") === "banner");
            },

            hasCustomBannerItems() {
                var host = this.isHomeBlockHost(this.selTopEl) ? this.selTopEl : this.selEl;
                return !!(this.isHomeBannerHost(host) && (host.data || {}).items_mode === "custom");
            },

            bannerHost() {
                if (this.isHomeBannerHost(this.selTopEl)) return this.selTopEl;
                return this.isHomeBannerHost(this.selEl) ? this.selEl : null;
            },

            bannerItems() {
                var host = this.bannerHost();
                return host && host.data && Array.isArray(host.data.children) ? host.data.children : [];
            },

            bannerPreviewItems() {
                if (this.hasCustomBannerItems()) return this.bannerItems();
                return this.homeBannerSeeds.map(function (item, index) {
                    return { id: "seed-banner-" + index, type: "home-banner-item", data: item };
                });
            },

            homeBannerItemCount() {
                return this.bannerPreviewItems().length;
            },

            adoptBannerData(host) {
                if (!this.isHomeBannerHost(host)) return [];
                var self = this;
                host.data.items_mode = "custom";
                host.data.children = this.homeBannerSeeds.map(function (item) {
                    return { id: self.uid("e"), type: "home-banner-item", data: JSON.parse(JSON.stringify(item)) };
                });
                return host.data.children;
            },

            selectBannerItem(index) {
                var host = this.bannerHost();
                if (!host || !this.bannerPreviewItems()[index]) return;
                if (!this.hasCustomBannerItems()) this.adoptBannerData(host);
                if (!this.bannerItems()[index]) return;
                this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, index);
            },

            replaceBannerImage(index) {
                var host = this.bannerHost();
                if (!host || !this.bannerPreviewItems()[index]) return;
                var self = this;
                this.openMedia(function (url) {
                    if (!self.hasCustomBannerItems()) self.adoptBannerData(host);
                    var item = self.bannerItems()[index];
                    if (!item) return;
                    item.data = item.data || {};
                    item.data.image = url;
                    self.selectChild(self.selectedSi, self.selectedCi, self.selectedEi, index);
                });
            },

            adoptBannerItems(index) {
                var host = this.isHomeBlockHost(this.selTopEl) ? this.selTopEl : this.selEl;
                if (!this.isHomeBannerHost(host)) return;
                var items = this.adoptBannerData(host);
                if (items.length === 0) {
                    this.addBannerItem();
                    return;
                }
                var selectedIndex = Number.isInteger(index) ? index : 0;
                this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, Math.min(items.length - 1, selectedIndex));
            },

            addBannerItem() {
                var host = this.isHomeBlockHost(this.selTopEl) ? this.selTopEl : this.selEl;
                if (!this.isHomeBannerHost(host)) return;
                if (!this.hasCustomBannerItems()) this.adoptBannerData(host);
                host.data.children = host.data.children || [];
                var defaults = JSON.parse(JSON.stringify((this.elSchema("home-banner-item").defaults || {})));
                defaults.title = defaults.title || this.homeDynamicText.newItemTitle;
                host.data.children.push({ id: this.uid("e"), type: "home-banner-item", data: defaults });
                this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, host.data.children.length - 1);
            },

            restoreBannerSource() {
                var host = this.isHomeBlockHost(this.selTopEl) ? this.selTopEl : this.selEl;
                if (!this.isHomeBannerHost(host)) return;
                if ((host.data.children || []).length && !confirm(this.homeDynamicText.restoreConfirm)) return;
                host.data.items_mode = "inherit";
                host.data.children = [];
                this.selectElement(this.selectedSi, this.selectedCi, this.selectedEi);
            },

            isLoopTemplateChild() {
                return this.selectedSubEi >= 0 && this.isLoopTemplateHost(this.selTopEl);
            },

            hasLoopTemplate() {
                var host = this.isLoopTemplateHost(this.selTopEl) ? this.selTopEl : null;
                return !!(host && host.data && (host.data.children || []).length);
            },

            allowedChildTypes(host) {
                var schema = this.elSchema(host && host.type);
                var allowed = Array.isArray(schema.allowedChildren) ? schema.allowedChildren.slice() : [];
                var data = (host && host.data) || {};
                (schema.childRules || []).forEach(function(rule) {
                    var actual = data[rule.field];
                    var matches = rule.operator === "!="
                        ? actual !== rule.value
                        : (Array.isArray(rule.value) ? rule.value.indexOf(actual) !== -1 : actual === rule.value);
                    if (matches) {
                        allowed = Array.isArray(rule.allowedChildren) ? rule.allowedChildren.slice() : [];
                    }
                });
                return allowed;
            },

            canNestElement(host, node) {
                if (!host || !node || !this.elSchema(host.type).container) return false;
                var childSchema = this.elSchema(node.type);
                if (childSchema.deprecated) return false;
                var allowed = this.allowedChildTypes(host);
                if (allowed.indexOf(node.type) !== -1) return true;
                return allowed.indexOf("*") !== -1
                    && !childSchema.container
                    && childSchema.genericChild !== false
                    && childSchema.paletteVisible === true;
            },

            supportsBoxStyles(type) {
                return this.elSchema(type).supportsBoxStyles === true;
            },

            boxSpacingOptions(allowAuto) {
                return allowAuto ? this.boxSpacingBase : this.boxSpacingBase.filter(function (opt) {
                    return opt.k !== "auto";
                });
            },

            setBoxSpacing(kind, side, value) {
                if (!this.selEl || !this.selEl.data) return;
                var key = "style_" + kind + (side ? "_" + side : "");
                if (value === "exact") {
                    delete this.selEl.data[key];
                    this.clearBoxSides(kind);
                    this.boxOpen[kind] = false;
                    this.boxExactOpen[kind] = true;
                    return;
                }
                if (value === "custom") {
                    // 四边独立设置：清掉总值、展开该类自己的四边环
                    delete this.selEl.data[key];
                    this.boxExactOpen[kind] = false;
                    this.boxOpen[kind] = true;
                    return;
                }
                if (value === "") delete this.selEl.data[key];
                else this.selEl.data[key] = value;
                if (!side) {
                    this.clearBoxSides(kind);
                    this.boxOpen[kind] = false;
                    this.boxExactOpen[kind] = false;
                }
            },

            clearBoxSides(kind) {
                if (!this.selEl || !this.selEl.data) return;
                var data = this.selEl.data;
                ["top", "right", "bottom", "left"].forEach(function (s2) { delete data["style_" + kind + "_" + s2]; });
            },

            hasKindSides(kind) {
                if (!this.selEl || !this.selEl.data) return false;
                var data = this.selEl.data;
                return ["top", "right", "bottom", "left"].some(function (s2) {
                    var v = data["style_" + kind + "_" + s2];
                    return v !== undefined && v !== null && v !== "";
                });
            },

            kindBoxVisible(kind) { return this.boxOpen[kind] || this.hasKindSides(kind); },

            resetBoxPanelState() {
                this.boxOpen.margin = false;
                this.boxOpen.padding = false;
                this.boxExactOpen.margin = false;
                this.boxExactOpen.padding = false;
            },

            /** 总值 select 显示：该类存在任一四边值 → 显示「自定义」档；否则显示总值档位 */
            spacingSelectValue(kind, side) {
                var data = this.selEl && this.selEl.data ? this.selEl.data : {};
                if (!side) {
                    var hasSides = ["top", "right", "bottom", "left"].some(function (s2) {
                        var sv = data["style_" + kind + "_" + s2];
                        return sv !== undefined && sv !== null && sv !== "";
                    });
                    if (hasSides) return "custom";
                }
                var v = data["style_" + kind + (side ? "_" + side : "")];
                if (v === undefined || v === null) return "";
                var known = this.boxSpacingBase.some(function (opt) {
                    return opt.k !== "exact" && opt.k !== "custom" && opt.k === v;
                });
                return known ? v : "exact";
            },

            kindExactVisible(kind) {
                if (this.boxExactOpen[kind]) return true;
                var data = this.selEl && this.selEl.data ? this.selEl.data : {};
                var value = data["style_" + kind];
                if (value === undefined || value === null || value === "") return false;
                return !this.boxSpacingBase.some(function (opt) {
                    return opt.k !== "exact" && opt.k !== "custom" && opt.k === value;
                });
            },

            boxOverallDisplay(kind) {
                var data = this.selEl && this.selEl.data ? this.selEl.data : {};
                var value = data["style_" + kind];
                return this.spacingRegex(kind).test(String(value || "")) ? value : "";
            },

            setBoxOverall(kind, ev) {
                if (!this.selEl || !this.selEl.data) return;
                var key = "style_" + kind;
                var raw = String(ev.target.value).trim();
                if (raw === "") {
                    delete this.selEl.data[key];
                    this.boxExactOpen[kind] = false;
                    return;
                }
                if (!this.spacingRegex(kind).test(raw)) {
                    this.toast(<?php echo json_encode(__('blox_spacing_invalid'), JSON_UNESCAPED_UNICODE); ?>);
                    ev.target.value = this.boxOverallDisplay(kind);
                    return;
                }
                this.clearBoxSides(kind);
                this.selEl.data[key] = raw;
                this.boxOpen[kind] = false;
                this.boxExactOpen[kind] = true;
            },

            spacingRegex(kind) {
                return kind === "margin"
                    ? /^(-?\d{1,4}(\.\d{1,2})?(px|rem|em|%|vw|vh)|auto|0)$/
                    : /^(\d{1,4}(\.\d{1,2})?(px|rem|em|%|vw|vh)|0)$/;
            },

            /** 盒模型输入框显示值（档位或精确值原样显示，空=未设置） */
            boxSideDisplay(kind, side) {
                var v = this.selEl && this.selEl.data ? this.selEl.data["style_" + kind + "_" + side] : "";
                return (v === undefined || v === null) ? "" : v;
            },

            /** 盒模型输入提交：档位关键词或精确值皆可；非法值提示并回显原值 */
            setBoxSide(kind, side, ev) {
                if (!this.selEl || !this.selEl.data) return;
                var key = "style_" + kind + "_" + side;
                var raw = String(ev.target.value).trim();
                if (raw === "") { delete this.selEl.data[key]; return; }
                var tokenOk = this.boxSpacingBase.some(function (o) { return o.k === raw && o.k !== "" && o.k !== "custom"; });
                if (tokenOk && !(kind === "padding" && raw === "auto")) { this.selEl.data[key] = raw; return; }
                if (this.spacingRegex(kind).test(raw)) { this.selEl.data[key] = raw; return; }
                this.toast(<?php echo json_encode(__('blox_spacing_invalid'), JSON_UNESCAPED_UNICODE); ?>);
                ev.target.value = this.boxSideDisplay(kind, side);
            },

            resetBoxSpacing() {
                if (!this.selEl || !this.selEl.data) return;
                var data = this.selEl.data;
                var self = this;
                ["margin", "padding"].forEach(function (kind) {
                    delete data["style_" + kind];
                    ["top", "right", "bottom", "left"].forEach(function (side) {
                        delete data["style_" + kind + "_" + side];
                    });
                    self.boxOpen[kind] = false;
                    self.boxExactOpen[kind] = false;
                });
            },

            containerChildCount() {
                if (!this.isSelectedContainerEl()) return 0;
                return ((this.selEl.data && this.selEl.data.children) || []).length;
            },

            containerControl(key) {
                if (!this.isSelectedContainerEl()) return null;
                return (this.elSchema(this.selEl.type).controls || []).find(function (control) {
                    return control.key === key;
                }) || null;
            },

            containerControlValue(key) {
                var control = this.containerControl(key);
                return control ? this.controlValue(control) : "";
            },

            setContainerControlValue(key, value) {
                var control = this.containerControl(key);
                if (control) this.setControlValue(control, value);
            },

            containerControlState(key, device) {
                var control = this.containerControl(key);
                return control
                    ? this.controlResponsiveState(control, device)
                    : { device: "d", value: "", source: "d", overridden: false, inherited: false };
            },

            inheritContainerControl(key) {
                var control = this.containerControl(key);
                if (control) this.inheritControlValue(control);
            },

            containerHasResponsiveOverride(device) {
                var self = this;
                return ["direction", "gap", "padding"].some(function (key) {
                    return self.containerControlState(key, device).overridden;
                });
            },

            containerPreviewClass() {
                if (!this.isSelectedContainerEl()) return "";
                var d = this.selEl.data || {};
                var isDiv = this.selEl.type === "div";
                var isFlex = !isDiv || (d.display || "block") === "flex";
                var isRow = this.containerControlValue("direction") === "row";
                var cls = isFlex ? (isRow ? "flex flex-row" : "flex flex-col") : "block";
                if (isFlex) {
                    var wrap = d.wrap || "auto";
                    if (wrap === "wrap" || (wrap === "auto" && isRow)) cls += " flex-wrap";
                    else if (wrap === "nowrap") cls += " flex-nowrap";
                    cls += " " + ({none:"gap-0", sm:"gap-1", md:"gap-2", lg:"gap-4", xl:"gap-6"}[this.containerControlValue("gap")] || "gap-0");
                    cls += " " + ({stretch:"items-stretch", start:"items-start", center:"items-center", end:"items-end", baseline:"items-baseline"}[d.align || "stretch"] || "items-stretch");
                    cls += " " + ({start:"justify-start", center:"justify-center", end:"justify-end", between:"justify-between", around:"justify-around", evenly:"justify-evenly"}[d.justify || "start"] || "justify-start");
                }
                cls += " " + ({none:"", sm:"p-2", md:"p-4", lg:"p-6", xl:"p-8"}[this.containerControlValue("padding")] || "");
                cls += " " + ({none:"rounded", md:"rounded-lg", xl:"rounded-2xl"}[d.radius || "none"] || "rounded");
                return cls;
            },

            containerPreviewStyle() {
                if (!this.isSelectedContainerEl()) return "";
                var bg = (this.selEl.data && this.selEl.data.bg_color) || "#f8fafc";
                return "background:" + bg;
            },

            /** 树里显示的元素名：自定义命名 > 自己的文字 > 类型名 */
            elLabel(el) {
                if (el.name) return String(el.name);
                var d = el.data || {};
                var schema = this.elSchema(el.type);
                if (el.type === "home-block") {
                    var customLabel = String(d.label || "").trim();
                    var defaultLabel = String(((this.elSchema("home-block").defaults || {}).label) || "");
                    if (customLabel && customLabel !== "首页区块" && customLabel !== defaultLabel) return customLabel;
                    return this.homeBlockSourceLabel(el);
                }
                var txt = schema.treeLabelField ? (d[schema.treeLabelField] || "") : "";
                txt = txt || d.text || d.title || d.html || d.number || "";
                txt = String(txt).replace(/<[^>]*>/g, "").trim();
                var name = schema.label || el.type;
                return txt ? (name + "：" + (txt.length > 12 ? txt.slice(0, 12) + "…" : txt)) : name;
            },

            panelTitle() {
                if (this.selEl && this.selectedHomeColumn && !this.selectedHomeField) {
                    return this.uiText.settingsOf.replace(":t", this.selectedHomeColumnLabel());
                }
                if (this.selEl) return this.uiText.settingsOf.replace(":t", this.elSchema(this.selEl.type).label || this.uiText.elementWord);
                if (!this.sel) return this.uiText.settingsWord;
                var n = this.selectedSi + 1;
                if (this.selectedSectionField) return this.sectionFieldName(this.selectedSectionField) + this.uiText.ofSection.replace(":n", n);
                if (this.selLayer === "con") return this.uiText.containerWord + this.uiText.ofSection.replace(":n", n);
                if (this.selLayer === "col") return this.uiText.colWord.replace(":n", this.selectedCi + 1) + this.uiText.ofSection.replace(":n", n);
                return this.uiText.settingsOf.replace(":t", this.uiText.sectionWord.replace(":n", n));
            },

            sectionLabel(section, si) {
                var columns = section && Array.isArray(section.columns) ? section.columns : [];
                var elements = columns.length === 1 && Array.isArray(columns[0].elements) ? columns[0].elements : [];
                var homeData = elements.length === 1 && elements[0].type === "home-block"
                    && elements[0].data && typeof elements[0].data === "object" ? elements[0].data : null;
                var homeLabel = homeData && typeof homeData.label === "string" ? homeData.label.trim() : "";
                if (homeLabel) return homeLabel;
                var name = section && typeof section.name === "string" ? section.name.trim() : "";
                return name || this.uiText.sectionWord.replace(":n", si + 1);
            },

            isSectionFieldSelected(si, field) {
                return this.selectedSi === si && this.selectedSectionField === field
                    && this.selectedCi < 0 && this.selectedEi < 0;
            },

            selectSectionField(si, field, notifyCanvas) {
                if (!this.sections[si] || (field !== "title" && field !== "subtitle")) return;
                this.selectSection(si, false);
                this.selectedSectionField = field;
                this.panelTab = "content";
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            isElSelected(si, ci, ei) {
                return this.selectedSi === si && this.selectedCi === ci && this.selectedEi === ei
                    && this.selectedSubEi < 0;
            },

            isChildSelected(si, ci, ei, k) {
                return this.selectedSi === si && this.selectedCi === ci && this.selectedEi === ei
                    && this.selectedSubEi === k;
            },

            isColumnSelected(si, ci) {
                return this.selectedSi === si && this.selectedCi === ci && this.selectedEi < 0 && this.selLayer === "col";
            },

            selectedCol() {
                var s = this.sel;
                if (!s || this.selectedCi < 0 || !s.columns[this.selectedCi]) return null;
                return s.columns[this.selectedCi];
            },

            selectedColData() {
                return this.selectedCol() || this._emptyCol;
            },

            // 响应式跨度 {d:桌面, t:平板}：存储标量优先——仅当平板≠桌面才升级为对象，
            // 存量文档保持标量形态不被改写（黄金对拍同款纪律）。手机始终单列，无 m 轴。
            rawSpanD(col) {
                if (!col || col.span == null) return 0;
                if (typeof col.span === "object") return parseInt(col.span.d || 0, 10) || 0;
                return parseInt(col.span || 0, 10) || 0;
            },

            rawSpanT(col) {
                if (!col || col.span == null || typeof col.span !== "object") return null;
                var t = parseInt(col.span.t || 0, 10);
                return t >= 1 && t <= 12 ? t : null;
            },

            writeSpan(col, d, t) {
                d = Math.min(12, Math.max(1, parseInt(d, 10) || 12));
                t = t == null ? null : Math.min(12, Math.max(1, parseInt(t, 10) || d));
                col.span = (t === null || t === d) ? d : { d: d, t: t };
            },

            columnSpan(col) {
                var span = this.rawSpanD(col);
                if (!span) {
                    var s = this.sel;
                    span = s && s.columns && s.columns.length ? Math.floor(12 / Math.max(1, s.columns.length)) : 12;
                }
                return Math.min(12, Math.max(1, span));
            },

            columnSpanT(col) {
                return this.rawSpanT(col);
            },

            setColumnSpan(span) {
                var col = this.selectedCol();
                if (!col) return;
                this.writeSpan(col, span, this.rawSpanT(col));
            },

            // 断点可见性 hide_on = ['m','t','d'] 子集；空数组时删除键保持文档干净。
            hideOnOf(target, isCol) {
                if (!target) return [];
                var raw = isCol ? target.hide_on : (target.settings || {}).hide_on;
                return Array.isArray(raw) ? raw : [];
            },

            deviceVisible(target, key, isCol) {
                return this.hideOnOf(target, isCol).indexOf(key) === -1;
            },

            toggleDevice(target, key, isCol) {
                if (!target) return;
                var list = this.hideOnOf(target, isCol).slice();
                var i = list.indexOf(key);
                if (i === -1) list.push(key); else list.splice(i, 1);
                if (isCol) {
                    if (list.length) target.hide_on = list; else delete target.hide_on;
                } else {
                    target.settings = target.settings || {};
                    if (list.length) target.settings.hide_on = list; else delete target.settings.hide_on;
                }
            },

            conditionTarget() {
                if (this.selEl) {
                    this.selEl.data = this.selEl.data && typeof this.selEl.data === "object" ? this.selEl.data : {};
                    return this.selEl.data;
                }
                if (this.sel && !this.selectedSectionField && this.selLayer === "sec") {
                    this.sel.settings = this.sel.settings && typeof this.sel.settings === "object" ? this.sel.settings : {};
                    return this.sel.settings;
                }
                return null;
            },

            conditionGroups() {
                var target = this.conditionTarget();
                return target && Array.isArray(target._conditions) ? target._conditions : [];
            },

            defaultConditionRule() {
                return { type: "login", operator: "is", value: "logged_in" };
            },

            addConditionGroup() {
                var target = this.conditionTarget();
                if (!target) return;
                if (!Array.isArray(target._conditions)) target._conditions = [];
                if (target._conditions.length >= 10) return;
                target._conditions.push({ rules: [this.defaultConditionRule()] });
            },

            removeConditionGroup(groupIndex) {
                var target = this.conditionTarget();
                if (!target || !Array.isArray(target._conditions)) return;
                target._conditions.splice(groupIndex, 1);
                if (!target._conditions.length) delete target._conditions;
            },

            addConditionRule(groupIndex) {
                var group = this.conditionGroups()[groupIndex];
                if (!group) return;
                if (!Array.isArray(group.rules)) group.rules = [];
                if (group.rules.length < 10) group.rules.push(this.defaultConditionRule());
            },

            removeConditionRule(groupIndex, ruleIndex) {
                var group = this.conditionGroups()[groupIndex];
                if (!group || !Array.isArray(group.rules)) return;
                group.rules.splice(ruleIndex, 1);
                if (!group.rules.length) this.removeConditionGroup(groupIndex);
            },

            conditionTypeChanged(rule) {
                if (!rule) return;
                if (rule.type === "login") {
                    rule.operator = "is";
                    rule.value = "logged_in";
                } else if (rule.type === "date") {
                    rule.operator = "on";
                    var today = new Date();
                    rule.value = today.getFullYear() + "-"
                        + String(today.getMonth() + 1).padStart(2, "0") + "-"
                        + String(today.getDate()).padStart(2, "0");
                } else if (rule.type === "channel") {
                    rule.operator = "is";
                    rule.value = this.conditionChannels.length ? this.conditionChannels[0].value : "";
                } else {
                    rule.type = "url";
                    rule.operator = "contains";
                    rule.value = "/";
                }
            },

            conditionOperators(type) {
                if (type === "login") return [{ value: "is", label: this.conditionText.is }];
                if (type === "date") return [
                    { value: "before", label: this.conditionText.before },
                    { value: "on", label: this.conditionText.on },
                    { value: "after", label: this.conditionText.after },
                ];
                if (type === "channel") return [
                    { value: "is", label: this.conditionText.is },
                    { value: "is_not", label: this.conditionText.isNot },
                ];
                return [
                    { value: "equals", label: this.conditionText.equals },
                    { value: "not_equals", label: this.conditionText.notEquals },
                    { value: "contains", label: this.conditionText.contains },
                    { value: "not_contains", label: this.conditionText.notContains },
                    { value: "starts_with", label: this.conditionText.startsWith },
                ];
            },

            setColumnSpanT(t) {
                var col = this.selectedCol();
                if (!col) return;
                this.writeSpan(col, this.columnSpan(col), t === "" ? null : t);
            },

            twoColumnLeftSpan() {
                var s = this.sel;
                if (!s || !Array.isArray(s.columns) || s.columns.length !== 2) return 6;
                var left = this.columnSpan(s.columns[0]);
                var right = this.columnSpan(s.columns[1]);
                var total = left + right;
                if (total !== 12) left = Math.round((left / Math.max(1, total)) * 12);
                return Math.min(10, Math.max(2, left));
            },

            setTwoColumnDivider(span) {
                var s = this.sel;
                if (!s || !Array.isArray(s.columns) || s.columns.length !== 2) return;
                var left = Math.min(10, Math.max(2, parseInt(span, 10) || 6));
                this.writeSpan(s.columns[0], left, this.rawSpanT(s.columns[0]));
                this.writeSpan(s.columns[1], 12 - left, this.rawSpanT(s.columns[1]));
                this.highlightCanvasSelection();
            },

            applyCanvasColumnRatio(payload) {
                payload = payload || {};
                if (payload.kind === "home" && typeof payload.path === "string") {
                    this.selectPath(payload.path, false);
                    this.setAboutRatioIndex(payload.index);
                    this.highlightCanvasSelection();
                    return;
                }
                if (payload.kind !== "section") return;
                var si = parseInt(payload.si, 10);
                var section = this.sections[si];
                if (!section || !Array.isArray(section.columns) || section.columns.length < 2) return;
                var spans = Array.isArray(payload.spans) ? payload.spans.map(function (span) {
                    return parseInt(span, 10);
                }) : [];
                if (!spans.length && section.columns.length === 2) {
                    var left = Math.min(10, Math.max(2, parseInt(payload.leftSpan, 10) || 6));
                    spans = [left, 12 - left];
                }
                if (spans.length !== section.columns.length
                    || spans.some(function (span) { return !Number.isInteger(span) || span < 1 || span > 12; })
                    || spans.reduce(function (total, span) { return total + span; }, 0) > 12) return;
                var self2 = this; section.columns.forEach(function (column, index) { self2.writeSpan(column, spans[index], self2.rawSpanT(column)); });
                this.selectContainer(si, false);
                this.highlightCanvasSelection();
            },
            adjustTwoColumnDivider(delta) {
                this.setTwoColumnDivider(this.twoColumnLeftSpan() + (parseInt(delta, 10) || 0));
            },

            twoColumnRatioLabel() {
                var left = this.twoColumnLeftSpan();
                var right = 12 - left;
                function gcd(a, b) { return b ? gcd(b, a % b) : a; }
                var divisor = gcd(left, right);
                return (left / divisor) + ':' + (right / divisor);
            },

            openMobileSettings() {
                if (window.innerWidth <= 1023 && this.selectedSi >= 0) {
                    this.mobilePanel = "settings";
                    this.libOpen = false;
                }
            },
            selectElement(si, ci, ei, notifyCanvas) {
                this.selectedSi = si;
                this.selectedCi = ci;
                this.selectedEi = ei;
                this.selectedSubEi = -1;
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.resetBoxPanelState();
                this.targetCi = ci;   // 插入新元素时默认跟着当前所在列
                // 选中即进设置（Bricks 动线）；换选中项就重置面板筛选状态
                this.libOpen = false;
                this.openMobileSettings();
                var el = this.sections[si].columns[ci].elements[ei];
                this.panelTab = (el.type === "list-dynamic" || el.type === "home-block")
                    ? "content"
                    : (this.elSchema(el.type).container ? "style" : "content");
                this.ctrlQuery = "";
                this.iconPick = "";
                this.iconQuery = "";
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            selectHomeField(path, field, notifyCanvas) {
                var el = this.elementAtPath(path);
                if (!this.homeFieldAllowed(el, field)) return;
                this.selectPath(path, false);
                this.selectedHomeField = field;
                this.selectedHomeColumn = this.homeFieldGroupKey(el, field);
                this.panelTab = "content";
                this.libOpen = false;
                this.openMobileSettings();
                // 结构树点选时把焦点交给设置控件；画布点选时保留 iframe 焦点，
                // 否则双击刚开启的 contenteditable 会立即 blur 并退出。
                if (notifyCanvas !== false) {
                    this.$nextTick(function () {
                        var control = field.indexOf(".") === -1
                            ? document.querySelector('[data-control-key="' + field + '"]')
                            : document.querySelector("[data-home-field-editor]");
                        if (!control) return;
                        control.scrollIntoView({ block: "nearest" });
                        var input = control.querySelector("input,textarea,select,button");
                        if (input) input.focus({ preventScroll: true });
                    });
                }
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            selectChild(si, ci, ei, k, notifyCanvas) {
                this.selectElement(si, ci, ei, false);
                this.selectedSubEi = k;
                this.panelTab = this.isSelectedContainerEl() ? "style" : "content";
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
                else if (this.isHomeBannerHost(this.selTopEl)) this.showBannerSlide(k);
            },

            showBannerSlide(index) {
                this.canvasBridge().post({
                    ykBannerSlide: index,
                    ykBannerPath: this.selectedSi + "." + this.selectedCi + "." + this.selectedEi,
                });
            },

            moveChild(si, ci, ei, k, dir) {
                var kids = this.sections[si].columns[ci].elements[ei].data.children || [];
                var nk = k + dir;
                if (nk < 0 || nk >= kids.length) return;
                var tmp = kids[k]; kids[k] = kids[nk]; kids[nk] = tmp;
                if (this.isChildSelected(si, ci, ei, k)) this.selectedSubEi = nk;
            },

            isNavigationElementSelected() {
                return !!(this.selEl && ["nav", "nav-mega"].indexOf(this.selEl.type) !== -1);
            },

            selectedElementPosition() {
                if (!this.selTopEl) return null;
                if (this.selectedSubEi >= 0) {
                    var children = (this.selTopEl.data && this.selTopEl.data.children) || [];
                    return { kind: "child", index: this.selectedSubEi, length: children.length };
                }
                var section = this.sections[this.selectedSi];
                var column = section && section.columns ? section.columns[this.selectedCi] : null;
                return column
                    ? { kind: "element", index: this.selectedEi, length: (column.elements || []).length }
                    : null;
            },

            canMoveSelectedElement(dir) {
                var position = this.selectedElementPosition();
                if (!position) return false;
                var next = position.index + dir;
                return next >= 0 && next < position.length;
            },

            moveSelectedElement(dir) {
                if (!this.canMoveSelectedElement(dir)) return;
                var self = this;
                this.runCommand("move-selected-element", function () {
                    if (self.selectedSubEi >= 0) {
                        self.moveChild(self.selectedSi, self.selectedCi, self.selectedEi, self.selectedSubEi, dir);
                    } else {
                        self.moveElement(self.selectedSi, self.selectedCi, self.selectedEi, dir);
                    }
                });
                this.highlightCanvasSelection(false);
            },

            switchNavigationType(type) {
                if (["nav", "nav-mega"].indexOf(type) === -1 || !this.isNavigationElementSelected()) return;
                var node = this.selEl;
                if (!node || node.type === type) return;
                var self = this;
                this.runCommand("switch-navigation-type", function () {
                    var previous = node.data || {};
                    var next = JSON.parse(JSON.stringify((self.elSchema(type).defaults || {})));
                    if (Object.prototype.hasOwnProperty.call(previous, "menu_group")) {
                        next.menu_group = previous.menu_group;
                    }
                    ["_conditions", "_global_style", "_global_style_snapshot", "animation", "animation_speed", "animation_delay"].forEach(function (key) {
                        if (!Object.prototype.hasOwnProperty.call(previous, key)) return;
                        var value = previous[key];
                        next[key] = value && typeof value === "object"
                            ? JSON.parse(JSON.stringify(value))
                            : value;
                    });
                    if (type === "nav") {
                        next.dropdown = true;
                        next.desktop_only = true;
                    }
                    node.type = type;
                    node.data = next;
                    self.panelTab = "content";
                    self.ctrlQuery = "";
                    self.modifiedOnly = false;
                });
                this.highlightCanvasSelection(false);
            },

            deleteChild(si, ci, ei, k) {
                (this.sections[si].columns[ci].elements[ei].data.children || []).splice(k, 1);
                if (this.selectedSi === si && this.selectedCi === ci && this.selectedEi === ei) {
                    if (this.selectedSubEi === k) this.selectedSubEi = -1;      // 回到容器本身
                    else if (this.selectedSubEi > k) this.selectedSubEi--;
                }
            },

            /** 元素设置的可见控件：页签归属（color→样式，其余→内容）+ 搜索 + 只看已修改 */
            visibleCtrls() {
                if (!this.selEl) return [];
                if (this.panelTab === "condition") return [];
                if (this.isSelectedContainerEl() && this.panelTab === "style") return [];
                var self = this;
                var q = this.ctrlQuery.trim().toLowerCase();
                return (this.elSchema(this.selEl.type).controls || []).filter(function (c) {
                    // 页签归属：控件可在 schema 里显式标 tab（如容器的布局控件全在样式页）；
                    // 未标注的按类型推断——color 归样式，其余归内容
                    var tab = c.tab || (c.type === "color" ? "style" : "content");
                    if (tab !== self.panelTab) return false;
                    if (c.loop_only && !self.isLoopTemplateChild()) return false;
                    if (c.outside_loop_only && self.isLoopTemplateChild()) return false;
                    if (self.selEl.type === "list-dynamic" && self.hasLoopTemplate()
                        && ["show_image","image_field","show_title","title_field","show_summary","summary_field","show_date","date_field","show_meta","meta_field","link_field","summary_len","item_preset","image_ratio"].indexOf(c.key) !== -1) return false;
                    if (!self.controlRequirementMet(c)) return false;
                    if ((c.key === "animation_speed" || c.key === "animation_delay")
                        && !self.selEl.data.animation) return false;
                    if (q && String(c.label || "").toLowerCase().indexOf(q) === -1
                          && String(c.key).toLowerCase().indexOf(q) === -1) return false;
                    if (self.modifiedOnly && !self.isCtrlModified(c)) return false;
                    return true;
                });
            },

            controlRequirementMet(ctrl) {
                // r15 声明式显示规则：required（单条件兼容别名）/ visible_when（AND-OR + 8 操作符）
                // 求值器在 blox-control-rules.js（可单测纯函数）；引用存在性由 SchemaContract 测试兜底
                if (ctrl.source_kind && this.dynamicSourceKind() !== String(ctrl.source_kind)) return false;
                if (!this.selEl) return true;
                var self = this;
                return window.BloxControlRules.visibleWhenMet(ctrl, function (key) {
                    var dependency = (self.elSchema(self.selEl.type).controls || []).find(function (item) { return item.key === key; });
                    return dependency ? self.controlValue(dependency) : (self.selEl.data || {})[key];
                });
            },

            dynamicSourceKind() {
                var node = this.isLoopTemplateHost(this.selTopEl) ? this.selTopEl : this.selEl;
                var data = (node && node.data) || {};
                var source = String(data.query_source || "");
                if (!source && data.source_type) source = "type:" + String(data.source_type);
                return source === "type:product" ? "product" : "content";
            },

            controlOptions(ctrl) {
                var groups = ctrl.source_options || null;
                if (!groups) return ctrl.options || {};
                return groups[this.dynamicSourceKind()] || ctrl.options || {};
            },

            normalizeSourceControls() {
                var host = this.isLoopTemplateHost(this.selTopEl) ? this.selTopEl : this.selEl;
                if (!host || host.type !== "list-dynamic") return;
                var sourceKind = this.dynamicSourceKind();
                var self = this;
                var normalizeNode = function (node) {
                    if (!node || !node.data) return;
                    (self.elSchema(node.type).controls || []).forEach(function (ctrl) {
                        if (!ctrl.source_options) return;
                        var options = ctrl.source_options[sourceKind] || ctrl.options || {};
                        var current = node.data[ctrl.key];
                        if (current === undefined || current === null || current === "") current = ctrl.default ?? "";
                        if (!Object.prototype.hasOwnProperty.call(options, String(current))) {
                            node.data[ctrl.key] = Object.prototype.hasOwnProperty.call(options, String(ctrl.default ?? ""))
                                ? ctrl.default
                                : (Object.keys(options)[0] || "");
                        }
                    });
                };
                normalizeNode(host);
                (((host.data || {}).children) || []).forEach(normalizeNode);
            },
            controlValue(ctrl) {
                if (!this.selEl || !this.selEl.data) return ctrl.default ?? "";
                var value = this.selEl.data[ctrl.key];
                var isLegacyCtaOverlay = this.selEl.type === "home-block"
                    && String(this.selEl.data.block_type || "") === "cta"
                    && (ctrl.key === "bg_overlay_color" || ctrl.key === "bg_overlay_opacity")
                    && (value === undefined || value === null || value === "");
                if (isLegacyCtaOverlay && ctrl.key === "bg_overlay_color") {
                    value = this.selEl.data.bg_color || "#000000";
                }
                if (isLegacyCtaOverlay && ctrl.key === "bg_overlay_opacity"
                    && Object.prototype.hasOwnProperty.call(this.selEl.data, "bg_opacity")) {
                    var legacyOpacity = Math.max(0, Math.min(100, Number(this.selEl.data.bg_opacity) || 0));
                    value = this.selEl.data.bg_color ? legacyOpacity : 100 - legacyOpacity;
                }
                if ((value === undefined || value === null || value === "")
                    && this.selEl.type === "list-dynamic" && ctrl.key === "query_source" && this.selEl.data.source_type) {
                    value = "type:" + String(this.selEl.data.source_type);
                }
                value = value === undefined || value === null || value === "" ? (ctrl.default ?? "") : value;
                if (ctrl.responsive && window.BloxResponsive) {
                    return window.BloxResponsive.valueFor(
                        value,
                        this.previewDevice,
                        this.controlOptions(ctrl),
                        ctrl.default ?? ""
                    );
                }
                return value;
            },

            setControlValue(ctrl, value) {
                if (!this.selEl) return;
                var oldLabel = String((this.selEl.data || {}).label || "");
                this.selEl.data[ctrl.key] = ctrl.responsive && window.BloxResponsive
                    ? window.BloxResponsive.setFor(
                        this.selEl.data[ctrl.key],
                        this.previewDevice,
                        value,
                        this.controlOptions(ctrl),
                        ctrl.default ?? ""
                    )
                    : value;
                if (this.selEl.type === "list-dynamic" && ctrl.key === "query_source") {
                    this.normalizeSourceControls();
                }
                if (this.selEl.type === "home-block" && ctrl.key === "block_type") {
                    var defaultLabel = String(((this.elSchema("home-block").defaults || {}).label) || "");
                    if (!oldLabel || oldLabel === "首页区块" || oldLabel === defaultLabel) {
                        this.selEl.data.label = this.homeBlockSourceLabel();
                    }
                }
            },

            responsiveDeviceKey() {
                return window.BloxResponsive
                    ? window.BloxResponsive.deviceKey(this.previewDevice)
                    : ({ desktop: "d", tablet: "t", mobile: "m" }[this.previewDevice] || "d");
            },

            responsiveState(value, options, fallback, device) {
                if (!window.BloxResponsive) {
                    return { device: "d", value: fallback, source: "d", overridden: false, inherited: false };
                }
                return window.BloxResponsive.stateFor(
                    value,
                    device || this.previewDevice,
                    options,
                    fallback
                );
            },

            responsiveStatusText(state) {
                if (!state || state.device === "d") return "";
                if (state.overridden) return this.responsiveText.override;
                return state.source === "t"
                    ? this.responsiveText.inheritsTablet
                    : this.responsiveText.inheritsDesktop;
            },

            controlResponsiveState(ctrl, device) {
                var value = this.selEl && this.selEl.data
                    ? this.selEl.data[ctrl.key]
                    : (ctrl.default ?? "");
                value = value === undefined || value === null || value === "" ? (ctrl.default ?? "") : value;
                return this.responsiveState(
                    value,
                    this.controlOptions(ctrl),
                    ctrl.default ?? "",
                    device
                );
            },

            inheritControlValue(ctrl) {
                if (!this.selEl || !ctrl.responsive || !window.BloxResponsive) return;
                this.selEl.data[ctrl.key] = window.BloxResponsive.inheritFor(
                    this.selEl.data[ctrl.key],
                    this.previewDevice,
                    this.controlOptions(ctrl),
                    ctrl.default ?? ""
                );
            },

            sectionResponsiveValue(key, fallback) {
                if (!this.sel || !window.BloxResponsive) return fallback;
                var options = key === "padding"
                    ? { none: true, sm: true, md: true, lg: true, xl: true }
                    : { none: true, sm: true, md: true, lg: true, xl: true };
                return window.BloxResponsive.valueFor(
                    this.sel.settings[key], this.previewDevice, options, fallback
                );
            },

            setSectionResponsiveValue(key, value, fallback) {
                if (!this.sel || !window.BloxResponsive) return;
                var options = { none: true, sm: true, md: true, lg: true, xl: true };
                this.sel.settings[key] = window.BloxResponsive.setFor(
                    this.sel.settings[key], this.previewDevice, value, options, fallback
                );
            },

            sectionResponsiveState(key, fallback, device) {
                if (!this.sel) return this.responsiveState(fallback, {}, fallback, device);
                return this.responsiveState(
                    this.sel.settings[key],
                    { none: true, sm: true, md: true, lg: true, xl: true },
                    fallback,
                    device
                );
            },

            inheritSectionResponsiveValue(key, fallback) {
                if (!this.sel || !window.BloxResponsive) return;
                this.sel.settings[key] = window.BloxResponsive.inheritFor(
                    this.sel.settings[key],
                    this.previewDevice,
                    { none: true, sm: true, md: true, lg: true, xl: true },
                    fallback
                );
            },

            accordionItems(el) {
                var node = el || this.selEl;
                if (!node || node.type !== "accordion") return [];
                var control = (this.elSchema("accordion").controls || []).find(function (item) {
                    return item.key === "items";
                }) || {};
                var data = node.data && typeof node.data === "object" ? node.data : {};
                var value = Object.prototype.hasOwnProperty.call(data, "items") ? data.items : control.default;
                return window.BloxHomeFieldStore.parseAccordionItems(value, control.max || 30);
            },

            storeAccordionItems(items) {
                if (!this.selEl || this.selEl.type !== "accordion") return;
                var control = (this.elSchema("accordion").controls || []).find(function (item) {
                    return item.key === "items";
                }) || {};
                this.selEl.data = this.selEl.data && typeof this.selEl.data === "object" ? this.selEl.data : {};
                this.selEl.data.items = window.BloxHomeFieldStore.parseAccordionItems(items, control.max || 30);
            },

            setAccordionItem(index, field, value) {
                if (["question", "answer"].indexOf(String(field)) === -1) return;
                var items = this.accordionItems();
                var position = Number(index);
                if (!Number.isInteger(position) || position < 0 || position >= items.length) return;
                items[position][field] = String(value ?? "");
                this.storeAccordionItems(items);
            },

            addAccordionItem() {
                var items = this.accordionItems();
                var max = 30;
                var control = (this.elSchema("accordion").controls || []).find(function (item) {
                    return item.key === "items";
                });
                if (control) max = Math.max(1, Math.min(30, Number(control.max) || 30));
                if (items.length >= max) {
                    this.toast(String(this.homeDynamicText.faqLimit || "").replace(":n", String(max)), "error");
                    return;
                }
                items.push({
                    question: this.homeDynamicText.faqNewQuestion,
                    answer: this.homeDynamicText.faqNewAnswer,
                });
                this.storeAccordionItems(items);
                this.highlightCanvasSelection(false);
            },

            deleteAccordionItem(index) {
                var items = this.accordionItems();
                var position = Number(index);
                if (!Number.isInteger(position) || position < 0 || position >= items.length) return;
                items.splice(position, 1);
                this.storeAccordionItems(items);
                this.highlightCanvasSelection(false);
            },

            accordionItemCanMove(index, delta) {
                var position = Number(index);
                var target = position + Number(delta);
                var count = this.accordionItems().length;
                return Number.isInteger(position) && Number.isInteger(target)
                    && position >= 0 && position < count && target >= 0 && target < count;
            },

            moveAccordionItem(index, delta) {
                if (!this.accordionItemCanMove(index, delta)) return;
                var position = Number(index);
                this.storeAccordionItems(window.BloxHomeFieldStore.moveItem(
                    this.accordionItems(),
                    position,
                    position + Number(delta)
                ));
                this.highlightCanvasSelection(false);
            },

            orgNodes(el) {
                var node = el || this.selEl;
                if (!node || node.type !== "org-chart") return [];
                var control = (this.elSchema("org-chart").controls || []).find(function (item) {
                    return item.key === "nodes";
                }) || {};
                var source = Array.isArray((node.data || {}).nodes) ? node.data.nodes : (control.default || []);
                var nodes = [], ids = {};
                source.slice(0, Math.max(1, Number(control.max) || 100)).forEach(function (item, index) {
                    if (!item || typeof item !== "object") return;
                    var id = String(item.id || "").trim();
                    if (!/^[a-zA-Z0-9_-]{1,64}$/.test(id) || ids[id]) id = "org_" + (index + 1);
                    while (ids[id]) id += "_x";
                    ids[id] = true;
                    nodes.push({
                        id: id,
                        parent_id: String(item.parent_id || "").trim(),
                        name: String(item.name || ""),
                        title: String(item.title || ""),
                    });
                });
                var accepted = {};
                nodes.forEach(function (item, index) {
                    if (index === 0) item.parent_id = "";
                    else if (!accepted[item.parent_id] || item.parent_id === item.id) item.parent_id = nodes[0].id;
                    accepted[item.id] = true;
                });
                return nodes;
            },

            storeOrgNodes(nodes) {
                if (!this.selEl || this.selEl.type !== "org-chart") return;
                this.selEl.data = this.selEl.data && typeof this.selEl.data === "object" ? this.selEl.data : {};
                this.selEl.data.nodes = Array.isArray(nodes) ? nodes : [];
            },

            orgNodeDepth(index, nodes) {
                var items = nodes || this.orgNodes();
                var node = items[Number(index)];
                if (!node) return 0;
                var byId = {}, depth = 0, seen = {};
                items.forEach(function (item) { byId[item.id] = item; });
                while (node.parent_id && byId[node.parent_id] && !seen[node.parent_id] && depth < 12) {
                    seen[node.parent_id] = true;
                    node = byId[node.parent_id];
                    depth++;
                }
                return depth;
            },

            orgNodeLevelText(index) {
                var depth = this.orgNodeDepth(index);
                return depth === 0 ? this.orgText.root : this.orgText.level.replace(":n", String(depth + 1));
            },

            setOrgNode(index, field, value) {
                if (["name", "title"].indexOf(String(field)) === -1) return;
                var nodes = this.orgNodes(), position = Number(index);
                if (!Number.isInteger(position) || !nodes[position]) return;
                nodes[position][field] = String(value == null ? "" : value);
                this.storeOrgNodes(nodes);
            },

            orgSubtreeEnd(index, nodes) {
                var items = nodes || this.orgNodes();
                var start = Number(index), depth = this.orgNodeDepth(start, items), end = start + 1;
                while (end < items.length && this.orgNodeDepth(end, items) > depth) end++;
                return end;
            },

            addOrgNode(index, relation) {
                var nodes = this.orgNodes(), position = Number(index);
                var control = (this.elSchema("org-chart").controls || []).find(function (item) {
                    return item.key === "nodes";
                }) || {};
                var max = Math.max(1, Math.min(100, Number(control.max) || 100));
                if (!Number.isInteger(position) || !nodes[position] || nodes.length >= max) {
                    if (nodes.length >= max) this.toast(this.orgText.limit.replace(":n", String(max)), "error");
                    return;
                }
                if (relation === "sibling" && position === 0) return;
                var parentId = relation === "sibling" ? nodes[position].parent_id : nodes[position].id;
                var insertAt = this.orgSubtreeEnd(position, nodes);
                nodes.splice(insertAt, 0, {
                    id: this.uid("org"),
                    parent_id: parentId,
                    name: this.orgText.newName,
                    title: this.orgText.newTitle,
                });
                this.storeOrgNodes(nodes);
                this.highlightCanvasSelection(false);
            },

            deleteOrgNode(index) {
                var nodes = this.orgNodes(), position = Number(index);
                if (!Number.isInteger(position) || position <= 0 || !nodes[position]) return;
                if (!window.confirm(this.orgText.deleteConfirm)) return;
                nodes.splice(position, this.orgSubtreeEnd(position, nodes) - position);
                this.storeOrgNodes(nodes);
                this.highlightCanvasSelection(false);
            },

            orgSiblingPositions(index, nodes) {
                var items = nodes || this.orgNodes(), node = items[Number(index)];
                if (!node) return [];
                return items.map(function (item, position) {
                    return item.parent_id === node.parent_id ? position : -1;
                }).filter(function (position) { return position >= 0; });
            },

            orgNodeCanMove(index, delta) {
                var siblings = this.orgSiblingPositions(index), at = siblings.indexOf(Number(index));
                return at >= 0 && at + Number(delta) >= 0 && at + Number(delta) < siblings.length;
            },

            moveOrgNode(index, delta) {
                var nodes = this.orgNodes(), position = Number(index), direction = Number(delta);
                if (!this.orgNodeCanMove(position, direction)) return;
                var siblings = this.orgSiblingPositions(position, nodes), at = siblings.indexOf(position);
                var end = this.orgSubtreeEnd(position, nodes);
                var block = nodes.splice(position, end - position);
                if (direction < 0) {
                    nodes.splice(siblings[at - 1], 0, ...block);
                } else {
                    var nextStart = siblings[at + 1] - block.length;
                    nodes.splice(this.orgSubtreeEnd(nextStart, nodes), 0, ...block);
                }
                this.storeOrgNodes(nodes);
                this.highlightCanvasSelection(false);
            },

            homeBlockSourceControl() {
                if (!this.selEl || this.selEl.type !== "home-block") return null;
                return (this.elSchema("home-block").controls || []).find(function (ctrl) {
                    return ctrl.key === "block_type";
                }) || null;
            },

            homeBlockSourceLabel(el) {
                var node = el || this.selEl;
                if (!node || node.type !== "home-block") return "";
                var ctrl = this.homeBlockSourceControl();
                var type = String((node.data || {}).block_type || "banner");
                return String(((ctrl && ctrl.options) || {})[type] || type);
            },

            homeBlockSourceIcon() {
                var type = String(((this.selEl || {}).data || {}).block_type || "");
                if (type === "banner") return "photo";
                if (type.indexOf("channel:") === 0) return "layout-grid";
                if (type === "product_categories") return "category";
                return "database";
            },

            homeControlLabel(key) {
                var control = (this.elSchema("home-block").controls || []).find(function (item) {
                    return item.key === key;
                });
                return control ? control.label : key;
            },

            homeAboutLayoutLabel(el) {
                var control = (this.elSchema("home-block").controls || []).find(function (item) {
                    return item.key === "override_layout";
                });
                var layout = String(((el || {}).data || {}).override_layout || "text_left");
                return String(((control || {}).options || {})[layout] || "");
            },

            homeFieldBlueprint(el) {
                var type = String((((el || {}).data) || {}).block_type || "");
                return this.homeEditorBlueprints[type] || {};
            },

            aboutColumnCards() {
                var cards = [
                    { key: "text", label: this.homeDynamicText.aboutTextColumn, icon: "align-left" },
                    { key: "image", label: this.homeDynamicText.aboutImageColumn, icon: "photo" },
                ];
                return String((((this.selEl || {}).data) || {}).override_layout || "text_left") === "image_left"
                    ? cards.reverse()
                    : cards;
            },

            swapAboutColumns() {
                if (!this.selEl || this.selEl.type !== "home-block") return;
                var data = this.selEl.data || {};
                if (String(data.block_type || "") !== "about") return;
                data.override_layout = String(data.override_layout || "text_left") === "image_left"
                    ? "text_left"
                    : "image_left";
                this.toast(this.homeDynamicText.swapColumns);
                this.highlightCanvasSelection();
            },

            aboutRatioValue() {
                var value = String(((((this.selEl || {}).data) || {}).override_ratio) || "1_1");
                return this.aboutRatioOptions.some(function (option) { return option.value === value; })
                    ? value
                    : "1_1";
            },

            setAboutRatio(value) {
                if (!this.selEl || this.selEl.type !== "home-block") return;
                var allowed = this.aboutRatioOptions.some(function (option) { return option.value === value; });
                this.selEl.data.override_ratio = allowed ? value : "1_1";
            },

            aboutRatioIndex() {
                var value = this.aboutRatioValue();
                var index = this.aboutRatioOptions.findIndex(function (option) { return option.value === value; });
                return index >= 0 ? index : 2;
            },

            setAboutRatioIndex(index) {
                var safeIndex = Math.min(this.aboutRatioOptions.length - 1, Math.max(0, parseInt(index, 10) || 0));
                this.setAboutRatio(this.aboutRatioOptions[safeIndex].value);
            },

            adjustAboutRatio(delta) {
                this.setAboutRatioIndex(this.aboutRatioIndex() + (parseInt(delta, 10) || 0));
            },

            aboutRatioPreviewSpans(ratio) {
                var spans = Array.isArray(ratio.spans) ? ratio.spans.slice(0, 2) : [1, 1];
                return String((((this.selEl || {}).data) || {}).override_layout || "text_left") === "image_left"
                    ? spans.reverse()
                    : spans;
            },

            homeColumnProjection(el) {
                if (!el || el.type !== "home-block") return null;
                var projection = this.homeFieldBlueprint(el).projection || null;
                return projection && String(projection.type || "") === "columns" ? projection : null;
            },

            isProjectedHomeColumnsSection(section) {
                if (!section || !Array.isArray(section.columns) || section.columns.length !== 1) return false;
                var elements = Array.isArray(section.columns[0].elements) ? section.columns[0].elements : [];
                return elements.length === 1 && !!this.homeColumnProjection(elements[0]);
            },

            projectedHomeElement(section) {
                return this.isProjectedHomeColumnsSection(section) ? section.columns[0].elements[0] : null;
            },

            selectedHomeColumnLabel() {
                if (!this.selEl || !this.selectedHomeColumn) return "";
                var group = this.homeFieldGroups(this.selEl).find(function (candidate) {
                    return String(candidate.key || "") === this.selectedHomeColumn;
                }, this);
                return group ? String(group.displayLabel || group.label || "") : "";
            },

            homeColumnAllowed(el, column) {
                var key = String(column || "");
                return key !== "" && this.homeFieldGroups(el).some(function (group) {
                    return String(group.key || "") === key;
                });
            },

            homeFieldGroupKey(el, field) {
                var key = String(field || "");
                var group = this.homeFieldGroups(el).find(function (candidate) {
                    return (candidate.fields || []).some(function (item) {
                        return String(item.key || "") === key;
                    });
                });
                return group ? String(group.key || "") : "";
            },

            selectHomeColumn(path, column, notifyCanvas) {
                var el = this.elementAtPath(path);
                if (!this.homeColumnAllowed(el, column)) return;
                this.selectPath(path, false);
                this.selectedHomeColumn = String(column);
                this.selectedHomeField = "";
                this.panelTab = "content";
                this.libOpen = false;
                this.openMobileSettings();
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            selectProjectedHomeColumn(si, group) {
                this.selectHomeColumn(si + ".0.0", String((group || {}).key || ""), true);
            },

            isProjectedHomeGroupSelected(si, group) {
                return this.isElSelected(si, 0, 0)
                    && this.selectedHomeColumn === String((group || {}).key || "");
            },

            homeGroupSpanLabel(el, group) {
                var projection = this.homeColumnProjection(el);
                if (!projection) return "";
                var ratioKey = String(projection.ratio_key || "");
                var defaultRatio = String(projection.default_ratio || "");
                var data = ((el || {}).data) || {};
                var ratio = String(data[ratioKey] || defaultRatio);
                var ratios = projection.ratios || {};
                var spans = ratios[ratio] || ratios[defaultRatio] || {};
                var span = parseInt(spans[String((group || {}).key || "")], 10);
                return Math.max(1, Math.min(12, span || 12)) + "/12";
            },

            homeFieldGroups(el) {
                var blueprint = this.homeFieldBlueprint(el);
                var groups = [];
                (blueprint.groups || []).forEach(function (source) {
                    if (source.columnRepeaterKey || source.tree === false) return;
                    var repeat = Math.max(1, Math.min(12, Number(source.repeat) || 1));
                    for (var index = 0; index < repeat; index++) {
                        var fields = (source.fields || []).map(function (field) {
                            var copy = Object.assign({}, field);
                            copy.key = String(copy.key || "").replace("{index}", String(index));
                            return copy;
                        });
                        groups.push({
                            key: String(source.key || "group") + (repeat > 1 ? "-" + index : ""),
                            label: String(source.label || ""),
                            displayLabel: repeat > 1 ? String(source.label || "") + " " + (index + 1) : String(source.label || ""),
                            icon: String(source.icon || "box"),
                            numbered: !!source.numbered,
                            repeated: repeat > 1,
                            fields: fields,
                        });
                    }
                });
                var self = this;
                this.customColumnRepeaters(el).forEach(function (repeater) {
                    self.customColumnItems(el, repeater).forEach(function (item, index) {
                        groups.push({
                            key: String(repeater.key || "custom-columns") + "-" + index,
                            label: self.customColumnLabel(item, index),
                            displayLabel: self.customColumnLabel(item, index),
                            icon: String(repeater.icon || "layout-cards"),
                            fields: self.customColumnFields(repeater, item, index),
                            columnRepeaterKey: String(repeater.key || ""),
                            columnItemIndex: index,
                        });
                    });
                });
                this.customFaqRepeaters(el).forEach(function (repeater) {
                    self.customFaqItems(el, repeater).forEach(function (item, index) {
                        var fields = (repeater.fields || []).map(function (field) {
                            return {
                                key: String(repeater.items_key || "") + "." + index + "." + String(field.suffix || ""),
                                icon: String(field.icon || "box"),
                                label: String(field.label || ""),
                                control: String(field.control || "text"),
                            };
                        });
                        groups.push({
                            key: String(repeater.key || "custom-faq") + "-" + index,
                            label: String(item.question || self.homeDynamicText.faqNewQuestion),
                            displayLabel: String(item.question || self.homeDynamicText.faqNewQuestion),
                            icon: String(repeater.icon || "help-circle"),
                            fields: fields,
                            faqRepeaterKey: String(repeater.key || ""),
                            faqItemIndex: index,
                        });
                    });
                });
                if (blueprint.reverse_key
                    && String((((el || {}).data) || {})[blueprint.reverse_key] || "") === String(blueprint.reverse_value || "")) {
                    groups.reverse();
                }
                groups.forEach(function (group, index) {
                    if (group.numbered) group.displayLabel = (index + 1) + " · " + group.label;
                    group.fields.forEach(function (field) { field.groupLabel = group.displayLabel; });
                });
                return groups;
            },

            customColumnRepeaters(el) {
                if (!this.isCustomHomeBlock(el)) return [];
                var repeaters = this.homeFieldBlueprint(el).column_repeaters || [];
                return Array.isArray(repeaters) ? repeaters : [];
            },

            customColumnRepeater(el, key) {
                return this.customColumnRepeaters(el).find(function (repeater) {
                    return String(repeater.key || "") === String(key || "");
                }) || null;
            },

            customColumnIsCustomized(el, repeater) {
                this.homeFieldRevision;
                if (!el || !repeater) return false;
                return this.homeFieldStoredValue(el, String(repeater.mode_key || "")).value === "custom";
            },

            customColumnItems(el, repeater) {
                this.homeFieldRevision;
                if (!el || !repeater) return [];
                var seed = this.homeFieldSeedValue(el, String(repeater.items_key || ""));
                var stored = this.homeFieldStoredValue(el, String(repeater.items_key || ""));
                return window.BloxHomeFieldStore.structuralItems(
                    seed,
                    stored.found ? stored.value : [],
                    this.customColumnIsCustomized(el, repeater),
                    repeater.max
                );
            },

            customColumnLabel(item, index) {
                var elements = Array.isArray((item || {}).elements) ? item.elements : [];
                var heading = elements.find(function (element) {
                    return String((element || {}).type || "") === "heading"
                        && String((((element || {}).data || {}).text || "")).trim() !== "";
                });
                if (heading) return String((heading.data || {}).text || "").replace(/<[^>]*>/g, "").trim().slice(0, 40);
                return String(this.homeDynamicText.customColumn || "").replace(":n", String(index + 1));
            },

            customColumnFields(repeater, item, index) {
                var base = String(repeater.items_key || "") + "." + index;
                var fields = [{
                    key: base + ".card_bg",
                    icon: "palette",
                    label: this.homeDynamicText.customCardBackground,
                    control: "color",
                }];
                var self = this;
                (Array.isArray((item || {}).elements) ? item.elements : []).forEach(function (element, elementIndex) {
                    var prefix = base + ".elements." + elementIndex + ".data.";
                    var type = String((element || {}).type || "");
                    if (type === "heading") {
                        fields.push({ key: prefix + "text", icon: "heading", label: self.homeDynamicText.customTitle, control: "text" });
                    } else if (type === "text") {
                        var html = String((((element || {}).data || {}).html || ""));
                        var plain = html.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
                        fields.push({
                            key: prefix + "html",
                            icon: "align-left",
                            label: plain ? plain.slice(0, 28) : self.homeDynamicText.customBody,
                            control: "richtext",
                        });
                    } else if (type === "button") {
                        fields.push({ key: prefix + "text", icon: "click", label: self.homeDynamicText.customButtonText, control: "text" });
                        fields.push({ key: prefix + "url", icon: "link", label: self.homeDynamicText.customLinkUrl, control: "url" });
                    }
                });
                return fields;
            },

            materializeCustomColumns(el, repeater) {
                if (!el || !repeater) return [];
                var items = this.customColumnItems(el, repeater);
                var type = String(((el || {}).data || {}).block_type || "");
                var seeds = this.homeFieldSeeds[type] || {};
                window.BloxHomeFieldStore.setValue(el, String(repeater.items_key || ""), items, seeds);
                window.BloxHomeFieldStore.setValue(el, String(repeater.mode_key || ""), "custom", seeds);
                return items;
            },

            storeCustomColumns(repeater, items, selectedIndex) {
                var type = String((this.selEl.data || {}).block_type || "");
                window.BloxHomeFieldStore.setValue(
                    this.selEl,
                    String(repeater.items_key || ""),
                    items,
                    this.homeFieldSeeds[type] || {}
                );
                this.homeFieldRevision++;
                this.selectedHomeField = "";
                this.selectedHomeColumn = items.length
                    ? String(repeater.key || "") + "-" + Math.max(0, Math.min(selectedIndex, items.length - 1))
                    : "";
                this.panelTab = "content";
                this.highlightCanvasSelection(false);
            },

            duplicateCustomColumn(group, repeaterKey) {
                if (!this.selEl) return;
                var repeater = this.customColumnRepeater(this.selEl, repeaterKey || (group || {}).columnRepeaterKey);
                if (!repeater) return;
                var max = Math.max(2, Math.min(12, Number(repeater.max) || 12));
                var current = this.customColumnItems(this.selEl, repeater);
                if (current.length >= max) {
                    this.toast(String(this.homeDynamicText.planLimit || "").replace(":n", String(max)), "error");
                    return;
                }
                var items = this.materializeCustomColumns(this.selEl, repeater);
                var sourceIndex = Number((group || {}).columnItemIndex);
                if (!Number.isInteger(sourceIndex) || sourceIndex < 0 || sourceIndex >= items.length) {
                    sourceIndex = Math.max(0, items.length - 1);
                }
                var copy = JSON.parse(JSON.stringify(items[sourceIndex] || { elements: [] }));
                items.splice(sourceIndex + 1, 0, copy);
                this.storeCustomColumns(repeater, items, sourceIndex + 1);
            },

            deleteCustomColumn(group) {
                if (!this.selEl || !group) return;
                var repeater = this.customColumnRepeater(this.selEl, group.columnRepeaterKey);
                if (!repeater) return;
                var items = this.materializeCustomColumns(this.selEl, repeater);
                if (items.length <= 1) {
                    this.toast(this.homeDynamicText.planMinimum, "error");
                    return;
                }
                var index = Number(group.columnItemIndex);
                if (!Number.isInteger(index) || index < 0 || index >= items.length) return;
                items.splice(index, 1);
                this.storeCustomColumns(repeater, items, Math.min(index, items.length - 1));
            },

            customColumnCanMove(group, delta) {
                if (!this.selEl || !group) return false;
                var repeater = this.customColumnRepeater(this.selEl, group.columnRepeaterKey);
                if (!repeater) return false;
                var index = Number(group.columnItemIndex);
                var target = index + Number(delta);
                var count = this.customColumnItems(this.selEl, repeater).length;
                return Number.isInteger(index) && Number.isInteger(target)
                    && target !== index && index >= 0 && index < count && target >= 0 && target < count;
            },

            moveCustomColumn(group, delta) {
                if (!this.customColumnCanMove(group, delta)) return;
                var repeater = this.customColumnRepeater(this.selEl, group.columnRepeaterKey);
                if (!repeater) return;
                var fromIndex = Number(group.columnItemIndex);
                var toIndex = fromIndex + Number(delta);
                var items = window.BloxHomeFieldStore.moveItem(
                    this.materializeCustomColumns(this.selEl, repeater),
                    fromIndex,
                    toIndex
                );
                this.storeCustomColumns(repeater, items, toIndex);
            },

            restoreCustomColumns(repeaterKey) {
                if (!this.selEl || !confirm(this.homeDynamicText.planRestoreConfirm)) return;
                var repeater = this.customColumnRepeater(this.selEl, repeaterKey);
                if (!repeater) return;
                var changed = window.BloxHomeFieldStore.deleteValue(this.selEl, String(repeater.mode_key || ""));
                changed = window.BloxHomeFieldStore.deleteValue(this.selEl, String(repeater.items_key || "")) || changed;
                if (!changed) return;
                this.homeFieldRevision++;
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.highlightCanvasSelection(false);
            },

            customFaqRepeaters(el) {
                if (!this.isCustomHomeBlock(el)) return [];
                var repeaters = this.homeFieldBlueprint(el).repeaters || [];
                return Array.isArray(repeaters) ? repeaters.filter(function (repeater) {
                    return String((repeater || {}).key || "").indexOf("custom-faq-") === 0;
                }) : [];
            },

            customFaqRepeater(el, key) {
                return this.customFaqRepeaters(el).find(function (repeater) {
                    return String(repeater.key || "") === String(key || "");
                }) || null;
            },

            customFaqIsCustomized(el, repeater) {
                this.homeFieldRevision;
                if (!el || !repeater) return false;
                return this.homeFieldStoredValue(el, String(repeater.mode_key || "")).value === "custom";
            },

            customFaqItems(el, repeater) {
                this.homeFieldRevision;
                if (!el || !repeater) return [];
                var seed = this.homeFieldSeedValue(el, String(repeater.items_key || ""));
                var stored = this.homeFieldStoredValue(el, String(repeater.items_key || ""));
                return window.BloxHomeFieldStore.faqItems(
                    seed,
                    stored.found ? stored.value : [],
                    this.customFaqIsCustomized(el, repeater),
                    repeater.max
                );
            },

            materializeCustomFaq(el, repeater) {
                if (!el || !repeater) return [];
                var items = this.customFaqItems(el, repeater);
                var type = String(((el || {}).data || {}).block_type || "");
                var seeds = this.homeFieldSeeds[type] || {};
                window.BloxHomeFieldStore.setValue(el, String(repeater.items_key || ""), items, seeds);
                window.BloxHomeFieldStore.setValue(el, String(repeater.mode_key || ""), "custom", seeds);
                return items;
            },

            addCustomFaqItem(repeaterKey) {
                if (!this.selEl) return;
                var repeater = this.customFaqRepeater(this.selEl, repeaterKey);
                if (!repeater) return;
                var max = Math.max(1, Math.min(30, Number(repeater.max) || 30));
                var items = this.customFaqItems(this.selEl, repeater);
                if (items.length >= max) {
                    this.toast(String(this.homeDynamicText.faqLimit || "").replace(":n", String(max)), "error");
                    return;
                }
                items = this.materializeCustomFaq(this.selEl, repeater);
                items.push({
                    question: this.homeDynamicText.faqNewQuestion,
                    answer: this.homeDynamicText.faqNewAnswer,
                });
                var type = String((this.selEl.data || {}).block_type || "");
                window.BloxHomeFieldStore.setValue(
                    this.selEl,
                    String(repeater.items_key || ""),
                    items,
                    this.homeFieldSeeds[type] || {}
                );
                this.homeFieldRevision++;
                var index = items.length - 1;
                this.selectedHomeColumn = String(repeater.key || "") + "-" + index;
                this.selectedHomeField = String(repeater.items_key || "") + "." + index + ".question";
                this.panelTab = "content";
                this.highlightCanvasSelection(true);
            },

            deleteCustomFaqItem(group) {
                if (!this.selEl || !group) return;
                var repeater = this.customFaqRepeater(this.selEl, group.faqRepeaterKey);
                if (!repeater) return;
                var items = this.materializeCustomFaq(this.selEl, repeater);
                var index = Number(group.faqItemIndex);
                if (!Number.isInteger(index) || index < 0 || index >= items.length) return;
                items.splice(index, 1);
                var type = String((this.selEl.data || {}).block_type || "");
                window.BloxHomeFieldStore.setValue(
                    this.selEl,
                    String(repeater.items_key || ""),
                    items,
                    this.homeFieldSeeds[type] || {}
                );
                this.homeFieldRevision++;
                this.selectedHomeField = "";
                this.selectedHomeColumn = items.length
                    ? String(repeater.key || "") + "-" + Math.min(index, items.length - 1)
                    : "";
                this.highlightCanvasSelection(false);
            },

            customFaqCanMove(group, delta) {
                if (!this.selEl || !group) return false;
                var repeater = this.customFaqRepeater(this.selEl, group.faqRepeaterKey);
                if (!repeater) return false;
                var index = Number(group.faqItemIndex);
                var target = index + Number(delta);
                var count = this.customFaqItems(this.selEl, repeater).length;
                return Number.isInteger(index) && Number.isInteger(target)
                    && target !== index && index >= 0 && index < count && target >= 0 && target < count;
            },

            moveCustomFaqItem(group, delta) {
                if (!this.customFaqCanMove(group, delta)) return;
                var repeater = this.customFaqRepeater(this.selEl, group.faqRepeaterKey);
                if (!repeater) return;
                var fromIndex = Number(group.faqItemIndex);
                var toIndex = fromIndex + Number(delta);
                var items = window.BloxHomeFieldStore.moveItem(
                    this.materializeCustomFaq(this.selEl, repeater),
                    fromIndex,
                    toIndex
                );
                var type = String((this.selEl.data || {}).block_type || "");
                window.BloxHomeFieldStore.setValue(
                    this.selEl,
                    String(repeater.items_key || ""),
                    items,
                    this.homeFieldSeeds[type] || {}
                );
                this.homeFieldRevision++;
                this.selectedHomeField = "";
                this.selectedHomeColumn = String(repeater.key || "") + "-" + toIndex;
                this.panelTab = "content";
                this.highlightCanvasSelection(false);
            },

            restoreCustomFaq(repeaterKey) {
                if (!this.selEl || !confirm(this.homeDynamicText.faqRestoreConfirm)) return;
                var repeater = this.customFaqRepeater(this.selEl, repeaterKey);
                if (!repeater) return;
                var changed = window.BloxHomeFieldStore.deleteValue(this.selEl, String(repeater.mode_key || ""));
                changed = window.BloxHomeFieldStore.deleteValue(this.selEl, String(repeater.items_key || "")) || changed;
                if (!changed) return;
                this.homeFieldRevision++;
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.highlightCanvasSelection(false);
            },

            selectedCustomStructuralField() {
                if (!this.selEl || !this.selectedHomeField) return false;
                var faq = this.customFaqRepeaters(this.selEl).some(function (repeater) {
                    return this.customFaqIsCustomized(this.selEl, repeater)
                        && this.selectedHomeField.indexOf(String(repeater.items_key || "") + ".") === 0;
                }, this);
                return faq || this.customColumnRepeaters(this.selEl).some(function (repeater) {
                    return this.customColumnIsCustomized(this.selEl, repeater)
                        && this.selectedHomeField.indexOf(String(repeater.items_key || "") + ".") === 0;
                }, this);
            },

            isCustomHomeBlock(el) {
                return !!(el && el.type === "home-block"
                    && String(((el || {}).data || {}).block_type || "").indexOf("custom:") === 0);
            },

            customHomeColumnGroups(el) {
                return this.isCustomHomeBlock(el) ? this.homeFieldGroups(el).filter(function (group) {
                    return String(group.key || "").indexOf("custom-") === 0;
                }) : [];
            },

            selectCustomHomeGroup(group) {
                var path = this.selectedPath();
                if (!path || !this.selEl || !group) return;
                this.selectHomeColumn(path, String(group.key || ""), false);
            },

            homeFieldGroupOpen(group) {
                return !group.repeated || (group.fields || []).some(function (field) {
                    return field.key === this.selectedHomeField;
                }, this);
            },

            homeFieldDefinition(el, field) {
                var found = null;
                this.homeFieldGroups(el).some(function (group) {
                    return (group.fields || []).some(function (candidate) {
                        if (candidate.key !== field) return false;
                        found = Object.assign({}, candidate, { groupLabel: group.displayLabel });
                        return true;
                    });
                });
                return found;
            },

            homeFieldAllowed(el, field) {
                return !!(el && el.type === "home-block" && this.homeFieldDefinition(el, String(field || "")));
            },

            selectedHomeFieldDefinition() {
                return this.selectedHomeField && this.selEl
                    ? this.homeFieldDefinition(this.selEl, this.selectedHomeField)
                    : null;
            },

            homeFieldStoredValue(el, field) {
                var cursor = ((el || {}).data) || {};
                var parts = String(field || "").split(".");
                for (var index = 0; index < parts.length; index++) {
                    var part = parts[index];
                    if (cursor === null || typeof cursor !== "object"
                        || !Object.prototype.hasOwnProperty.call(cursor, part)) {
                        return { found: false, value: "" };
                    }
                    cursor = cursor[part];
                }
                return { found: true, value: cursor ?? "" };
            },

            homeFieldSeedValue(el, field) {
                var type = String((((el || {}).data) || {}).block_type || "");
                var cursor = this.homeFieldSeeds[type] || {};
                var parts = String(field || "").split(".");
                for (var index = 0; index < parts.length; index++) {
                    var part = parts[index];
                    if (cursor === null || typeof cursor !== "object"
                        || !Object.prototype.hasOwnProperty.call(cursor, part)) return "";
                    cursor = cursor[part];
                }
                return cursor ?? "";
            },

            homeFieldValue(el, field) {
                var stored = this.homeFieldStoredValue(el, field);
                return stored.found ? stored.value : this.homeFieldSeedValue(el, field);
            },

            selectedHomeFieldValue() {
                this.homeFieldRevision;
                return this.selEl && this.selectedHomeField
                    ? this.homeFieldValue(this.selEl, this.selectedHomeField)
                    : "";
            },

            selectedHomeFieldInherited() {
                this.homeFieldRevision;
                return !!(this.selEl && this.selectedHomeField
                    && !this.homeFieldStoredValue(this.selEl, this.selectedHomeField).found);
            },

            setHomeFieldValue(el, field, value) {
                if (!this.homeFieldAllowed(el, field)) return;
                var type = String((((el || {}).data) || {}).block_type || "");
                if (window.BloxHomeFieldStore.setValue(el, field, value, this.homeFieldSeeds[type] || {})) {
                    this.homeFieldRevision++;
                }
            },

            setSelectedHomeFieldValue(value) {
                if (this.selEl && this.selectedHomeField) {
                    this.setHomeFieldValue(this.selEl, this.selectedHomeField, value);
                }
            },

            resetSelectedCustomHomeField() {
                if (!this.selEl || this.selectedHomeField.indexOf("custom_overrides.") !== 0) return;
                if (window.BloxHomeFieldStore.deleteValue(this.selEl, this.selectedHomeField)) {
                    this.homeFieldRevision++;
                }
            },

            homeBlockSummary() {
                if (!this.selEl || this.selEl.type !== "home-block") return "";
                var data = this.selEl.data || {};
                var type = String(data.block_type || "");
                var parts = [];
                if (type === "banner" || type.indexOf("channel:") === 0) {
                    parts.push(this.homeDynamicText.limit + "：" + ((Number(data.limit) || 0) > 0 ? Number(data.limit) : this.homeDynamicText.inherit));
                }
                if (type.indexOf("channel:") === 0) {
                    var sortCtrl = (this.elSchema("home-block").controls || []).find(function (ctrl) { return ctrl.key === "sort"; });
                    parts.push(this.homeDynamicText.sort + "：" + String(((sortCtrl && sortCtrl.options) || {})[data.sort || "inherit"] || this.homeDynamicText.inherit));
                    parts.push(this.homeDynamicText.columns + "：" + ((Number(data.per_row) || 0) > 0 ? Number(data.per_row) : this.homeDynamicText.inherit));
                }
                return parts.join(" · ");
            },

            /** 与注册表默认值对比（undefined/null 一律按空串归一，避免「没设过」误报已修改） */
            isCtrlModified(c) {
                var d = (this.selEl && this.selEl.data) || {};
                var defs = this.elSchema(this.selEl.type).defaults || {};
                var norm = function (v) { return (v === undefined || v === null) ? "" : v; };
                return JSON.stringify(norm(d[c.key])) !== JSON.stringify(norm(defs[c.key]));
            },

            historyData() {
                return JSON.stringify(this.sections);
            },

            historySettingsData() {
                return JSON.stringify(this.docSettings || {});
            },

            documentData() {
                return JSON.stringify({ schema: 1, settings: this.docSettings || {}, sections: this.sections || [] });
            },

            draftRecovery() {
                if (this._draftRecovery) return this._draftRecovery;
                var Recovery = window.BloxDraftRecovery && window.BloxDraftRecovery.DraftRecovery;
                if (typeof Recovery !== "function") return null;
                this._draftRecovery = new Recovery({
                    storage: window.localStorage,
                    key: this.recoveryKey,
                    delay: 1200,
                    maxBytes: 2000000,
                });
                return this._draftRecovery;
            },

            initDraftRecovery() {
                var recovery = this.draftRecovery();
                if (!recovery) return;
                var snapshot = recovery.read(this._savedDocumentSnapshot);
                if (!snapshot) return;
                this.recoveryDraft = snapshot;
                this.recoveryOpen = true;
                this.focusDialog(this.$refs.recoveryDialog, "[data-dialog-initial]");
            },

            queueDraftRecovery() {
                if (!this._ready) return;
                var recovery = this.draftRecovery();
                if (!recovery) return;
                var data = this.documentData();
                if (data === this._savedDocumentSnapshot) {
                    recovery.clear();
                    return;
                }
                recovery.queue(data, this.baseRevision);
            },

            markDocumentSettingsChanged() {
                if (this._ready && !this._historyApplying) this.queueHistory(this.historyData());
                this.dirty = this.documentData() !== this._savedDocumentSnapshot;
                this.queueDraftRecovery();
            },

            restoreRecovery() {
                if (!this.recoveryDraft || typeof this.recoveryDraft.data !== "string") return;
                try {
                    var document = JSON.parse(this.recoveryDraft.data);
                    if (!document || !Array.isArray(document.sections)) throw new Error("invalid recovery document");
                    this.docSettings = document.settings && typeof document.settings === "object" ? document.settings : {};
                    this.normalizeHeaderSettings();
                    this.sections = document.sections;
                    this.normalizeIds();
                    if (this.recoveryDraft.baseRevision) this.baseRevision = this.recoveryDraft.baseRevision;
                    this.recoveryOpen = false;
                    this.recoveryDraft = null;
                    this.dirty = this.documentData() !== this._savedDocumentSnapshot;
                    this.queueDraftRecovery();
                    this.releaseDialog(this.$refs.recoveryDialog);
                } catch (_) {
                    this.discardRecovery();
                }
            },

            discardRecovery() {
                var recovery = this.draftRecovery();
                if (recovery) recovery.clear();
                this.recoveryDraft = null;
                this.recoveryOpen = false;
                this.releaseDialog(this.$refs.recoveryDialog);
            },

            showSaveConflict() {
                this.queueDraftRecovery();
                var recovery = this.draftRecovery();
                if (recovery) recovery.flush();
                this.conflictOpen = true;
                this.focusDialog(this.$refs.conflictDialog, "[data-dialog-initial]");
            },

            continueAfterConflict() {
                if (!this.conflictOpen) return;
                var root = this.$refs.conflictDialog;
                this.conflictOpen = false;
                this.releaseDialog(root);
            },

            reloadAfterConflict() {
                var recovery = this.draftRecovery();
                if (recovery) recovery.flush();
                this.dirty = false;
                window.location.reload();
            },

            copyConflictDocument() {
                var self = this;
                var data = this.documentData();
                var copied = function () { self.toast(self.recoveryText.copied); };
                var failed = function () { self.toast(self.recoveryText.copyFailed); };
                if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
                    navigator.clipboard.writeText(data).then(copied).catch(failed);
                    return;
                }
                try {
                    var field = document.createElement("textarea");
                    field.value = data;
                    field.style.position = "fixed";
                    field.style.opacity = "0";
                    document.body.appendChild(field);
                    field.select();
                    var ok = document.execCommand("copy");
                    field.remove();
                    if (ok) copied(); else failed();
                } catch (_) { failed(); }
            },

            historyStructure() {
                var nodeShape = function (el) {
                    return {
                        id: String((el && el.id) || ""),
                        type: String((el && el.type) || ""),
                        children: ((((el || {}).data || {}).children) || []).map(nodeShape),
                    };
                };
                return JSON.stringify((this.sections || []).map(function (section) {
                    return {
                        id: String(section.id || ""),
                        type: String(section.type || ""),
                        columns: (section.columns || []).map(function (column) {
                            return {
                                id: String(column.id || ""),
                                span: (typeof column.span === "object" && column.span ? parseInt(column.span.d || 0, 10) : parseInt(column.span || 0, 10)) || 0,
                                elements: (column.elements || []).map(nodeShape),
                            };
                        }),
                    };
                }));
            },

            historySelection() {
                return {
                    selectedSi: this.selectedSi,
                    selectedCi: this.selectedCi,
                    selectedEi: this.selectedEi,
                    selectedSubEi: this.selectedSubEi,
                    selectedSectionField: this.selectedSectionField,
                    selectedHomeField: this.selectedHomeField,
                    selectedHomeColumn: this.selectedHomeColumn,
                    selLayer: this.selLayer,
                    targetCi: this.targetCi,
                    panelTab: this.panelTab,
                    libOpen: this.libOpen,
                };
            },

            historyStore() {
                if (this._historyStore) return this._historyStore;
                var self = this;
                this._historyStore = new window.BloxHistoryStore({
                    limit: 51,
                    delay: 700,
                    getData: function () { return self.historyData(); },
                    getSettings: function () { return self.historySettingsData(); },
                    getStructure: function () { return self.historyStructure(); },
                    getSelection: function () { return self.historySelection(); },
                    isApplying: function () { return self._historyApplying; },
                });
                return this._historyStore;
            },

            initHistory() {
                var initial = this.historyStore().init();
                this._savedSnapshot = initial.data;
                this._savedDocumentSnapshot = this.documentData();
            },

            queueHistory(data) {
                this.historyStore().queue(data);
            },

            flushHistory(captureCurrent) {
                this.historyStore().flush(captureCurrent === true);
            },

            canUndo() {
                return this._historyStore ? this._historyStore.canUndo() : false;
            },

            canRedo() {
                return this._historyStore ? this._historyStore.canRedo() : false;
            },

            restoreHistorySelection(selection) {
                selection = selection || {};
                this.selectedSi = parseInt(selection.selectedSi, 10);
                this.selectedCi = parseInt(selection.selectedCi, 10);
                this.selectedEi = parseInt(selection.selectedEi, 10);
                this.selectedSubEi = parseInt(selection.selectedSubEi, 10);
                this.selectedSectionField = selection.selectedSectionField === "title" || selection.selectedSectionField === "subtitle"
                    ? selection.selectedSectionField : "";
                this.selectedHomeField = typeof selection.selectedHomeField === "string"
                    ? selection.selectedHomeField : "";
                this.selectedHomeColumn = typeof selection.selectedHomeColumn === "string"
                    ? selection.selectedHomeColumn : "";
                this.selLayer = ["sec", "con", "col"].indexOf(selection.selLayer) >= 0 ? selection.selLayer : "sec";
                this.targetCi = Math.max(0, parseInt(selection.targetCi, 10) || 0);
                this.panelTab = ["content", "style", "condition"].indexOf(selection.panelTab) >= 0
                    ? selection.panelTab : "content";
                this.libOpen = !!selection.libOpen;

                var section = this.sections[this.selectedSi];
                if (!section) {
                    this.selectedSi = -1;
                    this.selectedCi = -1;
                    this.selectedEi = -1;
                    this.selectedSubEi = -1;
                    this.selectedSectionField = "";
                    this.selectedHomeField = "";
                    this.selectedHomeColumn = "";
                    return;
                }
                var column = this.selectedCi >= 0 ? (section.columns || [])[this.selectedCi] : null;
                if (this.selectedCi >= 0 && !column) {
                    this.selectedCi = -1;
                    this.selectedEi = -1;
                    this.selectedSubEi = -1;
                }
                var element = column && this.selectedEi >= 0 ? (column.elements || [])[this.selectedEi] : null;
                if (this.selectedEi >= 0 && !element) {
                    this.selectedEi = -1;
                    this.selectedSubEi = -1;
                }
                var children = element ? ((((element || {}).data || {}).children) || []) : [];
                if (this.selectedSubEi >= 0 && !children[this.selectedSubEi]) this.selectedSubEi = -1;
                if (this.selectedHomeField && !this.homeFieldAllowed(element, this.selectedHomeField)) {
                    this.selectedHomeField = "";
                }
                if (this.selectedHomeField) {
                    this.selectedHomeColumn = this.homeFieldGroupKey(element, this.selectedHomeField);
                } else if (this.selectedHomeColumn && !this.homeColumnAllowed(element, this.selectedHomeColumn)) {
                    this.selectedHomeColumn = "";
                }
                this.targetCi = Math.min(this.targetCi, Math.max(0, (section.columns || []).length - 1));
            },

            /**
             * 薄命令层（r11）：结构命令统一走 runCommand——mutate 中途抛异常时
             * 恢复执行前快照（复用 applyHistorySnapshot 协议，回滚不产生新历史），
             * 半改的树绝不留给 watcher 入历史/预览。多步修改合并为一个历史项由
             * Alpine watcher 每 tick 合并天然保证，命令层不重复做。
             */
            commandRunner() {
                if (this._commandRunner) return this._commandRunner;
                var self = this;
                this._commandRunner = new window.BloxCommandRunner({
                    capture: function () {
                        return self.historyStore().snapshot(self.historyData());
                    },
                    restore: function (snapshot) {
                        self.applyHistorySnapshot(snapshot);
                    },
                    onError: function (name, error) {
                        self.toast(self.homeText.actionFailed);
                        if (window.console && console.error) console.error("[blox command] " + name, error);
                    },
                });
                return this._commandRunner;
            },

            runCommand(name, fn) {
                var self = this;
                return this.commandRunner().execute(name, function () { return fn.call(self); });
            },

            applyHistorySnapshot(snapshot) {
                if (!snapshot) return;
                this._historyApplying = true;
                var currentSelection = this.historySelection();
                this.sections = JSON.parse(snapshot.data);
                if (typeof snapshot.settings === "string") {
                    var settings = JSON.parse(snapshot.settings);
                    this.docSettings = settings && typeof settings === "object" ? settings : {};
                    this.normalizeHeaderSettings();
                }
                this.restoreHistorySelection(snapshot.selection);
                // The first history entry can predate any selection. Keep the current
                // section selected when it still exists so Alpine never tears down an
                // open settings panel against a transient null `sel`.
                if (this.selectedSi < 0 && this.sections[currentSelection.selectedSi]) {
                    this.restoreHistorySelection(currentSelection);
                }
                this.dirty = this.documentData() !== this._savedDocumentSnapshot;
                this.closeCtx();
                var self = this;
                this.$nextTick(function () {
                    self._historyApplying = false;
                    self.highlightCanvasSelection(false);
                });
            },

            undo() {
                var snapshot = this.historyStore().undo();
                if (!snapshot) return;
                this.applyHistorySnapshot(snapshot);
                this.toast(this.historyText.undoDone);
            },

            redo() {
                var snapshot = this.historyStore().redo();
                if (!snapshot) return;
                this.applyHistorySnapshot(snapshot);
                this.toast(this.historyText.redoDone);
            },

            moveElement(si, ci, ei, dir) {
                var els = this.sections[si].columns[ci].elements;
                var ni = ei + dir;
                if (ni < 0 || ni >= els.length) return;
                var tmp = els[ei]; els[ei] = els[ni]; els[ni] = tmp;
                if (this.isElSelected(si, ci, ei)) this.selectedEi = ni;
            },

            deleteElement(si, ci, ei) { return this.runCommand("delete-element", function () { return this._deleteElementRaw(si, ci, ei); }); },
            _deleteElementRaw(si, ci, ei) {
                var el = this.sections[si].columns[ci].elements[ei];
                var kids = (el && el.data && el.data.children) ? el.data.children.length : 0;
                if (kids > 0 && !confirm(this.uiText.confirmDeleteContainer.replace(":count", kids))) return;
                this.sections[si].columns[ci].elements.splice(ei, 1);
                // 选中项要跟着修正，否则设置面板会指向已删除或错位的元素
                if (this.selectedSi === si && this.selectedCi === ci) {
                    if (this.selectedEi === ei) {
                        this.selectedCi = -1;
                        this.selectedEi = -1;
                        this.selectedSubEi = -1;
                        this.selectedHomeField = "";
                        this.selectedHomeColumn = "";
                    } else if (this.selectedEi > ei) {
                        this.selectedEi--;
                    }
                }
            },

            init() {
                var self = this;
                this.normalizeHeaderSettings();
                // 先归一化 id 再渲染：老数据（排版编辑器早期格式）可能缺 id 或 id 重复，
                // x-for 的 :key 遇到 undefined/重复会让 Alpine 崩掉、结构树整个不渲染
                this.normalizeIds();
                this.initHistory();
                this.initDraftRecovery();
                this.$nextTick(function() {
                    self.observeCanvasHost();
                    self.refreshPreview();
                    self.initTreeSortable();
                    self._ready = true;
                    if (self.initialPanel === "design") self.openDesignSystem();
                    else if (self.initialPanel === "templates") self.openTemplates();
                });
                window.addEventListener("resize", function () { self.canvasViewportTick++; });
                // 未保存离开守卫：dirty 时关闭/刷新标签页要过浏览器确认
                window.addEventListener("beforeunload", function (e) {
                    if (self.dirty || self.contactCardsChanged || self.contactFormChanged) { e.preventDefault(); e.returnValue = ""; }
                });
                window.addEventListener("pagehide", function () {
                    if (self._draftRecovery) self._draftRecovery.dispose(self.dirty);
                    if (self._previewClient) self._previewClient.cancel();
                    if (self._canvasBridge) self._canvasBridge.dispose();
                    if (self._historyStore) self._historyStore.dispose();
                });
                window.addEventListener("keydown", function (e) {
                    if (e.key === "Escape" && self.ctx.open) { e.preventDefault(); self.closeCtx(); return; }
                    if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
                    var activeEditor = window.tinymce && tinymce.activeEditor;
                    if (activeEditor && typeof activeEditor.hasFocus === "function" && activeEditor.hasFocus()) return;
                    var active = document.activeElement;
                    if (active && (active.tagName === "INPUT" || active.tagName === "TEXTAREA" || active.tagName === "SELECT" || active.isContentEditable)) return;
                    var key = String(e.key || "").toLowerCase();
                    if (key === "z") {
                        e.preventDefault();
                        if (e.shiftKey) self.redo(); else self.undo();
                    } else if (key === "y" && !e.shiftKey) {
                        e.preventDefault();
                        self.redo();
                    } else if (!e.shiftKey && key === "c" && self.hasClipboardSelection()) {
                        e.preventDefault();
                        self.copySelection();
                    } else if (!e.shiftKey && key === "x" && self.hasClipboardSelection()) {
                        e.preventDefault();
                        self.cutSelection();
                    } else if (!e.shiftKey && key === "v" && self.clipboard) {
                        e.preventDefault();
                        self.pasteSelection();
                    }
                });
                // 数据变更 → 与保存基线比较、记录历史、重渲染画布并重绑结构树拖拽
                this.$watch("sections", function() {
                    self._insertAt = null; // 定点插入覆盖位一次性生效
                    var currentData = self.historyData();
                    if (self._ready) {
                        self.dirty = self.documentData() !== self._savedDocumentSnapshot;
                        if (!self._historyApplying) self.queueHistory(currentData);
                        self.queueDraftRecovery();
                    }
                    self.schedulePreview();
                    self.scheduleTreeSortable();
                });
                this.canvasBridge().start();
                this.postDragRules();
            },

            // 画布拖放禁用态所需的嵌套规则表：容器 allowedChildren + 容器/通用子元素标记。
            postDragRules() {
                var rules = { containers: {}, isContainer: {}, generic: {} };
                var meta = this.elementSchemas || {};
                Object.keys(meta).forEach(function (type) {
                    var m = meta[type] || {};
                    if (m.container) rules.containers[type] = Array.isArray(m.allowedChildren) ? m.allowedChildren : [];
                    rules.isContainer[type] = !!m.container;
                    rules.generic[type] = m.genericChild !== false;
                });
                // JSON 脱壳：elementSchemas 是 Alpine Proxy，其数组引用不可 structured-clone
                // （Sortable 需 Alpine.raw() 是同一族问题）。
                this.canvasBridge().post(JSON.parse(JSON.stringify({ ykDragRules: rules })));
            },

            canvasBridge() {
                if (this._canvasBridge) return this._canvasBridge;
                var self = this;
                this._canvasBridge = new window.BloxCanvasBridge({
                    getFrame: function () { return self.$refs.canvas; },
                    onColumnRatio: function (payload) { self.applyCanvasColumnRatio(payload); },
                    onContext: function (payload) { self.openCtxFromCanvas(payload); },
                    onDrop: function (payload) { self.handleCanvasDrop(payload); },
                    onInlineEdit: function (payload) { self.applyInlineEdit(payload); },
                    onEditSectionField: function (payload) { self.editSectionField(payload.si, payload.field); },
                    onPickSectionField: function (payload) { self.selectSectionField(payload.si, payload.field, false); },
                    onPickHomeColumn: function (payload) { self.selectHomeColumn(payload.path, payload.column, false); },
                    onPickHomeField: function (payload) { self.selectHomeField(payload.path, payload.field, false); },
                    onPickElement: function (path) { self.selectPath(path, false); },
                    onEditElement: function (path) { self.selectPath(path, false); self.quickEditSelected(); },
                    onPickColumn: function (si, ci) { self.selectColumn(si, ci, false); },
                    onPickContainer: function (si) { self.selectContainer(si, false); },
                    onPickSection: function (si) { self.selectSection(si, false); },
                    onClear: function () { self.deselectAll(); },
                    onAreaHit: function (id) { self.ctxHit = id; },
                    onEditArea: function (payload) { window.location.assign(payload.url); },
                    // 画布空态双入口：模板库起步 / 空白区块起步
                    onEmptyAction: function (action) {
                        if (action === "templates") {
                            self.openTemplates();
                            return;
                        }
                        self.deselectAll();
                        self.addSection(1);
                    },
                    // 空列/空容器就地「+」（r18）：只负责定位并打开既有元素库，
                    // 插入仍走 addElement/Validator/历史命令，不建立第二条写入路径。
                    onQuickAdd: function (payload) { self.openElementLibraryAt(payload); },
                    // 画布插入轨道（r13）：边界/末尾「+」的定点插入
                    onInsertAt: function (payload) { self.insertAtBoundary(payload); },
                    // 具名拒因（r14）：无效落点松手不再静默，toast 说明原因
                    onDropRejected: function (reason) {
                        if (reason === "restricted-children") { self.toast(self.uiText.dropRestricted); return; }
                        self.toast(self.uiText.noNestedContainer);
                    },
                });
                return this._canvasBridge;
            },

            openElementLibraryAt(payload) {
                payload = payload || {};
                if (payload.kind === "column") {
                    var section = this.sections[payload.sec];
                    if (!section || !section.columns || !section.columns[payload.col]) return;
                    this.selectColumn(payload.sec, payload.col, false);
                } else if (payload.kind === "container") {
                    var host = this.elementAtPath(payload.path);
                    if (!host || !this.elSchema(host.type).container) return;
                    this.selectPath(payload.path, false);
                } else {
                    return;
                }
                this.libQuery = "";
                this.libOpen = true;
                if (window.innerWidth <= 1023) this.mobilePanel = "library";
                var self = this;
                this.$nextTick(function () {
                    if (self.$refs.libSearch) self.$refs.libSearch.focus();
                });
            },

            /** 头尾模板：切换预览上下文（首页/单页/栏目）。重建预览客户端换 endpoint，立即刷新。 */
            ctxChanged() {
                var base = "<?php echo $previewEndpoint; ?>";
                this.previewEndpoint = this.previewContext === "home"
                    ? base
                    : base + "&preview_context=" + encodeURIComponent(this.previewContext);
                this.ctxHit = null; // 旧命中作废，等新画布上报
                if (this._previewClient) {
                    this._previewClient.cancel(); // 丢弃在途请求，防旧上下文响应覆盖
                    this._previewClient = null;
                }
                this.schedulePreview();
            },

            /**
             * 画布插入轨道：在指定 section 边界定点插入。
             * _insertAt 覆盖 insertIndex()，既有插入函数（addSection/insertTemplate）
             * 零改动获得定点能力；覆盖位在下一次文档变化（watcher）自动失效——
             * layout 立即消费；templates 打开面板等用户选中模板时消费，中途取消
             * 后从侧栏再加则回默认位置语义。
             */
            insertAtBoundary(payload) {
                this._insertAt = payload.index;
                if (payload.kind === "templates") {
                    this.openTemplates();
                    return;
                }
                var spans = payload.kind === "layout" && Array.isArray(payload.spans) ? payload.spans : 1;
                this.addSection(spans);
            },

            /**
             * 画布选中区块另存为区块模板（r14）：客户端只发该 section 的 JSON 与名称，
             * 服务端组包走 Importer 安全链并发布——模板库刷新后立即可插回。
             */
            saveSectionAsTemplate(si) {
                var sec = this.sections[si];
                if (!sec) return;
                var name = window.prompt(this.templateText.saveAsPrompt, "");
                if (name === null) return;
                name = name.trim();
                if (name === "") { this.toast(this.templateText.saveAsNameRequired); return; }
                var self = this;
                var body = new URLSearchParams();
                body.set("action", "save_section");
                body.set("name", name);
                body.set("section", JSON.stringify(sec));
                body.set("_token", this.csrf);
                fetch("/admin/blox_template_api.php", { method: "POST", body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && Number(res.code) === 0) {
                            var item = res.data && res.data.template;
                            if (item) {
                                self.templateItems = window.BloxTemplateLibrary.upsertLocal(self.templateItems, item);
                                self.templateLoaded = true;
                                self.templateScope = "local";
                                self.templateFilter = "section";
                                self.templateQuery = "";
                                self.templateError = "";
                            }
                            self.toast(self.templateText.saveAsDone.replace(":name", name));
                            self.loadTemplates(true); // 后台对账；请求占用时登记为待刷新，不再丢弃
                        } else {
                            self.toast((res && (res.message || res.msg)) || self.homeText.actionFailed);
                        }
                    })
                    .catch(function () { self.toast(self.homeText.actionFailed); });
            },

            /** 面包屑项：从选择状态派生（不存 DOM/索引副本，重排、undo 后天然指向当前树） */
            breadcrumb() {
                var items = [];
                var si = this.selectedSi;
                var sec = si >= 0 ? this.sections[si] : null;
                if (!sec) return items;
                items.push({ act: "sec", label: <?= $jt('blox_section_word') ?>.replace(":n", si + 1) });
                if (this.selLayer === "con") {
                    items.push({ act: "con", label: <?= $jt('blox_el_container') ?> });
                    return items;
                }
                if (this.selectedCi < 0) return items;
                items.push({ act: "col", label: <?= $jt('blox_col_word') ?>.replace(":n", this.selectedCi + 1) });
                var col = (sec.columns || [])[this.selectedCi];
                var el = col && this.selectedEi >= 0 ? (col.elements || [])[this.selectedEi] : null;
                if (!el) return items;
                items.push({ act: "el", label: this.elLabel(el) });
                var child = this.selectedSubEi >= 0 ? (((el.data || {}).children || [])[this.selectedSubEi]) : null;
                if (child) items.push({ act: "child", label: this.elLabel(child) });
                return items;
            },

            crumbGo(c) {
                var si = this.selectedSi, ci = this.selectedCi, ei = this.selectedEi;
                if (c.act === "sec") this.selectSection(si, false);
                else if (c.act === "con") this.selectContainer(si, false);
                else if (c.act === "col") this.selectColumn(si, ci, false);
                else if (c.act === "el") this.selectElement(si, ci, ei, false);
                // child 是叶节点（当前项），无动作
            },

            /** 取消全部选择（点画布空白/宿主空白触发）——回到「插入到末尾」的初始语义。 */
            deselectAll() {
                this.selectedSi = -1;
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.selectedSubEi = -1;
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.selLayer = "sec";
                this.highlightCanvasSelection();
            },

            handleCanvasDrop(payload) { return this.runCommand("canvas-drop", function () { return this._handleCanvasDropRaw(payload); }); },
            _handleCanvasDropRaw(payload) {
                var lib = this.elementLib.find(function (item) { return item.type === payload.type; });
                this.dragEl = null;
                if (!lib) return;
                if (lib.type === "__section") {
                    this.selectSection(payload.sec, false);
                    this.addSection(1);
                    return;
                }
                var target = payload.target && payload.target.kind
                    ? payload.target
                    : { kind: "column", sec: payload.sec, col: payload.col, position: "end" };
                this.addElement(lib, target);
            },

            schedulePreview() {
                this.previewClient().schedule();
            },

            previewClient() {
                if (this._previewClient) return this._previewClient;
                var self = this;
                this._previewClient = new window.BloxPreviewClient({
                    endpoint: this.previewEndpoint,
                    csrf: this.csrf,
                    getFrame: function () { return self.$refs.canvas; },
                    getHost: function () { return self.$refs.canvasHost; },
                    getDocument: function () { return JSON.parse(self.documentData()); },
                    getParams: function () {
                        return self.headerTemplateMode ? { header_state: self.headerPreviewState } : {};
                    },
                    setLoading: function (loading) { self.previewLoading = loading; },
                    onLoaded: function () { self.highlightCanvasSelection(false); },
                    onError: function () { self.toast(self.uiText.previewFailed); },
                });
                return this._previewClient;
            },

            previewWidth() {
                if (this.previewDevice === "desktop") return this.previewDesktopWidth() + "px";
                return ({ tablet: "768px", mobile: "390px" })[this.previewDevice] || "1280px";
            },

            observeCanvasHost() {
                var self = this;
                var host = this.$refs.canvasHost;
                if (!host) return;
                var update = function () { self.canvasViewportTick++; };
                update();
                requestAnimationFrame(function () {
                    update();
                    requestAnimationFrame(update);
                });
                if (typeof ResizeObserver === "function") {
                    if (this._canvasResizeObserver) this._canvasResizeObserver.disconnect();
                    this._canvasResizeObserver = new ResizeObserver(update);
                    this._canvasResizeObserver.observe(host);
                }
            },

            previewCanvasAvailable() {
                this.canvasViewportTick;
                var host = this.$refs.canvasHost;
                return Math.max(320, (host ? host.clientWidth : 1280) - 24);
            },

            previewDesktopWidth() {
                return Math.max(1280, Math.round(this.previewCanvasAvailable()));
            },

            previewScale() {
                if (this.previewDevice !== "desktop") return 1;
                return Math.min(1, this.previewCanvasAvailable() / this.previewDesktopWidth());
            },

            previewShellStyle() {
                var visualHeight = "calc(100vh - 5rem)";
                if (this.previewDevice !== "desktop") {
                    return "width:" + this.previewWidth() + ";height:" + visualHeight + ";max-width:100%;overflow:hidden";
                }
                var desktopWidth = this.previewDesktopWidth();
                return "width:" + Math.round(desktopWidth * this.previewScale()) + "px;height:" + visualHeight
                    + ";max-width:100%;overflow:hidden";
            },

            previewFrameStyle() {
                if (this.previewDevice !== "desktop") {
                    return "width:100%;height:100%;transform:none";
                }
                var scale = this.previewScale();
                var desktopWidth = this.previewDesktopWidth();
                var visualHeight = Math.max(480, window.innerHeight - 80);
                return "width:" + desktopWidth + "px;height:" + Math.round(visualHeight / scale) + "px;zoom:" + scale
                    + ";transform:none";
            },

            refreshPreview() {
                return this.previewClient().refresh();
            },

            setHeaderPreviewState(state) {
                if (["normal", "overlay", "stuck"].indexOf(state) === -1) return;
                this.headerPreviewState = state;
                this.refreshPreview();
            },

            headerStateOpacity() {
                var states = this.docSettings && this.docSettings.header_states;
                var current = states && states[this.headerPreviewState] ? states[this.headerPreviewState] : {};
                var color = String(current.background || "").trim().toLowerCase();
                if (color === "transparent") return 0;
                var functional = color.match(/^(?:rgba|hsla)\([^)]*,\s*([0-9.]+)\s*\)$/i);
                if (functional) return Math.max(0, Math.min(100, Math.round(Number(functional[1]) * 100)));
                if (/^#[0-9a-f]{4}$/i.test(color)) return Math.round(parseInt(color.slice(4, 5) + color.slice(4, 5), 16) / 255 * 100);
                if (/^#[0-9a-f]{8}$/i.test(color)) return Math.round(parseInt(color.slice(7, 9), 16) / 255 * 100);
                return 100;
            },

            setHeaderStateOpacity(value) {
                this.normalizeHeaderSettings();
                var opacity = Math.max(0, Math.min(100, Number(value) || 0));
                var alpha = Math.round(opacity) / 100;
                var state = this.docSettings.header_states[this.headerPreviewState];
                var color = String(state.background || "").trim();
                var rgb = null;
                var hex = color.match(/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i);
                if (hex) {
                    var digits = hex[1];
                    if (digits.length === 3 || digits.length === 4) {
                        rgb = [0, 1, 2].map(function (index) {
                            return parseInt(digits[index] + digits[index], 16);
                        });
                    } else {
                        rgb = [0, 2, 4].map(function (index) {
                            return parseInt(digits.slice(index, index + 2), 16);
                        });
                    }
                }
                var rgbFunction = color.match(/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)/i);
                if (!rgb && rgbFunction) {
                    rgb = [1, 2, 3].map(function (index) {
                        return Math.max(0, Math.min(255, Math.round(Number(rgbFunction[index]))));
                    });
                }
                var hslFunction = color.match(/^hsla?\(\s*([0-9.+-]+)\s*,\s*([0-9.+-]+%)\s*,\s*([0-9.+-]+%)/i);
                if (hslFunction) {
                    state.background = "hsla(" + hslFunction[1] + "," + hslFunction[2] + "," + hslFunction[3] + "," + alpha + ")";
                } else {
                    rgb = rgb || [255, 255, 255];
                    state.background = "rgba(" + rgb[0] + "," + rgb[1] + "," + rgb[2] + "," + alpha + ")";
                }
                this.markDocumentSettingsChanged();
                this.refreshPreview();
            },

            normalizeHeaderSettings() {
                if (!this.headerTemplateMode) return;
                var defaults = {
                    normal: { background: "", text: "", border: "", shadow: "none" },
                    overlay: { background: "transparent", text: "#ffffff", border: "rgba(255,255,255,.18)", shadow: "none" },
                    stuck: { background: "#ffffff", text: "#111827", border: "#e5e7eb", shadow: "sm" },
                };
                this.docSettings = this.docSettings && typeof this.docSettings === "object" ? this.docSettings : {};
                this.docSettings.sticky_behavior = ["always", "scroll-up"].indexOf(this.docSettings.sticky_behavior) >= 0
                    ? this.docSettings.sticky_behavior : "always";
                var stickyDevices = Array.isArray(this.docSettings.sticky_devices)
                    ? this.docSettings.sticky_devices.filter(function (device) {
                        return device === "desktop" || device === "tablet" || device === "mobile";
                    }) : ["desktop", "tablet", "mobile"];
                this.docSettings.sticky_devices = stickyDevices.length > 0
                    ? Array.from(new Set(stickyDevices)) : ["desktop", "tablet", "mobile"];
                if (!Object.prototype.hasOwnProperty.call(this.docSettings, "header_overlay_enabled")) {
                    this.docSettings.header_overlay_enabled = true;
                }
                var states = this.docSettings.header_states && typeof this.docSettings.header_states === "object"
                    ? this.docSettings.header_states : {};
                Object.keys(defaults).forEach(function (state) {
                    states[state] = Object.assign({}, defaults[state], states[state] || {});
                });
                this.docSettings.header_states = states;
            },

            stickyDeviceEnabled(device) {
                return Array.isArray(this.docSettings.sticky_devices)
                    && this.docSettings.sticky_devices.indexOf(device) >= 0;
            },

            toggleStickyDevice(device, enabled) {
                if (["desktop", "tablet", "mobile"].indexOf(device) === -1) return;
                var devices = Array.isArray(this.docSettings.sticky_devices)
                    ? this.docSettings.sticky_devices.slice() : ["desktop", "tablet", "mobile"];
                var index = devices.indexOf(device);
                if (enabled && index === -1) devices.push(device);
                if (!enabled && index >= 0 && devices.length > 1) devices.splice(index, 1);
                this.docSettings.sticky_devices = ["desktop", "tablet", "mobile"].filter(function (item) {
                    return devices.indexOf(item) >= 0;
                });
                this.markDocumentSettingsChanged();
                this.refreshPreview();
            },

            addContactCard() {
                if (this.contactCards.length >= 4) {
                    this.toast(this.contactCardsText.limit);
                    return;
                }
                this.contactCards.push({ _key: this.uid("contact-card"), icon: "phone", label: "", value: "" });
                this.contactCardsChanged = true;
            },

            contactCardIcon(value) {
                var option = this.contactCardIconOptions.find(function (item) { return item.value === value; });
                return option ? option.icon : "ban";
            },

            removeContactCard(index) {
                if (!this.contactCards[index]) return;
                this.contactCards.splice(index, 1);
                this.contactCardsChanged = true;
            },

            moveContactCard(index, offset) {
                var next = index + offset;
                if (!this.contactCards[index] || next < 0 || next >= this.contactCards.length) return;
                var card = this.contactCards.splice(index, 1)[0];
                this.contactCards.splice(next, 0, card);
                this.contactCardsChanged = true;
            },

            pickContactCardImage(card) {
                var self = this;
                this.openMedia(function (url) {
                    card.value = url;
                    self.contactCardsChanged = true;
                });
            },

            saveContactCards() {
                if (this.contactCardsSaving || !this.contactCardsChanged) return;
                var incomplete = this.contactCards.some(function (card) {
                    return !String(card.label || "").trim() || !String(card.value || "").trim();
                });
                if (incomplete) {
                    this.toast(this.contactCardsText.incomplete);
                    return;
                }
                var body = new URLSearchParams();
                body.set("action", "save_cards");
                body.set("cards", JSON.stringify(this.contactCards));
                body.set("_token", this.csrf);
                var self = this;
                this.contactCardsSaving = true;
                fetch(this.contactEndpoint, { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result || Number(result.code) !== 0) {
                            throw new Error((result && result.msg) || self.contactCardsText.failed);
                        }
                        self.contactCards = (Array.isArray(result.data.cards) ? result.data.cards : []).map(function (card) {
                            card._key = self.uid("contact-card");
                            return card;
                        });
                        self.contactCardsChanged = false;
                        self.refreshPreview();
                        self.toast(self.contactCardsText.saved);
                    })
                    .catch(function (error) { self.toast(error.message || self.contactCardsText.failed); })
                    .finally(function () { self.contactCardsSaving = false; });
            },

            addContactFormField() {
                if (!this.contactFormVisual || !this.contactFormCanEdit) return;
                if (this.contactForm.fields.length >= 12) {
                    this.toast(this.contactFormText.limit);
                    return;
                }
                var number = this.contactForm.fields.length + 1;
                var keys = this.contactForm.fields.map(function (field) { return String(field.key || "").toLowerCase(); });
                while (keys.indexOf("field_" + number) !== -1) number++;
                this.contactForm.fields.push({
                    _key: this.uid("contact-form-field"),
                    key: "field_" + number,
                    label: this.contactFormText.newField,
                    type: "text",
                    placeholder: "",
                    required: false,
                    enabled: true
                });
                this.contactFormChanged = true;
            },

            removeContactFormField(index) {
                if (!this.contactForm.fields[index]) return;
                this.contactForm.fields.splice(index, 1);
                this.contactFormChanged = true;
            },

            moveContactFormField(index, offset) {
                var next = index + offset;
                if (!this.contactForm.fields[index] || next < 0 || next >= this.contactForm.fields.length) return;
                var field = this.contactForm.fields.splice(index, 1)[0];
                this.contactForm.fields.splice(next, 0, field);
                this.contactFormChanged = true;
            },

            saveContactForm() {
                if (!this.contactFormVisual || !this.contactFormCanEdit || this.contactFormSaving || !this.contactFormChanged) return;
                var title = String(this.contactForm.title || "").trim();
                var successMessage = String(this.contactForm.success_message || "").trim();
                var keys = {};
                var invalid = !title || !successMessage || this.contactForm.fields.length === 0
                    || this.contactForm.fields.length > 12
                    || this.contactForm.fields.some(function (field) {
                        var key = String(field.key || "").trim().toLowerCase();
                        var bad = !/^[a-z][a-z0-9_-]*$/.test(key) || !!keys[key]
                            || !String(field.label || "").trim();
                        keys[key] = true;
                        return bad;
                    });
                if (invalid) {
                    this.toast(this.contactFormText.invalid);
                    return;
                }
                if (!this.contactForm.fields.some(function (field) { return !!field.enabled; })) {
                    this.toast(this.contactFormText.needEnabled);
                    return;
                }

                var body = new URLSearchParams();
                body.set("action", "save_form");
                body.set("title", title);
                body.set("description", String(this.contactForm.description || ""));
                body.set("success_message", successMessage);
                body.set("captcha", this.contactForm.captcha ? "1" : "0");
                body.set("fields", JSON.stringify(this.contactForm.fields));
                body.set("_token", this.csrf);
                var self = this;
                this.contactFormSaving = true;
                fetch(this.contactEndpoint, { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result || Number(result.code) !== 0 || !result.data || !result.data.form) {
                            throw new Error((result && result.msg) || self.contactFormText.failed);
                        }
                        var saved = result.data.form;
                        saved.fields = (Array.isArray(saved.fields) ? saved.fields : []).map(function (field) {
                            field._key = self.uid("contact-form-field");
                            return field;
                        });
                        self.contactForm = saved;
                        self.contactFormChanged = false;
                        self.refreshPreview();
                        self.toast(self.contactFormText.saved);
                    })
                    .catch(function (error) { self.toast(error.message || self.contactFormText.failed); })
                    .finally(function () { self.contactFormSaving = false; });
            },

            selectedPath() {
                if (this.selectedSi < 0 || this.selectedCi < 0 || this.selectedEi < 0) return "";
                var path = [this.selectedSi, this.selectedCi, this.selectedEi];
                if (this.selectedSubEi >= 0) path.push(this.selectedSubEi);
                return path.join(".");
            },

            selectPath(path, notifyCanvas) {
                var parts = String(path).split(".").map(function (v) { return parseInt(v, 10); });
                if (parts.length < 3 || parts.some(function (v) { return isNaN(v); })) return;
                if (!this.sections[parts[0]] || !this.sections[parts[0]].columns[parts[1]]) return;
                var el = this.sections[parts[0]].columns[parts[1]].elements[parts[2]];
                if (!el) return;
                if (parts.length >= 4) {
                    var kids = (el.data && el.data.children) || [];
                    if (!kids[parts[3]]) return;
                    this.selectChild(parts[0], parts[1], parts[2], parts[3], notifyCanvas);
                } else {
                    this.selectElement(parts[0], parts[1], parts[2], notifyCanvas);
                }
            },

            highlightCanvasSelection(scrollToSelection) {
                var frame = this.$refs.canvas;
                if (!frame || !frame.contentWindow) return;
                var path = this.selectedPath();
                var message = { ykScroll: scrollToSelection === true };
                if (this.selectedHomeField && path
                    && this.homeFieldAllowed(this.selTopEl, this.selectedHomeField)) {
                    message.ykHighlightHomeField = { path: path, field: this.selectedHomeField };
                } else if (this.selectedHomeColumn && path
                    && this.homeColumnAllowed(this.selTopEl, this.selectedHomeColumn)) {
                    message.ykHighlightHomeColumn = { path: path, column: this.selectedHomeColumn };
                } else if (this.selectedSubEi >= 0 && this.isHomeBannerHost(this.selTopEl)) {
                    message.ykBannerSlide = this.selectedSubEi;
                    message.ykBannerPath = this.selectedSi + "." + this.selectedCi + "." + this.selectedEi;
                    message.ykHighlightEl = this.selectedSi + "." + this.selectedCi + "." + this.selectedEi;
                } else if (this.selectedSectionField && this.selectedSi >= 0) {
                    message.ykHighlightSectionField = { si: this.selectedSi, field: this.selectedSectionField };
                } else if (path) message.ykHighlightEl = path;
                else if (this.selectedSi >= 0 && this.selLayer === "col" && this.selectedCi >= 0) message.ykHighlightCol = this.selectedSi + "." + this.selectedCi;
                else if (this.selectedSi >= 0 && this.selLayer === "con") message.ykHighlightCon = this.selectedSi;
                else if (this.selectedSi >= 0) message.ykHighlight = this.selectedSi;
                else return;
                this.canvasBridge().post(message);
            },

            selectSection(si, notifyCanvas) {
                this.selectedSi = si;
                this.targetCi = 0;
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.selectedSubEi = -1;
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.resetBoxPanelState();
                this.selLayer = "sec";
                this.libOpen = false;
                this.openMobileSettings();
                this.panelTab = "content";
                this.ctrlQuery = "";
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            /** Select the section's inner container layer separately from the section background layer. */
            selectContainer(si, notifyCanvas) {
                this.selectedSi = si;
                this.targetCi = 0;
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.selectedSubEi = -1;
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.resetBoxPanelState();
                this.selLayer = "con";
                this.libOpen = false;
                this.openMobileSettings();
                this.panelTab = "style";
                this.ctrlQuery = "";
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            selectColumn(si, ci, notifyCanvas) {
                if (!this.sections[si] || !this.sections[si].columns[ci]) return;
                this.selectedSi = si;
                this.targetCi = ci;
                this.selectedCi = ci;
                this.selectedEi = -1;
                this.selectedSubEi = -1;
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.selLayer = "col";
                this.libOpen = false;
                this.openMobileSettings();
                this.panelTab = "style";
                this.ctrlQuery = "";
                // 列可能覆盖整屏或更高；scrollIntoView 会把画布强制拉到列顶部。
                // 选择列只更新高亮和设置面板，不改变用户当前查看位置。
                if (notifyCanvas !== false) this.highlightCanvasSelection(false);
            },

            isContainerSelected(si) {
                return this.selectedSi === si && this.selectedCi < 0 && this.selLayer === "con";
            },

            elCount(section) {
                // 容器的子元素也计数：树和「本区块含 N 个元素」都按可见节点算
                return (section.columns || []).reduce(function(n, c) {
                    return n + (c.elements || []).reduce(function(m, e) {
                        return m + 1 + (((e.data || {}).children) || []).length;
                    }, 0);
                }, 0);
            },

            uid(p) { return p + "_" + Math.random().toString(36).substr(2, 9); },

            /** 补齐/去重所有层级的 id（区块/列/元素/容器子元素）。只在本地补，保存时自然带上 */
            normalizeIds() {
                var self = this;
                var seen = {};
                var fix = function (obj, prefix) {
                    if (!obj.id || seen[obj.id]) obj.id = self.uid(prefix);
                    seen[obj.id] = true;
                };
                (this.sections || []).forEach(function (s) {
                    fix(s, "s");
                    (s.columns || []).forEach(function (c) {
                        fix(c, "c");
                        (c.elements || []).forEach(function (e) {
                            fix(e, "e");
                            (((e.data || {}).children) || []).forEach(function (k) { fix(k, "e"); });
                        });
                    });
                });
            },

            elementAtPath(path) {
                var parts = String(path || "").split(".").map(function (v) { return parseInt(v, 10); });
                if (parts.length < 3 || parts.length > 4 || parts.some(function (v) { return isNaN(v); })) return null;
                var section = this.sections[parts[0]];
                var column = section && section.columns ? section.columns[parts[1]] : null;
                var element = column && column.elements ? column.elements[parts[2]] : null;
                if (!element) return null;
                if (parts.length === 4) {
                    return (element.data && element.data.children ? element.data.children[parts[3]] : null) || null;
                }
                return element;
            },

            plainTextHtml(value) {
                var text = String(value || "").replace(/\r/g, "").trim();
                if (!text) return "";
                var escapeHtml = function (part) {
                    return part.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
                };
                return text.split(/\n{2,}/).map(function (paragraph) {
                    return "<p>" + paragraph.split("\n").map(escapeHtml).join("<br>") + "</p>";
                }).join("");
            },

            applyInlineEdit(data) {
                if (!data || typeof data.value !== "string") return;
                if (data.kind === "sectionField") {
                    var si = parseInt(data.si, 10);
                    if (!this.sections[si] || (data.field !== "title" && data.field !== "subtitle")) return;
                    this.selectSectionField(si, data.field, false);
                    this.sections[si].settings = this.sections[si].settings || {};
                    this.sections[si].settings[data.field] = data.value;
                    return;
                }
                if (data.kind === "homeField" && typeof data.path === "string") {
                    var homeBlock = this.elementAtPath(data.path);
                    if (!this.homeFieldAllowed(homeBlock, data.field)) return;
                    this.selectHomeField(data.path, data.field, false);
                    this.setHomeFieldValue(homeBlock, data.field, data.value);
                    return;
                }
                if (data.kind !== "element" || typeof data.path !== "string") return;
                var el = this.elementAtPath(data.path);
                if (!el) return;
                el.data = el.data || {};
                if ((el.type === "heading" || el.type === "button") && data.field === "text" && data.format === "text") {
                    this.selectPath(data.path, false);
                    el.data.text = data.value;
                    return;
                }
                if (el.type === "text" && data.field === "html" && data.format === "plain") {
                    this.selectPath(data.path, false);
                    el.data.html = this.plainTextHtml(data.value);
                }
            },

            sectionFieldName(field) {
                return field === "subtitle" ? this.ctxText.subtitle : this.ctxText.sectionTitle;
            },

            sectionFieldLabel(field) {
                return this.ctxText.editPrefix + this.sectionFieldName(field);
            },

            setSectionField(si, field, value) {
                var s = this.sections[si];
                if (!s || (field !== "title" && field !== "subtitle")) return;
                s.settings = s.settings || {};
                s.settings[field] = value;
                this.selectSectionField(si, field);
            },

            editSectionField(si, field) {
                var s = this.sections[si];
                if (!s || (field !== "title" && field !== "subtitle")) return;
                s.settings = s.settings || {};
                this.selectSectionField(si, field);
                var current = s.settings[field] || "";
                var next = prompt(this.sectionFieldLabel(field), current);
                if (next === null) return;
                s.settings[field] = next;
            },

            quickEditSelected() {
                var el = this.selEl;
                if (!el) return;
                el.data = el.data || {};
                if (el.type === "text") {
                    this.openRte(function () { return el.data.html || ""; }, function (v) { el.data.html = v; });
                    return;
                }
                if (el.type === "heading") {
                    this.quickPrompt(el, "text", this.ctxText.editTitle);
                    return;
                }
                if (el.type === "button") {
                    this.quickPrompt(el, "text", this.ctxText.editButtonText);
                    return;
                }
                this.panelTab = "content";
                this.libOpen = false;
                this.toast(this.ctxText.elementSelected);
            },

            quickPrompt(el, key, title) {
                var current = (el.data && el.data[key]) || "";
                var next = prompt(title || this.ctxText.editText, current);
                if (next === null) return;
                el.data[key] = next;
            },

            selectionClipboardSource() {
                var s = this.sel;
                var top = this.selTopEl;
                if (!s || !top || this.selectedCi < 0 || this.selectedEi < 0) return null;
                if (this.selectedSubEi >= 0) {
                    var kids = (top.data && top.data.children) || [];
                    var child = kids[this.selectedSubEi];
                    return child ? { kind: "child", si: this.selectedSi, ci: this.selectedCi, ei: this.selectedEi, cei: this.selectedSubEi, id: child.id, node: child } : null;
                }
                return { kind: "element", si: this.selectedSi, ci: this.selectedCi, ei: this.selectedEi, id: top.id, node: top };
            },

            hasClipboardSelection() {
                return !!this.selectionClipboardSource();
            },

            copySelection() {
                var source = this.selectionClipboardSource();
                if (!source) { this.toast(this.clipboardText.empty); return; }
                this.clipboard = {
                    mode: "copy",
                    kind: source.kind,
                    id: source.id,
                    node: JSON.parse(JSON.stringify(source.node)),
                };
                this.toast(this.clipboardText.copyDone);
            },

            cutSelection() {
                var source = this.selectionClipboardSource();
                if (!source) { this.toast(this.clipboardText.empty); return; }
                this.clipboard = {
                    mode: "cut",
                    kind: source.kind,
                    id: source.id,
                    node: JSON.parse(JSON.stringify(source.node)),
                };
                if (!this.removeClipboardSource(source)) {
                    this.clipboard = null;
                    this.toast(this.clipboardText.sourceMissing);
                    return;
                }
                this.selectedSi = -1;
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.selectedSubEi = -1;
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.selLayer = "sec";
                this.libOpen = false;
                this.closeCtx();
                this.highlightCanvasSelection(false);
                this.toast(this.clipboardText.cutDone);
            },

            removeClipboardSource(source) {
                if (!source) return false;
                var section = this.sections[source.si];
                var column = section && section.columns ? section.columns[source.ci] : null;
                if (!column) return false;
                if (source.kind === "child") {
                    var parent = column.elements[source.ei];
                    var kids = parent && parent.data ? (parent.data.children || []) : [];
                    var childIndex = kids.findIndex(function (item) { return item && item.id === source.id; });
                    if (childIndex < 0) return false;
                    kids.splice(childIndex, 1);
                    return true;
                }
                var elementIndex = (column.elements || []).findIndex(function (item) { return item && item.id === source.id; });
                if (elementIndex < 0) return false;
                column.elements.splice(elementIndex, 1);
                return true;
            },

            pasteTarget(kind, target) {
                target = target || {};
                if (!this.clipboard) return null;
                if (kind === "child") {
                    var childSection = this.sections[target.si];
                    var childColumn = childSection && childSection.columns ? childSection.columns[target.ci] : null;
                    var childParent = childColumn && childColumn.elements ? childColumn.elements[target.ei] : null;
                    if (!childParent || !this.elSchema(childParent.type).container) return null;
                    if (!this.canNestElement(childParent, this.clipboard.node)) return null;
                    var childCount = (childParent.data && childParent.data.children || []).length;
                    return { mode: "child", si: target.si, ci: target.ci, ei: target.ei, index: Math.min(childCount, Math.max(0, parseInt(target.cei, 10) + 1)) };
                }
                if (kind === "element") {
                    var elementSection = this.sections[target.si];
                    var elementColumn = elementSection && elementSection.columns ? elementSection.columns[target.ci] : null;
                    var element = elementColumn && elementColumn.elements ? elementColumn.elements[target.ei] : null;
                    if (!element) return null;
                    if (this.elSchema(element.type).container) {
                        if (!this.canNestElement(element, this.clipboard.node)) return null;
                        var elementCount = (element.data && element.data.children || []).length;
                        return { mode: "child", si: target.si, ci: target.ci, ei: target.ei, index: elementCount };
                    }
                    return { mode: "element", si: target.si, ci: target.ci, index: Math.max(0, parseInt(target.ei, 10) + 1) };
                }
                if (kind === "column") {
                    var columnSection = this.sections[target.si];
                    var targetColumn = columnSection && columnSection.columns ? columnSection.columns[target.ci] : null;
                    return targetColumn ? { mode: "element", si: target.si, ci: target.ci, index: targetColumn.elements.length } : null;
                }
                if (kind === "container" || kind === "section") {
                    var section = this.sections[target.si];
                    if (!section || !section.columns || !section.columns.length) return null;
                    var ci = parseInt(target.ci, 10);
                    if (isNaN(ci)) ci = this.selectedSi === target.si ? this.targetCi : 0;
                    ci = Math.min(Math.max(ci, 0), section.columns.length - 1);
                    return { mode: "element", si: target.si, ci: ci, index: section.columns[ci].elements.length };
                }
                if (kind === "canvas") {
                    if (this.sections.length === 0) return { mode: "new-section" };
                    var si = this.selectedSi >= 0 ? this.selectedSi : this.sections.length - 1;
                    var current = this.sections[si];
                    var targetCi = current && current.columns ? Math.min(Math.max(this.targetCi, 0), current.columns.length - 1) : 0;
                    return current && current.columns[targetCi] ? { mode: "element", si: si, ci: targetCi, index: current.columns[targetCi].elements.length } : null;
                }
                return null;
            },

            canPasteTo(kind, target) {
                return !!this.pasteTarget(kind, target);
            },

            pasteClipboard(kind, target) {
                if (!this.clipboard) { this.toast(this.clipboardText.empty); return; }
                var destination = this.pasteTarget(kind, target);
                if (!destination) { this.toast(this.clipboardText.invalid); return; }
                if (destination.mode === "new-section") {
                    this.addSection(1, true);
                    destination = this.pasteTarget("section", { si: this.selectedSi, ci: 0 });
                }
                if (!destination) { this.toast(this.clipboardText.invalid); return; }
                var node = this.deepCloneNode(this.clipboard.node, "e");
                if (destination.mode === "child") {
                    var parent = this.sections[destination.si].columns[destination.ci].elements[destination.ei];
                    parent.data.children = parent.data.children || [];
                    if (this.isHomeBannerHost(parent)) parent.data.items_mode = "custom";
                    parent.data.children.splice(destination.index, 0, node);
                    this.selectChild(destination.si, destination.ci, destination.ei, destination.index, false);
                } else {
                    var elements = this.sections[destination.si].columns[destination.ci].elements;
                    var index = Math.min(elements.length, Math.max(0, destination.index));
                    elements.splice(index, 0, node);
                    this.selectElement(destination.si, destination.ci, index, false);
                }
                if (this.clipboard.mode === "cut") this.clipboard = null;
                this.closeCtx();
                this.toast(this.clipboardText.pasteDone);
            },

            pasteSelection() { return this.runCommand("paste", function () { return this._pasteSelectionRaw(); }); },
            _pasteSelectionRaw() {
                var target = null;
                if (this.selectedSubEi >= 0 && this.selTopEl) {
                    target = { kind: "child", si: this.selectedSi, ci: this.selectedCi, ei: this.selectedEi, cei: this.selectedSubEi };
                } else if (this.selTopEl) {
                    target = { kind: "element", si: this.selectedSi, ci: this.selectedCi, ei: this.selectedEi };
                } else if (this.selLayer === "col" && this.selectedCi >= 0) {
                    target = { kind: "column", si: this.selectedSi, ci: this.selectedCi };
                } else if (this.selectedSi >= 0) {
                    target = { kind: "section", si: this.selectedSi, ci: this.targetCi };
                } else {
                    target = { kind: "canvas" };
                }
                this.pasteClipboard(target.kind, target);
            },
            closeCtx() {
                this.ctx.open = false;
            },

            selectCtxTarget(kind, t, notifyCanvas) {
                t = t || {};
                if (kind === "canvas") return;
                if (kind === "sectionField") { this.selectSectionField(t.si, t.field, notifyCanvas); return; }
                if (kind === "child") this.selectChild(t.si, t.ci, t.ei, t.cei, notifyCanvas);
                else if (kind === "element") this.selectElement(t.si, t.ci, t.ei, notifyCanvas);
                else if (kind === "column") this.selectColumn(t.si, t.ci, notifyCanvas);
                else if (kind === "container") this.selectContainer(t.si, notifyCanvas);
                else if (kind === "section") this.selectSection(t.si, notifyCanvas);
            },

            openCtx(evt, kind, target) {
                if (evt && evt.preventDefault) evt.preventDefault();
                this.selectCtxTarget(kind, target, true);
                this.showCtx(kind, target, evt ? evt.clientX : 0, evt ? evt.clientY : 0);
            },

            openCtxFromCanvas(d) {
                var frame = this.$refs.canvas;
                var r = frame ? frame.getBoundingClientRect() : { left: 0, top: 0 };
                var target = d.target || {};
                this.selectCtxTarget(d.kind, target, false);
                this.showCtx(d.kind, target, r.left + (d.x || 0), r.top + (d.y || 0));
            },

            showCtx(kind, target, x, y) {
                var w = 190, h = 260;
                this.ctx.kind = kind;
                this.ctx.target = Object.assign({}, target || {});
                this.ctx.x = Math.max(8, Math.min(window.innerWidth - w - 8, Math.round(x || 0)));
                this.ctx.y = Math.max(8, Math.min(window.innerHeight - h - 8, Math.round(y || 0)));
                this.ctx.open = true;
            },

            ctxItems() {
                var k = this.ctx.kind;
                var t = this.ctx.target || {};
                var items = [];
                if (k !== "canvas") items.push({ key: "settings", label: this.ctxText.settings, icon: "adjustments" });
                if (k === "canvas") {
                    items.push({ key: "addSectionEnd", label: this.ctxText.addSection, icon: "layout-board-split" });
                    items.push({ key: "addLayout2", label: this.ctxText.addLayout2, icon: "layout-columns" });
                    items.push({ key: "addLayout3", label: this.ctxText.addLayout3, icon: "columns-3" });
                    items.push({ key: "addElement", label: this.ctxText.addElement, icon: "plus" });
                    if (this.clipboard) items.push({ key: "paste", label: this.clipboardText.paste, icon: "clipboard", disabled: !this.canPasteTo("canvas", {}) });
                    return items;
                }
                if (k === "sectionField") {
                    items.push({ key: "editSectionField", label: this.ctxText.editText, icon: "pencil" });
                    items.push({ key: "clearSectionField", label: this.ctxText.clearText, icon: "eraser", danger: true });
                    return items;
                }
                if (k === "section") {
                    items.push({ key: "addSectionAfter", label: this.ctxText.addSectionAfter, icon: "layout-board-split" });
                    items.push({ key: "addElement", label: this.ctxText.addElement, icon: "plus" });
                    if (this.clipboard) items.push({ key: "paste", label: this.clipboardText.paste, icon: "clipboard", disabled: !this.canPasteTo("section", t) });
                    items.push({ sep: true, key: "s1" });
                    items.push({ key: "moveUp", label: this.ctxText.moveUp, icon: "arrow-up", disabled: t.si <= 0 });
                    items.push({ key: "moveDown", label: this.ctxText.moveDown, icon: "arrow-down", disabled: t.si >= this.sections.length - 1 });
                    items.push({ key: "duplicate", label: this.ctxText.duplicateSection, icon: "copy" });
                    items.push({ key: "saveAsTemplate", label: this.ctxText.saveAsTemplate, icon: "template" });
                    items.push({ sep: true, key: "s2" });
                    items.push({ key: "delete", label: this.ctxText.deleteSection, icon: "trash", danger: true });
                    return items;
                }
                if (k === "container" || k === "column") {
                    items.push({ key: "addElement", label: this.ctxText.addElement, icon: "plus" });
                    if (this.clipboard) items.push({ key: "paste", label: this.clipboardText.paste, icon: "clipboard", disabled: !this.canPasteTo(k, t) });
                    items.push({ key: "addSectionAfter", label: this.ctxText.addSectionAfter, icon: "layout-board-split" });
                    if (this.ctxSectionIsEmpty(t)) {
                        items.push({ sep: true, key: "empty-section-separator" });
                        items.push({ key: "deleteSection", label: this.ctxText.deleteEmptySection, icon: "trash", danger: true });
                    }
                    return items;
                }
                if (k === "element" || k === "child") {
                    var el = k === "element" ? this.sections[t.si]?.columns[t.ci]?.elements[t.ei] : ((this.sections[t.si]?.columns[t.ci]?.elements[t.ei]?.data?.children || [])[t.cei]);
                    var isContainer = !!(el && this.elSchema(el.type).container && k === "element"
                        && (!this.isHomeBlockHost(el) || this.isHomeBannerHost(el)));
                    items.push({ key: "addChild", label: this.ctxText.addChild, icon: "corner-down-right", disabled: !isContainer });
                    items.push({ key: "copy", label: this.clipboardText.copy, icon: "copy" });
                    items.push({ key: "cut", label: this.clipboardText.cut, icon: "scissors" });
                    if (this.clipboard) items.push({ key: "paste", label: this.clipboardText.paste, icon: "clipboard", disabled: !this.canPasteTo(k, t) });
                    items.push({ sep: true, key: "e1" });
                    items.push({ key: "moveUp", label: this.ctxText.moveUp, icon: "arrow-up", disabled: !this.ctxCanMove(-1) });
                    items.push({ key: "moveDown", label: this.ctxText.moveDown, icon: "arrow-down", disabled: !this.ctxCanMove(1) });
                    items.push({ key: "duplicate", label: this.ctxText.duplicateItem, icon: "copy" });
                    items.push({ sep: true, key: "e2" });
                    items.push({ key: "delete", label: this.ctxText.deleteItem, icon: "trash", danger: true });
                }
                return items;
            },

            runCtx(action) {
                if (action === "saveAsTemplate") {
                    this.closeCtx();
                    this.saveSectionAsTemplate((this.ctx.target || {}).si);
                    return;
                }
                var k = this.ctx.kind;
                var t = this.ctx.target || {};
                this.closeCtx();
                if (action === "settings") { this.selectCtxTarget(k, t, true); return; }
                if (action === "copy") { this.selectCtxTarget(k, t, false); this.copySelection(); return; }
                if (action === "cut") { this.selectCtxTarget(k, t, false); this.cutSelection(); return; }
                if (action === "paste") { this.pasteClipboard(k, t); return; }
                if (action === "editSectionField") { this.editSectionField(t.si, t.field); return; }
                if (action === "clearSectionField") { this.setSectionField(t.si, t.field, ""); return; }
                if (action === "addSectionEnd") { this.selectedSi = -1; this.addSection(1); return; }
                if (action === "addLayout2") { this.selectedSi = -1; this.addLayout([6, 6]); return; }
                if (action === "addLayout3") { this.selectedSi = -1; this.addLayout([4, 4, 4]); return; }
                if (action === "addElement") {
                    if (k === "column") this.selectColumn(t.si, t.ci);
                    else if (k === "container") this.selectContainer(t.si);
                    else if (k === "section") this.selectSection(t.si);
                    this.libOpen = true;
                    return;
                }
                if (action === "addChild") { this.selectElement(t.si, t.ci, t.ei); this.libOpen = true; return; }
                if (action === "addSectionAfter") { this.selectSection(t.si); this.addSection(1); return; }
                if (action === "deleteSection") { this.deleteSection(t.si); return; }
                if (action === "moveUp") { this.ctxMove(-1); return; }
                if (action === "moveDown") { this.ctxMove(1); return; }
                if (action === "duplicate") { this.ctxDuplicate(); return; }
                if (action === "delete") { this.ctxDelete(); return; }
            },

            ctxSectionIsEmpty(target) {
                target = target || {};
                var section = this.sections[target.si];
                if (!section || this.elCount(section) !== 0) return false;
                var settings = section.settings || {};
                return String(settings.title || "").trim() === ""
                    && String(settings.subtitle || "").trim() === "";
            },

            ctxCanMove(dir) {
                var k = this.ctx.kind, t = this.ctx.target || {};
                if (k === "section") return t.si + dir >= 0 && t.si + dir < this.sections.length;
                if (k === "element") {
                    var els = this.sections[t.si]?.columns[t.ci]?.elements || [];
                    return t.ei + dir >= 0 && t.ei + dir < els.length;
                }
                if (k === "child") {
                    var kids = this.sections[t.si]?.columns[t.ci]?.elements[t.ei]?.data?.children || [];
                    return t.cei + dir >= 0 && t.cei + dir < kids.length;
                }
                return false;
            },

            ctxMove(dir) {
                var k = this.ctx.kind, t = this.ctx.target || {};
                if (k === "section") this.moveSection(t.si, dir);
                else if (k === "element") this.moveElement(t.si, t.ci, t.ei, dir);
                else if (k === "child") this.moveChild(t.si, t.ci, t.ei, t.cei, dir);
                this.highlightCanvasSelection();
            },

            deepCloneNode(node, prefix) {
                var copy = JSON.parse(JSON.stringify(node));
                var self = this;
                function fix(n) {
                    n.id = self.uid(prefix || "e");
                    (((n.data || {}).children) || []).forEach(fix);
                }
                fix(copy);
                return copy;
            },

            ctxDuplicate() {
                var k = this.ctx.kind, t = this.ctx.target || {};
                if (k === "section") { this.duplicateSection(t.si); return; }
                if (k === "element") {
                    var els = this.sections[t.si]?.columns[t.ci]?.elements;
                    if (!els || !els[t.ei]) return;
                    els.splice(t.ei + 1, 0, this.deepCloneNode(els[t.ei], "e"));
                    this.selectElement(t.si, t.ci, t.ei + 1);
                    return;
                }
                if (k === "child") {
                    var kids = this.sections[t.si]?.columns[t.ci]?.elements[t.ei]?.data?.children || [];
                    if (!kids[t.cei]) return;
                    kids.splice(t.cei + 1, 0, this.deepCloneNode(kids[t.cei], "e"));
                    this.selectChild(t.si, t.ci, t.ei, t.cei + 1);
                }
            },

            ctxDelete() {
                var k = this.ctx.kind, t = this.ctx.target || {};
                if (k === "section") this.deleteSection(t.si);
                else if (k === "element") this.deleteElement(t.si, t.ci, t.ei);
                else if (k === "child") this.deleteChild(t.si, t.ci, t.ei, t.cei);
                this.highlightCanvasSelection();
            },

            initTreeSortable() {
                if (typeof Sortable === "undefined" || !this.$refs.tree) return;
                if (Sortable.active) {
                    this.scheduleTreeSortable(50);
                    return;
                }
                this._sortables.forEach(function (s) {
                    try {
                        var sortable = window.Alpine && typeof Alpine.raw === "function" ? Alpine.raw(s) : s;
                        sortable.destroy();
                    } catch (e) {}
                });
                this._sortables = [];
                var self = this;
                var secRoot = this.$refs.tree.querySelector("[data-sort-sections]") || this.$refs.tree;
                this._sortables.push(new Sortable(secRoot, {
                    animation: 150,
                    draggable: "[data-section-id]",
                    handle: "[data-section-drag-handle]",
                    onEnd: function (evt) { self.sortSections((evt.oldDraggableIndex ?? evt.oldIndex), (evt.newDraggableIndex ?? evt.newIndex)); }
                }));
                this.$refs.tree.querySelectorAll("[data-sort-elements]").forEach(function (el) {
                    self._sortables.push(new Sortable(el, {
                        animation: 150,
                        group: { name: "blox-elements", pull: true, put: true },
                        ghostClass: "blox-sort-ghost",
                        draggable: "[data-sort-el-item]",
                        handle: "[data-element-drag-handle]",
                        onEnd: function (evt) {
                            var from = evt.from || el;
                            var to = evt.to || el;
                            self.moveElementsBetweenColumns(
                                parseInt(from.dataset.si, 10), parseInt(from.dataset.ci, 10),
                                parseInt(to.dataset.si, 10), parseInt(to.dataset.ci, 10),
                                (evt.oldDraggableIndex ?? evt.oldIndex), (evt.newDraggableIndex ?? evt.newIndex)
                            );
                        }
                    }));
                });
                this.$refs.tree.querySelectorAll("[data-sort-children]").forEach(function (el) {
                    self._sortables.push(new Sortable(el, {
                        animation: 150,
                        draggable: "[data-sort-child-item]",
                        handle: "[data-child-drag-handle]",
                        onEnd: function (evt) {
                            self.sortChildren(parseInt(el.dataset.si, 10), parseInt(el.dataset.ci, 10), parseInt(el.dataset.ei, 10), (evt.oldDraggableIndex ?? evt.oldIndex), (evt.newDraggableIndex ?? evt.newIndex));
                        }
                    }));
                });
            },

            scheduleTreeSortable(delay) {
                var self = this;
                var wait = Math.max(0, parseInt(delay, 10) || 0);
                this.$nextTick(function() {
                    if (self._treeSortableTimer) clearTimeout(self._treeSortableTimer);
                    self._treeSortableTimer = setTimeout(function() {
                        self._treeSortableTimer = null;
                        self.initTreeSortable();
                    }, wait);
                });
            },

            sortSections(oldIndex, newIndex) {
                if (oldIndex === newIndex || oldIndex < 0 || newIndex < 0) return;
                var selectedId = this.sel ? this.sel.id : "";
                var s = this.sections.splice(oldIndex, 1)[0];
                this.sections.splice(newIndex, 0, s);
                if (selectedId) this.selectedSi = this.sections.findIndex(function (x) { return x.id === selectedId; });
            },

            sortElements(si, ci, oldIndex, newIndex) {
                var col = this.sections[si] && this.sections[si].columns[ci];
                if (!col || oldIndex === newIndex || oldIndex < 0 || newIndex < 0) return;
                var selectedId = this.selTopEl ? this.selTopEl.id : "";
                var item = col.elements.splice(oldIndex, 1)[0];
                col.elements.splice(newIndex, 0, item);
                if (selectedId) this.selectedEi = col.elements.findIndex(function (x) { return x.id === selectedId; });
            },

            moveElementsBetweenColumns(fromSi, fromCi, toSi, toCi, oldIndex, newIndex) {
                if (fromSi === toSi && fromCi === toCi) {
                    this.sortElements(fromSi, fromCi, oldIndex, newIndex);
                    return;
                }
                var fromSection = this.sections[fromSi];
                var toSection = this.sections[toSi];
                var fromColumn = fromSection && fromSection.columns ? fromSection.columns[fromCi] : null;
                var toColumn = toSection && toSection.columns ? toSection.columns[toCi] : null;
                if (!fromColumn || !toColumn || oldIndex < 0 || oldIndex >= fromColumn.elements.length) return;
                var selectedId = this.selTopEl ? this.selTopEl.id : "";
                var item = fromColumn.elements.splice(oldIndex, 1)[0];
                if (!item) return;
                var index = Math.min(toColumn.elements.length, Math.max(0, newIndex));
                toColumn.elements.splice(index, 0, item);
                if (!selectedId) return;
                for (var si = 0; si < this.sections.length; si++) {
                    var columns = this.sections[si].columns || [];
                    for (var ci = 0; ci < columns.length; ci++) {
                        var elements = columns[ci].elements || [];
                        for (var ei = 0; ei < elements.length; ei++) {
                            if (elements[ei].id === selectedId) {
                                this.selectedSi = si;
                                this.selectedCi = ci;
                                this.selectedEi = ei;
                                return;
                            }
                        }
                    }
                }
            },
            sortChildren(si, ci, ei, oldIndex, newIndex) {
                var el = this.sections[si] && this.sections[si].columns[ci] && this.sections[si].columns[ci].elements[ei];
                var kids = el && el.data ? (el.data.children || []) : [];
                if (!kids.length || oldIndex === newIndex || oldIndex < 0 || newIndex < 0) return;
                var selectedId = this.selEl ? this.selEl.id : "";
                var item = kids.splice(oldIndex, 1)[0];
                kids.splice(newIndex, 0, item);
                if (selectedId) this.selectedSubEi = kids.findIndex(function (x) { return x.id === selectedId; });
            },

            moveSection(si, dir) {
                var ni = si + dir;
                if (ni < 0 || ni >= this.sections.length) return;
                var s = this.sections.splice(si, 1)[0];
                this.sections.splice(ni, 0, s);
                this.selectedSi = ni;
            },

            duplicateSection(si) {
                var copy = JSON.parse(JSON.stringify(this.sections[si]));
                copy.id = this.uid("s");
                (copy.columns || []).forEach(function(c) { c.id = "c_" + Math.random().toString(36).substr(2, 9); });
                this.sections.splice(si + 1, 0, copy);
                this.selectedSi = si + 1;
            },

            deleteSection(si) { return this.runCommand("delete-section", function () { return this._deleteSectionRaw(si); }); },
            _deleteSectionRaw(si) {
                if (!confirm(this.uiText.confirmDeleteSection)) return;
                this.sections.splice(si, 1);
                if (this.selectedSi === si) this.selectedSi = -1;
                else if (this.selectedSi > si) this.selectedSi--;
            },

            /**
             * 插入区块。
             * 位置：有选中项 → 插到它**之后**；没有 → 追加到末尾。
             * 这比「永远追加」符合直觉——在中间调整版面时不必插完再一路上移。
             * settings 与通用 BlockRenderer 渲染等价：默认值不写入时也按同样规则处理，
             * title/subtitle/bg_opacity/col_card，这里写成显式默认值，渲染器对
             * 「缺键」与这些值的处理相同（空标题不输出、bg_opacity 默认 100、
             * col_card 空即不启用）。两个编辑器共用同一份 blocks_data，
             * 默认值若真不一致，来回切换会造成版面漂移。
             */
            addLayout(spans) {
                if (!Array.isArray(spans) || spans.length < 1) return;
                this.addSection(spans);
            },

            normalizedLayoutSpans(spans) {
                if (!Array.isArray(spans) || spans.length < 1) return [];
                return spans.map(function (n) {
                    n = parseInt(n, 10);
                    return Math.min(12, Math.max(1, isNaN(n) ? 12 : n));
                });
            },

            layoutPresetActive(spans) {
                var s = this.sel;
                if (!s || !Array.isArray(s.columns)) return false;
                var normalized = this.normalizedLayoutSpans(spans);
                if (normalized.length !== s.columns.length) return false;
                for (var i = 0; i < normalized.length; i++) {
                    var current = this.rawSpanD(s.columns[i]);
                    if (!current) current = Math.floor(12 / Math.max(1, s.columns.length));
                    if (current !== normalized[i]) return false;
                }
                return true;
            },

            swapSelectedColumns() {
                var s = this.sel;
                if (!s || !Array.isArray(s.columns) || s.columns.length !== 2) return;
                s.columns = [s.columns[1], s.columns[0]];
                if (this.targetCi === 0 || this.targetCi === 1) this.targetCi = 1 - this.targetCi;
                if (this.selectedCi === 0 || this.selectedCi === 1) this.selectedCi = 1 - this.selectedCi;
                this.toast(this.homeDynamicText.swapColumns);
                this.highlightCanvasSelection();
            },

            applyLayoutToSelected(spans) { return this.runCommand("apply-layout", function () { return this._applyLayoutToSelectedRaw(spans); }); },
            _applyLayoutToSelectedRaw(spans) {
                var s = this.sel;
                if (!s) return;
                var normalized = this.normalizedLayoutSpans(spans);
                if (!normalized.length) return;
                var oldColumns = Array.isArray(s.columns) ? s.columns : [];
                var cols = [];
                for (var i = 0; i < normalized.length; i++) {
                    var old = oldColumns[i] || null;
                    cols.push({
                        id: old && old.id ? old.id : this.uid("c"),
                        span: normalized[i],
                        elements: old && Array.isArray(old.elements) ? old.elements : [],
                    });
                }
                for (var j = normalized.length; j < oldColumns.length; j++) {
                    if (oldColumns[j] && Array.isArray(oldColumns[j].elements)) {
                        cols[cols.length - 1].elements = cols[cols.length - 1].elements.concat(oldColumns[j].elements);
                    }
                }
                s.columns = cols;
                this.targetCi = Math.min(this.targetCi, cols.length - 1);
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.selectedChildEi = -1;
                this.selLayer = "con";
                this.toast(this.uiText.layoutApplied);
                this.highlightCanvasSelection();
            },

            addSection(cols, silent) { return this.runCommand("add-section", function () { return this._addSectionRaw(cols, silent); }); },
            _addSectionRaw(cols, silent) {
                var spans = Array.isArray(cols) ? cols.map(function (n) {
                    n = parseInt(n, 10);
                    return Math.min(12, Math.max(1, isNaN(n) ? 12 : n));
                }) : null;
                var count = spans ? spans.length : Math.max(1, Math.min(6, parseInt(cols, 10) || 1));
                var c = [];
                for (var i = 0; i < count; i++) {
                    var col = { id: this.uid("c"), elements: [] };
                    if (spans) col.span = spans[i];
                    c.push(col);
                }
                var sec = {
                    id: this.uid("s"), type: "section",
                    settings: {
                        title: "", subtitle: "",
                        bg_color: "", bg_image: "", bg_gradient: "", bg_opacity: 100,
                        // 新区块默认宽容器（1280px）——用户定的现代默认；旧区块存的值不受影响
                        padding: "md", max_width: "wide", max_width_px: 1280,
                        container_bg: "", container_bg_image: "", container_bg_overlay_color: "", container_bg_overlay_opacity: 0,
                        container_padding: "", container_radius: "", container_gutter: "default",
                        align_items: "stretch", justify_items: "stretch",
                        gap: "lg", col_card: false, tablet_stack: false,
                    },
                    columns: c,
                };
                var at = this.insertIndex();
                this.sections.splice(at, 0, sec);
                this.selectedSi = at;
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.selectedChildEi = -1;
                this.selLayer = "sec";
                                if (!silent) this.toast(spans ? this.uiText.layoutInserted : this.uiText.nColInserted.replace(":n", count));
            },

            /** 下一个区块插入到的下标（选中项之后，否则末尾） */
            insertIndex() {
                if (this._insertAt !== null) {
                    return Math.max(0, Math.min(this._insertAt, this.sections.length));
                }
                return (this.selectedSi >= 0 && this.selectedSi < this.sections.length)
                    ? this.selectedSi + 1
                    : this.sections.length;
            },

            /** 插入位置的人话描述，显示在「添加区块」上方 */
            insertHint() {
                var at = this.insertIndex();
                return at >= this.sections.length ? this.uiText.insertAtEnd : this.uiText.insertAfterSection.replace(":n", at);
            },

            // ── 元素库 ──────────────────────────────────────

            /**
             * 目标列下标。夹在有效范围内——换了区块后 targetCi 可能越界
             * （比如从 3 列区块切到 1 列），越界会把元素塞进不存在的列里丢掉。
             */
            colIndex() {
                var s = this.sel;
                if (!s || !s.columns.length) return 0;
                return Math.min(Math.max(this.targetCi, 0), s.columns.length - 1);
            },

            // ── 元素库分类折叠态（Bricks 式；默认全开，搜索时强制全开） ──
            catOpen: {},
            isCatOpen(cat) { return this.catOpen[cat] !== false; },

            // ── 拖拽插入（路线图③）：库瓦片拖到结构树/画布 ──
            dragEl: null,          // 正在拖的库条目
            dragOver: "",          // 当前悬停的放置目标 key（高亮用）

            /**
             * 结构树放置：ei=null → 插到 si 区块的 ci 列末尾；ei≥0 → 插进该列 ei 号容器。
             * 通过先选中目标再走 addElement，复用其全部规则（容器嵌套约束、插入即选中、toast）。
             */
            treeDrop(si, ci, ei) {
                var el = this.dragEl;
                this.dragEl = null;
                this.dragOver = "";
                if (!el) return;
                if (el.type === "__section") { this.selectSection(si, false); this.addSection(1); return; }
                if (ei === null) {
                    this.selectSection(si, false);
                    this.targetCi = ci;
                } else {
                    this.selectElement(si, ci, ei, false);
                }
                this.addElement(el);
            },

            /** 按分类分组 + 关键词过滤；布局组置顶、组内 区块/容器 领先（对齐 Bricks） */
            filteredLib() {
                var q = this.libQuery.trim().toLowerCase();
                var self = this;
                var groups = [];
                var host = this.selTopEl && this.elSchema(this.selTopEl.type).container ? this.selTopEl : null;
                this.elementLib.forEach(function (el) {
                    if (el.type === "__section") {
                        if (host) return;
                    } else if (host) {
                        if (!self.canNestElement(host, { type: el.type })) return;
                    } else if (el.paletteVisible !== true || el.deprecated) {
                        return;
                    }
                    if (q && el.label.toLowerCase().indexOf(q) === -1 && el.type.indexOf(q) === -1) return;
                    var g = groups.find(function (x) { return x.cat === el.category; });
                    if (!g) {
                        g = { cat: el.category, label: self.catLabels[el.category] || el.category, items: [] };
                        groups.push(g);
                    }
                    g.items.push(el);
                });
                var li = groups.findIndex(function (g) { return g.cat === "layout"; });
                if (li > 0) groups.unshift(groups.splice(li, 1)[0]);
                groups.forEach(function (g) {
                    if (g.cat !== "layout") return;
                    var w = { "__section": 0, "container": 1, "div": 2 };
                    g.items.sort(function (a, b) { return (w[a.type] ?? 5) - (w[b.type] ?? 5); });
                });
                return groups;
            },

            /**
             * 插入元素到选中区块的目标列。
             * data 用注册表给的 defaults 深拷贝——直接引用会让多次插入共享同一个对象，
             * 改一个全变。
             */
            addElement(el, target) { return this.runCommand("add-element", function () { return this._addElementRaw(el, target); }); },
            _addElementRaw(el, target) {
                // 合成项「区块」：插顶层 section（1 列起步；多列预设在右下角）
                if (el.type === "__section") { this.addSection(1); return; }
                var s = this.sel;
                if (!s) {
                    this.addSection(1, true);
                    s = this.sel;
                    if (!s) { this.toast(this.uiText.createSectionFailed); return; }
                }
                var node = this.newElementNode(el);
                if (target && this.insertElementAt(node, target, el.label)) return;
                this.appendElementNode(node, el.label);
            },

            newElementNode(el) {
                var node = {
                    id: this.uid("e"),
                    type: el.type,
                    data: JSON.parse(JSON.stringify(el.defaults || {})),
                };
                var self = this;
                var assignChildIds = function (item) {
                    if (!item || typeof item !== "object") return;
                    if (!item.id) item.id = self.uid("e");
                    ((item.data && item.data.children) || []).forEach(assignChildIds);
                };
                ((node.data && node.data.children) || []).forEach(assignChildIds);
                return node;
            },

            appendElementNode(node, label) {
                var s = this.sel;
                if (!s) return;
                // 选中容器（或其子元素）时插进该容器；一层嵌套约束：容器里不能再放容器
                var host = this.selTopEl;
                if (host && this.elSchema(host.type).container) {
                    if (!this.canNestElement(host, node)) {
                        this.toast(this.isLoopTemplateHost(host) ? <?php echo json_encode(__('blox_loop_child_invalid'), JSON_UNESCAPED_UNICODE); ?> : this.uiText.noNestedContainer);
                        return;
                    }
                    host.data.children = host.data.children || [];
                    if (this.isHomeBannerHost(host)) host.data.items_mode = "custom";
                    host.data.children.push(node);
                    this.selectChild(this.selectedSi, this.selectedCi, this.selectedEi, host.data.children.length - 1, false);
                    this.toast(this.uiText.insertedContainer.replace(":label", label || this.uiText.elementWord));
                    return;
                }
                var ci = this.colIndex();
                s.columns[ci].elements.push(node);
                // 插入即选中：左栏自动切到它的设置（selectElement 会收起元素库），接着就能改文字
                this.selectElement(this.selectedSi, ci, s.columns[ci].elements.length - 1, false);
                this.toast((s.columns.length > 1 ? this.uiText.insertedCol.replace(":n", ci + 1) : this.uiText.inserted).replace(":label", label || this.uiText.elementWord));
            },

            /**
             * 统一处理画布和结构树使用的插入目标：
             * column/end 追加到列尾；element/before|after 插入到节点同级。
             * 子元素路径按同一套 si.ci.ei.cei 规则处理，容器约束在这里集中校验。
             */
            insertElementAt(node, target, label) {
                if (!target || !target.kind) return false;
                var si = parseInt(target.sec, 10);
                var ci = parseInt(target.col, 10);
                if (target.kind === "column") {
                    var section = this.sections[si];
                    var column = section && section.columns ? section.columns[ci] : null;
                    if (!column) return false;
                    column.elements = column.elements || [];
                    var endIndex = column.elements.length;
                    column.elements.splice(endIndex, 0, node);
                    this.selectElement(si, ci, endIndex, false);
                    this.toast((section.columns.length > 1 ? this.uiText.insertedCol.replace(":n", ci + 1) : this.uiText.inserted).replace(":label", label || this.uiText.elementWord));
                    return true;
                }
                if (target.kind !== "element") return false;
                var parts = String(target.path || "").split(".").map(function (value) { return parseInt(value, 10); });
                if (parts.length < 3 || parts.length > 4 || parts.some(function (value) { return isNaN(value); })) return false;
                si = parts[0];
                ci = parts[1];
                var targetSection = this.sections[si];
                var targetColumn = targetSection && targetSection.columns ? targetSection.columns[ci] : null;
                if (!targetColumn) return false;
                var position = target.position === "before" ? "before" : "after";
                if (parts.length === 4) {
                    var parent = targetColumn.elements && targetColumn.elements[parts[2]];
                    var children = parent && parent.data ? (parent.data.children || []) : [];
                    if (!parent || !this.elSchema(parent.type).container || !children[parts[3]]) return false;
                    if (!this.canNestElement(parent, node)) {
                        this.toast(this.isLoopTemplateHost(parent) ? <?php echo json_encode(__('blox_loop_child_invalid'), JSON_UNESCAPED_UNICODE); ?> : this.uiText.noNestedContainer);
                        return true;
                    }
                    var childIndex = parts[3] + (position === "before" ? 0 : 1);
                    children.splice(Math.min(children.length, childIndex), 0, node);
                    this.selectChild(si, ci, parts[2], Math.min(children.length - 1, childIndex), false);
                    this.toast((position === "before" ? this.uiText.insertedBefore : this.uiText.insertedAfter).replace(":label", label || this.uiText.elementWord));
                    return true;
                }
                var elements = targetColumn.elements || [];
                if (!elements[parts[2]]) return false;
                var index = parts[2] + (position === "before" ? 0 : 1);
                elements.splice(Math.min(elements.length, index), 0, node);
                this.selectElement(si, ci, Math.min(elements.length - 1, index), false);
                this.toast((position === "before" ? this.uiText.insertedBefore : this.uiText.insertedAfter).replace(":label", label || this.uiText.elementWord));
                return true;
            },

            openRevisions() {
                this.revisionOpen = true;
                this.focusDialog(this.$refs.revisionDialog, "[data-dialog-initial]");
                this.loadRevisions();
            },

            closeRevisions() {
                if (!this.revisionOpen) return;
                var root = this.$refs.revisionDialog;
                this.revisionOpen = false;
                this.releaseDialog(root);
            },

            loadRevisions() {
                var self = this;
                this.revisionLoading = true;
                fetch("/admin/revision.php?action=list&type=page&id=<?php echo $id; ?>")
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.code === 0) {
                            self.revisions = res.data.items || [];
                            if (!self.activeRev && self.revisions.length) self.previewRevision(self.revisions[0]);
                        } else {
                            self.toast(res.msg || self.uiText.historyLoadFailed);
                        }
                    })
                    .catch(function () { self.toast(self.uiText.historyFailed); })
                    .finally(function () { self.revisionLoading = false; });
            },

            previewRevision(rev) {
                var self = this;
                this.activeRev = rev;
                this.revisionPreview = "<!doctype html><html><body style='font-family:system-ui;padding:24px;color:#94a3b8'>" + this.uiText.revisionLoading + "</body></html>";
                fetch("/admin/revision.php?action=preview&type=page&id=<?php echo $id; ?>&rev_id=" + encodeURIComponent(rev.id))
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.code === 0) {
                            self.revisionPreview = "<!doctype html><html><head><meta charset='utf-8'><link rel='stylesheet' href='/assets/css/tailwind.css'><link rel='stylesheet' href='/assets/css/style.css'><link rel='stylesheet' href='/assets/tabler/tabler-icons.min.css'><link rel='stylesheet' href='/assets/bootstrap-icons/bootstrap-icons.min.css'><style>body{margin:0;background:#fff}</style></head><body>" + (res.data.html || "") + "</body></html>";
                        } else {
                            self.revisionPreview = "<!doctype html><html><body style='font-family:system-ui;padding:24px;color:#ef4444'>" + self.uiText.revisionPreviewFailed + "</body></html>";
                        }
                    })
                    .catch(function () { self.revisionPreview = "<!doctype html><html><body style='font-family:system-ui;padding:24px;color:#ef4444'>" + self.uiText.revisionPreviewFailed + "</body></html>"; });
            },

            restoreRevision(rev) {
                if (!rev || this.revisionRestoring) return;
                var msg = this.dirty ? this.uiText.restoreConfirmDirty : this.uiText.restoreConfirm;
                if (!confirm(msg)) return;
                var self = this;
                this.revisionRestoring = true;
                var fd = new FormData();
                fd.append("action", "restore");
                fd.append("type", "page");
                fd.append("id", "<?php echo $id; ?>");
                fd.append("rev_id", rev.id);
                fd.append("_token", this.csrf);
                fetch("/admin/revision.php", { method: "POST", body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res.code === 0) {
                            self.toast(self.uiText.restoredReloading);
                            setTimeout(function () { location.reload(); }, 500);
                        } else {
                            self.toast(res.msg || "Restore failed");
                        }
                    })
                    .catch(function () { self.toast(self.uiText.restoreFailed); })
                    .finally(function () { self.revisionRestoring = false; });
            },

            homeAction(action) {
                if (!this.homeMode || this.homeActionBusy) return;
                if (action === "rollback" && this.dirty) {
                    this.toast(this.homeText.publishRequiresSaved);
                    return;
                }
                var message = action === "publish" ? this.homeText.publishConfirm : this.homeText.rollbackConfirm;
                if (!window.confirm(message)) return;

                var self = this;
                var savedData = null;
                var payload = "";
                if (action === "publish") {
                    this.flushHistory(true);
                    savedData = this.historyData();
                    payload = this.documentData();
                }
                this.homeActionBusy = true;
                var body = new URLSearchParams();
                body.set("action", action);
                if (action === "publish") {
                    body.set("blocks_data", payload);
                    body.set("base_revision", this.baseRevision);
                }
                body.set("_token", this.csrf);
                fetch("/admin/blox_home_api.php", { method: "POST", body: body })
                    .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
                    .then(function (res) {
                        if (res && Number(res.code) === 409) {
                            self.showSaveConflict();
                            return;
                        }
                        if (!res || res.success === false
                            || (typeof res.code !== "undefined" && Number(res.code) !== 0)) {
                            self.toast((res && (res.message || res.msg)) || self.homeText.actionFailed);
                            return;
                        }
                        if (action === "publish") {
                            self.acceptSavedDocument(payload, savedData, res);
                        }
                        self.homePublished = action === "publish";
                        self.toast(action === "publish" ? self.homeText.publishDone : self.homeText.rollbackDone);
                    })
                    .catch(function () { self.toast(self.homeText.actionFailed); })
                    .finally(function () { self.homeActionBusy = false; });
            },

            publishTemplate() {
                var self = this;
                this.flushHistory(true);
                var savedData = this.historyData();
                var payload = this.documentData();
                var replaceThemeArea = "<?php echo e($replaceThemeAreaOnPublish); ?>";
                if (!confirm(replaceThemeArea ? this.uiText.tplPublishReplaceConfirm : this.uiText.tplPublishConfirm)) return;
                this.saving = true;
                this.submitTemplatePublish(false, payload, savedData)
                    .catch(function () { self.toast(self.uiText.saveFailed); })
                    .finally(function () { self.saving = false; });
            },

            submitTemplatePublish(confirmConflict, payload, savedData) {
                var self = this;
                var body = new URLSearchParams();
                body.set("action", "publish");
                body.set("id", "<?php echo (int) $templateId; ?>");
                body.set("blocks_data", payload);
                body.set("base_revision", this.baseRevision);
                body.set("_token", this.csrf);
                var replaceThemeArea = "<?php echo e($replaceThemeAreaOnPublish); ?>";
                if (replaceThemeArea) body.set("replace_theme_area", replaceThemeArea);
                if (confirmConflict) body.set("confirm_conflict", "1");
                // 必须 return 整条 Promise：调用方 publishTemplate() 会 .catch()/.finally()，
                // 丢了 return 则链在 undefined 上抛 "Cannot read properties of undefined (reading 'catch')"。
                return fetch("/admin/blox_template_api.php", { method: "POST", body: body })
                    .then(function (r) { return r.json().catch(function () { return { code: 1 }; }); })
                    .then(function (res) {
                        if (Number(res.code) === 409) {
                            if (res.msg === self.uiText.saveConflict) {
                                self.showSaveConflict();
                                return;
                            }
                            if (!confirmConflict && window.confirm(res.msg || self.uiText.tplPublishConfirm)) {
                                return self.submitTemplatePublish(true, payload, savedData);
                            }
                            return;
                        }
                        if (Number(res.code) === 0) {
                            self.acceptSavedDocument(payload, savedData, res);
                            var activated = res.data && res.data.activated_area;
                            self.toast(activated ? self.uiText.tplPublishedAndUsed : self.uiText.tplPublished);
                        }
                        else self.toast(self.uiText.saveFailedMsg.replace(":msg", res.msg || ""));
                    });
            },

            publishPage() {
                if (!this.pageMode || this.pageActionBusy) return;
                if (!window.confirm(this.pageText.publishConfirm)) return;

                this.flushHistory(true);
                var savedData = this.historyData();
                var payload = this.documentData();
                var self = this;
                var body = new URLSearchParams();
                body.set("action", "publish");
                body.set("id", "<?php echo (int) $id; ?>");
                body.set("blocks_data", payload);
                body.set("base_revision", this.baseRevision);
                body.set("_token", this.csrf);
                this.pageActionBusy = true;
                fetch(this.endpoint, { method: "POST", body: body })
                    .then(function(r) { return r.json().catch(function() { return { success: false }; }); })
                    .then(function(res) {
                        if (res && Number(res.code) === 409) {
                            self.showSaveConflict();
                            return;
                        }
                        var ok = res && res.success !== false
                            && (typeof res.code === "undefined" || Number(res.code) === 0);
                        if (!ok) {
                            self.toast((res && (res.message || res.msg)) || self.pageText.actionFailed);
                            return;
                        }
                        self.acceptSavedDocument(payload, savedData, res);
                        self.pagePublished = true;
                        self.pageHasUnpublishedChanges = false;
                        self.toast(self.pageText.publishDone);
                    })
                    .catch(function() { self.toast(self.pageText.actionFailed); })
                    .finally(function() { self.pageActionBusy = false; });
            },

            publishHome() {
                this.homeAction("publish");
            },

            rollbackHome() {
                this.homeAction("rollback");
            },

            acceptSavedDocument(payload, savedData, res) {
                if (res.data && typeof res.data.base_revision === "string") {
                    this.baseRevision = res.data.base_revision;
                }
                this._savedSnapshot = savedData;
                this._savedDocumentSnapshot = payload;
                this.dirty = this.documentData() !== payload;
                if (this.pageMode && res.data && typeof res.data.has_unpublished_changes !== "undefined") {
                    this.pageHasUnpublishedChanges = !!res.data.has_unpublished_changes;
                }
                if (this.currentThemeHeaderMode && window.history && window.URL) {
                    var currentUrl = new URL(window.location.href);
                    currentUrl.searchParams.delete("current_header");
                    window.history.replaceState(null, "", currentUrl.pathname + currentUrl.search + currentUrl.hash);
                    this.currentThemeHeaderMode = false;
                }
                var recovery = this.draftRecovery();
                if (recovery) {
                    if (this.dirty) this.queueDraftRecovery();
                    else recovery.clear();
                }
            },

            save() {
                var self = this;
                this.flushHistory(true);
                var savedData = this.historyData();
                // 保存载荷 = v1 信封（服务端 Pipeline::migrate 严格校验）；
                // 历史 data 保持裸 sections，文档设置由快照 settings 字段独立追踪。
                var payload = this.documentData();
                this.saving = true;
                var body = new URLSearchParams();
                <?php if ($templateId): ?>
                body.set("action", "save_draft");
                body.set("id", "<?php echo (int) $templateId; ?>");
                body.set("blocks_data", payload);
                <?php elseif ($isHomeBlox): ?>
                body.set("blocks_data", payload);
                <?php else: ?>
                body.set("action", "save_draft");
                body.set("id", "<?php echo (int) $id; ?>");
                body.set("blocks_data", payload);
                <?php endif; ?>
                body.set("base_revision", this.baseRevision);
                body.set("_token", this.csrf);
                fetch(this.endpoint, { method: "POST", body: body })
                    .then(function(r) { return r.json().catch(function() { return { success: false }; }); })
                    .then(function(res) {
                        if (res && Number(res.code) === 409) {
                            self.showSaveConflict();
                            return;
                        }
                        var ok = res && res.success !== false
                            && (typeof res.code === "undefined" || Number(res.code) === 0);
                        if (ok) {
                            self.acceptSavedDocument(payload, savedData, res);
                            self.toast(self.uiText.saved);
                        } else {
                            self.toast(self.uiText.saveFailedMsg.replace(":msg", res.message || res.msg || ""));
                        }
                    })
                    .catch(function() { self.toast(self.uiText.saveFailed); })
                    .finally(function() { self.saving = false; });
            },

            toast(msg) {
                var self = this;
                this.toastMsg = msg;
                clearTimeout(this._tt);
                this._tt = setTimeout(function() { self.toastMsg = ""; }, 2200);
            },

            clearSiteCache() {
                if (this.cacheClearing || !confirm(<?php echo json_encode(__('scache_clear_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
                var self = this;
                var body = new URLSearchParams({ _token: this.csrf });
                this.cacheClearing = true;
                fetch("/admin/blox_cache_api.php", { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        self.toast(result.msg || (Number(result.code) === 0 ? <?php echo json_encode(__('scache_clear_now'), JSON_UNESCAPED_UNICODE); ?> : <?php echo json_encode(__('admin_failed'), JSON_UNESCAPED_UNICODE); ?>));
                    })
                    .catch(function () { self.toast(<?php echo json_encode(__('admin_failed'), JSON_UNESCAPED_UNICODE); ?>); })
                    .finally(function () { self.cacheClearing = false; });
            },
        };
    }
    </script>
</body>
</html>
