<?php
/**
 * WP HTML API — minimal shims so the vendored classes run outside WordPress.
 *
 * Defines stand-ins for the few WordPress globals referenced by
 * WP_HTML_Tag_Processor / Decoder. Yikai's own __() already exists and is
 * compatible (returns the input string when key is missing).
 */

declare(strict_types=1);

if (!function_exists('_doing_it_wrong')) {
    /**
     * No-op replacement — WP uses this for developer warnings.
     */
    function _doing_it_wrong(string $function, string $message, string $version): void
    {
        // intentionally empty; logging would be noise on production
    }
}

if (!function_exists('wp_has_noncharacters')) {
    /**
     * Detect Unicode non-characters (U+FDD0..U+FDEF and U+FFFE/U+FFFF per plane).
     * Used by Tag_Processor when validating attribute names.
     */
    function wp_has_noncharacters(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        // Quick ASCII bail-out
        if (!preg_match('//u', $value)) {
            return true; // invalid UTF-8 — treat as having non-characters
        }
        // U+FDD0..U+FDEF
        if (preg_match('/[\x{FDD0}-\x{FDEF}]/u', $value)) {
            return true;
        }
        // U+FFFE/U+FFFF and equivalents on every plane
        if (preg_match('/[\x{FFFE}\x{FFFF}\x{1FFFE}\x{1FFFF}\x{2FFFE}\x{2FFFF}\x{3FFFE}\x{3FFFF}\x{4FFFE}\x{4FFFF}\x{5FFFE}\x{5FFFF}\x{6FFFE}\x{6FFFF}\x{7FFFE}\x{7FFFF}\x{8FFFE}\x{8FFFF}\x{9FFFE}\x{9FFFF}\x{AFFFE}\x{AFFFF}\x{BFFFE}\x{BFFFF}\x{CFFFE}\x{CFFFF}\x{DFFFE}\x{DFFFF}\x{EFFFE}\x{EFFFF}\x{FFFFE}\x{FFFFF}\x{10FFFE}\x{10FFFF}]/u', $value)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('wp_kses_uri_attributes')) {
    /**
     * Attribute names whose values are URIs and require URL-safety treatment.
     * Mirrors WordPress's stable list.
     */
    function wp_kses_uri_attributes(): array
    {
        return [
            'action', 'archive', 'background', 'cite', 'classid', 'codebase',
            'data', 'formaction', 'href', 'icon', 'longdesc', 'manifest',
            'poster', 'profile', 'src', 'srcset', 'usemap', 'xmlns',
        ];
    }
}
