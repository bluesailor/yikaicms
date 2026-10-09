<?php
/**
 * 首页「行动号召」去掉重复的背景（2.0.x 的安装数据把同一张图既设在外层区块、又设在 CTA 里）。
 *
 * 结果是同一张大图画两遍、两层遮罩叠加（黑 45% + 深蓝 60%），比设计的更暗。只处理真正的重复：
 * 区块背景图与里面 CTA 的背景图是同一张时，去掉区块的背景与遮罩；站长自己换过的不动。
 */

declare(strict_types=1);

$homeCtaDuplicates = static function (bool $apply): int {
    // ESCAPE 用 '!'：反斜杠在 MySQL 与 SQLite 里转义规则不同（见 20260810_normalize_default_lang_shadow）
    $rows = db()->fetchAll(
        'SELECT id, value FROM ' . DB_PREFIX . "settings WHERE `key` LIKE ? ESCAPE '!'",
        ['home!_blox!_%']
    );
    $fixed = 0;
    foreach ($rows as $row) {
        $doc = json_decode((string) ($row['value'] ?? ''), true);
        if (!is_array($doc) || !is_array($doc['sections'] ?? null)) {
            continue;
        }
        $changed = false;
        foreach ($doc['sections'] as &$section) {
            $image = is_array($section['settings'] ?? null) ? (string) ($section['settings']['container_bg_image'] ?? '') : '';
            if ($image === '') {
                continue;
            }
            foreach ((array) ($section['columns'] ?? []) as $column) {
                foreach ((array) ($column['elements'] ?? []) as $element) {
                    $data = is_array($element['data'] ?? null) ? $element['data'] : [];
                    if (($element['type'] ?? '') === 'home-block' && ($data['block_type'] ?? '') === 'cta'
                        && (string) ($data['bg_image'] ?? '') === $image) {
                        unset($section['settings']['container_bg_image'], $section['settings']['container_bg_overlay_color'], $section['settings']['container_bg_overlay_opacity']);
                        $changed = true;
                        $fixed++;
                        continue 3;
                    }
                }
            }
        }
        unset($section);
        if ($changed && $apply) {
            db()->update('settings', ['value' => json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], 'id = ?', [(int) $row['id']]);
        }
    }
    return $fixed;
};

return [
    'id' => '20261010_home_cta_double_background',
    'title' => '首页行动号召去掉重复背景',
    'desc' => '旧安装数据把同一张背景图设在首页「行动号召」的外层区块和 CTA 里，图片画两遍、遮罩叠两层。只去掉与 CTA 相同的外层背景，自己改过的不动。',
    'title_en' => 'Remove the duplicated homepage call-to-action background',
    'title_ja' => 'トップページの CTA 背景の重複を解消',
    'desc_en' => 'Older install data set the same background image on the homepage call-to-action section and on the CTA inside it, so the image was drawn twice under two overlays. Only an outer background identical to the CTA\'s is removed; custom backgrounds are kept.',
    'desc_ja' => '旧インストールデータでは、トップページの CTA 外側セクションと CTA 本体に同じ背景画像が設定され、画像が二重に描画されオーバーレイも重なっていました。CTA と同じ外側の背景だけを削除し、変更済みの背景はそのまま残します。',
    'check' => static fn (): bool => $homeCtaDuplicates(false) === 0,
    'php' => static function () use ($homeCtaDuplicates): string {
        $fixed = $homeCtaDuplicates(true);
        if ($fixed > 0 && class_exists('HtmlCache')) {
            HtmlCache::invalidate();
        }
        return 'fixed ' . $fixed;
    },
];
