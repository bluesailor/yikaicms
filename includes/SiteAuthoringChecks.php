<?php
declare(strict_types=1);

// 文档管线会用到页头状态（BloxHeaderStates → AbstractElement）。这里不走渲染器 bootstrap（不注册元素与钩子），
// 只按 bootstrap 的顺序载入元素基类所依赖的那几个文件。
require_once __DIR__ . '/TagEngine.php';
require_once __DIR__ . '/UrlPolicy.php';
require_once __DIR__ . '/HtmlPolicy.php';
require_once __DIR__ . '/PageHeroStyleResolver.php';
require_once __DIR__ . '/PageHeroDesignDraft.php';
require_once __DIR__ . '/builder/BloxResponsiveValue.php';
require_once __DIR__ . '/builder/BloxCssCompiler.php';
require_once __DIR__ . '/builder/AbstractElement.php';
require_once __DIR__ . '/builder/BloxDocumentPipeline.php';
require_once __DIR__ . '/builder/ProductTemplateDocument.php';
require_once __DIR__ . '/builder/DetailTemplateResolver.php';
require_once __DIR__ . '/builder/BloxDotNav.php';
require_once __DIR__ . '/builder/BloxHeaderStates.php';

/** Read-only hints over stored Builder documents, never a substitute for rendered-page checks. */
final class SiteAuthoringChecks
{
    /** @return array{issues:list<array{kind:string,detail:string}>,limited:bool} */
    public static function inspect(string $json): array
    {
        try { $document = BloxDocumentPipeline::decode($json); }
        catch (RuntimeException) {
            return ['issues' => [['kind' => 'sc_builder_invalid', 'detail' => '']], 'limited' => false];
        }
        $issues = [];
        $anchors = [];
        $numbered = [];
        $budget = 10000;
        $limited = false;
        foreach ($document['sections'] as $index => $section) {
            if (!is_array($section)) {
                $issues[] = ['kind' => 'sc_builder_invalid', 'detail' => ''];
                break;
            }
            if ($index >= 100) { $limited = true; break; }
            $settings = is_array($section['settings'] ?? null) ? $section['settings'] : [];
            $label = self::text($settings['title'] ?? '') ?: '#' . ($index + 1);
            $anchor = self::text($settings['anchor_id'] ?? '');
            if ($anchor !== '') {
                if (isset($anchors[$anchor])) $issues[] = ['kind' => 'sc_anchor_duplicate', 'detail' => '#' . $anchor . ' (' . $anchors[$anchor] . ' / ' . $label . ')'];
                $anchors[$anchor] = $label;
            }
            $numbers = [];
            self::walk($section, $numbers, $issues, $budget, $limited);
            if (isset($numbers['01'], $numbers['02'], $numbers['03'])) $numbered[] = $label;
            if ($budget <= 0 || count($issues) >= 100) { $limited = true; break; }
        }
        if (count($numbered) > 1) $issues[] = ['kind' => 'sc_numbered_repeat', 'detail' => implode(' / ', $numbered)];
        return ['issues' => array_slice($issues, 0, 100), 'limited' => $limited];
    }

    public static function hasChanges(string $draft, string $published): bool
    {
        if (trim($draft) === '') return false;
        if (trim($published) === '') return true;
        try { return !hash_equals(BloxDocumentPipeline::fingerprint($draft), BloxDocumentPipeline::fingerprint($published)); }
        catch (RuntimeException) { return $draft !== $published; }
    }

    private static function walk(array $node, array &$numbers, array &$issues, int &$budget, bool &$limited, int $depth = 0): void
    {
        if ($depth > 24 || --$budget < 0) { $limited = true; return; }
        $type = $node['type'] ?? '';
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        if (in_array($type, ['text', 'heading'], true)) {
            $text = self::text($data[$type === 'text' ? 'html' : 'text'] ?? '');
            if (preg_match('/^0[123]$/D', $text)) $numbers[$text] = true;
        }
        if ($type === 'button' && array_key_exists('text', $data) && self::text($data['text']) === '') {
            $issues[] = ['kind' => 'sc_button_label', 'detail' => self::text($node['id'] ?? '')];
        }
        foreach ($node as $child) {
            if ($budget <= 0 || count($issues) >= 100) { $limited = true; break; }
            if (is_array($child)) self::walk($child, $numbers, $issues, $budget, $limited, $depth + 1);
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8')) : '';
    }
}
