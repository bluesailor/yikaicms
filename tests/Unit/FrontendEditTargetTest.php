<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FrontendEditTargetTest extends TestCase
{
    private array $oldSession = [];

    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    protected function setUp(): void
    {
        $this->oldSession = is_array($_SESSION ?? null) ? $_SESSION : [];
        $_SESSION['admin_id'] = 1;
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->oldSession;
    }

    public function testHeaderAllowlistMarksNavigationWithStableIdAndLocalizedLabel(): void
    {
        $html = BloxFrontendEditTarget::inArea(
            'header',
            static fn (): string => BloxFrontendEditTarget::mark(
                '<nav aria-label="Main">Menu</nav>',
                'nav-mega',
                'header-nav-stable'
            )
        );

        self::assertStringContainsString('data-yk-element-edit="header-navigation"', $html);
        self::assertStringContainsString('data-yk-element-id="header-nav-stable"', $html);
        self::assertStringContainsString('data-yk-element-label="' . __('fe_edit_header_navigation') . '"', $html);
    }

    public function testAreaAllowlistDoesNotExposeUnregisteredOrWrongAreaElements(): void
    {
        $headerSocial = BloxFrontendEditTarget::inArea(
            'header',
            static fn (): string => BloxFrontendEditTarget::mark('<div>Social</div>', 'social-links', 'social-1')
        );
        $footerNavigation = BloxFrontendEditTarget::inArea(
            'footer',
            static fn (): string => BloxFrontendEditTarget::mark('<nav>Footer</nav>', 'nav', 'footer-nav-1')
        );
        $headerCta = BloxFrontendEditTarget::inArea(
            'header',
            static fn (): string => BloxFrontendEditTarget::mark('<div>CTA</div>', 'cta', 'cta-1')
        );

        self::assertStringNotContainsString('data-yk-element-edit', $headerSocial . $footerNavigation . $headerCta);
    }

    public function testFooterContactAndSocialTargetsUseDistinctLabels(): void
    {
        $html = BloxFrontendEditTarget::inArea('footer', static function (): string {
            return BloxFrontendEditTarget::mark('<div>Contact</div>', 'site-contact', 'contact-1')
                . BloxFrontendEditTarget::mark('<div>Social</div>', 'social-links', 'social-1');
        });

        self::assertStringContainsString('data-yk-element-edit="contact"', $html);
        self::assertStringContainsString('data-yk-element-label="' . __('fe_edit_contact_block') . '"', $html);
        self::assertStringContainsString('data-yk-element-edit="social-links"', $html);
        self::assertStringContainsString('data-yk-element-label="' . __('fe_edit_social_links_block') . '"', $html);
    }

    public function testPublicAndInvalidIdsDoNotExposeEditMetadata(): void
    {
        unset($_SESSION['admin_id']);
        $public = BloxFrontendEditTarget::inArea(
            'header',
            static fn (): string => BloxFrontendEditTarget::mark('<nav>Menu</nav>', 'nav', 'nav-1')
        );
        $_SESSION['admin_id'] = 1;
        $invalid = BloxFrontendEditTarget::inArea(
            'header',
            static fn (): string => BloxFrontendEditTarget::mark('<nav>Menu</nav>', 'nav', "bad\nid")
        );

        self::assertSame('<nav>Menu</nav>', $public);
        self::assertSame('<nav>Menu</nav>', $invalid);
    }

    public function testAreaContextIsRestoredAfterRendering(): void
    {
        BloxFrontendEditTarget::inArea(
            'header',
            static fn (): string => BloxFrontendEditTarget::mark('<nav>Menu</nav>', 'nav', 'nav-1')
        );

        self::assertSame(
            '<nav>Menu</nav>',
            BloxFrontendEditTarget::mark('<nav>Menu</nav>', 'nav', 'nav-2')
        );
    }
}
