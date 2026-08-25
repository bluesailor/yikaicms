<?php
/** Header/Footer 文档边界：区域类型设置白名单与统一处理入口。 */

declare(strict_types=1);

final class BloxAreaDocument
{
    public const TYPES = ['header', 'footer'];

    public static function isArea(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** @return array<string,mixed> */
    public static function normalizeSettings(string $type, mixed $settings): array
    {
        self::assertArea($type);
        if ($type !== 'header') {
            return [];
        }
        $settings = is_array($settings) ? $settings : [];
        return [
            'sticky' => !empty($settings['sticky']),
            'sticky_behavior' => BloxHeaderStates::normalizeStickyBehavior($settings['sticky_behavior'] ?? null),
            'sticky_devices' => BloxHeaderStates::normalizeStickyDevices($settings['sticky_devices'] ?? null),
            'header_overlay_enabled' => !array_key_exists('header_overlay_enabled', $settings)
                || !empty($settings['header_overlay_enabled']),
            'header_states' => BloxHeaderStates::normalize($settings['header_states'] ?? null),
        ];
    }

    /**
     * @return array{schema:int,settings:array<string,mixed>,sections:array<int,array<string,mixed>>,json:string}
     */
    public static function process(string $type, string $json, string $idPrefix = 'area'): array
    {
        self::assertArea($type);
        $processed = BloxDocumentPipeline::process($json, $idPrefix);
        $processed['settings'] = self::normalizeSettings($type, $processed['settings']);
        $processed['json'] = json_encode([
            'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings' => $processed['settings'],
            'sections' => $processed['sections'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $processed;
    }

    /** @return array{schema:int,settings:array<string,mixed>,sections:array<int,mixed>} */
    public static function decode(string $type, string $json): array
    {
        self::assertArea($type);
        $document = BloxDocumentPipeline::decode($json);
        $document['settings'] = self::normalizeSettings($type, $document['settings']);
        return $document;
    }

    /** Render the semantic area shell used by both the frontend and editor preview. */
    public static function renderShell(
        string $type,
        array $settings,
        string $body,
        string $previewState = '',
        string $editUrl = ''
    ): string
    {
        self::assertArea($type);
        $editUrl = str_starts_with($editUrl, '/admin/blox_editor.php?') ? $editUrl : '';
        $editAttr = $editUrl === '' ? '' : ' data-yk-edit="' . htmlspecialchars($editUrl, ENT_QUOTES)
            . '" data-yk-edit-label="' . htmlspecialchars(__($type === 'footer' ? 'fe_edit_footer' : 'fe_edit_layout'), ENT_QUOTES) . '"';
        if ($type === 'footer') {
            return '<footer class="yk-blox-area yk-blox-footer"' . $editAttr . '>' . $body . '</footer>';
        }

        $settings = self::normalizeSettings('header', $settings);
        $sticky = !empty($settings['sticky']);
        $stickyDevices = $settings['sticky_devices'];
        if ($sticky && class_exists('BloxAssetCollector')) {
            BloxAssetCollector::addScript('/assets/js/blox-sticky-header.js');
        }

        $previewState = in_array($previewState, BloxHeaderStates::NAMES, true) ? $previewState : '';
        $classes = ['yk-blox-area', 'yk-blox-header'];
        if ($sticky) {
            $classes[] = 'yk-sticky-header';
        }
        if ($previewState !== '') {
            $classes[] = 'yk-header-preview-' . $previewState;
        }

        $variables = [];
        $flags = [];
        foreach ($settings['header_states'] as $state => $values) {
            foreach (['background' => 'bg', 'text' => 'text', 'border' => 'border'] as $key => $suffix) {
                if ($values[$key] !== '') {
                    $variables[] = '--yk-header-' . $state . '-' . $suffix . ':' . $values[$key];
                    $flags[] = ' data-yk-' . $state . '-' . $suffix . '="1"';
                }
            }
            $variables[] = '--yk-header-' . $state . '-shadow:' . BloxHeaderStates::shadowCss($values['shadow']);
        }

        return '<header id="siteHeader" class="' . implode(' ', $classes) . '"'
            . ' data-yk-header-state="' . ($previewState !== '' ? $previewState : 'normal') . '"'
            . ' data-yk-sticky-behavior="' . $settings['sticky_behavior'] . '"'
            . ' data-yk-sticky-desktop="' . (in_array('desktop', $stickyDevices, true) ? '1' : '0') . '"'
            . ' data-yk-sticky-tablet="' . (in_array('tablet', $stickyDevices, true) ? '1' : '0') . '"'
            . ' data-yk-sticky-mobile="' . (in_array('mobile', $stickyDevices, true) ? '1' : '0') . '"'
            . ' data-yk-overlay-enabled="' . (!empty($settings['header_overlay_enabled']) ? '1' : '0') . '"'
            . implode('', array_unique($flags))
            . $editAttr . ' style="' . htmlspecialchars(implode(';', $variables), ENT_QUOTES) . '">'
            . $body . '</header>';
    }

    private static function assertArea(string $type): void
    {
        if (!self::isArea($type)) {
            throw new InvalidArgumentException('Invalid Blox area type');
        }
    }
}
