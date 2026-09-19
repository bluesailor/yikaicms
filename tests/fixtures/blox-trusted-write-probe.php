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

// 自带四栏页脚含站点数据绑定（query_loop 档）：作者直接保存同样的内容应被拦截
$fourColumn = null;
foreach ($catalog as $item) {
    if ($item['slug'] === 'four-column-dark-site-footer') {
        $fourColumn = $item;
    }
}
$json = json_encode(['schema' => 1, 'settings' => [], 'sections' => $fourColumn['sections'] ?? []], JSON_THROW_ON_ERROR);
try {
    BloxAreaDocument::process('footer', $json, 'probe');
    $result['author_save'] = 'saved';
} catch (Throwable $error) {
    $result['author_save'] = $error->getMessage() === __('blox_query_loop_license_required') ? 'license' : 'error:' . $error->getMessage();
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
