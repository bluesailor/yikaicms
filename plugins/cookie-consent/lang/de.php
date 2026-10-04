<?php
/**
 * YikaiCMS language translation draft.
 * Translation validation deferred until de, ru and fa are complete.
 */
return array (
  'cc_title' => 'Cookie-Einwilligungsbanner',
  'cc_desc' => 'DSGVO / PIPL: drei Einwilligungskategorien (notwendig, Analyse, Marketing) und jederzeitige Widerrufsmöglichkeit. Andere Skripte können über :api1 oder das Ereignis :api2 abhängig von der Kategorie geladen werden (PHP-seitig über :api3).',
  'cc_policy_url' => 'Link zur Datenschutzerklärung',
  'cc_policy_tip' => 'Wenn angegeben, zeigt das Banner einen Link zur Datenschutzerklärung. Für die DSGVO sollte dieser ausgefüllt sein.',
  'cc_policy_ver' => 'Version der Datenschutzerklärung',
  'cc_policy_ver_tip' => 'Erhöhen Sie bei wesentlichen Änderungen an der Cookie-Nutzung oder Datenschutzerklärung diesen Wert um :b+1:_b. Bisherige Einwilligungen werden ungültig und das Banner fragt erneut nach Zustimmung.',
  'cc_consent_mode' => 'Google Consent Mode v2 aktivieren',
  'cc_consent_mode_tip' => 'Sendet consent default/update-Signale an gtag (analytics_storage / ad_storage / ad_user_data / ad_personalization).',
  'cc_footer_link' => 'Dauerhaften Zugang „Cookie-Einstellungen“ anzeigen (unten links)',
  'cc_footer_link_tip' => 'Ermöglicht Besuchern jederzeit den Widerruf oder die Änderung ihrer Einwilligung. DSGVO Art. 7(3) verlangt einen ebenso einfachen Widerruf wie die Erteilung; lassen Sie dies daher aktiviert.',
  'cc_save' => 'Speichern',
  'cc_example_title' => 'Integrationsbeispiel: GA nur nach Einwilligung laden',
  'cc_example_note' => 'Bei aktiviertem Consent Mode v2 können Sie gtag.js auch dauerhaft laden. Google verwendet anhand der Einwilligungssignale gegebenenfalls Pings ohne Cookies.',
  'cc_log_update' => 'Cookie-Einwilligungseinstellungen aktualisiert',
);
