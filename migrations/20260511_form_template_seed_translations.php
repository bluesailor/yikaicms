<?php
/**
 * 表单模板：EN/JA 翻译种子（contact / product-inquiry）
 *
 * 为 yikai_form_templates 现有的两条记录填充 name_en/ja, fields_en/ja, success_message_en/ja，
 * 让前台 /en/contact.html、/ja/contact.html 的表单按当前语言显示字段标签和成功提示。
 *
 * 前置依赖：20260511_form_template_i18n
 */

declare(strict_types=1);

return [
    'id'    => '20260511_form_template_seed_translations',
    'title' => '表单模板：EN/JA 翻译种子（contact / product-inquiry）',
    'desc'  => '为现有两条记录（contact、product-inquiry）填充 name_en/ja、fields_en/ja、success_message_en/ja。幂等：检测 contact 行的 fields_en 已非空则跳过。前置依赖：「表单模板：加 EN/JA 列」迁移已执行。',
    'check' => function (): bool {
        // 依赖前置迁移 form_template_i18n 加 fields_en 列。
        // 该列不存在时直接返回 false（待应用），避免 SELECT 抛错。
        if (function_exists('_columnExists') && !_columnExists('form_templates', 'fields_en')) {
            return false;
        }
        $v = (string) db()->fetchColumn("SELECT fields_en FROM " . DB_PREFIX . "form_templates WHERE slug = 'contact' LIMIT 1");
        return $v !== '';
    },
    'sqls' => [
        "UPDATE `" . DB_PREFIX . "form_templates` SET
            `name_en` = 'Contact Form',
            `name_ja` = 'お問い合わせフォーム',
            `fields_en` = '[{\"key\":\"name\",\"label\":\"Your Name\",\"type\":\"text\",\"required\":true},{\"key\":\"phone\",\"label\":\"Phone\",\"type\":\"tel\",\"required\":true},{\"key\":\"email\",\"label\":\"Email\",\"type\":\"email\",\"required\":false},{\"key\":\"company\",\"label\":\"Company\",\"type\":\"text\",\"required\":false},{\"key\":\"content\",\"label\":\"Message\",\"type\":\"textarea\",\"required\":true}]',
            `fields_ja` = '[{\"key\":\"name\",\"label\":\"お名前\",\"type\":\"text\",\"required\":true},{\"key\":\"phone\",\"label\":\"電話番号\",\"type\":\"tel\",\"required\":true},{\"key\":\"email\",\"label\":\"メールアドレス\",\"type\":\"email\",\"required\":false},{\"key\":\"company\",\"label\":\"会社名\",\"type\":\"text\",\"required\":false},{\"key\":\"content\",\"label\":\"お問い合わせ内容\",\"type\":\"textarea\",\"required\":true}]',
            `success_message_en` = 'Thank you! Your message has been received. We will contact you shortly.',
            `success_message_ja` = 'ありがとうございます。お問い合わせを受け付けました。担当者よりご連絡いたします。'
         WHERE `slug` = 'contact'",

        "UPDATE `" . DB_PREFIX . "form_templates` SET
            `name_en` = 'Product Inquiry',
            `name_ja` = '製品お問い合わせ',
            `fields_en` = '[{\"key\":\"name\",\"label\":\"Your Name\",\"type\":\"text\",\"required\":true},{\"key\":\"phone\",\"label\":\"Phone\",\"type\":\"tel\",\"required\":true},{\"key\":\"email\",\"label\":\"Email\",\"type\":\"email\",\"required\":false},{\"key\":\"company\",\"label\":\"Company\",\"type\":\"text\",\"required\":false},{\"key\":\"content\",\"label\":\"Inquiry Details\",\"type\":\"textarea\",\"required\":true}]',
            `fields_ja` = '[{\"key\":\"name\",\"label\":\"お名前\",\"type\":\"text\",\"required\":true},{\"key\":\"phone\",\"label\":\"電話番号\",\"type\":\"tel\",\"required\":true},{\"key\":\"email\",\"label\":\"メールアドレス\",\"type\":\"email\",\"required\":false},{\"key\":\"company\",\"label\":\"会社名\",\"type\":\"text\",\"required\":false},{\"key\":\"content\",\"label\":\"お問い合わせ内容\",\"type\":\"textarea\",\"required\":true}]',
            `success_message_en` = 'Thank you for your inquiry. We will contact you shortly.',
            `success_message_ja` = 'お問い合わせいただきありがとうございます。担当者よりご連絡いたします。'
         WHERE `slug` = 'product-inquiry'",
    ],
];
