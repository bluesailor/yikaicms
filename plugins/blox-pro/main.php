<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) exit('Access Denied');

require_once __DIR__ . '/access.php';

/** Loaded by the normal active-plugin loader; never included by the public renderer. */
function blox_pro_feature_allowed(string $feature): bool
{
    return BloxProAccess::allows($feature);
}
