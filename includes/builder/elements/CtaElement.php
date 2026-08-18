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
            // 跟随首页：文案/按钮取 home_cta_* 设置（多语言后缀 aware），首页设置一处修改全站同步。
            ['key' => 'use_home_text', 'type' => 'checkbox', 'label' => __('blox_cta_follow_home'), 'default' => false,
                'help' => __('blox_cta_follow_home_help')],
            ['key' => 'title', 'type' => 'text', 'label' => __('blox_field_title_short'), 'default' => ''],
            ['key' => 'text', 'type' => 'textarea', 'label' => __('blox_ctl_subtext'), 'default' => '', 'rows' => 2],
            ['key' => 'btn_text', 'type' => 'text', 'label' => __('blox_ctl_btn_text'), 'default' => __('nav_contact')],
            ['key' => 'btn_url', 'type' => 'text', 'label' => __('blox_ctl_btn_url'), 'default' => '', 'placeholder' => '/contact.html',
                'visible_when' => ['terms' => [['btn_text', 'not_empty']]]],
            // 背景图：设了即渲染首页同款横幅观感（深色遮罩+白字）；留空保持灰底卡片。
            ['key' => 'bg_image', 'type' => 'image', 'label' => __('blox_cta_bg_image'), 'default' => '',
                'help' => __('blox_cta_bg_image_help')],
            ...$this->animationControls(),
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $followHome = !empty($data['use_home_text']);
        $rawTitle = $followHome ? configLang('home_cta_title', 'home_cta_title') : (string) ($data['title'] ?? '');
        $rawText = $followHome ? configLang('home_cta_desc', 'home_cta_desc') : (string) ($data['text'] ?? '');
        $rawBtnText = $followHome
            ? ((string) config('home_cta_button', '') ?: __('detail_consult'))
            : (string) ($data['btn_text'] ?? '');
        $rawBtnUrl = $followHome
            ? ((string) config('home_cta_link', '') ?: '/contact.html')
            : (string) ($data['btn_url'] ?? '#');

        $title = htmlspecialchars($rawTitle);
        $text = htmlspecialchars($rawText);
        $btnText = htmlspecialchars($rawBtnText);
        $btnUrl = htmlspecialchars($rawBtnUrl);

        $bgImage = self::cssImageUrl($data['bg_image'] ?? null);
        if ($bgImage !== null && $bgImage !== '') {
            // 背景横幅形态：与首页 CTA 版块同一观感（深色遮罩、白字、圆角胶囊按钮）。
            $html = '<div class="relative rounded-xl overflow-hidden my-4 bg-cover bg-center" style="background-image:'
                . self::cssUrlLiteral($bgImage) . '"' . $this->animationAttrs($data) . '>'
                . '<div class="absolute inset-0 bg-black/60"></div>'
                . '<div class="relative text-center py-16 px-6">';
            if ($title !== '') {
                $html .= '<h3 class="text-3xl font-bold text-white mb-2">' . $title . '</h3>';
            }
            if ($text !== '') {
                $html .= '<p class="text-gray-200 text-lg mb-6">' . $text . '</p>';
            }
            if ($btnText !== '') {
                $html .= '<a class="inline-block bg-white text-primary hover:bg-gray-100 px-8 py-3 rounded-full font-bold shadow-lg transition no-underline" style="text-decoration:none" href="'
                    . $btnUrl . '">' . $btnText . '</a>';
            }
            return $html . '</div></div>';
        }

        // 无背景：维持既有灰底卡片输出（存量文档逐字节不变）。
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
