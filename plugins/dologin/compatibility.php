<?php
declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition Direct requests do not load the CMS bootstrap. */
if (!defined('ROOT_PATH')) exit('Access Denied');

final class DoLoginCompatibility
{
    public static function supports(string $version): bool
    {
        $meta = json_decode((string) file_get_contents(__DIR__ . '/plugin.json'), true);
        $minimum = (string) ($meta['requires_cms'] ?? '');
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][a-zA-Z0-9.-]+)?$/D', $version) === 1
            && preg_match('/^\d+\.\d+\.\d+$/D', $minimum) === 1
            && version_compare($version, $minimum, '>=');
    }

    public static function currentSiteSupported(): bool
    {
        return defined('CMS_VERSION') && self::supports((string) CMS_VERSION);
    }
}
