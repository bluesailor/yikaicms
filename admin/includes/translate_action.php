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
require_once ROOT_PATH . '/includes/content_translation.php';
require_once ROOT_PATH . '/includes/TranslationWrite.php';
$bodyField = translationBodyField($bodySource);
// 调模型之前的状态：写库时逐行比对，期间有人改过就不覆盖（见 TranslationWrite）
$translationRead = [
    'table' => $table, 'model' => $model, 'lang' => $toLang, 'group_id' => $groupId,
    'src' => ['translation_group_id' => $groupId] + $src, 'src_page' => $pageContent,
    'target' => $target,
    'target_page' => $target && $pageContent ? contentModel()->queryOne('SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? ORDER BY id DESC LIMIT 1', [(int) $target['id']]) : null,
    'title_key' => $titleKey, 'summary_key' => $summKey, 'body_field' => $bodyField,
];
{
    require_once ROOT_PATH . '/includes/AiService.php';
    require_once ROOT_PATH . '/includes/content_translation.php';
    try {
        $translationSource = $src;
        $translationSource['title'] = $srcTitle;
        $translationSource['summary'] = $srcSumm;
        if ($summKey !== 'summary' && array_key_exists('summary', $src)) $translationSource['secondary_summary'] = (string) $src['summary'];
        $translationSource['content'] = (string) ($bodySource[$bodyField] ?? '');
        if (function_exists('set_time_limit')) @set_time_limit(180);
        $translationDeadline = microtime(true) + 120;
        $translationModel = trim((string) config('ai_translation_model', ''));
        $translationAi = $translationModel !== '' ? new AiService(null, null, $translationModel) : aiService();
        $translationChat = static function (string $prompt, string $system = '', float $temperature = 0.7, bool $jsonObject = false) use ($translationDeadline, $translationAi): array {
            // Each provider request may take up to 60 seconds; leave room to return a JSON error.
            if (microtime(true) + 61 >= $translationDeadline) throw new RuntimeException('Translation time budget exceeded. The existing draft has not been changed.');
            return $translationAi->chat($prompt, $system, $temperature, $jsonObject);
        };
        $translated = translateContentFields($translationSource, $toLang, $translationChat, true);
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
if ($table === 'channels' && (int) ($newData['parent_id'] ?? 0) > 0) {
    $mappedParent = findTranslatedChannelId((int) $newData['parent_id'], $toLang);
    if ($mappedParent > 0) $newData['parent_id'] = $mappedParent;
}

// SEO 字段同步翻译（如存在）
if (!isset($translated['seo_title']) && array_key_exists('seo_title', $newData) && $newData['seo_title']) {
    $newData['seo_title'] = $translated['title'];
}
if (!isset($translated['seo_description']) && array_key_exists('seo_description', $newData) && $newData['seo_description']) {
    $newData['seo_description'] = $translated['summary'];
}

try {
    $written = (new TranslationWrite(db()))->apply($translationRead, $translated, $newData);
} catch (Throwable $exception) {
    error(__('lse_failed') . ': ' . $exception->getMessage());
}
// 同时有人建好了这一语言：与「已有该语言版本」一样，跳过去
if ($written['conflict'] === 'target_exists') success(['id' => $written['id']], '已有该语言版本');
// 模型请求期间内容被改过：一个字都不写，把生成的译文交还给编辑页，由人决定怎么用
if ($written['conflict'] !== '') {
    json(['code' => 409, 'msg' => __('lse_conflict_' . $written['conflict']), 'data' => [
        'conflict' => $written['conflict'],
        'id' => $written['id'],
        'translation' => ['title' => (string) $translated['title'], 'summary' => (string) $translated['summary'], 'content' => (string) $translated['content']],
    ]], 409);
}
success(['id' => $written['id']], '翻译已创建');
