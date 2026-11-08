<?php
/**
 * 译文审阅动作（评审 T04），由 translate_action.php 分派，各编辑页共用：
 *   translation_mark_reviewed  人确认这份译文对应当前原文（source_fp / target_fp：页面打开时的指纹）
 *   translation_accept         逐字段采用待审译文（fields[] + proposal_rev）
 *   translation_discard        放弃待审译文（proposal_rev）
 * 都按译文行本身的真实类型判权（与创建译文同一个守卫）。
 * 都只认页面打开时看到的版本：之后有人又生成或改过，返回 409 请人刷新重看（评审 F04）。
 */

declare(strict_types=1);

verifyCsrf();
require_once ROOT_PATH . '/includes/TranslationWrite.php';

/** @var array<string, mixed> $langSwitcher */
$_tr_model = $langSwitcher['model'];
$_tr_table = (string) $langSwitcher['table'];
$_tr_titleKey = (string) ($langSwitcher['title_field'] ?? 'title');
$_tr_summaryKey = (string) ($langSwitcher['summary_field'] ?? 'summary');
$_tr_target = $_tr_model->find((int) ($_POST['target_id'] ?? 0));
if (!$_tr_target || !empty($_tr_target['deleted_at']) || ($_tr_target['lang'] ?? '') === config('site_lang', 'zh-CN')) error(__('lse_failed'));
requireTranslationPermission($_tr_table, $_tr_target, (string) ($langSwitcher['content_type'] ?? ''));
$_tr_id = (int) $_tr_target['id'];
$_tr_hasPage = $_tr_table === 'channels' && ($_tr_target['type'] ?? '') === 'page';
$_tr_writer = new TranslationWrite(db());
$_tr_revision = (string) ($_POST['proposal_rev'] ?? '');
$_tr_conflict = static function (string $conflict): void {
    json(['code' => 409, 'msg' => __('lse_accept_' . $conflict), 'data' => ['conflict' => $conflict]], 409);
};

switch ((string) $_POST['action']) {
    case 'translation_discard':
        $_tr_result = $_tr_writer->discard($_tr_table, $_tr_id, $_tr_revision);
        if ($_tr_result !== '') $_tr_conflict($_tr_result);
        adminLog('translate', 'discard', "{$_tr_table}#{$_tr_id} 放弃待审译文");
        success(['id' => $_tr_id], __('lse_proposal_discarded'));

    case 'translation_accept':
        $_tr_fields = array_values(array_filter(array_map('strval', (array) ($_POST['fields'] ?? []))));
        $_tr_result = $_tr_writer->accept([
            'table' => $_tr_table, 'model' => $_tr_model, 'target_id' => $_tr_id,
            'title_key' => $_tr_titleKey, 'summary_key' => $_tr_summaryKey, 'has_page' => $_tr_hasPage,
        ], $_tr_fields, (int) ($_SESSION['admin_id'] ?? 0), $_tr_revision);
        if ($_tr_result !== '') $_tr_conflict($_tr_result);
        success(['id' => $_tr_id], __('lse_accept_done'));

    case 'translation_mark_reviewed':
        // 源 = 翻译组的根行（站点默认语言）
        $_tr_source = $_tr_model->find((int) ($_tr_target['translation_group_id'] ?? 0));
        if (!$_tr_source || ($_tr_source['lang'] ?? '') !== config('site_lang', 'zh-CN')) error(__('lse_failed'));
        $_tr_result = $_tr_writer->markReviewed([
            'table' => $_tr_table, 'target_id' => $_tr_id, 'title_key' => $_tr_titleKey, 'summary_key' => $_tr_summaryKey, 'has_page' => $_tr_hasPage,
        ], (int) $_tr_source['id'], (string) ($_POST['source_fp'] ?? ''), (string) ($_POST['target_fp'] ?? ''), (int) ($_SESSION['admin_id'] ?? 0));
        if ($_tr_result !== '') $_tr_conflict($_tr_result);
        success(['id' => $_tr_id], __('lse_marked_reviewed'));
}
error(__('lse_failed'));
