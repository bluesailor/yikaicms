<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/models/Model.php';
require_once dirname(__DIR__, 2) . '/includes/models/DownloadCategoryModel.php';

final class DownloadCategoryI18nTest extends TestCase
{
    public function testLocalizedNameUsesTranslationAndFallsBackToChinese(): void
    {
        $category = [
            'name' => '软件下载',
            'name_en' => 'Software Downloads',
            'name_ja' => 'ソフトウェア',
        ];

        self::assertSame('Software Downloads', DownloadCategoryModel::localizedName($category, 'en'));
        self::assertSame('ソフトウェア', DownloadCategoryModel::localizedName($category, 'ja'));
        self::assertSame('软件下载', DownloadCategoryModel::localizedName($category, 'zh-CN'));
        self::assertSame('软件下载', DownloadCategoryModel::localizedName(['name' => '软件下载'], 'en'));
    }
}
