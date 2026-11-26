<?php
/**
 * 栏目编辑页：类型下拉框的 onchange 调 ykSyncProductSlugLock()，但页面中段的脚本先 dispatchEvent('change')，
 * 函数定义在页尾——不加判断就在每次打开栏目编辑页时报 ReferenceError（2.3.1 升级演练在浏览器里发现）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ChannelEditScriptOrderTest extends TestCase
{
    public function testTypeChangeHandlerToleratesTheEarlyChangeEvent(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/channel.php');
        $dispatch = strpos($page, "getElementById('channelType').dispatchEvent(new Event('change'))");
        $definition = strpos($page, 'function ykSyncProductSlugLock()');
        self::assertNotFalse($dispatch);
        self::assertNotFalse($definition);
        if ($definition < $dispatch) {
            self::assertTrue(true, '函数在派发 change 之前就已定义');
            return;
        }
        self::assertMatchesRegularExpression('/id="channelType" onchange="if \(typeof ykSyncProductSlugLock === \'function\'\) ykSyncProductSlugLock\(\)"/', $page,
            '函数定义在派发 change 之后：onchange 要先确认函数已定义');
        self::assertStringContainsString("\nykSyncProductSlugLock();\n", $page, '页尾定义后仍同步一次');
    }
}
