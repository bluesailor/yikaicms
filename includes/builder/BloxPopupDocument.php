<?php
/** Type-specific document contract for Blox popup templates. */

declare(strict_types=1);

final class BloxPopupDocument
{
    private const TRIGGERS = ['delay', 'exit', 'click'];
    private const FREQUENCIES = ['every', 'session', 'hours'];
    private const DEVICES = ['all', 'desktop', 'mobile'];
    private const WIDTHS = ['sm', 'md', 'lg', 'xl'];

    /** @return array{schema:int,settings:array<string,mixed>,sections:array<int,mixed>} */
    public static function decode(string $json): array
    {
        $raw = self::raw($json);
        $document = BloxDocumentPipeline::decode($json);
        $document['settings'] = self::normalizeSettings($raw['settings'] ?? null);
        return $document;
    }

    /** @return array{schema:int,settings:array<string,mixed>,sections:array<int,array<string,mixed>>,json:string} */
    public static function process(string $json, string $idPrefix = 'popup'): array
    {
        $document = self::decode($json);
        $baseJson = json_encode([
            'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings' => [],
            'sections' => $document['sections'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $processed = BloxDocumentPipeline::process($baseJson, $idPrefix);
        $processed['settings'] = $document['settings'];
        $processed['json'] = json_encode([
            'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings' => $document['settings'],
            'sections' => $processed['sections'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $processed;
    }

    /** @return array<string,mixed> */
    public static function normalizeSettings(mixed $settings): array
    {
        $settings = is_array($settings) ? $settings : [];
        $trigger = (string) ($settings['trigger'] ?? 'delay');
        $frequency = (string) ($settings['frequency'] ?? 'session');
        $device = (string) ($settings['device'] ?? 'all');
        $width = (string) ($settings['width'] ?? 'md');
        return [
            'trigger' => in_array($trigger, self::TRIGGERS, true) ? $trigger : 'delay',
            'delay' => max(0, min(60, (int) ($settings['delay'] ?? 3))),
            'selector' => self::selector((string) ($settings['selector'] ?? '')),
            'frequency' => in_array($frequency, self::FREQUENCIES, true) ? $frequency : 'session',
            'hours' => max(1, min(720, (int) ($settings['hours'] ?? 24))),
            'device' => in_array($device, self::DEVICES, true) ? $device : 'all',
            'width' => in_array($width, self::WIDTHS, true) ? $width : 'md',
            'overlay_close' => !array_key_exists('overlay_close', $settings) || !empty($settings['overlay_close']),
            'show_close' => !array_key_exists('show_close', $settings) || !empty($settings['show_close']),
        ];
    }

    public static function fingerprint(string $json): string
    {
        $document = self::decode($json);
        return hash('sha256', json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function revisionMatches(string $json, string $revision): bool
    {
        $revision = strtolower(trim($revision));
        return preg_match('/^[a-f0-9]{64}$/', $revision) === 1
            && hash_equals(self::fingerprint($json), $revision);
    }

    /** @return array<string|int,mixed> */
    private static function raw(string $json): array
    {
        if (strlen($json) > BloxDocumentPipeline::MAX_JSON_BYTES) {
            throw new RuntimeException(__('blox_doc_too_large'));
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(__('blox_doc_invalid_json'));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException(__('blox_doc_invalid_json'));
        }
        return $decoded;
    }

    private static function selector(string $selector): string
    {
        $selector = trim($selector);
        if ($selector === '') {
            return '';
        }
        return preg_match('/^(?:#[A-Za-z][A-Za-z0-9_-]*|\.[A-Za-z][A-Za-z0-9_-]*|\[data-[a-z0-9_-]+\])$/', $selector) === 1
            ? $selector
            : '';
    }
}
