<?php
/** Multilingual Header/Footer overview states. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxAreaLanguageManager;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxAreaResolver.php';
require_once ROOT_PATH . '/includes/builder/BloxAreaLanguageManager.php';

final class BloxAreaLanguageManagerTest extends TestCase
{
    public function testOverviewDistinguishesDefaultInheritanceIndependentAndAdvancedRules(): void
    {
        $published = [
            'header' => [
                ['id' => 1, 'name' => 'Default', 'conditions' => '[{"main":"any"}]'],
                ['id' => 2, 'name' => 'English', 'type' => 'header', 'conditions' => '[{"main":"any","langs":["en"]}]'],
            ],
            'footer' => [
                ['id' => 3, 'name' => 'Shared languages', 'conditions' => '[{"main":"any","langs":["en","ja"]}]'],
            ],
        ];
        $stored = [
            ['id' => 4, 'type' => 'footer', 'name' => 'Japanese draft', 'status' => 0, 'conditions' => '[{"main":"any","ids":[],"langs":["ja"],"exclude":false}]'],
        ];

        $rows = BloxAreaLanguageManager::overview(
            ['en' => 'English', 'ja' => '日本語', 'zh-CN' => '中文'],
            'zh-CN',
            $published,
            $stored,
            ['header' => true, 'footer' => true]
        );

        self::assertSame(['zh-CN', 'en', 'ja'], array_column($rows, 'code'));
        self::assertSame('default', $rows[0]['areas']['header']['mode']);
        self::assertSame('independent', $rows[1]['areas']['header']['mode']);
        self::assertSame('inherit', $rows[2]['areas']['header']['mode']);
        self::assertSame('advanced', $rows[1]['areas']['footer']['mode']);
        self::assertSame(4, $rows[2]['areas']['footer']['draft']['id'] ?? null);
    }

    public function testDisabledAreaDoesNotPretendItsLanguageTemplateIsActive(): void
    {
        $rows = BloxAreaLanguageManager::overview(
            ['zh-CN' => '中文'],
            'zh-CN',
            ['header' => [['id' => 1, 'conditions' => null]], 'footer' => []],
            [],
            ['header' => false, 'footer' => true]
        );

        self::assertSame('disabled', $rows[0]['areas']['header']['mode']);
        self::assertSame('theme', $rows[0]['areas']['footer']['mode']);
    }
}
