<?php
/** Template taxonomy used by the website-design dashboard and template filters. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxTemplateModel;
use PHPUnit\Framework\TestCase;

final class BloxSiteDesignContractTest extends TestCase
{
    public function testTemplateTaxonomySeparatesReusableAndSiteAreaTypes(): void
    {
        self::assertSame(['section', 'page', 'header', 'footer', 'popup'], BloxTemplateModel::TYPES);
        self::assertFalse(BloxTemplateModel::conditionalType('section'));
        self::assertFalse(BloxTemplateModel::conditionalType('page'));
        self::assertTrue(BloxTemplateModel::conditionalType('header'));
        self::assertTrue(BloxTemplateModel::conditionalType('footer'));
        self::assertTrue(BloxTemplateModel::conditionalType('popup'));
        self::assertFalse(BloxTemplateModel::validType('all'));
    }

    public function testCustomHeaderRuntimeIsEnabledByDefault(): void
    {
        $defaults = require ROOT_PATH . '/config/defaults.php';

        self::assertSame('1', $defaults['system']['blox_custom_header_enabled']['value'] ?? null);
        self::assertSame('switch', $defaults['system']['blox_custom_header_enabled']['type'] ?? null);
        self::assertSame('1', $defaults['system']['blox_custom_footer_enabled']['value'] ?? null);
        self::assertSame('switch', $defaults['system']['blox_custom_footer_enabled']['type'] ?? null);
    }
}
