<?php
/**
 * 表单设计：常用表单预设（联系 / 预约 / 报名 / 合作加盟）。
 *
 * 预设只在编辑弹窗里「填入」名称、调用标识、成功提示和模板文本，保存前仍可修改；
 * 不自动建表单，不影响已有表单。文案取传入语言，默认语言视图生成源语言版本，
 * 翻译视图生成该语言的译文。
 */

declare(strict_types=1);

/**
 * 字段描述：[类型, 字段名, 必填, 标签键, 占位/选项键列表, 半宽]
 *
 * @return array<string, array{icon:string, slug:string, name:string, success:string, submit:string, fields:list<array{0:string,1:string,2:bool,3:string,4:list<string>,5:bool}>}>
 */
function formDesignPresetBlueprints(): array
{
    return [
        'contact' => [
            'icon' => 'mail', 'slug' => 'contact-us', 'name' => 'fd_preset_contact', 'success' => 'fd_default_success_msg', 'submit' => 'form_submit',
            'fields' => [
                ['text', 'name', true, 'fd_field_name', ['fd_ph_name'], true],
                ['tel', 'phone', false, 'fd_field_phone', ['fd_ph_phone'], true],
                ['email', 'email', true, 'fd_field_email', ['fd_ph_email'], true],
                ['text', 'company', false, 'fd_field_company', ['fd_ph_company'], true],
                ['textarea', 'content', true, 'fd_field_message', ['fd_ph_message'], false],
            ],
        ],
        'appointment' => [
            'icon' => 'calendar-event', 'slug' => 'appointment', 'name' => 'fd_preset_appointment', 'success' => 'fd_pre_appointment_success', 'submit' => 'fd_pre_appointment_submit',
            'fields' => [
                ['text', 'name', true, 'fd_field_name', ['fd_ph_name'], true],
                ['tel', 'phone', true, 'fd_pre_mobile', ['fd_ph_phone'], true],
                ['select', 'service', true, 'fd_pre_service', ['fd_pre_choose', 'fd_pre_service_consult', 'fd_pre_service_visit', 'fd_pre_service_store', 'fd_pre_service_after'], true],
                ['number', 'people', false, 'fd_pre_people', ['fd_pre_people_ph'], true],
                ['date', 'appointment_date', true, 'fd_pre_date', [], true],
                ['select', 'time_slot', true, 'fd_pre_time_slot', ['fd_pre_choose', 'fd_pre_slot_morning', 'fd_pre_slot_afternoon', 'fd_pre_slot_evening'], true],
                ['radio', 'contact_method', false, 'fd_pre_contact_method', ['fd_pre_contact_phone', 'fd_pre_contact_wechat', 'fd_pre_contact_email'], false],
                ['textarea', 'content', false, 'fd_pre_remark', ['fd_pre_remark_ph'], false],
            ],
        ],
        'event' => [
            'icon' => 'ticket', 'slug' => 'event-signup', 'name' => 'fd_preset_event', 'success' => 'fd_pre_event_success', 'submit' => 'fd_pre_event_submit',
            'fields' => [
                ['text', 'name', true, 'fd_field_name', ['fd_ph_name'], true],
                ['tel', 'phone', true, 'fd_pre_mobile', ['fd_ph_phone'], true],
                ['email', 'email', false, 'fd_field_email', ['fd_ph_email'], true],
                ['text', 'company', false, 'fd_field_company', ['fd_ph_company'], true],
                ['text', 'job_title', false, 'fd_pre_job_title', ['fd_pre_job_title_ph'], true],
                ['select', 'attendees', true, 'fd_pre_attendees', ['fd_pre_choose', 'fd_pre_attendees_1', 'fd_pre_attendees_2', 'fd_pre_attendees_3'], true],
                ['radio', 'attend_mode', true, 'fd_pre_attend_mode', ['fd_pre_attend_offline', 'fd_pre_attend_online'], false],
                ['checkbox', 'source', false, 'fd_pre_source', ['fd_pre_source_site', 'fd_pre_source_social', 'fd_pre_source_friend', 'fd_pre_source_other'], false],
                ['textarea', 'content', false, 'fd_pre_remark', ['fd_pre_event_remark_ph'], false],
            ],
        ],
        'partner' => [
            'icon' => 'heart-handshake', 'slug' => 'partnership', 'name' => 'fd_preset_partner', 'success' => 'fd_pre_partner_success', 'submit' => 'fd_pre_partner_submit',
            'fields' => [
                ['text', 'name', true, 'fd_field_name', ['fd_ph_name'], true],
                ['tel', 'phone', true, 'fd_pre_mobile', ['fd_ph_phone'], true],
                ['email', 'email', true, 'fd_field_email', ['fd_ph_email'], true],
                ['text', 'company', true, 'fd_field_company', ['fd_ph_company'], true],
                ['text', 'city', true, 'fd_pre_city', ['fd_pre_city_ph'], true],
                ['select', 'partner_type', true, 'fd_pre_partner_type', ['fd_pre_choose', 'fd_pre_partner_dealer', 'fd_pre_partner_franchise', 'fd_pre_partner_project', 'fd_pre_partner_other'], true],
                ['select', 'budget', false, 'fd_pre_budget', ['fd_pre_choose', 'fd_pre_budget_small', 'fd_pre_budget_medium', 'fd_pre_budget_large'], false],
                ['textarea', 'content', true, 'fd_pre_partner_message', ['fd_pre_partner_message_ph'], false],
            ],
        ],
    ];
}

/** 标签参数里的引号会截断语法，替换为全角/弯引号。 */
function formDesignPresetQuote(string $text): string
{
    return '"' . str_replace('"', '”', $text) . '"';
}

/**
 * 按当前语言生成预设（调用方用 withLanguageStrings 切换语言）。
 *
 * @return list<array{key:string, icon:string, slug:string, name:string, success_message:string, template_text:string}>
 */
function formDesignPresets(): array
{
    $presets = [];
    foreach (formDesignPresetBlueprints() as $key => $blueprint) {
        $blocks = [];
        $half = [];
        $halfRow = static fn(array $cells): string
            => '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">' . "\n" . implode("\n", $cells) . "\n</div>";
        foreach ($blueprint['fields'] as [$type, $name, $required, $labelKey, $argKeys, $isHalf]) {
            $args = array_map(static fn(string $argKey): string => formDesignPresetQuote(__($argKey)), $argKeys);
            $tag = '[' . $type . ($required ? '*' : '') . ' ' . $name . ($args === [] ? '' : ' ' . implode(' ', $args)) . ']';
            $label = '<label>' . e(__($labelKey)) . ($required && $type !== 'checkbox' ? ' <span class="text-red-500">*</span>' : '') . '</label>';
            $cell = "<div>\n    " . $label . "\n    " . $tag . "\n</div>";
            if ($isHalf) {
                $half[] = $cell;
                continue;
            }
            if ($half !== []) {
                $blocks[] = $halfRow($half);
                $half = [];
            }
            $blocks[] = '<div class="mt-4">' . "\n    " . $label . "\n    " . $tag . "\n</div>";
        }
        if ($half !== []) {
            $blocks[] = $halfRow($half);
        }
        $blocks[] = '<div class="mt-4">' . "\n    [submit " . formDesignPresetQuote(__($blueprint['submit'])) . "]\n</div>";

        $presets[] = [
            'key' => $key,
            'icon' => $blueprint['icon'],
            'slug' => $blueprint['slug'],
            'name' => __($blueprint['name']),
            'success_message' => __($blueprint['success']),
            'template_text' => implode("\n\n", $blocks),
        ];
    }
    return $presets;
}
