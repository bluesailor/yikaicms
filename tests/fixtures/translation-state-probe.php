<?php
/**
 * 译文来源追踪与保护（评审 T04）的行为探针（独立进程、完整表结构、假模型）。
 * 每个场景重建数据，打印 JSON，由 TranslationStateTest 断言。
 *
 * 用法：php translation-state-probe.php <场景名>
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', 'sqlite');
define('DB_PATH', ':memory:');
define('DB_PREFIX', 'yikai_');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', true);

require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/TranslationBatches.php';
db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql'));
foreach (['settings', 'channels', 'contents', 'products', 'content_revisions'] as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);

function config(string $key, mixed $default = ''): mixed { return $key === 'site_lang' ? 'zh-CN' : $default; }
function findTranslatedChannelId(int $channelId, string $lang): int { return 0; }
function resolveExtFieldOwner(string $type): string { return ''; }
function adminLog(string $module, string $action, string $detail = ''): void {}
function recordContentRevision(string $type, int $targetId, string $lang, array $targets, string $summary): void {}
final class ExtFields
{
    public static function copyValues(string $owner, int $from, int $to): void {}
    /** @return list<array<string,mixed>> */
    public static function fields(string $owner, bool $onlyEnabled = true): array { return []; }
}

$now = 1800000000;
$product = static fn (string $title, string $lang = 'zh-CN', int $group = 0, int $status = 1): int => (int) productModel()->create([
    'title' => $title, 'summary' => '摘要', 'content' => '<p>正文</p>', 'slug' => 'p-' . bin2hex(random_bytes(3)),
    'lang' => $lang, 'translation_group_id' => $group, 'status' => $status, 'created_at' => $now, 'updated_at' => $now,
]);
$calls = [];
$generator = static function (array $source, string $lang) use (&$calls): array {
    $calls[] = $source['title'] . '→' . $lang;
    return ['title' => "[{$lang}] {$source['title']}", 'summary' => 'Summary', 'content' => "<p>Body of {$source['title']}</p>"];
};
$batches = new TranslationBatches(db(), Closure::fromCallable($generator));
/** 批量「翻译缺失项」建出机器译文，返回各源的英文译文 id */
$translate = static function (array $ids) use ($batches, $now): array {
    $created = $batches->create(1, $ids, ['en'], 'zh-CN', $now);
    $batches->step($created['batch_id'], 30, static fn (): int => $now);
    $targets = [];
    foreach ($ids as $id) $targets[$id] = (int) db()->fetchColumn("SELECT id FROM yikai_products WHERE translation_group_id = ? AND lang = 'en'", [$id]);
    return $targets;
};
$states = new TranslationState(db());
$status = static fn (int $src, int $target): array => TranslationState::status($states->link('products', $target),
    TranslationState::rowFingerprint(productModel()->find($src), 'title', 'summary'), TranslationState::rowFingerprint(productModel()->find($target), 'title', 'summary'));
$read = static fn (int $src, int $target): array => [
    'table' => 'products', 'model' => productModel(), 'lang' => 'en', 'group_id' => $src,
    'src' => productModel()->find($src), 'src_page' => null, 'target' => productModel()->find($target), 'target_page' => null,
    'title_key' => 'title', 'summary_key' => 'summary', 'body_field' => 'content',
];
$ctx = static fn (int $target): array => ['table' => 'products', 'model' => productModel(), 'target_id' => $target, 'title_key' => 'title', 'summary_key' => 'summary', 'has_page' => false];
$row = static fn (int $id): array => db()->fetchOne('SELECT title, summary, content, status FROM yikai_products WHERE id = ?', [$id]) ?? [];
$writer = new TranslationWrite(db());
$rev = static fn (int $target): string => TranslationState::proposalRevision($states->link('products', $target));
$out = [];

switch ((string) ($argv[1] ?? '')) {
    case 'track':
        $a = $product('液压泵');
        $b = $product('齿轮泵');
        $t = $translate([$a, $b]);
        $out['fresh'] = $status($a, $t[$a]);
        $out['link'] = $states->link('products', $t[$a]);
        // 价格、排序、浏览数不是要翻译的内容；空参数表写成 {} 也不算改动
        db()->execute("UPDATE yikai_products SET price = 99, sort_order = 5, views = views + 3, specs = '{}', updated_at = updated_at + 9 WHERE id = ?", [$a]);
        $out['non_text_edit'] = $status($a, $t[$a]);
        db()->execute("UPDATE yikai_products SET title = '齿轮泵 V2' WHERE id = ?", [$b]);
        $out['title_edit'] = $status($b, $t[$b]);
        break;

    case 'protect_and_accept':
        $a = $product('液压泵');
        $t = $translate([$a])[$a];
        // 有人改了译文正文并发布；之后原文也改了
        db()->execute("UPDATE yikai_products SET content = '<p>HUMAN</p>', status = 1 WHERE id = ?", [$t]);
        db()->execute("UPDATE yikai_products SET title = '液压泵 V2' WHERE id = ?", [$a]);
        $out['before'] = $status($a, $t);
        $translated = $generator(productModel()->find($a), 'en');
        $out['apply'] = $writer->apply($read($a, $t), $translated, []);
        $out['after_apply'] = $row($t);
        $out['propose'] = $writer->propose($read($a, $t), $translated);
        $out['with_proposal'] = $status($a, $t);
        $out['accept_none'] = $writer->accept($ctx($t), ['not_a_field'], 7, $rev($t));
        $out['accept'] = $writer->accept($ctx($t), ['title', 'not_a_field'], 7, $rev($t));
        $out['after_accept'] = $row($t);
        $out['link'] = $states->link('products', $t);
        $out['accepted_status'] = $status($a, $t);
        $out['accept_again'] = $writer->accept($ctx($t), ['title'], 7, $rev($t));
        break;

    case 'accept_after_change':
        $a = $product('液压泵');
        $t = $translate([$a])[$a];
        db()->execute("UPDATE yikai_products SET content = '<p>HUMAN</p>' WHERE id = ?", [$t]);
        $writer->propose($read($a, $t), ['title' => 'Proposed', 'summary' => 'S', 'content' => '<p>P</p>']);
        db()->execute("UPDATE yikai_products SET content = '<p>HUMAN 2</p>' WHERE id = ?", [$t]);
        $out['accept'] = $writer->accept($ctx($t), ['title', 'content'], 7, $rev($t));
        $out['after'] = $row($t);
        break;

    case 'mark_reviewed':
        $a = $product('液压泵');
        $t = $product('Hand pump', 'en', $a, 1);
        db()->execute('UPDATE yikai_products SET translation_group_id = id WHERE id = ?', [$a]);
        $out['untracked'] = $status($a, $t);
        $out['untracked_apply'] = $writer->apply($read($a, $t), ['title' => 'X', 'summary' => 'X', 'content' => '<p>X</p>'], []);
        $states->markReviewed('products', $t, $a, 'en', TranslationState::rowFingerprint(productModel()->find($a), 'title', 'summary'),
            TranslationState::rowFingerprint(productModel()->find($t), 'title', 'summary'), 7, $now);
        $out['reviewed'] = $status($a, $t);
        db()->execute("UPDATE yikai_products SET summary = '新摘要' WHERE id = ?", [$a]);
        $out['reviewed_then_source_edit'] = $status($a, $t);
        break;

    case 'batch_update':
        $ids = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) $ids[$name] = $product('泵' . $name);
        $t = $translate(array_values($ids));
        $calls = [];
        db()->execute('UPDATE yikai_products SET status = 1 WHERE id = ?', [$t[$ids['B']]]);                       // 已发布的机器译文
        db()->execute("UPDATE yikai_products SET content = '<p>HUMAN</p>' WHERE id = ?", [$t[$ids['C']]]);         // 人改过
        db()->execute('DELETE FROM yikai_translation_links WHERE target_id = ?', [$t[$ids['E']]]);                 // 未追踪
        foreach (['A', 'B', 'C', 'E'] as $name) db()->execute("UPDATE yikai_products SET title = title || ' V2' WHERE id = ?", [$ids[$name]]);
        $created = $batches->create(1, array_values($ids), ['en'], 'zh-CN', $now, 'update');
        $out['created'] = $created;
        $out['counts'] = $batches->step($created['batch_id'], 30, static fn (): int => $now);
        $out['calls'] = $calls;
        foreach ($ids as $name => $id) $out['rows'][$name] = $row($t[$id]) + ['state' => $status($id, $t[$id])];
        // 已覆盖的 A 不再过期；待审的 B 仍过期，可以再排队重新生成
        $out['again'] = $batches->create(1, [$ids['A'], $ids['B']], ['en'], 'zh-CN', $now, 'update');
        break;

    case 'retranslate_live':
        // 评审 F01：机器译文没人改过，在列表里批量发布（只改 status，不记已审），然后单条「重新翻译」
        $a = $product('液压泵');
        $t = $translate([$a])[$a];
        db()->execute('UPDATE yikai_products SET status = 1 WHERE id = ?', [$t]);
        $out['before'] = $row($t) + ['state' => $status($a, $t)];
        $translated = ['title' => 'Retranslated', 'summary' => 'S2', 'content' => '<p>New</p>'];
        // 与 translate_action.php 同样的调用：不带任何额外参数
        $out['apply'] = $writer->apply($read($a, $t), $translated, []);
        $out['after_apply'] = $row($t);
        $out['propose'] = $writer->propose($read($a, $t), $translated);
        $out['after_propose'] = $row($t);
        $out['proposal_title'] = json_decode((string) ($states->link('products', $t)['proposal_json'] ?? ''), true)['title'] ?? null;
        $out['live'] = TranslationWrite::isLive(productModel()->find($t));
        $out['draft_live'] = TranslationWrite::isLive(['status' => 0]);
        $out['scheduled_live'] = TranslationWrite::isLive(['status' => 2]);
        $out['page_live'] = TranslationWrite::isLive(['status' => 0], ['status' => 1]);
        break;

    case 'no_review_ui':
        // 没有审阅界面的入口（产品分类列表）：已启用、没人碰过的译文照写但保持启用；人改过的拒绝
        $a = $product('液压泵');
        $b = $product('齿轮泵');
        $t = $translate([$a, $b]);
        db()->execute('UPDATE yikai_products SET status = 1 WHERE id IN (?, ?)', [$t[$a], $t[$b]]);
        db()->execute("UPDATE yikai_products SET content = '<p>HUMAN</p>' WHERE id = ?", [$t[$b]]);
        $translated = ['title' => 'Retranslated', 'summary' => 'S2', 'content' => '<p>New</p>'];
        $out['untouched'] = $writer->apply(['review_ui' => false] + $read($a, $t[$a]), $translated, []);
        $out['untouched_row'] = $row($t[$a]);
        $out['edited'] = $writer->apply(['review_ui' => false] + $read($b, $t[$b]), $translated, []);
        $out['edited_row'] = $row($t[$b]);
        break;

    case 'category_map':
        // 评审 F02：中文分类 → 德语分类在同一翻译组；英语没有对应分类；嵌套分类按自身的组映射
        $cat = static fn (string $name, string $lang, int $group = 0, int $parent = 0): int => (int) db()->insert('product_categories', [
            'name' => $name, 'slug' => 'c-' . bin2hex(random_bytes(3)), 'lang' => $lang, 'translation_group_id' => $group, 'parent_id' => $parent, 'created_at' => $now,
        ]);
        $zh = $cat('泵', 'zh-CN');
        $zhChild = $cat('液压泵', 'zh-CN', 0, $zh);
        $de = $cat('Pumpen', 'de', $zh);
        $deChild = $cat('Hydraulikpumpen', 'de', $zhChild, $de);
        $a = $product('液压泵');
        db()->execute('UPDATE yikai_products SET category_id = ? WHERE id = ?', [$zhChild, $a]);
        $src = productModel()->find($a);
        $translated = ['title' => 'X', 'summary' => 'S', 'content' => '<p>B</p>'];
        $deRow = TranslationWrite::newRow('products', $src, $translated, 'de', $a, 'title', 'summary', 'content');
        $enRow = TranslationWrite::newRow('products', $src, $translated, 'en', $a, 'title', 'summary', 'content');
        $out['ids'] = ['zh' => $zh, 'zh_child' => $zhChild, 'de' => $de, 'de_child' => $deChild];
        $out['de_category'] = (int) $deRow['category_id'];
        $out['en_category'] = (int) $enRow['category_id'];
        $out['de_unmapped'] = TranslationWrite::categoryUnmapped('products', $src, $deRow);
        $out['en_unmapped'] = TranslationWrite::categoryUnmapped('products', $src, $enRow);
        // 批量翻译走同一条 newRow：建出的德语产品落在德语分类下
        $created = $batches->create(1, [$a], ['de'], 'zh-CN', $now);
        $batches->step($created['batch_id'], 30, static fn (): int => $now);
        $out['batch_de_category'] = (int) db()->fetchColumn("SELECT category_id FROM yikai_products WHERE translation_group_id = ? AND lang = 'de'", [$a]);
        break;

    case 'stale_review':
        // 评审 F04：A 打开待审 P1；B 又生成 P2（译文没动）；A 在旧页面上采用 / 放弃 / 标为已审
        $a = $product('液压泵');
        $t = $translate([$a])[$a];
        db()->execute("UPDATE yikai_products SET content = '<p>HUMAN</p>', status = 1 WHERE id = ?", [$t]);
        $writer->propose($read($a, $t), ['title' => 'P1 title', 'summary' => 'S', 'content' => '<p>P1</p>']);
        $rev1 = $rev($t);
        $writer->propose($read($a, $t), ['title' => 'P2 title', 'summary' => 'S', 'content' => '<p>P2</p>']);
        $rev2 = $rev($t);
        $out['revisions_differ'] = $rev1 !== $rev2 && $rev1 !== '' && $rev2 !== '';
        $out['accept_stale'] = $writer->accept($ctx($t), ['title'], 7, $rev1);
        $out['after_accept_stale'] = $row($t);
        $out['discard_stale'] = $writer->discard('products', $t, $rev1);
        $out['proposal_after_discard_stale'] = json_decode((string) ($states->link('products', $t)['proposal_json'] ?? ''), true)['title'] ?? null;
        $out['accept_missing_rev'] = $writer->accept($ctx($t), ['title'], 7, '');
        $out['accept_current'] = $writer->accept($ctx($t), ['title'], 7, $rev2);
        $out['after_accept_current'] = $row($t);
        // 标为已审：带回页面打开时的指纹
        $fp = static fn (int $id): string => TranslationState::rowFingerprint(productModel()->find($id), 'title', 'summary');
        $reviewCtx = ['table' => 'products', 'target_id' => $t, 'title_key' => 'title', 'summary_key' => 'summary', 'has_page' => false];
        $seenSrc = $fp($a);
        $seenTarget = $fp($t);
        db()->execute("UPDATE yikai_products SET title = '液压泵 V2' WHERE id = ?", [$a]);
        $out['review_after_source_edit'] = $writer->markReviewed($reviewCtx, $a, $seenSrc, $seenTarget, 7);
        $out['outdated_after_stale_review'] = $status($a, $t)['outdated'];
        $out['review_current'] = $writer->markReviewed($reviewCtx, $a, $fp($a), $fp($t), 7);
        $out['state_after_review'] = $status($a, $t);
        // 放弃：页面上的版本对得上才放弃
        $writer->propose($read($a, $t), ['title' => 'P3 title', 'summary' => 'S', 'content' => '<p>P3</p>']);
        $out['discard_current'] = $writer->discard('products', $t, $rev($t));
        $out['proposal_after_discard'] = $states->link('products', $t)['proposal_json'] ?? null;
        break;

    default:
        throw new InvalidArgumentException('unknown scenario');
}

echo json_encode($out + ['calls' => $calls, 'in_transaction' => db()->getPdo()->inTransaction()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
