<?php
/**
 * 通用翻译创建器 —— 各编辑页 require_once 这个文件，
 * 拦截 action=create_translation 的 POST 请求，做：
 *   1. 读源行（zh-CN 或当前 lang）
 *   2. AI 翻译标题、摘要、正文及 SEO 文本，保留布局与资源地址
 *   3. 在目标 lang 创建新行（slug 加后缀避免 uk_slug 冲突）
 *   4. 维护 translation_group_id 串起所有翻译版本
 *   5. 对带 channel_id 的内容 (contents/products)，自动重映射到目标语言频道
 *   6. 返回新行 ID 给前端跳转
 *
 * 调用约定（在编辑页顶部）：
 *   $langSwitcher = [
 *       'table' => 'channels',
 *       'model' => channelModel(),
 *       'item'  => $channel,
 *       'edit_url' => '/admin/channel_edit.php',
 *       'title_field' => 'name',           // 可选：默认 'title'，channels 用 'name'
 *       'summary_field' => 'description',  // 可选：默认 'summary'
 *   ];
 *   require_once ROOT_PATH . '/admin/includes/translate_action.php';
 */

declare(strict_types=1);

if (empty($langSwitcher) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || (($_POST['action'] ?? '') !== 'create_translation')) {
    return;
}

verifyCsrf();

$srcId  = (int) ($_POST['src_id'] ?? 0);
$toLang = (string) ($_POST['to_lang'] ?? '');
$retranslate = (int) ($_POST['retranslate'] ?? 0) === 1;

$supported = array_keys(availableLanguages());
if ($srcId <= 0 || !in_array($toLang, $supported, true)) {
    error('参数错误');
}

/** @var array<string, mixed> $langSwitcher 各编辑页自行声明，键集因页而异（title_field / content_type 等可选） */
$model    = $langSwitcher['model'];
$table    = (string) $langSwitcher['table'];
$tName    = DB_PREFIX . $table;
$titleKey = $langSwitcher['title_field']   ?? 'title';
$summKey  = $langSwitcher['summary_field'] ?? 'summary';

$src = $model->find($srcId);
if (!$src) error('源记录不存在');

// 任何写库、AI 调用之前先判权：按源记录的真实类型，而不是按当前页面的模块权限。
requireTranslationPermission($table, $src, (string) ($langSwitcher['content_type'] ?? ''));
if ($retranslate && ($src['lang'] ?? '') !== config('site_lang', 'zh-CN')) {
    $original = $model->find((int) ($src['translation_group_id'] ?: $src['id']));
    if (!$original || ($original['lang'] ?? '') === $toLang) error(__('lse_failed'));
    requireTranslationPermission($table, $original, (string) ($langSwitcher['content_type'] ?? ''));
    $src = $original;
    $srcId = (int) $src['id'];
}
if (($src['lang'] ?? '') === $toLang) error(__('lse_failed'));

// translation group
$groupId = (int) ($src['translation_group_id'] ?: $src['id']);
if ((int) $src['translation_group_id'] === 0) {
    db()->execute("UPDATE {$tName} SET translation_group_id = ? WHERE id = ?", [$src['id'], $src['id']]);
}

// 已存在该语言翻译则直接返回
$existing = db()->fetchOne(
    "SELECT id FROM {$tName} WHERE translation_group_id = ? AND lang = ? LIMIT 1",
    [$groupId, $toLang]
);
if ($existing && !$retranslate) {
    success(['id' => (int) $existing['id']], '已有该语言版本');
}
$target = $existing ? $model->find((int) $existing['id']) : null;
if ($target) requireTranslationPermission($table, $target, (string) ($langSwitcher['content_type'] ?? ''));

// AI 翻译短字段
$srcTitle = (string) ($src[$titleKey] ?? '');
$srcSumm  = (string) ($src[$summKey]  ?? '');
$pageContent = null;
if ($table === 'channels' && ($src['type'] ?? '') === 'page') {
    $pageContent = contentModel()->queryOne('SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? ORDER BY is_top DESC, id DESC LIMIT 1', [$srcId]);
}
$bodySource = $pageContent ?: $src;
$bodyField = (($bodySource['content_type'] ?? '') === 'blox' && !empty($bodySource['blocks_data'])) ? 'blocks_data' : 'content';
{
    require_once ROOT_PATH . '/includes/AiService.php';
    require_once ROOT_PATH . '/includes/content_translation.php';
    try {
        $translationSource = $src;
        $translationSource['title'] = $srcTitle;
        $translationSource['summary'] = $srcSumm;
        if ($summKey !== 'summary' && array_key_exists('summary', $src)) $translationSource['secondary_summary'] = (string) $src['summary'];
        $translationSource['content'] = (string) ($bodySource[$bodyField] ?? '');
        $translated = translateContentFields($translationSource, $toLang, [aiService(), 'chat']);
    } catch (Throwable $exception) {
        error(__('lse_failed') . ': ' . $exception->getMessage());
    }
}

// 拷贝源行所有列
$newData = $src;
unset($newData['id']);
$newData['lang']                 = $toLang;
$newData['translation_group_id'] = $groupId;
$newData[$titleKey]              = $translated['title'];
$newData[$summKey]               = $translated['summary'];
if (isset($translated['secondary_summary'])) $newData['summary'] = $translated['secondary_summary'];
foreach ($translated as $field => $value) {
    if ($field !== 'title' && $field !== 'summary' && $field !== 'content' && array_key_exists($field, $src)) $newData[$field] = $value;
}
if (array_key_exists($bodyField, $src)) $newData[$bodyField] = $translated['content'];
$newData['created_at']           = time();
if (array_key_exists('updated_at', $src)) $newData['updated_at'] = time();

// 机器翻译先落草稿：整行拷贝会连 status 一起继承，源是已发布时译文就直接上线了，
// 没有任何人看过那段 AI 译文。发布必须是明确的下一步动作（复审 R01）。
if (array_key_exists('status', $newData)) {
    $newData['status'] = 0;
}

// slug 加语言后缀避免 uk_slug 冲突（仅当源有 slug 字段且非空）
if (!empty($newData['slug'])) {
    $newData['slug'] = $newData['slug'] . '-' . $toLang;
    // 防御：万一 slug-{lang} 已存在（如先前手工建过），加随机后缀
    $exists = db()->fetchOne("SELECT id FROM {$tName} WHERE slug = ?", [$newData['slug']]);
    if ($exists) {
        $newData['slug'] .= '-' . substr(md5((string) microtime(true)), 0, 6);
    }
}

// 内容/产品类有 channel_id —— 重映射到目标语言频道（如已存在）
if (isset($newData['channel_id']) && (int) $newData['channel_id'] > 0) {
    $mapped = findTranslatedChannelId((int) $newData['channel_id'], $toLang);
    if ($mapped > 0) $newData['channel_id'] = $mapped;
}

// SEO 字段同步翻译（如存在）
if (!isset($translated['seo_title']) && array_key_exists('seo_title', $newData) && $newData['seo_title']) {
    $newData['seo_title'] = $translated['title'];
}
if (!isset($translated['seo_description']) && array_key_exists('seo_description', $newData) && $newData['seo_description']) {
    $newData['seo_description'] = $translated['summary'];
}

db()->beginTransaction();
try {
if ($target) {
    $changes = [$titleKey => $translated['title'], $summKey => $translated['summary']];
    if (isset($translated['secondary_summary'])) $changes['summary'] = $translated['secondary_summary'];
    foreach ($translated as $field => $value) {
        if (!in_array($field, ['title', 'summary', 'content'], true) && array_key_exists($field, $target)) $changes[$field] = $value;
    }
    if (array_key_exists($bodyField, $target)) $changes[$bodyField] = $translated['content'];
    if (array_key_exists('status', $target)) $changes['status'] = 0;
    if (array_key_exists('updated_at', $target)) $changes['updated_at'] = time();
    $revisionType = match ($table) {
        'contents' => (string) ($src['type'] ?? 'article'),
        'products' => 'product',
        'channels' => 'page',
        'jobs' => 'job',
        'downloads' => 'download',
        default => $table,
    };
    recordContentRevision($revisionType, (int) $target['id'], $toLang, [['table' => $table, 'id' => (int) $target['id'], 'fields' => array_intersect_key($target, $changes)]], (string) ($target[$titleKey] ?? ''));
    $model->updateById((int) $target['id'], $changes);
    $newId = (int) $target['id'];
} else $newId = (int) $model->create($newData);
if ($pageContent) {
    $translatedPage = $pageContent;
    unset($translatedPage['id']);
    $translatedPage['channel_id'] = $newId;
    $translatedPage['lang'] = $toLang;
    $translatedPage['translation_group_id'] = (int) ($pageContent['translation_group_id'] ?: $pageContent['id']);
    $translatedPage['title'] = $translated['title'];
    $translatedPage['summary'] = $translated['summary'];
    $translatedPage[$bodyField] = $translated['content'];
    $translatedPage['status'] = 0;
    $translatedPage['slug'] = resolveSlug('', (string) $translated['title'], 'contents');
    $translatedPage['created_at'] = time();
    $translatedPage['updated_at'] = time();
    $existingPage = $target ? contentModel()->queryOne('SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? ORDER BY id DESC LIMIT 1', [$newId]) : null;
    if ($existingPage) {
        $pageChanges = ['title' => $translated['title'], 'summary' => $translated['summary'], $bodyField => $translated['content'], 'status' => 0, 'updated_at' => time()];
        recordContentRevision('page', $newId, $toLang, [['table' => 'contents', 'id' => (int) $existingPage['id'], 'fields' => array_intersect_key($existingPage, $pageChanges)]], (string) $existingPage['title']);
        contentModel()->updateById((int) $existingPage['id'], $pageChanges);
    } else contentModel()->create($translatedPage);
}
// 扩展字段值随新语言版本带过去（2.0.4；之后各语言各自维护）
$extOwner = match ($table) {
    'products' => 'product',
    'contents' => resolveExtFieldOwner((string) ($src['type'] ?? '')),
    'channels' => 'channel',
    'product_categories' => 'product_category',
    default => '',
};
if ($extOwner !== '' && !$target) ExtFields::copyValues($extOwner, $srcId, $newId);
adminLog('translate', 'create', "为 {$table}#{$srcId} 创建 {$toLang} 翻译 → #{$newId}");
db()->commit();
} catch (Throwable $exception) {
    db()->rollback();
    error(__('lse_failed') . ': ' . $exception->getMessage());
}
success(['id' => $newId], '翻译已创建');
