<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxTemplateCatalogPresentationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testRemoteThumbnailOnlyAcceptsOfficialTemplateImages(): void
    {
        $response = json_encode([
            'code' => 0,
            'data' => [
                'updated_at' => '2026-08-11',
                'templates' => [
                    [
                        'slug' => 'safe-template',
                        'type' => 'section',
                        'name' => 'Safe',
                        'thumbnail' => '/assets/templates/safe.webp',
                    ],
                    [
                        'slug' => 'foreign-template',
                        'type' => 'section',
                        'name' => 'Foreign',
                        'thumbnail' => 'https://example.com/templates/foreign.webp',
                    ],
                    [
                        'slug' => 'traversal-template',
                        'type' => 'section',
                        'name' => 'Traversal',
                        'thumbnail' => '/assets/templates/../secret.webp',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $provider = new BloxRemoteTemplateProvider(
            static fn (string $url, int $timeout, int $maxBytes): string => $response,
            static fn (string $canonical, string $signature): bool => true,
            'en'
        );

        $items = $provider->items();

        self::assertSame('https://update.yikaicms.com/assets/templates/safe.webp', $items[0]['thumbnail']);
        self::assertSame('', $items[1]['thumbnail']);
        self::assertSame('', $items[2]['thumbnail']);
    }

    public function testLocalThumbnailStaysWithinTemplateContractRoots(): void
    {
        $method = new ReflectionMethod(BloxTemplateCatalog::class, 'safeLocalThumbnail');

        self::assertSame('/uploads/templates/card.png', $method->invoke(null, '/uploads/templates/card.png'));
        self::assertSame('/plugins/shop/assets/card.webp', $method->invoke(null, '/plugins/shop/assets/card.webp'));
        self::assertSame('', $method->invoke(null, 'https://example.com/card.png'));
        self::assertSame('', $method->invoke(null, '/uploads/../config/config.php'));
        self::assertSame('', $method->invoke(null, '/themes/default/card.png'));
    }
}
