<?php
/** 2.0.5 设计系统 2.0：排版 token（字号三档、行高、字距、字重）的校验、输出、读写与元素消费。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxDesignSystem;
use BloxDesignType;
use BlockRenderer;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxDesignTypeTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, \"group\" TEXT DEFAULT 'basic', \"key\" TEXT UNIQUE, value TEXT, type TEXT DEFAULT 'text', name TEXT DEFAULT '', tip TEXT DEFAULT '', options TEXT, sort_order INT DEFAULT 0)",
        ];
    }

    public function testItemsAreWhitelisted(): void
    {
        $item = BloxDesignType::normalizeItem(['id' => 'lead', 'name' => 'Lead', 'size' => ['d' => 22, 't' => '1.25rem', 'm' => ''],
            'line_height' => '1.45', 'letter_spacing' => '-0.01em', 'weight' => '500']);
        self::assertSame(['d' => '22px', 't' => '1.25rem'], $item['size']);
        self::assertSame(['1.45', '-0.01em', '500'], [$item['line_height'], $item['letter_spacing'], $item['weight']]);
        foreach ([['size' => ['t' => '16px']], ['size' => ['d' => '4px']], ['size' => ['d' => '16px;}x{']], ['size' => ['d' => '16px'], 'line_height' => '5'],
            ['size' => ['d' => '16px'], 'weight' => '450'], ['size' => ['d' => '16px'], 'letter_spacing' => '1vw']] as $bad) {
            self::assertNull(BloxDesignType::normalizeItem(['id' => 'x', 'name' => 'X'] + $bad), json_encode($bad));
        }
    }

    public function testSeedsCssAndPrecedenceRules(): void
    {
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode(['tokens' => []], JSON_THROW_ON_ERROR);
        $state = BloxDesignSystem::snapshot();
        self::assertSame(['text-xs', 'text-sm', 'text-base', 'text-lg', 'text-xl', 'heading-sm', 'heading-md', 'heading-lg', 'heading-xl'],
            array_column($state['typography'], 'id'));
        $tag = BloxDesignSystem::styleTag();
        self::assertStringContainsString('--yk-typo-heading-lg-size:32px;--yk-typo-heading-lg-lh:1.25;--yk-typo-heading-lg-weight:700;', $tag);
        self::assertStringContainsString('@media (max-width:1023.98px){:root{', $tag);
        self::assertStringContainsString('--yk-typo-heading-lg-size:28px;', $tag);
        self::assertStringContainsString('@media (max-width:767.98px){:root{', $tag);
        // 特异性 (0,2,0) 压过主题标题样式；:not(.yk-r-…) 让元素单独设的精确字号 / 行高优先
        self::assertStringContainsString('.yk-typo-heading-lg:not(.yk-r-font-size){font-size:var(--yk-typo-heading-lg-size)}', $tag);
        self::assertStringContainsString('.yk-typo-heading-lg:not(.yk-r-font-weight){font-weight:var(--yk-typo-heading-lg-weight)}', $tag);
        self::assertStringNotContainsString('.yk-typo-text-xs:not(.yk-r-font-weight)', $tag, '没设的属性不输出规则');
    }

    public function testMutationsAndElementClass(): void
    {
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode(['tokens' => [['id' => 'c_ink', 'name' => 'Ink', 'value' => '#111111']]], JSON_THROW_ON_ERROR);
        BloxDesignSystem::mutate('type_update', ['id' => 'heading-lg', 'name' => '', 'type' => json_encode(['size' => ['d' => '36px', 'm' => '26px'], 'weight' => '800'])], false);
        $stored = json_decode((string) db()->fetchColumn("SELECT value FROM settings WHERE \"key\" = ?", [BloxDesignSystem::SETTING_KEY]), true);
        $saved = array_column($stored['typography'], null, 'id')['heading-lg'];
        self::assertSame(['d' => '36px', 'm' => '26px'], $saved['size']);
        self::assertSame('800', $saved['weight']);
        self::assertSame('c_ink', $stored['tokens'][0]['id'], '改排版不丢颜色 token');
        self::assertCount(4, $stored['containers'], '其它类别随首次保存落盘');
        try {
            BloxDesignSystem::mutate('type_add', ['name' => 'Bad', 'type' => json_encode(['size' => ['d' => '2px']])], false);
            self::fail('非法字号应被拒绝');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('blox_design_invalid', $e->getMessage());
        }

        $render = static fn (string $type, array $data): string => BlockRenderer::render((string) json_encode([[
            'type' => 'custom', 'id' => 's1', 'columns' => [['width' => 'w-full', 'elements' => [['type' => $type, 'id' => 'e1', 'data' => $data]]]],
        ]]));
        self::assertMatchesRegularExpression('/<h2 class="[^"]*\byk-typo-heading-lg\b/', $render('heading', ['text' => 'Hi', 'level' => 'h2', 'type_token' => 'heading-lg']));
        self::assertMatchesRegularExpression('/<div class="prose[^"]*\byk-typo-text-lg\b/', $render('text', ['html' => '<p>Hi</p>', 'type_token' => 'text-lg']));
        self::assertStringNotContainsString('yk-typo-', $render('heading', ['text' => 'Hi', 'level' => 'h2']), '不选不挂类，输出不变');
        self::assertStringNotContainsString('yk-typo-', $render('heading', ['text' => 'Hi', 'level' => 'h2', 'type_token' => 'x;}y{']));
    }
}
