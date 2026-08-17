<?php

declare(strict_types=1);

require_once ROOT_PATH . '/includes/HomeSettingsLanguageDefaults.php';

$homeLanguageDefaults = static fn(): array => getDefaults('home');
$siteLanguage = static function (): string {
    $row = db()->fetchOne('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', ['site_lang']);
    return trim((string) ($row['value'] ?? 'zh-CN')) ?: 'zh-CN';
};

return [
    'id' => '20260817_repair_non_zh_home_factory_defaults',
    'title' => '清理非中文站点的首页中文默认文案',
    'title_en' => 'Remove leaked Chinese home defaults on non-Chinese sites',
    'title_ja' => '中国語以外のサイトに混入したホーム既定文言を削除',
    'desc' => '非中文默认语言站点打开旧版首页设置时，缺失项会显示并保存中文出厂默认。'
        . '本迁移仅删除值与中文出厂默认完全一致的首页文案行，使语言包兜底重新生效；'
        . '站长修改过的内容和结构配置不变。',
    'desc_en' => 'Older home settings forms could persist zh-CN factory copy into missing base rows on '
        . 'non-Chinese sites. This migration removes only exact factory-value matches so the target '
        . 'language fallback works again. Customized copy and structural settings are untouched.',
    'desc_ja' => '旧版のホーム設定画面は、中国語以外のサイトでも欠落した主言語行へ中国語の出荷時文言を'
        . '保存することがありました。本マイグレーションは出荷時値と完全一致する行だけを削除し、'
        . '対象言語のフォールバックを復元します。編集済み文言と構造設定は変更しません。',
    'check' => static function () use ($homeLanguageDefaults, $siteLanguage): bool {
        try {
            $language = $siteLanguage();
            foreach (HomeSettingsLanguageDefaults::pollutedFactoryRows($language, $homeLanguageDefaults()) as $key => $value) {
                $hit = db()->fetchOne(
                    'SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ? AND value = ?',
                    [$key, $value]
                );
                if ($hit !== null) {
                    return false;
                }
            }
            return true;
        } catch (Throwable) {
            return true;
        }
    },
    'sqls' => [],
    'php' => static function () use ($homeLanguageDefaults, $siteLanguage): string {
        $language = $siteLanguage();
        $candidates = HomeSettingsLanguageDefaults::pollutedFactoryRows($language, $homeLanguageDefaults());
        if ($candidates === []) {
            return '当前默认语言无需清理';
        }

        $rows = [];
        foreach ($candidates as $key => $value) {
            $matched = db()->fetchAll(
                'SELECT * FROM ' . DB_PREFIX . 'settings WHERE `key` = ? AND value = ?',
                [$key, $value]
            );
            foreach ($matched as $row) {
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            return '未发现中文出厂默认污染，无需清理';
        }

        $backup = ROOT_PATH . '/storage/home-lang-default-backup-' . date('Ymd-His') . '.json';
        @file_put_contents($backup, json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        foreach ($rows as $row) {
            db()->execute(
                'DELETE FROM ' . DB_PREFIX . 'settings WHERE id = ? AND `key` = ? AND value = ?',
                [(int) $row['id'], (string) $row['key'], (string) $row['value']]
            );
        }
        foreach ((array) glob(ROOT_PATH . '/storage/cache/html/*') as $file) {
            @unlink($file);
        }

        return sprintf(
            '已清理 %d 条中文出厂默认文案，备份见 storage/%s',
            count($rows),
            basename($backup)
        );
    },
];
