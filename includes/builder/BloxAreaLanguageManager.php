<?php
/** Header/Footer 的站点级语言版本管理。 */

declare(strict_types=1);

final class BloxAreaLanguageManager
{
    /** @param array<string,mixed> $template */
    public static function managedLanguage(array $template): string
    {
        $area = (string) ($template['type'] ?? '');
        if (!in_array($area, ['header', 'footer'], true)) {
            return '';
        }
        $conditions = BloxAreaResolver::parse($template['conditions'] ?? null);
        if (count($conditions) !== 1) {
            return '';
        }
        $condition = $conditions[0];
        $languages = is_array($condition['langs'] ?? null) ? array_values($condition['langs']) : [];
        return ($condition['main'] ?? '') === 'any'
            && ($condition['ids'] ?? null) === []
            && ($condition['exclude'] ?? null) === false
            && count($languages) === 1
            && is_string($languages[0])
            ? $languages[0]
            : '';
    }

    /**
     * @param array<string,string> $languages
     * @param array{header?:list<array<string,mixed>>,footer?:list<array<string,mixed>>} $publishedByType
     * @param list<array<string,mixed>> $storedTemplates
     * @param array{header?:bool,footer?:bool} $enabledByType
     * @return list<array{code:string,label:string,is_default:bool,areas:array<string,array<string,mixed>>}>
     */
    public static function overview(
        array $languages,
        string $defaultLanguage,
        array $publishedByType,
        array $storedTemplates,
        array $enabledByType
    ): array {
        if (isset($languages[$defaultLanguage])) {
            $languages = [$defaultLanguage => $languages[$defaultLanguage]] + $languages;
        }

        $rows = [];
        foreach ($languages as $language => $label) {
            $context = ['home' => false, 'channel_id' => 0, 'page_id' => 0, 'lang' => $language];
            $areas = [];
            foreach (['header', 'footer'] as $area) {
                $published = is_array($publishedByType[$area] ?? null) ? $publishedByType[$area] : [];
                $candidate = BloxAreaResolver::resolve($published, $context);
                $match = $candidate === null ? null : BloxAreaResolver::explain($candidate, $context);
                $managed = $candidate !== null && self::isManagedTemplate($candidate, $area, $language);
                $draft = self::findManagedTemplate($storedTemplates, $area, $language, 0);
                $enabled = (bool) ($enabledByType[$area] ?? false);
                $mode = !$enabled ? 'disabled'
                    : ($managed ? 'independent'
                        : (!empty($match['language_specific']) ? 'advanced'
                            : ($candidate === null ? 'theme' : ($language === $defaultLanguage ? 'default' : 'inherit'))));

                $areas[$area] = [
                    'enabled' => $enabled,
                    'candidate' => $candidate,
                    'match' => $match,
                    'mode' => $mode,
                    'managed' => $managed,
                    'draft' => $draft,
                ];
            }
            $rows[] = [
                'code' => $language,
                'label' => $label,
                'is_default' => $language === $defaultLanguage,
                'areas' => $areas,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,string> $languages
     * @return array{id:int,reused:bool}
     */
    public static function createLanguageDraft(
        int $sourceId,
        string $area,
        string $language,
        array $languages,
        int $adminId
    ): array {
        self::assertInput($area, $language, $languages);
        foreach (bloxTemplateModel()->catalog($area) as $template) {
            if (self::isManagedTemplate($template, $area, $language)) {
                return ['id' => (int) $template['id'], 'reused' => true];
            }
        }

        $source = bloxTemplateModel()->findForExport($sourceId);
        if (!$source || (string) ($source['type'] ?? '') !== $area) {
            throw new RuntimeException(__('blox_language_area_source_missing'));
        }
        $sourceJson = trim((string) ($source['published_data'] ?? ''));
        if ($sourceJson === '') {
            $sourceJson = trim((string) ($source['draft_data'] ?? ''));
        }
        if ($sourceJson === '') {
            throw new RuntimeException(__('blox_tpl_draft_missing'));
        }

        $document = BloxAreaDocument::decode($area, $sourceJson);
        $draftJson = json_encode([
            'schema' => BloxDocumentPipeline::SCHEMA_VERSION,
            'settings' => $document['settings'],
            'sections' => $document['sections'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $requirements = self::decodeArray($source['requirements'] ?? null);
        $metadata = self::decodeArray($source['metadata'] ?? null);
        $name = mb_substr(
            trim((string) $source['name']) . ' · ' . (string) $languages[$language],
            0,
            150
        );

        db()->beginTransaction();
        try {
            $id = bloxTemplateModel()->createDraft(
                $area,
                $name,
                $draftJson,
                'user',
                BloxDocumentPipeline::SCHEMA_VERSION,
                $requirements,
                (string) ($source['thumbnail'] ?? ''),
                max(0, $adminId),
                '',
                $metadata
            );
            bloxTemplateModel()->saveConditions($id, [[
                'main' => 'any',
                'ids' => [],
                'langs' => [$language],
                'exclude' => false,
            ]]);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }

        return ['id' => $id, 'reused' => false];
    }

    /** @param array<string,string> $languages @return list<int> */
    public static function restoreInheritance(string $area, string $language, array $languages): array
    {
        self::assertInput($area, $language, $languages);
        $ids = [];
        foreach (bloxTemplateModel()->catalog($area) as $template) {
            if ((int) ($template['status'] ?? 0) === 1 && self::isManagedTemplate($template, $area, $language)) {
                $ids[] = (int) $template['id'];
            }
        }
        if ($ids === []) {
            throw new RuntimeException(__('blox_language_area_restore_missing'));
        }
        db()->beginTransaction();
        try {
            foreach ($ids as $id) {
                bloxTemplateModel()->unpublish($id);
            }
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }
        return $ids;
    }

    /** @param list<array<string,mixed>> $templates @return array<string,mixed>|null */
    private static function findManagedTemplate(array $templates, string $area, string $language, int $status): ?array
    {
        foreach ($templates as $template) {
            if ((int) ($template['status'] ?? 0) === $status && self::isManagedTemplate($template, $area, $language)) {
                return $template;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $template */
    private static function isManagedTemplate(array $template, string $area, string $language): bool
    {
        return (string) ($template['type'] ?? $area) === $area
            && self::managedLanguage($template) === $language;
    }

    /** @param array<string,string> $languages */
    private static function assertInput(string $area, string $language, array $languages): void
    {
        if (!in_array($area, ['header', 'footer'], true) || !isset($languages[$language])) {
            throw new RuntimeException(__('blox_invalid_action'));
        }
    }

    /** @return array<string,mixed> */
    private static function decodeArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (JsonException) {
            return [];
        }
    }
}
