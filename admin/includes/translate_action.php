<?php
/**
 * 通用翻译创建器 —— 各编辑页 require_once 这个文件，
 * 拦截 action=create_translation 的 POST 请求，做：
 *   1. 读源行（zh-CN 或当前 lang）
 *   2. AI 翻译标题/摘要 / 名字/描述（短字段；长 content 保留原文）
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

$supported = array_keys(availableLanguages());
if ($srcId <= 0 || !in_array($toLang, $supported, true)) {
    error('参数错误');
}

$model    = $langSwitcher['model'];
$table    = (string) $langSwitcher['table'];
$tName    = DB_PREFIX . $table;
$titleKey = $langSwitcher['title_field']   ?? 'title';
$summKey  = $langSwitcher['summary_field'] ?? 'summary';

$src = $model->find($srcId);
if (!$src) error('源记录不存在');

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
if ($existing) {
    success(['id' => (int) $existing['id']], '已有该语言版本');
}

// AI 翻译短字段
$srcTitle = (string) ($src[$titleKey] ?? '');
$srcSumm  = (string) ($src[$summKey]  ?? '');
$translated = aiTranslateFields($srcTitle, $srcSumm, $toLang);

// 拷贝源行所有列
$newData = $src;
unset($newData['id']);
$newData['lang']                 = $toLang;
$newData['translation_group_id'] = $groupId;
$newData[$titleKey]              = $translated['title'];
$newData[$summKey]               = $translated['summary'];
$newData['created_at']           = time();
$newData['updated_at']           = time();

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
if (array_key_exists('seo_title', $newData) && $newData['seo_title']) {
    $newData['seo_title'] = $translated['title'];
}
if (array_key_exists('seo_description', $newData) && $newData['seo_description']) {
    $newData['seo_description'] = $translated['summary'];
}

$newId = (int) $model->create($newData);
adminLog('translate', 'create', "为 {$table}#{$srcId} 创建 {$toLang} 翻译 → #{$newId}");
success(['id' => $newId], '翻译已创建');
