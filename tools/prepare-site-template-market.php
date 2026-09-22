<?php
declare(strict_types=1);

/** Local release preparation only. Never signs packages, enables publication, or sends network requests. */
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/SiteTemplateArchive.php';
$options = getopt('', ['inventory:', 'delivery:', 'covers:', 'catalog:']);
foreach (['inventory', 'delivery', 'covers', 'catalog'] as $required) {
    if (!is_string($options[$required] ?? null) || $options[$required] === '') throw new RuntimeException('Required option: --' . $required);
}
$inventory = json_decode((string) file_get_contents($options['inventory']), true, 32, JSON_THROW_ON_ERROR);
$catalog = json_decode((string) file_get_contents($options['catalog']), true, 32, JSON_THROW_ON_ERROR);
if (!is_array($inventory) || !is_array($catalog) || !is_array($catalog['templates'] ?? null)) throw new RuntimeException('Invalid input');
$delivery = realpath($options['delivery']);
$coverRoot = realpath($options['covers']);
if ($delivery === false || $coverRoot === false) throw new RuntimeException('Delivery and covers must already exist');
$bySlug = [];
foreach ($catalog['templates'] as $entry) {
    if (!is_array($entry) || ($entry['status'] ?? '') !== 'draft' || ($entry['sig'] ?? '') !== '') throw new RuntimeException('Only unsigned draft catalogs may be prepared');
    $bySlug[(string) $entry['slug']] = $entry;
}
$prepared = [];
$assets = [];
foreach ($inventory as $site) {
    $slug = (string) ($site['theme'] ?? '');
    $id = (string) ($site['id'] ?? '');
    $version = (string) ($site['new_version'] ?? '');
    if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $slug) !== 1 || preg_match('/^[a-z0-9][a-z0-9-]*$/D', $id) !== 1
        || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1 || !isset($bySlug[$slug])) throw new RuntimeException('Invalid inventory identity');
    $filename = $slug . '-site-v' . $version . '.zip';
    $path = $delivery . '/packages/' . $filename;
    $size = is_file($path) ? filesize($path) : false;
    if (!is_int($size) || $size < 1 || $size > SiteTemplateArchive::MAX_ZIP) throw new RuntimeException('Missing or oversized package: ' . $filename);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Invalid ZIP: ' . $filename);
    try {
        $manifest = json_decode((string) $zip->getFromName('site.json'), true, 64, JSON_THROW_ON_ERROR);
        $meta = json_decode((string) $zip->getFromName('theme/theme.json'), true, 32, JSON_THROW_ON_ERROR);
    } finally { $zip->close(); }
    if (!is_array($manifest) || !is_array($meta) || ($manifest['format'] ?? '') !== 'yikaicms-site-template'
        || ($manifest['theme'] ?? '') !== $slug || ($meta['version'] ?? '') !== $version
        || !in_array($manifest['version'] ?? 0, SiteTemplateArchive::SUPPORTED_VERSIONS, true)
        || !is_string($manifest['cms'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/D', $manifest['cms']) !== 1) throw new RuntimeException('Package metadata mismatch: ' . $filename);
    $cover = $coverRoot . '/' . $id . '.webp';
    $info = is_file($cover) ? getimagesize($cover) : false;
    if (!is_array($info) || ($info['mime'] ?? '') !== 'image/webp' || $info[0] < 640 || $info[1] < 320) throw new RuntimeException('Missing desktop WebP cover: ' . $id);
    $item = array_replace($bySlug[$slug], [
        'version' => $version, 'cms' => $manifest['cms'], 'format_version' => $manifest['version'],
        'status' => 'draft', 'sig' => '', 'package' => $filename, 'size_bytes' => $size,
        'hash' => 'sha256:' . hash_file('sha256', $path),
        'download_url' => 'https://update.yikaicms.com/packages/site-templates/' . $filename,
        'screenshot' => 'https://update.yikaicms.com/assets/site-templates/' . $slug . '/' . $version . '/preview.webp',
    ]);
    $prepared[] = $item;
    $assets[] = ['source' => $cover, 'target' => $delivery . '/assets/site-templates/' . $slug . '/' . $version . '/preview.webp'];
}
if (count($prepared) !== count($bySlug)) throw new RuntimeException('Inventory and draft catalog differ');
foreach ($assets as $asset) {
    $directory = dirname($asset['target']);
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('Cannot create cover directory');
    if (!copy($asset['source'], $asset['target'])
        || !hash_equals((string) hash_file('sha256', $asset['source']), (string) hash_file('sha256', $asset['target']))) throw new RuntimeException('Cover copy verification failed');
}
$catalog = ['updated_at' => gmdate('c'), 'templates' => $prepared];
$json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
if (file_put_contents($options['catalog'], $json) === false || file_put_contents($delivery . '/catalog.json', $json) === false
    || !copy(ROOT_PATH . '/deploy/site-template-market/review.php', $delivery . '/index.php')
    || !copy(ROOT_PATH . '/deploy/site-template-market/review-router.php', $delivery . '/review-router.php')) throw new RuntimeException('Cannot write preparation files');
echo json_encode(['templates' => count($prepared), 'bytes' => array_sum(array_column($prepared, 'size_bytes')),
    'format_versions' => array_count_values(array_column($prepared, 'format_version')), 'status' => 'draft',
    'review' => $delivery . '/index.php'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
