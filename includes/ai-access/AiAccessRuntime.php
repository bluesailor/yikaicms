<?php

declare(strict_types=1);

require_once __DIR__ . '/AiAccessBootstrapConfig.php';
require_once __DIR__ . '/AiAccessIdentity.php';

/** 待 S2 消费的最小引导；本文件自身不提供可访问的工具/认证端点。 */
final class AiAccessRuntime
{
    public static function boot(string $root): AiAccessIdentity
    {
        if (session_status() !== PHP_SESSION_NONE || session_id() !== '' || function_exists('hasPermission')) {
            throw new RuntimeException('ai_access_bootstrap_contaminated', 503);
        }
        foreach (['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET', 'DB_PATH', 'DB_PREFIX'] as $key) {
            if (defined($key)) throw new RuntimeException('ai_access_bootstrap_contaminated', 503);
        }
        $resolved = realpath($root);
        if ($resolved === false || (defined('ROOT_PATH') && realpath(ROOT_PATH) !== $resolved)) {
            throw new RuntimeException('ai_access_root_invalid', 503);
        }
        $config = AiAccessBootstrapConfig::read($resolved);
        if (!defined('ROOT_PATH')) define('ROOT_PATH', $resolved);
        if (!defined('DEBUG')) define('DEBUG', false);
        foreach ($config['database'] as $key => $value) define($key, $value);
        require_once dirname(__DIR__, 2) . '/config/database.php';
        try {
            return new AiAccessIdentity(db(), $config['demo'], $config['overrides']);
        } catch (Throwable) {
            throw new RuntimeException('ai_access_unavailable', 503);
        }
    }
}
