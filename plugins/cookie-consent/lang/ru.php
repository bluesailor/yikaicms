<?php
/**
 * YikaiCMS language translation draft.
 * Translation validation deferred until de, ru and fa are complete.
 */
return array (
  'cc_title' => 'Баннер согласия на Cookie',
  'cc_desc' => 'Поддержка GDPR / PIPL: три категории согласия (необходимые, аналитические, маркетинговые) и постоянный доступ к отзыву. Скрипты загружаются по категориям через :api1 или событие :api2 (на стороне PHP — :api3).',
  'cc_policy_url' => 'Ссылка на политику конфиденциальности',
  'cc_policy_tip' => 'После заполнения в баннере появится ссылка на политику конфиденциальности. Для GDPR рекомендуется заполнить обязательно.',
  'cc_policy_ver' => 'Версия политики',
  'cc_policy_ver_tip' => 'При существенном изменении использования Cookie или политики конфиденциальности увеличьте значение :b+1:_b. Прежние согласия потеряют силу, баннер запросит их снова.',
  'cc_consent_mode' => 'Включить Google Consent Mode v2',
  'cc_consent_mode_tip' => 'Передаёт gtag сигналы consent default/update (analytics_storage / ad_storage / ad_user_data / ad_personalization).',
  'cc_footer_link' => 'Показывать постоянную ссылку «Настройки Cookie» (слева внизу)',
  'cc_footer_link_tip' => 'Позволяет посетителям в любой момент изменить или отозвать согласие. Согласно статье 7(3) GDPR отзыв должен быть столь же простым, как предоставление. Рекомендуется оставить включённым.',
  'cc_save' => 'Сохранить',
  'cc_example_title' => 'Пример: загружать GA после согласия',
  'cc_example_note' => 'С Consent Mode v2 можно постоянно загружать gtag.js. Google использует сигналы согласия и при необходимости отправляет запросы без Cookie.',
  'cc_log_update' => 'Обновлены настройки согласия на Cookie',
);
