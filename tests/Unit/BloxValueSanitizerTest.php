<?php
/**
 * Blox Typed Control Schema（v1.18.6）：
 *   - BloxValueSanitizer 各 control 类型的 normalize/sanitize 契约
 *   - BloxDocumentPipeline 保存管线集成（类型化清洗 + Unknown Key dry-run 不丢数据）
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxDocumentPipeline;
use BloxUnknownKeys;
use BloxValueSanitizer;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/security.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxValueSanitizerTest extends TestCase
{
    protected function tearDown(): void
    {
        BloxUnknownKeys::drain();
    }

    private function s(string $type, mixed $value, array $extra = []): mixed
    {
        return BloxValueSanitizer::sanitize(['type' => $type] + $extra, $value);
    }

    // ---- 标量类型契约 ----

    public function testTextIsStringifiedAndLengthCapped(): void
    {
        $this->assertSame('hello', $this->s('text', 'hello'));
        $this->assertSame('abc', $this->s('text', 'abcdef', ['maxlength' => 3]));
        $this->assertSame('', $this->s('text', null));
        $this->assertSame(BloxValueSanitizer::TEXT_MAX, mb_strlen($this->s('text', str_repeat('长', 3000))));
    }

    public function testRichtextIsSanitized(): void
    {
        $out = $this->s('richtext', '<p>ok</p><script>alert(1)</script>');
        $this->assertStringContainsString('<p>ok</p>', $out);
        $this->assertStringNotContainsString('<script', $out);
    }

    public function testUrlRejectsPseudoProtocolsKeepsLegit(): void
    {
        $this->assertSame('/about.html', $this->s('url', '/about.html'));
        $this->assertSame('https://a.b/c', $this->s('url', 'https://a.b/c'));
        $this->assertSame('{yk:field name=url /}', $this->s('url', '{yk:field name=url /}'));
        $this->assertSame('', $this->s('url', 'javascript:alert(1)'));
        $this->assertSame('', $this->s('url', '//evil.com/x'));
        $this->assertSame('', $this->s('url', 'data:text/html,x'));
    }

    public function testVideoUrlKeepsPlatformPagesRejectsPseudo(): void
    {
        $watch = 'https://www.youtube.com/watch?v=abc';
        $this->assertSame($watch, $this->s('video_url', $watch));
        $this->assertSame('/uploads/a.mp4', $this->s('video_url', '/uploads/a.mp4'));
        $this->assertSame('', $this->s('video_url', 'javascript:alert(1)'));
    }

    public function testImageAllowsRelativeAndHttpRejectsDataUri(): void
    {
        $this->assertSame('/uploads/a.jpg', $this->s('image', '/uploads/a.jpg'));
        $this->assertSame('https://cdn.x/a.png', $this->s('image', 'https://cdn.x/a.png'));
        $this->assertSame('', $this->s('image', 'javascript:alert(1)'));
        $this->assertSame('', $this->s('image', 'data:image/png;base64,xxxx'));
    }

    public function testNumberEnforcesBoundsAndFallback(): void
    {
        $this->assertSame(5, $this->s('number', '5'));
        $this->assertSame(1, $this->s('number', -3, ['min' => 1]));
        $this->assertSame(10, $this->s('number', 99, ['max' => 10]));
        $this->assertSame(7, $this->s('number', 'abc', ['default' => 7]));
        $this->assertSame(0, $this->s('number', []));
    }

    public function testSelectMustBelongToOptions(): void
    {
        $opts = ['options' => ['a' => 'A', 'b' => 'B'], 'default' => 'a'];
        $this->assertSame('b', $this->s('select', 'b', $opts));
        $this->assertSame('a', $this->s('select', 'evil', $opts));
        // 整数键 options（如 menu_group 0）字符串比较不误伤
        $this->assertSame(0, $this->s('select', 0, ['options' => [0 => 'x', 1 => 'y']]));
        $this->assertSame('0', $this->s('select', '0', ['options' => [0 => 'x', 1 => 'y']]));
    }

    public function testCheckboxNormalizesToStringFlags(): void
    {
        // 归一为 '1'/'0'：兼容 !empty() 与 (string)$v !== '0' 两种存量判定
        $this->assertSame('1', $this->s('checkbox', true));
        $this->assertSame('1', $this->s('checkbox', '1'));
        $this->assertSame('1', $this->s('checkbox', 'on'));
        $this->assertSame('0', $this->s('checkbox', false));
        $this->assertSame('0', $this->s('checkbox', '0'));
        $this->assertSame('0', $this->s('checkbox', ''));
    }

    public function testIconIsAllowlistFiltered(): void
    {
        $this->assertSame('bi:house', $this->s('icon', 'bi:house'));
        // 字符白名单过滤（残留字母无害：不在图标注册表的名字渲染层直接不出图标）
        $this->assertSame('alertcirclescript', $this->s('icon', 'alert circle<script>'));
    }

    public function testResponsiveArrayValuesPassThroughUntouched(): void
    {
        $value = ['base' => 'md', 'md' => 'lg'];
        $this->assertSame($value, $this->s('select', $value, ['options' => ['md' => 1, 'lg' => 2]]));
    }

    public function testUnknownControlTypePassesThrough(): void
    {
        $this->assertSame('anything', $this->s('faq_repeater', 'anything'));
    }

    // ---- 管线集成 ----

    /** @return array<string,mixed> 处理后的首个元素 data */
    private function pipe(array $element): array
    {
        $json = (string) json_encode([[ 'columns' => [['elements' => [$element]]] ]]);
        $processed = BloxDocumentPipeline::process($json);
        return $processed['sections'][0]['columns'][0]['elements'][0]['data'];
    }

    public function testPipelineSanitizesTypedFieldsOnSave(): void
    {
        $data = $this->pipe(['type' => 'button', 'data' => [
            'text' => str_repeat('x', 3000),
            'url' => 'javascript:alert(1)',
            'new_tab' => true,
        ]]);
        $this->assertSame(BloxValueSanitizer::TEXT_MAX, mb_strlen($data['text']));
        $this->assertSame('', $data['url']);
        $this->assertSame('1', $data['new_tab']);
    }

    public function testPipelineNormalizesVideoAndCard(): void
    {
        $video = $this->pipe(['type' => 'video', 'data' => ['url' => 'javascript:alert(1)//x.mp4']]);
        $this->assertSame('', $video['url']);

        $card = $this->pipe(['type' => 'card', 'data' => [
            'image' => 'data:image/png;base64,x', 'link' => '/p/1.html',
        ]]);
        $this->assertSame('', $card['image']);
        $this->assertSame('/p/1.html', $card['link']);
    }

    public function testPipelineKeepsUnknownKeysAndObservesThem(): void
    {
        BloxUnknownKeys::drain();
        $data = $this->pipe(['type' => 'button', 'data' => [
            'text' => 'Go', 'url' => '/a', 'legacy_key' => 'keep-me',
        ]]);
        // dry-run：未知键必须原样保留（兼容存量/插件数据），只观测不丢弃
        $this->assertSame('keep-me', $data['legacy_key']);
        $observed = BloxUnknownKeys::drain();
        $this->assertArrayHasKey('legacy_key', $observed['button'] ?? []);
    }

    public function testReservedKeysAreNotReportedAsUnknown(): void
    {
        BloxUnknownKeys::drain();
        $this->pipe(['type' => 'container', 'data' => ['children' => [], '_global_style' => '']]);
        $observed = BloxUnknownKeys::drain();
        $this->assertArrayNotHasKey('children', $observed['container'] ?? []);
        $this->assertArrayNotHasKey('_global_style', $observed['container'] ?? []);
    }
}
