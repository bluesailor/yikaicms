<?php
/**
 * 建站向导顶部的推荐模板（SiteSetup::marketPreview）：纯函数，只整理已取到的目录。
 * 只推荐能导入的；目录给了模板语言时，和本站内容语言一致的排前面；名称跟随后台语言。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/SiteSetup.php';

final class SiteSetupMarketPreviewTest extends TestCase
{
    /** @return array<string,mixed> */
    private function item(string $slug, array $overrides = []): array
    {
        return array_replace([
            'slug' => $slug, 'name' => $slug . '-zh', 'name_en' => $slug . '-en', 'name_ja' => '',
            'screenshot' => 'https://update.yikaicms.com/assets/site-templates/' . $slug . '/1.0.0/preview.webp',
            'blocked_reason' => '', 'languages' => [],
        ], $overrides);
    }

    public function testOnlyImportableTemplatesAreRecommendedInCatalogOrder(): void
    {
        $catalog = ['templates' => [
            $this->item('a'), $this->item('b', ['blocked_reason' => 'st_market_pending']), $this->item('c'),
            $this->item('d'), $this->item('e'), $this->item('f'),
        ]];
        $preview = SiteSetup::marketPreview($catalog, 'zh-CN', 'zh-CN');
        self::assertSame(6, $preview['total'], 'total counts every template, like the market summary');
        self::assertSame(['a', 'c', 'd', 'e'], array_column($preview['templates'], 'slug'));
        self::assertSame(['slug', 'name', 'screenshot'], array_keys($preview['templates'][0]), 'only what the page needs');
    }

    public function testTemplatesInTheSiteLanguageComeFirst(): void
    {
        $catalog = ['templates' => [
            $this->item('zh1', ['languages' => ['zh-CN']]),
            $this->item('ja1', ['languages' => ['ja']]),
            $this->item('zh2', ['languages' => ['zh-CN']]),
            $this->item('ja2', ['languages' => ['ja']]),
            $this->item('multi', ['languages' => ['zh-CN', 'en', 'ja']]),
        ]];
        self::assertSame(['ja1', 'ja2', 'multi', 'zh1'], array_column(SiteSetup::marketPreview($catalog, 'ja', 'ja')['templates'], 'slug'));
        self::assertSame(['zh1', 'zh2', 'multi', 'ja1'], array_column(SiteSetup::marketPreview($catalog, 'zh-CN', 'zh-CN')['templates'], 'slug'));
    }

    public function testNamesFollowTheAdminLanguageWithFallback(): void
    {
        $catalog = ['templates' => [$this->item('a')]];
        self::assertSame('a-en', SiteSetup::marketPreview($catalog, 'en', 'zh-CN')['templates'][0]['name']);
        self::assertSame('a-zh', SiteSetup::marketPreview($catalog, 'ja', 'zh-CN')['templates'][0]['name'], 'empty name_ja falls back');
    }

    public function testBrokenOrEmptyCatalogsYieldNothing(): void
    {
        self::assertSame(['total' => 0, 'templates' => []], SiteSetup::marketPreview([], 'zh-CN', 'zh-CN'));
        self::assertSame(['total' => 1, 'templates' => []], SiteSetup::marketPreview(['templates' => ['oops', $this->item('x', ['blocked_reason' => 'st_market_cms'])]], 'zh-CN', 'zh-CN'));
    }
}
