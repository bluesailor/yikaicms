<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 区块显示/隐藏：settings.hidden 是可选键，缺省即显示——老数据渲染结果必须不变。
 * 前台永不输出隐藏区块（含登录管理员）；编辑器画布经 showHidden 开关照常渲染。
 */
final class SectionVisibilityTest extends TestCase
{
    private function blocks(bool $hidden): string
    {
        return json_encode([
            ['id' => 's1', 'settings' => $hidden ? ['hidden' => true] : [], 'columns' => [
                ['id' => 'c1', 'elements' => [['id' => 'e1', 'type' => 'text', 'data' => ['html' => '<p>ALPHA</p>']]]],
            ]],
            ['id' => 's2', 'settings' => [], 'columns' => [
                ['id' => 'c2', 'elements' => [['id' => 'e2', 'type' => 'text', 'data' => ['html' => '<p>BETA</p>']]]],
            ]],
        ], JSON_UNESCAPED_UNICODE);
    }

    protected function tearDown(): void
    {
        \BlockRenderer::$showHidden = false;
    }

    public function testHiddenSectionIsNotRenderedOnFront(): void
    {
        $html = \BlockRenderer::render($this->blocks(true));
        $this->assertStringNotContainsString('ALPHA', $html);
        $this->assertStringContainsString('BETA', $html, '其余区块必须照常渲染');
    }

    public function testVisibleSectionRenders(): void
    {
        $this->assertStringContainsString('ALPHA', \BlockRenderer::render($this->blocks(false)));
    }

    public function testEditorCanvasStillShowsHiddenSection(): void
    {
        \BlockRenderer::$showHidden = true;
        $this->assertStringContainsString('ALPHA', \BlockRenderer::render($this->blocks(true)));
    }

    public function testAbsentKeyRendersIdenticallyToLegacyData(): void
    {
        // 老数据没有 hidden 键：输出须与显式 hidden=false 完全一致（黄金对拍）
        $legacy = json_encode([
            ['id' => 's1', 'settings' => ['padding' => 'md'], 'columns' => [
                ['id' => 'c1', 'elements' => [['id' => 'e1', 'type' => 'text', 'data' => ['html' => '<p>ALPHA</p>']]]],
            ]],
        ], JSON_UNESCAPED_UNICODE);
        $withFlag = str_replace('"padding":"md"', '"padding":"md","hidden":false', $legacy);
        $this->assertSame(\BlockRenderer::render($legacy), \BlockRenderer::render($withFlag));
    }
}
