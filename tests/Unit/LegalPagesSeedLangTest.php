<?php
/**
 * 迁移 20260810_legal_pages_seed 的语言选择。
 *
 * 病：文案按 slug 取，单语言站的栏目 slug 就是 privacy/terms，
 * 于是日文站被塞了一份中文样板正文（2026-09-26 ht-sshc 从 1.x 升 2.0.0 实测）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class LegalPagesSeedLangTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $mig;

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, '
            . "lang TEXT DEFAULT 'zh-CN', content TEXT)",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mig = require ROOT_PATH . '/migrations/20260810_legal_pages_seed.php';
    }

    private function seedChannel(string $slug, string $lang, ?string $content = null): void
    {
        db()->execute('INSERT INTO channels (slug, lang, content) VALUES (?, ?, ?)', [$slug, $lang, $content]);
    }

    private function contentOf(string $slug): string
    {
        $row = db()->fetchOne('SELECT content FROM channels WHERE slug = ? LIMIT 1', [$slug]);
        return (string) ($row['content'] ?? '');
    }

    private function runSeed(): void
    {
        ($this->mig['php'])();
    }

    public function testJapaneseSiteGetsJapaneseStarterText(): void
    {
        $this->seedChannel('privacy', 'ja');
        $this->seedChannel('terms', 'ja');
        $this->runSeed();

        self::assertStringContainsString('プライバシー', $this->contentOf('privacy'));
        self::assertStringNotContainsString('我们收集的信息', $this->contentOf('privacy'));
        self::assertStringNotContainsString('条款的接受', $this->contentOf('terms'));
    }

    public function testEnglishSiteGetsEnglishStarterText(): void
    {
        $this->seedChannel('privacy', 'en');
        $this->runSeed();

        $body = $this->contentOf('privacy');
        self::assertStringContainsString('Data Security', $body);
        self::assertStringNotContainsString('我们收集的信息', $body);
    }

    public function testChineseSiteKeepsChineseStarterText(): void
    {
        $this->seedChannel('privacy', 'zh-CN');
        $this->runSeed();

        self::assertStringContainsString('我们收集的信息', $this->contentOf('privacy'));
    }

    /** 多语言站按后缀 slug 分行，各自拿各自的文案 */
    public function testSuffixedSlugsAreUnaffected(): void
    {
        $this->seedChannel('privacy-ja', 'ja');
        $this->runSeed();

        self::assertStringContainsString('プライバシー', $this->contentOf('privacy-ja'));
    }

    /** 客户已写的正文绝不能被样板盖掉 */
    public function testExistingContentIsNeverOverwritten(): void
    {
        $this->seedChannel('privacy', 'ja', '<p>当社独自の条文</p>');
        $this->runSeed();

        self::assertSame('<p>当社独自の条文</p>', $this->contentOf('privacy'));
    }
}
