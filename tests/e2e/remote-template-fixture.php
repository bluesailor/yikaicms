<?php
/** Deterministic signed-remote-template fixture for the browser integration gate. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$slug = 'e2e-remote-template';
$action = (string) ($argv[1] ?? 'seed');
$existing = bloxTemplateModel()->findWhere(['source' => 'remote', 'source_ref' => $slug]);
if ($existing) {
    db()->delete('blox_remote_template_states', 'template_id = ?', [(int) $existing['id']]);
    db()->delete('blox_templates', 'id = ?', [(int) $existing['id']]);
}
if ($action === 'cleanup') {
    echo "clean\n";
    exit(0);
}
if ($action !== 'seed') {
    fwrite(STDERR, "usage: php tests/e2e/remote-template-fixture.php seed|cleanup\n");
    exit(2);
}

$document = json_encode([
    'format' => BloxTemplateImporter::FORMAT,
    'version' => BloxTemplateImporter::VERSION,
    'type' => 'section',
    'name' => 'E2E Remote Feature',
    'requires' => ['elements' => ['heading'], 'plugins' => []],
    'meta' => ['source_ref' => $slug, 'page_types' => ['general']],
    'document' => [[
        'settings' => ['width' => 'boxed', 'padding' => 'lg'],
        'columns' => [[
            'span' => 12,
            'elements' => [[
                'type' => 'heading',
                'data' => ['text' => 'Remote journey verified'],
            ]],
        ]],
    ]],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$tmp = tempnam(sys_get_temp_dir(), 'yk-e2e-remote');
if ($tmp === false) {
    throw new RuntimeException('Unable to create fixture package');
}
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    throw new RuntimeException('Unable to open fixture package');
}
$zip->addFromString('template.json', $document);
$zip->close();
$package = (string) file_get_contents($tmp);
@unlink($tmp);
$hash = 'sha256:' . hash('sha256', $package);
$catalog = json_encode([
    'code' => 0,
    'data' => [
        // 客户端要求 v2 协议信封（BloxRemoteTemplateProvider::PROTOCOL_VERSION）；
        // 夹具不带这个字段时会被判为「官方模板服务尚未支持所需协议」而整份拒绝。
        'protocol_version' => 2,
        'updated_at' => '2026-08-29',
        'templates' => [[
            'slug' => $slug,
            'type' => 'section',
            'category' => 'features',
            'tier' => 'pro',
            'name' => 'E2E Remote Feature',
            'version' => '1.0.0',
            'hash' => $hash,
            'sig' => 'fixture-signature',
            // v2 协议一致性断言（BloxRemoteTemplateProvider::normalizeItem）：
            // paid 必须等于 access==='licensed'，且 licensed 条目必须带合法 module slug。
            'access' => 'licensed',
            'module' => 'blox',
            'paid' => true,
            'entitled' => true,
            // v2 起只接受短时令牌地址或「合法身份 URL」，静态 /packages/ ZIP 已不被接受
            // （见 BloxRemoteTemplateProvider::safeDownloadUrl）。
            'download_url' => BloxRemoteTemplateProvider::DOWNLOAD_URL . '?' . http_build_query([
                'protocol_version' => BloxRemoteTemplateProvider::PROTOCOL_VERSION,
                'slug' => $slug,
                'version' => '1.0.0',
            ], '', '&', PHP_QUERY_RFC3986),
        ]],
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$provider = new BloxRemoteTemplateProvider(
    static fn (string $url): string => str_contains($url, '/templates/download.php') ? $package : $catalog,
    static fn (string $canonical, string $signature): bool => $canonical === $slug . '|1.0.0|' . $hash
        && $signature === 'fixture-signature'
);
$result = (new BloxRemoteTemplateInstaller($provider))->install($slug, 1);
bloxTemplateModel()->publishDraft((int) $result['id']);
echo json_encode(['id' => (int) $result['id'], 'name' => 'E2E Remote Feature'], JSON_THROW_ON_ERROR) . "\n";
