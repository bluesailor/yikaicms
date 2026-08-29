<?php
/** Header/Footer assignment overview uses the same resolver as the frontend. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxAreaAssignmentMatrix;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxAreaResolver.php';
require_once ROOT_PATH . '/includes/builder/BloxAreaAssignmentMatrix.php';

final class BloxAreaAssignmentMatrixTest extends TestCase
{
    public function testBuildResolvesEveryContextAndLanguage(): void
    {
        $rows = BloxAreaAssignmentMatrix::build([
            [
                'key' => 'home:en',
                'label' => 'Homepage · English',
                'context' => ['home' => true, 'channel_id' => 0, 'page_id' => 0, 'lang' => 'en'],
            ],
            [
                'key' => 'page:8',
                'label' => 'Page · About [ja]',
                'context' => ['home' => false, 'channel_id' => 0, 'page_id' => 8, 'lang' => 'ja'],
            ],
        ], [
            'header' => [
                ['id' => 10, 'name' => 'Default Header', 'conditions' => '[{"main":"any"}]'],
                ['id' => 5, 'name' => 'English Header', 'conditions' => '[{"main":"home","langs":["en"]}]'],
            ],
            'footer' => [
                ['id' => 20, 'name' => 'Home Footer', 'conditions' => '[{"main":"home"}]'],
            ],
        ], ['header' => true, 'footer' => true]);

        self::assertCount(2, $rows);
        self::assertSame('en', $rows[0]['lang']);
        self::assertSame(5, $rows[0]['areas']['header']['template']['id'] ?? null);
        self::assertSame('home', $rows[0]['areas']['header']['match']['scope'] ?? null);
        self::assertTrue($rows[0]['areas']['header']['match']['language_specific'] ?? false);
        self::assertSame(20, $rows[0]['areas']['footer']['template']['id'] ?? null);
        self::assertSame(10, $rows[1]['areas']['header']['template']['id'] ?? null);
        self::assertNull($rows[1]['areas']['footer']['template']);
    }

    public function testDisabledAreaDoesNotExposeAnInactiveTemplate(): void
    {
        $rows = BloxAreaAssignmentMatrix::build([[
            'key' => 'home',
            'label' => 'Homepage',
            'context' => ['home' => true, 'lang' => 'zh-CN'],
        ]], [
            'header' => [['id' => 1, 'conditions' => null]],
            'footer' => [],
        ], ['header' => false, 'footer' => true]);

        self::assertFalse($rows[0]['areas']['header']['enabled']);
        self::assertNull($rows[0]['areas']['header']['template']);
        self::assertNull($rows[0]['areas']['header']['match']);
        self::assertTrue($rows[0]['areas']['footer']['enabled']);
    }
}
