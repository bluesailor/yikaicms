<?php
/** Blox 前台精准编辑目标：只为高价值区域元素输出稳定 ID 与本地化标签。 */

declare(strict_types=1);

require_once __DIR__ . '/../HtmlTagRewriter.php';

final class BloxFrontendEditTarget
{
    /** @var array<string,array<string,array{target:string,label:string}>> */
    private const TARGETS = [
        'header' => [
            'logo' => ['target' => 'logo', 'label' => 'fe_edit_logo_block'],
            'nav' => ['target' => 'header-navigation', 'label' => 'fe_edit_header_navigation'],
            'nav-mega' => ['target' => 'header-navigation', 'label' => 'fe_edit_header_navigation'],
            'site-contact' => ['target' => 'contact', 'label' => 'fe_edit_contact_block'],
            'site-search' => ['target' => 'site-search', 'label' => 'fe_edit_site_search'],
            'language-switcher' => ['target' => 'language-switcher', 'label' => 'fe_edit_language_switcher'],
        ],
        'footer' => [
            'nav' => ['target' => 'footer-navigation', 'label' => 'fe_edit_footer_navigation'],
            'site-contact' => ['target' => 'contact', 'label' => 'fe_edit_contact_block'],
            'social-links' => ['target' => 'social-links', 'label' => 'fe_edit_social_links_block'],
            'site-copyright' => ['target' => 'site-copyright', 'label' => 'fe_edit_site_copyright'],
        ],
    ];

    private static string $area = '';

    /** 在区域渲染期间提供短生命周期上下文，异常时也恢复先前状态。 */
    public static function inArea(string $area, callable $render): string
    {
        if (!array_key_exists($area, self::TARGETS)) {
            throw new InvalidArgumentException('Invalid Blox frontend edit area');
        }
        $previous = self::$area;
        self::$area = $area;
        try {
            return (string) $render();
        } finally {
            self::$area = $previous;
        }
    }

    public static function mark(string $html, string $elementType, string $nodeId): string
    {
        $target = self::TARGETS[self::$area][$elementType] ?? null;
        $nodeId = trim($nodeId);
        if ($target === null || $html === '' || empty($_SESSION['admin_id'])
            || (function_exists('isCleanFrontendPreview') && isCleanFrontendPreview()) || $nodeId === ''
            || strlen($nodeId) > 512 || preg_match('/[\x00-\x1F\x7F]/', $nodeId) === 1) {
            return $html;
        }

        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag()) {
            return $html;
        }
        $processor->setAttribute('data-yk-element-edit', $target['target']);
        $processor->setAttribute('data-yk-element-id', $nodeId);
        $processor->setAttribute('data-yk-element-label', __($target['label']));
        return $processor->getUpdatedHtml();
    }
}
