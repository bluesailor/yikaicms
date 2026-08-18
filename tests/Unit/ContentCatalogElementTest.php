<?php
/** Blox news/content channel layout contract. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ContentCatalogElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testCatalogIsOnlyOfferedForContentListChannels(): void
    {
        $listMeta = \BuilderRegistry::meta('content-list');
        $pageMeta = \BuilderRegistry::meta('page');

        self::assertTrue($listMeta['content-catalog']['paletteVisible']);
        self::assertFalse($pageMeta['content-catalog']['paletteVisible']);
        self::assertTrue($listMeta['content-catalog']['dynamic']);
        self::assertSame('list', $listMeta['content-catalog']['defaults']['layout']);
    }

    public function testNewsFrontendUsesDedicatedChannelPublicationStorage(): void
    {
        $document = (string) file_get_contents(ROOT_PATH . '/includes/builder/ChannelBloxDocument.php');
        $news = (string) file_get_contents(ROOT_PATH . '/news.php');
        $api = (string) file_get_contents(ROOT_PATH . '/admin/blox_page_api.php');

        self::assertStringContainsString("'type' => 'content-catalog'", $document);
        self::assertStringContainsString('published_data', $document);
        self::assertStringContainsString('ChannelBloxDocument::publishedJson', $news);
        self::assertStringContainsString('ContentCatalogElement::setRuntimeContext', $news);
        self::assertStringContainsString('ChannelBloxDocument::class', $api);
        self::assertStringNotContainsString('contentModel()->updateById', $document);
    }
}
