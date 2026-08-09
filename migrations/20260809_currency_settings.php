<?php
/**
 * 自定义货币单位：新增 currency_symbol / currency_decimals 两个设置项。
 *
 * 背景：价格符号原先只跟随站点语言（zh ¥ / en $ / ja ¥），但「英文站 = 美元」
 * 并不成立——菲律宾站要 ₱、欧洲站要 €。formatPrice() 改为「设置优先、语言默认
 * 兜底」，本迁移给存量站补上这两个设置项（值留空 = 保持原有语言默认行为，
 * 升级前后前台输出逐字节不变）。
 *
 * 两项都支持 <key>_<lang> 后缀分设，多语言站可中文版 ¥、英文版 $。
 */

declare(strict_types=1);

return [
    'id' => '20260809_currency_settings',
    'title' => '自定义货币单位',
    'desc' => '产品设置新增「货币符号」与「价格小数位」，留空则按站点语言自动选择；'
        . '可按语言分别设置。升级后行为不变，需要时到「产品设置」里填写。',
    // 站点语言非中文时用这几项；Migrator::label() 取不到会回落上面的中文原文
    'title_en' => 'Custom currency unit',
    'title_ja' => 'カスタム通貨単位',
    'desc_en' => 'Adds a currency symbol and price decimals option to Product Settings. Leave blank to follow the site language. Can be set per language. Nothing changes until you fill them in.',
    'desc_ja' => '製品設定に通貨記号と小数桁数の項目を追加します。空欄のままならサイト言語に従います。言語ごとに設定できます。入力するまで表示は変わりません。',
    'check' => static function (): bool {
        try {
            return db()->fetchOne(
                'SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ?',
                ['currency_symbol']
            ) !== null;
        } catch (Throwable) {
            return true;   // 探测失败不阻塞升级链
        }
    },
    'sqls' => [],
    'php' => static function (): string {
        $added = [];
        $items = [
            ['currency_symbol', '货币符号', '留空按站点语言自动选择（中文 ¥ / 英文 $ / 日文 ¥）；填了以此为准，如 ₱ € £', 5],
            ['currency_decimals', '价格小数位', '留空按语言默认（中英 2 位、日元 0 位）；0-4', 6],
        ];
        foreach ($items as [$key, $name, $tip, $sort]) {
            try {
                $exists = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$key]);
                if ($exists) {
                    continue;
                }
                // 值留空 = 沿用语言默认，升级不改变任何站点的现有显示
                db()->execute(
                    'INSERT INTO ' . DB_PREFIX . 'settings (`group`, `key`, `value`, `type`, `name`, `tip`, `options`, `sort_order`) '
                    . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    ['product', $key, '', 'text', $name, $tip, null, $sort]
                );
                $added[] = $key;
            } catch (Throwable $e) {
                error_log('[20260809_currency_settings] ' . $key . ': ' . $e->getMessage());
            }
        }

        return $added === []
            ? '货币设置项已存在，无需添加'
            : ('已添加设置项：' . implode('、', $added) . '（留空 = 保持原有语言默认）');
    },
];
