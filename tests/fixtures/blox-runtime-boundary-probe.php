<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function bloxPageEditorEnabled(): bool { return false; }
function bloxAdvancedFeaturesEnabled(): bool { return false; }

// Only isolated test helpers and an in-memory database; never site configuration.
require dirname(__DIR__) . '/bootstrap.php';
require ROOT_PATH . '/includes/builder/bootstrap.php';

$authoring = ['DetailConditionInput', 'DetailTemplatePublishGuard', 'DetailTemplateImpactPreview'];
$before = array_map(static fn (string $class): bool => class_exists($class, false), $authoring);
$document = json_encode([[
    'settings' => ['_conditions' => [['rules' => [
        ['type' => 'login', 'operator' => 'is', 'value' => 'logged_in'],
    ]]]],
    'columns' => [['elements' => [
        ['type' => 'heading', 'data' => ['level' => 'h2', 'text' => 'Published <content>']],
    ]]],
]], JSON_THROW_ON_ERROR);
$_SESSION['member_id'] = 7;
$visible = BlockRenderer::render($document);
unset($_SESSION['member_id']);
$hidden = BlockRenderer::render($document);
$editingRejected = false;
try {
    BloxDocumentPipeline::process($document);
} catch (RuntimeException $error) {
    $editingRejected = $error->getMessage() === __('blox_display_conditions_license_required');
}
$afterRender = array_map(static fn (string $class): bool => class_exists($class, false), $authoring);
$detailSeeds = [];
foreach (['product-detail' => ProductTemplateDocument::class, 'article-detail' => ArticleTemplateDocument::class] as $type => $class) {
    $seed = $class::seed('zh-CN');
    $processed = BloxDocumentPipeline::process($seed);
    $detailSeeds[$type] = [
        'editable' => BloxTemplateEditPolicy::allows($type, false),
        'has_sections' => count($processed['sections']) > 0,
        'stable' => BloxDocumentPipeline::fingerprint($seed) === BloxDocumentPipeline::fingerprint($processed['json']),
    ];
}
require ROOT_PATH . '/includes/builder/detail-editor-bootstrap.php';
$afterEditor = array_map(static fn (string $class): bool => class_exists($class, false), $authoring);
echo json_encode(compact('before', 'visible', 'hidden', 'editingRejected', 'afterRender', 'afterEditor', 'detailSeeds'), JSON_THROW_ON_ERROR);
