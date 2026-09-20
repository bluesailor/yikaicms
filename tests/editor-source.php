<?php
declare(strict_types=1);

/** Expand only the extracted method partials, without executing editor PHP. */
function bloxEditorSourceForTest(): string
{
    $source = file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
    if ($source === false) throw new RuntimeException('Cannot read editor source');
    foreach (['template-library-methods.php', 'media-editing-methods.php', 'multi-selection-methods.php', 'condition-methods.php', 'publish-check-methods.php'] as $name) {
        $include = "<?php require __DIR__ . '/blox_editor/partials/" . $name . "'; ?>";
        $partial = file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/' . $name);
        if ($partial === false || substr_count($source, $include) !== 1) {
            throw new RuntimeException('Missing or duplicate editor method partial: ' . $name);
        }
        $source = str_replace($include, $partial, $source);
    }
    return $source;
}
