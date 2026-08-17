<?php
/**
 * Release gate for localized classic-home defaults.
 *
 * This is intentionally dependency-free so build.sh can run it before a
 * package is produced, even when PHPUnit and other dev dependencies are absent.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(2);
}

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/HomeSettingsLanguageDefaults.php';

$defaults = require ROOT_PATH . '/config/defaults.php';
$homeDefaults = $defaults['home'] ?? [];
if (!is_array($homeDefaults) || $homeDefaults === []) {
    fwrite(STDERR, "Home defaults are missing\n");
    exit(1);
}

// Numeric labels are text inputs but language-neutral. Every other text-like
// home setting must be classified by HomeSettingsLanguageDefaults.
$globalTextKeys = [
    'home_stat_1_num',
    'home_stat_2_num',
    'home_stat_3_num',
    'home_stat_4_num',
];
$allowedEmptyLocalized = [
    'home_about_title',
    'home_about_tag_title',
    'home_about_tag_desc',
];

$textLikeKeys = [];
foreach ($homeDefaults as $key => $definition) {
    $type = (string) ($definition['type'] ?? '');
    if (in_array($type, ['text', 'textarea', 'home_testimonials'], true)) {
        $textLikeKeys[] = (string) $key;
    }
}

$localizedKeys = HomeSettingsLanguageDefaults::keys();
$classified = array_values(array_unique(array_merge($localizedKeys, $globalTextKeys)));
$unclassified = array_values(array_diff($textLikeKeys, $classified));
$unknown = array_values(array_diff($classified, array_keys($homeDefaults)));
$errors = [];

foreach ($unclassified as $key) {
    $errors[] = "Unclassified text-like home setting: {$key}";
}
foreach ($unknown as $key) {
    $errors[] = "Home language registry references a missing default: {$key}";
}

foreach (['en', 'ja'] as $language) {
    foreach ($localizedKeys as $key) {
        $factory = (string) ($homeDefaults[$key]['value'] ?? '');
        $localized = HomeSettingsLanguageDefaults::localizedValue($key, $language, $homeDefaults);
        if ($localized === '' && !in_array($key, $allowedEmptyLocalized, true)) {
            $errors[] = "Missing {$language} home fallback: {$key}";
        }
        if ($factory !== '' && $localized === $factory) {
            $errors[] = "{$language} home fallback still equals zh-CN factory copy: {$key}";
        }
    }
}

foreach (['zh-CN', 'zh-TW'] as $language) {
    if (HomeSettingsLanguageDefaults::pollutedFactoryRows($language, $homeDefaults) !== []) {
        $errors[] = "{$language} must not be treated as a non-Chinese pollution target";
    }
}

if ($errors !== []) {
    echo "Home language default gate failed:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}

printf(
    "✓ 首页语言默认值检查通过（%d 个本地化文案键，en/ja 回退完整，zh-TW 保留 S2T 数据）\n",
    count($localizedKeys)
);
