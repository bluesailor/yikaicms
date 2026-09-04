<?php

declare(strict_types=1);

/** @return array<string, string> */
function releaseSchemaDefinitions(string $sql): array
{
    $pattern = '~CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`([^`]+)`|([A-Za-z0-9_]+))\s*\((.*?)\)\s*(?:ENGINE\b.*?)?;~is';
    if (!preg_match_all($pattern, $sql, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $definitions = [];
    foreach ($matches as $match) {
        $table = (string) ($match[1] !== '' ? $match[1] : $match[2]);
        $body = preg_replace('/\s+/', ' ', trim((string) $match[3]));
        $definitions[$table] = $body === null ? trim((string) $match[3]) : $body;
    }
    ksort($definitions);
    return $definitions;
}

/** @return list<string> */
function releaseSchemaChangedTables(string $beforeSql, string $afterSql): array
{
    $before = releaseSchemaDefinitions($beforeSql);
    $after = releaseSchemaDefinitions($afterSql);
    $tables = array_unique(array_merge(array_keys($before), array_keys($after)));
    sort($tables);

    $changed = [];
    foreach ($tables as $table) {
        if (!array_key_exists($table, $before)
            || !array_key_exists($table, $after)
            || $before[$table] !== $after[$table]) {
            $changed[] = $table;
        }
    }
    return $changed;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    if ($argc !== 3) {
        fwrite(STDERR, "Usage: php tools/release-schema-diff.php <before.sql> <after.sql>\n");
        exit(2);
    }
    $before = @file_get_contents((string) $argv[1]);
    $after = @file_get_contents((string) $argv[2]);
    if ($before === false || $after === false) {
        fwrite(STDERR, "Unable to read schema input.\n");
        exit(2);
    }
    $beforeDefinitions = releaseSchemaDefinitions($before);
    $afterDefinitions = releaseSchemaDefinitions($after);
    if ($beforeDefinitions === [] || $afterDefinitions === []) {
        fwrite(STDERR, "No CREATE TABLE definitions were found.\n");
        exit(2);
    }
    $changed = releaseSchemaChangedTables($before, $after);
    echo 'COUNT=' . count($changed) . "\n";
    echo 'TABLES=' . implode(',', $changed) . "\n";
}
