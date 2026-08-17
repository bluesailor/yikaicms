<?php
/** Runtime policy for selecting an installed theme safely. */

declare(strict_types=1);

final class ThemeRuntime
{
    public static function resolve(string $requested, string $themesRoot): string
    {
        $requested = strtolower(trim($requested));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $requested)) {
            return 'default';
        }

        $themeDir = rtrim($themesRoot, '/\\') . DIRECTORY_SEPARATOR . $requested;
        if (!is_file($themeDir . '/theme.json')
            || !is_file($themeDir . '/layouts/header.php')
            || !is_file($themeDir . '/layouts/footer.php')) {
            return 'default';
        }

        return $requested;
    }
}
