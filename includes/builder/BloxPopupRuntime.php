<?php
/** Resolve and render the active Blox popup at the common frontend footer hook. */

declare(strict_types=1);

final class BloxPopupRuntime
{
    private static bool $booted = false;

    public static function bootstrap(): void
    {
        if (self::$booted || !function_exists('add_action')) {
            return;
        }
        self::$booted = true;
        add_action('ik_footer_scripts', [self::class, 'render'], 4);
    }

    public static function render(): void
    {
        try {
            if (!db()->tableExists('blox_templates')) {
                return;
            }
            $templates = bloxTemplateModel()->publishedAreaTemplates('popup');
            if ($templates === []) {
                return;
            }
            $row = BloxAreaResolver::resolve($templates, self::context());
            if ($row === null) {
                return;
            }
            $json = (string) ($row['published_data'] ?? '');
            $document = BloxPopupDocument::decode($json);
            $body = BlockRenderer::render($json);
            if ($body === '') {
                return;
            }
            BloxAssetCollector::addStyle('/assets/css/blox-popup.css');
            BloxAssetCollector::addScript('/assets/js/blox-popup.js');
            $settings = $document['settings'];
            $id = max(1, (int) ($row['id'] ?? 0));
            $version = max(1, (int) ($row['published_at'] ?? 0));
            $attrs = [
                'data-blox-popup' => (string) $id,
                'data-trigger' => (string) $settings['trigger'],
                'data-delay' => (string) $settings['delay'],
                'data-selector' => (string) $settings['selector'],
                'data-frequency' => (string) $settings['frequency'],
                'data-hours' => (string) $settings['hours'],
                'data-device' => (string) $settings['device'],
                'data-version' => (string) $version,
            ];
            $attrHtml = '';
            foreach ($attrs as $name => $value) {
                $attrHtml .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
            }
            echo '<div class="yk-blox-popup"' . $attrHtml . ' role="dialog" aria-modal="true" aria-hidden="true" aria-label="'
                . htmlspecialchars((string) ($row['name'] ?? __('blox_popup')), ENT_QUOTES) . '">';
            echo '<div class="yk-blox-popup__backdrop"' . (!empty($settings['overlay_close']) ? ' data-popup-close' : '') . '></div>';
            echo '<div class="yk-blox-popup__panel yk-blox-popup__panel--' . htmlspecialchars((string) $settings['width'], ENT_QUOTES) . '" tabindex="-1">';
            if (!empty($settings['show_close'])) {
                echo '<button type="button" class="yk-blox-popup__close" data-popup-close aria-label="' . htmlspecialchars(__('close'), ENT_QUOTES) . '">&times;</button>';
            }
            echo $body . '</div></div>';
        } catch (Throwable $e) {
            error_log('[BloxPopupRuntime] ' . $e->getMessage());
        }
    }

    /** @return array{home:bool,channel_id:int,page_id:int} */
    private static function context(): array
    {
        $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        return [
            'home' => $script === 'index.php',
            'channel_id' => (int) ($GLOBALS['currentChannelId'] ?? 0),
            'page_id' => (int) ($GLOBALS['ykBloxPageId'] ?? 0),
        ];
    }
}
