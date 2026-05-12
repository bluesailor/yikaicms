<?php
/**
 * 邮件模板 EN/JA 翻译种子
 *
 * 4 类邮件 × 2 字段 × 2 语种 = 16 个 settings 行：
 *   mail_tpl_register_subject / _body   会员注册
 *   mail_tpl_forgot_subject   / _body   找回密码
 *   mail_tpl_reset_subject    / _body   密码重置
 *   mail_tpl_inquiry_subject  / _body   询盘通知
 *
 * 用 ON DUPLICATE KEY UPDATE（_sqlToSqlite 会自动转 INSERT OR REPLACE）
 * 幂等：通过 _sqlToSqlite 的语义重跑安全。
 */

declare(strict_types=1);

return [
    'id'    => '20260512_mail_template_translations',
    'title' => '邮件模板：EN/JA 翻译种子',
    'desc'  => '为 4 类邮件（注册/找回/重置/询盘）填 EN/JA 标题与正文模板，避免 /admin/setting_email.php?lang=en|ja 在模板 tab 上字段空白。幂等：检测到 mail_tpl_register_subject_en 已存在则跳过。',
    'check' => function (): bool {
        $row = db()->fetchOne("SELECT 1 FROM " . DB_PREFIX . "settings WHERE `key` = 'mail_tpl_register_subject_en' LIMIT 1");
        return !empty($row);
    },
    'sqls' => [
        // === 会员注册 ===
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_register_subject_en', 'Welcome to {{site_name}}', 'email', 'text', 'Register subject (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_register_subject_ja', '{{site_name}} へのご登録ありがとうございます', 'email', 'text', 'Register subject (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_register_body_en', 'Hi {{username}},\\n\\nThank you for registering at {{site_name}}.\\nVisit your member dashboard: {{site_url}}/member/\\n\\n— {{site_name}} {{date}}', 'email', 'textarea', 'Register body (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_register_body_ja', '{{username}} 様\\n\\n{{site_name}} へのご登録ありがとうございます。\\n会員ページ: {{site_url}}/member/\\n\\n— {{site_name}} {{date}}', 'email', 'textarea', 'Register body (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

        // === 找回密码 ===
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_forgot_subject_en', 'Reset your password — {{site_name}}', 'email', 'text', 'Forgot subject (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_forgot_subject_ja', 'パスワード再設定 — {{site_name}}', 'email', 'text', 'Forgot subject (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_forgot_body_en', 'Hi {{username}},\\n\\nClick the link below to reset your password (valid for 30 minutes):\\n{{reset_link}}\\n\\nIf you did not request this, please ignore this email.\\n\\n— {{site_name}} {{date}}', 'email', 'textarea', 'Forgot body (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_forgot_body_ja', '{{username}} 様\\n\\n下記リンクからパスワードを再設定してください（30分間有効）:\\n{{reset_link}}\\n\\n心当たりがない場合は本メールを無視してください。\\n\\n— {{site_name}} {{date}}', 'email', 'textarea', 'Forgot body (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

        // === 密码重置完成 ===
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_reset_subject_en', 'Your password has been reset — {{site_name}}', 'email', 'text', 'Reset subject (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_reset_subject_ja', 'パスワード変更完了 — {{site_name}}', 'email', 'text', 'Reset subject (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_reset_body_en', 'Hi {{username}},\\n\\nYour password has been successfully reset.\\nIf you did not perform this action, please contact us immediately.\\n\\n— {{site_name}} {{date}}', 'email', 'textarea', 'Reset body (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_reset_body_ja', '{{username}} 様\\n\\nパスワードが正常に変更されました。\\nお心当たりのない場合は、至急ご連絡ください。\\n\\n— {{site_name}} {{date}}', 'email', 'textarea', 'Reset body (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",

        // === 询盘通知（发给后台管理员） ===
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_inquiry_subject_en', 'New inquiry: {{product_title}} — {{site_name}}', 'email', 'text', 'Inquiry subject (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_inquiry_subject_ja', '新規お問い合わせ：{{product_title}} — {{site_name}}', 'email', 'text', 'Inquiry subject (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_inquiry_body_en', 'A new inquiry has been received:\\n\\nProduct: {{product_title}}\\nName:    {{name}}\\nPhone:   {{phone}}\\nEmail:   {{email}}\\nCompany: {{company}}\\n\\nMessage:\\n{{content}}\\n\\n---\\nIP: {{ip}}\\nSubmitted: {{date}}\\n— {{site_name}}', 'email', 'textarea', 'Inquiry body (EN)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
        "INSERT INTO `" . DB_PREFIX . "settings` (`key`,`value`,`group`,`type`,`name`) VALUES ('mail_tpl_inquiry_body_ja', '新しいお問い合わせを受信しました：\\n\\n製品:    {{product_title}}\\nお名前:  {{name}}\\n電話:    {{phone}}\\nメール:  {{email}}\\n会社:    {{company}}\\n\\nお問い合わせ内容:\\n{{content}}\\n\\n---\\nIP: {{ip}}\\n受信日時: {{date}}\\n— {{site_name}}', 'email', 'textarea', 'Inquiry body (JA)') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)",
    ],
];
