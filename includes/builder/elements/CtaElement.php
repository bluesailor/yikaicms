<?php
/** 行动号召（CTA）：标题 + 副文 + 按钮，居中卡片。schema 驱动。 */

declare(strict_types=1);

final class CtaElement extends AbstractElement
{
    public function type(): string { return 'cta'; }
    public function label(): string { return __('blox_el_cta'); }
    public function icon(): string { return 'speakerphone'; }

    public function controls(): array
    {
        return [
            ['key' => 'title', 'type' => 'text', 'label' => __('blox_field_title_short'), 'default' => ''],
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_ctl_subtext'), 'default' => '', 'rows' => 2],
            ['key' => 'btn_text', 'type' => 'text', 'label' => __('blox_ctl_btn_text'), 'default' => __('nav_contact')],
            ['key' => 'btn_url', 'type' => 'text', 'label' => __('blox_ctl_btn_url'), 'default' => '', 'placeholder' => '/contact.html',
                'visible_when' => ['terms' => [['btn_text', 'not_empty']]]],
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $title = htmlspecialchars($data['title'] ?? '');
        $text = htmlspecialchars($data['text'] ?? '');
        $btnText = htmlspecialchars($data['btn_text'] ?? '');
        $btnUrl = htmlspecialchars($data['btn_url'] ?? '#');
        $html = '<div class="text-center bg-gray-50 rounded-xl py-10 px-6 my-4"' . $this->animationAttrs($data) . '>';
        if ($title !== '') {
            $html .= '<h3 class="text-2xl font-bold mb-2">' . $title . '</h3>';
        }
        if ($text !== '') {
            $html .= '<p class="text-gray-500 mb-5">' . $text . '</p>';
        }
        if ($btnText !== '') {
            $html .= '<a class="inline-block bg-primary hover:bg-secondary text-white px-8 py-3 rounded-lg transition no-underline" style="color:#fff;text-decoration:none" href="' . $btnUrl . '">' . $btnText . '</a>';
        }
        return $html . '</div>';
    }
}
