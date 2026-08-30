<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/RecipeService.php';

final class RecipePortableSettingsTest extends TestCase
{
    public function testSensitiveTopLevelSettingsNeverEnterRecipe(): void
    {
        $portable = RecipeService::filterPortableSettings([
            'site_name' => 'Example',
            'smtp_pass' => 'smtp-secret',
            'translate_api_key' => 'translation-secret',
            'public_api_key' => 'public-secret',
            'license_key' => 'license-secret',
            'cron_token' => 'cron-secret',
            'authorization' => 'bearer-secret',
            'legacy_passwd' => 'legacy-secret',
            'site_keywords' => 'industrial,cms',
        ]);

        self::assertSame('Example', $portable['site_name']);
        self::assertSame('industrial,cms', $portable['site_keywords']);
        foreach (['smtp_pass', 'translate_api_key', 'public_api_key', 'license_key', 'cron_token', 'authorization', 'legacy_passwd'] as $key) {
            self::assertArrayNotHasKey($key, $portable);
        }
    }

    public function testSensitiveKeysAreRemovedRecursivelyFromJsonSettings(): void
    {
        $portable = RecipeService::filterPortableSettings([
            'plugin_options' => json_encode([
                'layout' => 'wide',
                'provider' => ['name' => 'example', 'api_key' => 'nested-secret'],
                'rows' => [['title' => 'A', 'access_token' => 'row-secret']],
            ], JSON_THROW_ON_ERROR),
        ]);
        $decoded = json_decode($portable['plugin_options'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('wide', $decoded['layout']);
        self::assertSame('example', $decoded['provider']['name']);
        self::assertArrayNotHasKey('api_key', $decoded['provider']);
        self::assertArrayNotHasKey('access_token', $decoded['rows'][0]);
    }

    public function testCallerExclusionsOnlyTightenCentralPolicy(): void
    {
        $portable = RecipeService::filterPortableSettings([
            'site_name' => 'Example',
            'theme' => 'default',
            'smtp_pass' => 'secret',
        ], ['theme']);

        self::assertSame(['site_name' => 'Example'], $portable);
    }
}
