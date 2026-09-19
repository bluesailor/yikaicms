<?php
declare(strict_types=1);

// CLI-only probe: 在五项全部 licensed、未授权、未装 yikai-builder 的条件下，
// 随包官方预置仍能列出与写入，而同样的内容按作者保存会被拦截。
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('YIKAI_BLOX_FEATURE_POLICY_FILE', dirname(__DIR__, 2) . '/config/blox-feature-policy.php');

require dirname(__DIR__) . '/bootstrap.php';
if (!function_exists('cacheClear')) {
    function cacheClear(): void {}
}
if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void {}
}
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$result = [];
$result['denied'] = BloxFeaturePolicy::denied();

$catalog = BloxAreaTemplatePresets::editorCatalog('footer');
$result['footer_catalog'] = array_column($catalog, 'slug');

// 作者写入样例：自带四栏页脚 + 首个元素加一条显示条件（display_conditions，Pro）。
// 站点数据绑定自 v1.20.2 起免费，所以用显示条件确保内容里确有专业能力：作者直接保存应被拦截，可信写入放行。
$fourColumn = null;
foreach ($catalog as $item) {
    if ($item['slug'] === 'four-column-dark-site-footer') {
        $fourColumn = $item;
    }
}
$sections = $fourColumn['sections'] ?? [];
if (isset($sections[0]['columns'][0]['elements'][0])) {
    $sections[0]['columns'][0]['elements'][0]['data']['_conditions'] = [['rules' => [['type' => 'login', 'operator' => 'is', 'value' => 'logged_in']]]];
}
$json = json_encode(['schema' => 1, 'settings' => [], 'sections' => $sections], JSON_THROW_ON_ERROR);
try {
    BloxAreaDocument::process('footer', $json, 'probe');
    $result['author_save'] = 'saved';
} catch (Throwable $error) {
    $result['author_save'] = in_array($error->getMessage(), [__('blox_query_loop_license_required'), __('blox_display_conditions_license_required')], true)
        ? 'license' : 'error:' . $error->getMessage();
}

$result['trusted_save'] = BloxFeaturePolicy::asTrustedWrite(static function () use ($json): string {
    BloxAreaDocument::process('footer', $json, 'probe');
    return 'saved';
});

// 可信写入中途抛错后必须复位，不得把放行状态泄漏给后续的作者保存
try {
    BloxFeaturePolicy::asTrustedWrite(static function (): void {
        throw new RuntimeException('boom');
    });
} catch (RuntimeException) {
}
$result['trusted_after_throw'] = BloxFeaturePolicy::inTrustedWrite();

echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
