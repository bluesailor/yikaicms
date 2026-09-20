<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// Isolated collaborators exercise the real published-document entry points.
function bloxPageEditorEnabled(): bool { return false; }
function bloxAdvancedFeaturesEnabled(): bool { return false; }

final class DetailTemplateResolver
{
    public const SOURCE_CUSTOM = 'custom';
}

final class DetailTemplateProvider
{
    public static string $source = 'custom';
    public static function resolveFor(string $type, array $context): array
    {
        return ['source' => self::$source, 'template' => ['id' => 7, 'published_data' => $type]];
    }
}

final class BlockRenderer
{
    public static function render(string $json): string
    {
        $context = $json === 'product'
            ? ProductTemplateDocument::currentProduct() : ArticleTemplateDocument::currentContent();
        return '<h1>' . htmlspecialchars((string) ($context['title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</h1>';
    }

    /** 与生产版同语义的空输出守门（外审 P2-4 后模板文档改经此判定）。 */
    public static function hasMeaningfulOutput(string $html, array $extraTags = []): bool
    {
        if (trim(strip_tags($html)) !== '') {
            return true;
        }
        $tags = array_merge(['img', 'video', 'iframe'], $extraTags);
        return (bool) preg_match('/<(?:' . implode('|', $tags) . ')\b/i', $html);
    }
}

require dirname(__DIR__, 2) . '/includes/builder/ProductTemplateDocument.php';
require dirname(__DIR__, 2) . '/includes/builder/ArticleTemplateDocument.php';

$output = [
    'product' => ProductTemplateDocument::renderPublished(['id' => 1, 'title' => 'Published product']),
    'article' => ArticleTemplateDocument::renderPublished(['id' => 2, 'title' => 'Published article']),
    'product_context' => ProductTemplateDocument::currentProduct(),
    'article_context' => ArticleTemplateDocument::currentContent(),
];
DetailTemplateProvider::$source = 'native';
$output['native_product'] = ProductTemplateDocument::renderPublished(['id' => 1]);
$output['native_article'] = ArticleTemplateDocument::renderPublished(['id' => 2]);
echo json_encode($output, JSON_THROW_ON_ERROR);
