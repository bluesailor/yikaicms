<?php
/** 在线留言表单元素：渲染表单设计器里的表单模板。 */

declare(strict_types=1);

final class ContactFormElement extends AbstractElement
{
    public function type(): string { return 'contact_form'; }
    public function label(): string { return __('blox_el_contact_form'); }
    public function icon(): string { return 'mail-forward'; }
    public function paletteVisible(string $context = 'page'): bool { return $context === 'contact'; }
    public function supportsBoxStyles(): bool { return false; }

    public function controls(): array { return []; }

    public function render(array $data, string $children = ''): string
    {
        require_once ROOT_PATH . '/includes/contact_parts.php';
        return renderContactFormHtml();
    }
}
