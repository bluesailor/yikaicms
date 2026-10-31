<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiProposalReview;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ai-access/AiProposalReview.php';

/** S3：审核页“当前 → 提案”的正文段落对照（只读显示，不回写）。 */
final class AiProposalReviewTest extends TestCase
{
    public function testParagraphsSplitOnBlocksAndDecodeEntitiesWithoutKeepingMarkup(): void
    {
        $html = '<h2>Title &amp; more</h2><p>First <strong>para</strong></p><p>&nbsp;</p><ul><li>One</li><li>Two<br>lines</li></ul><script>alert(1)</script>';
        self::assertSame(['Title & more', 'First para', 'One', 'Two', 'lines', 'alert(1)'], AiProposalReview::paragraphs($html));
        self::assertSame([], AiProposalReview::paragraphs(''));
    }

    public function testDiffMarksAddedRemovedAndKeptParagraphsInOrder(): void
    {
        $diff = AiProposalReview::diff(['a', 'b', 'c', 'd'], ['a', 'c', 'x', 'd']);
        self::assertSame([
            ['op' => 'same', 'text' => 'a'],
            ['op' => 'del', 'text' => 'b'],
            ['op' => 'same', 'text' => 'c'],
            ['op' => 'add', 'text' => 'x'],
            ['op' => 'same', 'text' => 'd'],
        ], $diff);
        self::assertSame([['op' => 'add', 'text' => 'new']], AiProposalReview::diff([], ['new']), '新建：全部是新增');
        self::assertSame([], AiProposalReview::diff([], []));
    }

    public function testVeryLongBodiesStayBoundedAndLoseNothing(): void
    {
        $before = array_map(static fn(int $i): string => 'p' . $i, range(1, 900));
        $after = $before;
        $after[10] = 'changed';
        $after[] = 'tail';
        $diff = AiProposalReview::diff($before, $after);
        $kept = array_column(array_filter($diff, static fn(array $l): bool => $l['op'] !== 'del'), 'text');
        $removed = array_column(array_filter($diff, static fn(array $l): bool => $l['op'] !== 'add'), 'text');
        self::assertSame($after, $kept, '按顺序取“相同+新增”就是提案正文');
        self::assertSame($before, $removed, '按顺序取“相同+移除”就是当前正文');
    }
}
