<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ArticleAdminScopeContractTest extends TestCase
{
    public function testDefaultArticleListIncludesTheNewsRootAndItsChildren(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/admin/article.php');

        self::assertIsString($source);
        self::assertStringContainsString(
            'array_unique(array_merge([$newsChannelId], $newsChildIds))',
            $source
        );
        self::assertStringContainsString('array_merge($params, $newsScopeIds)', $source);
    }
}
