-- ============================================================
-- 首页/页脚/联系方式 多语言种子数据 (2026-05-11)
--
-- 目的：为后台「首页设置」「SEO 设置」「页脚」启用 EN/JA 视图后，
-- 预先填入英文/日文的种子文案，避免首次访问翻译 tab 时出现空字段。
--
-- 所有 INSERT 均带 ON DUPLICATE KEY UPDATE：可重复执行；
-- 已被用户编辑过的翻译字段会被覆盖回种子，请谨慎重跑。
-- （upgrade.php 内的 check 函数确保正常情况下只执行一次。）
--
-- 应用方式（任选其一）：
--   1. 在 /admin/upgrade.php 点击对应升级项（推荐）
--   2. mysql -u root -p ikaicms < install/sql/upgrade_20260511_home_footer_translations.sql
-- ============================================================

-- ============== 联系方式：phone/email/address per-lang ==============
INSERT INTO `yikai_settings` (`key`, `value`, `group`, `type`, `name`) VALUES
  ('contact_phone_en',   '+86-400-888-8888',                          'contact', 'text',     'Contact phone (EN)'),
  ('contact_phone_ja',   '+86-400-888-8888',                          'contact', 'text',     'Contact phone (JA)'),
  ('contact_email_en',   'contact@example.com',                       'contact', 'text',     'Contact email (EN)'),
  ('contact_email_ja',   'contact@example.com',                       'contact', 'text',     'Contact email (JA)'),
  ('contact_address_en', 'XX Road, Pudong New Area, Shanghai, China', 'contact', 'textarea', 'Contact address (EN)'),
  ('contact_address_ja', '中国 上海市浦東新区XX路XX号',                   'contact', 'textarea', 'Contact address (JA)')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- ============== 首页：nav / 关于我们 / stats / testimonials / advantage / cta / partners ==============
INSERT INTO `yikai_settings` (`key`, `value`, `group`, `type`, `name`) VALUES
  -- nav home text
  ('nav_home_text_en', 'Home',  'home', 'text', 'Nav Home text (EN)'),
  ('nav_home_text_ja', 'ホーム', 'home', 'text', 'Nav Home text (JA)'),

  -- about block
  ('home_about_content_en',   'We are a technology company focused on enterprise digital transformation, delivering high-quality products and services to our customers.', 'home', 'textarea', 'About content (EN)'),
  ('home_about_content_ja',   '当社は企業のデジタルトランスフォーメーションに特化したテクノロジー企業として、お客様に高品質な製品とサービスを提供しています。', 'home', 'textarea', 'About content (JA)'),
  ('home_about_tag_title_en', 'Professional Service',         'home', 'text', 'About tag title (EN)'),
  ('home_about_tag_title_ja', 'プロフェッショナルサービス',     'home', 'text', 'About tag title (JA)'),
  ('home_about_tag_desc_en',  'Quality · Innovation · Win-Win', 'home', 'text', 'About tag desc (EN)'),
  ('home_about_tag_desc_ja',  '品質 · イノベーション · 共創',   'home', 'text', 'About tag desc (JA)'),

  -- stats text
  ('home_stat_1_text_en', 'Years in Industry',     'home', 'text', 'Stat 1 (EN)'),
  ('home_stat_1_text_ja', '業界経験年数',           'home', 'text', 'Stat 1 (JA)'),
  ('home_stat_2_text_en', 'Customers Served',      'home', 'text', 'Stat 2 (EN)'),
  ('home_stat_2_text_ja', '取引実績数',             'home', 'text', 'Stat 2 (JA)'),
  ('home_stat_3_text_en', 'Professional Team',     'home', 'text', 'Stat 3 (EN)'),
  ('home_stat_3_text_ja', 'プロフェッショナルチーム', 'home', 'text', 'Stat 3 (JA)'),
  ('home_stat_4_text_en', 'Customer Satisfaction', 'home', 'text', 'Stat 4 (EN)'),
  ('home_stat_4_text_ja', '顧客満足度',             'home', 'text', 'Stat 4 (JA)'),

  -- testimonials labels + JSON items
  ('home_testimonials_title_en', 'Testimonials',          'home', 'text', 'Testimonials title (EN)'),
  ('home_testimonials_title_ja', 'お客様の声',             'home', 'text', 'Testimonials title (JA)'),
  ('home_testimonials_desc_en',  'What our partners say', 'home', 'text', 'Testimonials desc (EN)'),
  ('home_testimonials_desc_ja',  'パートナーからの声',     'home', 'text', 'Testimonials desc (JA)'),
  ('home_testimonials_en', '[{"avatar":"","name":"Mr. Zhang","company":"Tech Co., Ltd.","content":"A very professional team — a pleasure to work with."},{"avatar":"","name":"Ms. Li","company":"Trading Corp.","content":"Quality products and excellent service."}]', 'home', 'textarea', 'Testimonials JSON (EN)'),
  ('home_testimonials_ja', '[{"avatar":"","name":"張様","company":"テクノロジー会社","content":"非常にプロフェッショナルなチームで、ご一緒できて大変光栄でした。"},{"avatar":"","name":"李様","company":"商社","content":"高品質な製品と優れたサービス。"}]', 'home', 'textarea', 'Testimonials JSON (JA)'),

  -- advantage block
  ('home_advantage_desc_en', 'Professional team, quality service, trusted partner', 'home', 'text', 'Advantage desc (EN)'),
  ('home_advantage_desc_ja', 'プロフェッショナルチーム・優れたサービス・信頼のパートナー', 'home', 'text', 'Advantage desc (JA)'),
  ('home_adv_1_title_en', 'Quality Assured',                                              'home', 'text', 'Adv 1 title (EN)'),
  ('home_adv_1_title_ja', '品質保証',                                                     'home', 'text', 'Adv 1 title (JA)'),
  ('home_adv_1_desc_en',  'Strict quality control ensures every product meets standards', 'home', 'text', 'Adv 1 desc (EN)'),
  ('home_adv_1_desc_ja',  '厳格な品質管理で、すべての製品が基準を満たすことを保証',           'home', 'text', 'Adv 1 desc (JA)'),
  ('home_adv_2_title_en', 'Tech Leadership',                                              'home', 'text', 'Adv 2 title (EN)'),
  ('home_adv_2_title_ja', '技術リーダーシップ',                                            'home', 'text', 'Adv 2 title (JA)'),
  ('home_adv_2_desc_en',  'Continuous R&D investment keeps us ahead of the curve',        'home', 'text', 'Adv 2 desc (EN)'),
  ('home_adv_2_desc_ja',  '継続的な研究開発で、技術の最前線をリードします',                   'home', 'text', 'Adv 2 desc (JA)'),
  ('home_adv_3_title_en', 'Professional Service',                                         'home', 'text', 'Adv 3 title (EN)'),
  ('home_adv_3_title_ja', 'プロフェッショナルサービス',                                     'home', 'text', 'Adv 3 title (JA)'),
  ('home_adv_3_desc_en',  'Expert team provides 24/7 technical support',                  'home', 'text', 'Adv 3 desc (EN)'),
  ('home_adv_3_desc_ja',  '専門チームが24時間365日テクニカルサポートを提供',                  'home', 'text', 'Adv 3 desc (JA)'),
  ('home_adv_4_title_en', 'Win-Win Partnership',                                          'home', 'text', 'Adv 4 title (EN)'),
  ('home_adv_4_title_ja', '共創パートナーシップ',                                          'home', 'text', 'Adv 4 title (JA)'),
  ('home_adv_4_desc_en',  'Long-term cooperation that creates mutual value',              'home', 'text', 'Adv 4 desc (EN)'),
  ('home_adv_4_desc_ja',  '長期的なパートナーシップで、共に成長します',                       'home', 'text', 'Adv 4 desc (JA)'),

  -- CTA
  ('home_cta_title_en', 'Ready to get started?',                  'home', 'text', 'CTA title (EN)'),
  ('home_cta_title_ja', 'お取引を始めませんか？',                  'home', 'text', 'CTA title (JA)'),
  ('home_cta_desc_en',  'Contact us for a professional solution', 'home', 'text', 'CTA desc (EN)'),
  ('home_cta_desc_ja',  'お問い合わせいただければ、最適なソリューションをご提案いたします', 'home', 'text', 'CTA desc (JA)'),

  -- partners block title
  ('home_links_title_en', 'Partners',  'home', 'text', 'Partners title (EN)'),
  ('home_links_title_ja', 'パートナー', 'home', 'text', 'Partners title (JA)')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

-- ============== 页脚：columns/nav/copyright + 站点描述 per-lang ==============
INSERT INTO `yikai_settings` (`key`, `value`, `group`, `type`, `name`) VALUES
  ('site_description_en', 'A professional CMS for enterprises, supporting multilingual content, SEO optimization, and responsive design.', 'basic', 'textarea', 'Site description (EN)'),
  ('site_description_ja', '企業のデジタルトランスフォーメーションを支えるプロフェッショナル CMS。多言語対応、SEO 最適化、レスポンシブデザイン。', 'basic', 'textarea', 'Site description (JA)'),

  ('footer_copyright_text_en', '© {year} {site_name}. All Rights Reserved.', 'footer', 'text', 'Footer copyright (EN)'),
  ('footer_copyright_text_ja', '© {year} {site_name}. All Rights Reserved.', 'footer', 'text', 'Footer copyright (JA)'),

  ('footer_columns_en', '[{"title":"About Us","content":"{{site_description}}","col_span":2},{"title":"Contact","content":"{{contact_info}}","col_span":1},{"title":"Follow Us","content":"{{qrcode}}{{social_icons}}","col_span":1}]', 'footer', 'textarea', 'Footer columns (EN)'),
  ('footer_columns_ja', '[{"title":"会社案内","content":"{{site_description}}","col_span":2},{"title":"お問合せ","content":"{{contact_info}}","col_span":1},{"title":"フォロー","content":"{{qrcode}}{{social_icons}}","col_span":1}]', 'footer', 'textarea', 'Footer columns (JA)'),

  ('footer_nav_en', '[{"title":"","links":[{"name":"Privacy Policy","url":"/privacy.html"},{"name":"Terms of Service","url":"/terms.html"}]}]', 'footer', 'textarea', 'Footer nav (EN)'),
  ('footer_nav_ja', '[{"title":"","links":[{"name":"プライバシーポリシー","url":"/privacy.html"},{"name":"利用規約","url":"/terms.html"}]}]', 'footer', 'textarea', 'Footer nav (JA)')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
