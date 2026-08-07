<?php
/** Blox 头尾激活裁决：条件解析、特异性评分、exclude 否决与平票规则。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxAreaResolver;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxAreaResolver.php';

final class BloxAreaResolverTest extends TestCase
{
    private const HOME = ['home' => true, 'channel_id' => 0, 'page_id' => 0];
    private const CHANNEL_5 = ['home' => false, 'channel_id' => 5, 'page_id' => 0];
    private const PAGE_7 = ['home' => false, 'channel_id' => 0, 'page_id' => 7];

    public function testNoConditionsScoresAsDefaultFallback(): void
    {
        $this->assertSame(0, BloxAreaResolver::score([], self::HOME));
    }

    public function testSpecificityLadder(): void
    {
        $any = BloxAreaResolver::parse('[{"main":"any"}]');
        $home = BloxAreaResolver::parse('[{"main":"home"}]');
        $channel = BloxAreaResolver::parse('[{"main":"channel","ids":[5]}]');
        $page = BloxAreaResolver::parse('[{"main":"page","ids":[7]}]');

        $this->assertSame(2, BloxAreaResolver::score($any, self::HOME));
        $this->assertSame(9, BloxAreaResolver::score($home, self::HOME));
        $this->assertNull(BloxAreaResolver::score($home, self::CHANNEL_5));
        $this->assertSame(8, BloxAreaResolver::score($channel, self::CHANNEL_5));
        $this->assertNull(BloxAreaResolver::score($channel, ['channel_id' => 6]));
        $this->assertSame(10, BloxAreaResolver::score($page, self::PAGE_7));
        $this->assertNull(BloxAreaResolver::score($page, ['page_id' => 8]));
    }

    public function testChannelWithEmptyIdsMatchesAnyChannelPageWhilePageRequiresIds(): void
    {
        $channelAny = BloxAreaResolver::parse('[{"main":"channel"}]');
        $pageNoIds = BloxAreaResolver::parse('[{"main":"page"}]');

        $this->assertSame(8, BloxAreaResolver::score($channelAny, self::CHANNEL_5));
        $this->assertNull(BloxAreaResolver::score($pageNoIds, self::PAGE_7));
    }

    public function testExcludeVetoesEntireTemplate(): void
    {
        $conditions = BloxAreaResolver::parse(
            '[{"main":"any"},{"main":"channel","ids":[5],"exclude":true}]'
        );
        // 栏目 5：exclude 命中 → 整个模板出局（即使 any 也命中）
        $this->assertNull(BloxAreaResolver::score($conditions, self::CHANNEL_5));
        // 其他上下文：exclude 未命中 → 按 any 计 2 分
        $this->assertSame(2, BloxAreaResolver::score($conditions, self::HOME));
    }

    public function testResolvePicksHighestSpecificityAndBreaksTiesByNewestId(): void
    {
        $templates = [
            ['id' => 1, 'conditions' => '[{"main":"any"}]'],
            ['id' => 2, 'conditions' => '[{"main":"home"}]'],
            ['id' => 3, 'conditions' => null], // 无条件兜底 0 分
        ];
        $this->assertSame(2, BloxAreaResolver::resolve($templates, self::HOME)['id']);
        $this->assertSame(1, BloxAreaResolver::resolve($templates, self::CHANNEL_5)['id']);

        // 平票（都是 any）→ id 大者赢
        $tied = [
            ['id' => 4, 'conditions' => '[{"main":"any"}]'],
            ['id' => 9, 'conditions' => '[{"main":"any"}]'],
        ];
        $this->assertSame(9, BloxAreaResolver::resolve($tied, self::HOME)['id']);
    }

    public function testResolveReturnsNullWhenNothingApplies(): void
    {
        $templates = [
            ['id' => 1, 'conditions' => '[{"main":"home"}]'],
        ];
        $this->assertNull(BloxAreaResolver::resolve($templates, self::CHANNEL_5));
    }

    public function testAllInvalidConditionsFailClosedInsteadOfSiteWideTakeover(): void
    {
        // P1 回归（2026-08-07 审计）：条件字段有输入但全部无效时，模板必须不激活——
        // 此前退化为「无条件 = 全站兜底 0 分」，一条坏数据即可误接管全站头尾。
        $templates = [
            ['id' => 1, 'conditions' => '[{"main":"bogus"}]'],
            ['id' => 2, 'conditions' => 'not-json'],
        ];
        $this->assertNull(BloxAreaResolver::resolve($templates, self::HOME));

        // 「从未设置」仍享受兜底语义（null / '' / '[]' 都算未设置）
        foreach ([null, '', '[]', 'null'] as $unset) {
            $this->assertFalse(BloxAreaResolver::hasConditionInput($unset));
        }
        $this->assertSame(
            3,
            BloxAreaResolver::resolve([['id' => 3, 'conditions' => null]], self::HOME)['id']
        );
        // 部分无效仍按有效条目评分（单条容错不变）
        $mixed = [['id' => 4, 'conditions' => '[{"main":"bogus"},{"main":"home"}]']];
        $this->assertSame(4, BloxAreaResolver::resolve($mixed, self::HOME)['id']);
        $this->assertNull(BloxAreaResolver::resolve($mixed, self::CHANNEL_5));
    }

    public function testMalformedInputIsSilentlyDropped(): void
    {
        $this->assertSame([], BloxAreaResolver::parse('not-json'));
        $this->assertSame([], BloxAreaResolver::parse('{"main":"any"}')); // 非列表
        $this->assertSame([], BloxAreaResolver::parse('[{"main":"bogus"}]'));
        // 坏条目丢弃、好条目保留；ids 去重且滤非正数
        $mixed = BloxAreaResolver::parse('[{"main":"bogus"},{"main":"channel","ids":[5,"5",-1,0]}]');
        $this->assertCount(1, $mixed);
        $this->assertSame(['main' => 'channel', 'ids' => [5], 'exclude' => false], $mixed[0]);
    }
}
