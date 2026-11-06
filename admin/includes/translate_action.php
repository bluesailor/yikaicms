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
try {
    $translated = TranslationWrite::generate(TranslationWrite::source($src, $titleKey, $summKey, $bodySource, $bodyField), $toLang);
} catch (Throwable $exception) {
    error(__('lse_failed') . ': ' . $exception->getMessage());
}
$newData = TranslationWrite::newRow($table, $src, $translated, $toLang, $groupId, $titleKey, $summKey, $bodyField);

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
