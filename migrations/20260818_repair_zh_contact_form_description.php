<?php

declare(strict_types=1);

return [
    'id' => '20260818_repair_zh_contact_form_description',
    'title' => '修复中文联系表单说明',
    'desc' => '仅将误写到中文基础配置中的内置英文说明恢复为中文，不覆盖自定义内容。',
    'check' => static function (): bool {
        if (!db()->tableExists('settings')) {
            return true;
        }
        return (string) db()->fetchColumn(
            'SELECT `value` FROM ' . DB_PREFIX . 'settings WHERE `key` = ? LIMIT 1',
            ['contact_form_desc']
        ) !== "Leave us a message and we'll get back to you shortly.";
    },
    'sqls' => [],
    'php' => static function (): string {
        $updated = db()->update(
            'settings',
            ['value' => '给我们留言，我们会尽快与您联系。'],
            '`key` = ? AND `value` = ?',
            ['contact_form_desc', "Leave us a message and we'll get back to you shortly."]
        );
        return "已修复 {$updated} 条中文联系表单说明";
    },
];
