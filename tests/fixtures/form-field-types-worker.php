<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));
define('DEBUG', false);
define('SITE_LANG', 'zh-CN');
require ROOT_PATH . '/includes/functions.php';

$tags = parseFormTags('[number qty "Quantity" min:1.5 max:5.5 step:0.5]'
    . '[date visit min:2026-09-20 max:2026-09-30 step:2]'
    . '[hidden campaign "configured"]'
    . '[file* proof "pdf,png" max:3]');

$legacy = formFieldsFromStored('[{"key":"choice","type":"select","options":"First, Second","placeholder":"Choose"},{"key":"proof","type":"file","accept":"pdf, png","max_size":3}]');
$legacyArray = formFieldsFromStored('[{"key":"choice","type":"select","options":["First","Second","First"," "],"placeholder":"Choose"},{"key":"radio_choice","type":"radio","options":["A","B","A"]},{"key":"checks","type":"checkbox","options":"One,Two,One"}]');

echo json_encode([
    'tags' => $tags,
    'html' => array_map('renderFormTagHtml', $tags),
    'valid' => array_map('formTagConfigurationValid', $tags),
    'invalid' => [
        formTagConfigurationValid(formTagFromMatch([0, 'file', '', 'bad', ' "svg" max:3'])),
        formTagConfigurationValid(formTagFromMatch([0, 'file', '', 'bad', ' "pdf"'])),
        formTagConfigurationValid(formTagFromMatch([0, 'number', '', 'bad', ' min:10 max:1 step:0'])),
        formTagConfigurationValid(formTagFromMatch([0, 'date', '', 'bad', ' min:2026-02-30'])),
    ],
    'empty_select' => formTagFromMatch([0, 'select', '', 'choice', ' "" "First"']),
    'legacy' => $legacy,
    'legacy_array' => $legacyArray,
    'legacy_html' => renderStoredFormFields(['fields' => '[{"key":"choice","type":"select","options":["First","Second","First"],"placeholder":"Choose"},{"key":"radio_choice","type":"radio","options":"A,B,A"},{"key":"checks","type":"checkbox","options":["One","Two","One"]}]']),
    'product_fields' => renderStoredFormFields(['fields' => '[{"key":"proof","type":"file","required":true,"accept":["pdf"],"max_size":3},{"key":"visit","type":"date","required":true,"min":"2026-09-20","max":"2026-09-30"},{"key":"quantity","type":"number","required":true,"min":"1","max":"10","step":"1"},{"key":"campaign","type":"hidden","value":"configured"},{"key":"content","type":"textarea","required":true}]'], ['content' => 'About Product']),
    'legacy_valid' => array_map('formTagConfigurationValid', $legacy),
    'sets' => [
        formFieldSetValid(parseFormTags('[text first][text second]')),
        formFieldSetValid(parseFormTags('[text same][hidden same "x"]')),
        formFieldSetValid(parseFormTags('[hidden product_id "9"]')),
    ],
    'any_step' => renderFormTagHtml(formTagFromMatch([0, 'number', '', 'amount', ''])),
    'exact_config' => [
        formTagConfigurationValid(formTagFromMatch([0, 'number', '', 'n', ' max:9007199254740992'])),
        formTagConfigurationValid(formTagFromMatch([0, 'number', '', 'n', ' min:9007199254740993 max:9007199254740992'])),
        formTagConfigurationValid(formTagFromMatch([0, 'date', '', 'd', ' min:0000-01-01'])),
    ],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
