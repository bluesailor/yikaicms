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
            'paid' => true,
            'entitled' => true,
            'download_url' => 'https://update.yikaicms.com/packages/templates/e2e-remote-template-v1.0.0.zip',
        ]],
    ],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

$provider = new BloxRemoteTemplateProvider(
    static fn (string $url): string => str_contains($url, '/packages/templates/') ? $package : $catalog,
    static fn (string $canonical, string $signature): bool => $canonical === $slug . '|1.0.0|' . $hash
        && $signature === 'fixture-signature'
);
$result = (new BloxRemoteTemplateInstaller($provider))->install($slug, 1);
bloxTemplateModel()->publishDraft((int) $result['id']);
echo json_encode(['id' => (int) $result['id'], 'name' => 'E2E Remote Feature'], JSON_THROW_ON_ERROR) . "\n";
