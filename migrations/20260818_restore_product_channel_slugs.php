<?php

declare(strict_types=1);

return [
    'id' => '20260818_restore_product_channel_slugs',
    'title' => '恢复产品主栏目的语言化地址',
    'desc' => '修复中文产品栏目误用 product-zh、英文栏目误占 product 的异常数据。',
    'check' => static function (): bool {
        if (!db()->tableExists('channels')) {
            return true;
        }
        $rows = db()->fetchAll(
            'SELECT lang, slug FROM ' . DB_PREFIX . 'channels'
            . ' WHERE translation_group_id = ? AND lang IN (?, ?)',
            [5, 'zh-CN', 'en']
        );
        $slugs = [];
        foreach ($rows as $row) {
            $slugs[(string) $row['lang']] = (string) $row['slug'];
        }
        return ($slugs['zh-CN'] ?? '') !== 'product-zh' || ($slugs['en'] ?? '') !== 'product';
    },
    'sqls' => [],
    'php' => static function (): string {
        $zh = db()->fetchOne(
            'SELECT id, slug FROM ' . DB_PREFIX . 'channels'
            . ' WHERE translation_group_id = ? AND lang = ? LIMIT 1',
            [5, 'zh-CN']
        );
        $en = db()->fetchOne(
            'SELECT id, slug FROM ' . DB_PREFIX . 'channels'
            . ' WHERE translation_group_id = ? AND lang = ? LIMIT 1',
            [5, 'en']
        );
        if (!is_array($zh) || !is_array($en)
            || (string) $zh['slug'] !== 'product-zh'
            || (string) $en['slug'] !== 'product') {
            return '产品栏目地址无需修复';
        }

        $temporarySlug = '__product-en-swap-' . (int) $en['id'];
        db()->beginTransaction();
        try {
            channelModel()->updateById((int) $en['id'], ['slug' => $temporarySlug]);
            channelModel()->updateById((int) $zh['id'], ['slug' => 'product']);
            channelModel()->updateById((int) $en['id'], ['slug' => 'product-en']);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }

        cacheClear();
        return '产品栏目地址已恢复为 product / product-en';
    },
];
