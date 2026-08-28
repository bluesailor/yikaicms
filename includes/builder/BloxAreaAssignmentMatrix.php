<?php
/** Resolve Header/Footer assignments for a list of concrete page contexts. */

declare(strict_types=1);

final class BloxAreaAssignmentMatrix
{
    /**
     * @param list<array{key:string,label:string,context:array{home?:bool,channel_id?:int,page_id?:int,lang?:string}}> $contexts
     * @param array{header?:list<array<string,mixed>>,footer?:list<array<string,mixed>>} $templatesByType
     * @param array{header?:bool,footer?:bool} $enabledByType
     * @return list<array{key:string,label:string,lang:string,context:array<string,mixed>,areas:array<string,array{enabled:bool,template:?array}>}>
     */
    public static function build(array $contexts, array $templatesByType, array $enabledByType): array
    {
        $rows = [];
        foreach ($contexts as $contextRow) {
            $context = is_array($contextRow['context'] ?? null) ? $contextRow['context'] : [];
            $areas = [];
            foreach (['header', 'footer'] as $areaType) {
                $enabled = (bool) ($enabledByType[$areaType] ?? false);
                $templates = is_array($templatesByType[$areaType] ?? null)
                    ? $templatesByType[$areaType]
                    : [];
                $areas[$areaType] = [
                    'enabled' => $enabled,
                    'template' => $enabled ? BloxAreaResolver::resolve($templates, $context) : null,
                ];
            }
            $rows[] = [
                'key' => trim((string) ($contextRow['key'] ?? '')),
                'label' => trim((string) ($contextRow['label'] ?? '')),
                'lang' => trim((string) ($context['lang'] ?? '')),
                'context' => $context,
                'areas' => $areas,
            ];
        }
        return $rows;
    }
}
