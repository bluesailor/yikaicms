<?php
/** @var array $lumiSignHome */
if (!function_exists('lumiHomeAdminEsc')) {
    function lumiHomeAdminEsc(mixed $value): string { return e((string) $value); }
    function lumiHomeAdminInput(string $name, string $label, mixed $value, string $type = 'text', string $help = ''): void {
        echo '<label class="block">';
        echo '<span class="block text-xs font-medium text-gray-600 mb-1">' . lumiHomeAdminEsc($label) . '</span>';
        echo '<input type="' . lumiHomeAdminEsc($type) . '" name="' . lumiHomeAdminEsc($name) . '" value="' . lumiHomeAdminEsc($value) . '" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">';
        if ($help !== '') echo '<span class="block mt-1 text-[11px] leading-relaxed text-gray-400">' . lumiHomeAdminEsc($help) . '</span>';
        echo '</label>';
    }
    function lumiHomeAdminTextarea(string $name, string $label, mixed $value, string $help = '', int $rows = 3): void {
        echo '<label class="block md:col-span-2">';
        echo '<span class="block text-xs font-medium text-gray-600 mb-1">' . lumiHomeAdminEsc($label) . '</span>';
        echo '<textarea name="' . lumiHomeAdminEsc($name) . '" rows="' . $rows . '" class="w-full border border-gray-300 rounded px-3 py-2 text-sm">' . lumiHomeAdminEsc($value) . '</textarea>';
        if ($help !== '') echo '<span class="block mt-1 text-[11px] leading-relaxed text-gray-400">' . lumiHomeAdminEsc($help) . '</span>';
        echo '</label>';
    }
    function lumiHomeAdminEnabled(string $name, bool $checked): void {
        echo '<label class="inline-flex items-center gap-2 text-xs text-gray-600"><input type="hidden" name="' . lumiHomeAdminEsc($name) . '" value="0"><input type="checkbox" name="' . lumiHomeAdminEsc($name) . '" value="1" ' . ($checked ? 'checked' : '') . ' class="rounded border-gray-300 text-primary">表示する</label>';
    }
    function lumiHomeAdminSection(string $key, string $title, array $data): void {
        echo '<details class="border border-gray-200 rounded-lg bg-white" open>';
        echo '<summary class="flex items-center justify-between gap-3 px-4 py-3 cursor-pointer list-none"><span class="font-semibold text-gray-800">' . lumiHomeAdminEsc($title) . '</span>';
        lumiHomeAdminEnabled('home_content[' . $key . '][enabled]', !empty($data['enabled']));
        echo '</summary><div class="border-t border-gray-100 p-4 space-y-4">';
    }
    function lumiHomeAdminSectionEnd(): void { echo '</div></details>'; }
}
?>
<div class="bg-blue-50 border border-blue-200 rounded-lg px-5 py-4 text-sm text-blue-900">
    <div class="flex items-start gap-3">
        <i class="ti ti-home text-base mt-0.5 shrink-0"></i>
        <div>
            <p class="font-semibold">LumiSign 首页内容</p>
            <p class="mt-1 leading-relaxed">这里编辑的是当前 LumiSign 首页的实际内容，保存后立即作用于前台。首页结构保持现有设计，区块可单独隐藏。</p>
            <p class="mt-1 text-blue-700">Banner 只填写分组短码；图片、标题、按钮和排序请到 Banner 管理中维护。</p>
        </div>
    </div>
</div>
<input type="hidden" name="home_lumisign_save" value="1">
<div class="space-y-4">
    <details class="border border-gray-200 rounded-lg bg-white" open>
        <summary class="flex items-center justify-between gap-3 px-4 py-3 cursor-pointer list-none"><span class="font-semibold text-gray-800">01 / Banner</span></summary>
        <div class="border-t border-gray-100 p-4 space-y-4">
            <?php lumiHomeAdminInput('home_content[banner][shortcode]', 'Banner 分组短码', $lumiSignHome['banner']['shortcode'], 'text', '默认值为 home。这里只控制首页读取哪个 Banner 分组，不重复编辑轮播内容。'); ?>
        </div>
    </details>

    <?php lumiHomeAdminSection('services', '02 / 三大加工内容', $lumiSignHome['services']); ?>
    <div class="grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput('home_content[services][title]', '区块标题', $lumiSignHome['services']['title']); ?>
        <?php lumiHomeAdminInput('home_content[services][description]', '区块说明', $lumiSignHome['services']['description']); ?>
    </div>
    <?php foreach ($lumiSignHome['services']['items'] as $i => $item): ?>
    <div class="border-t border-gray-100 pt-4 grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput("home_content[services][items][$i][title]", '项目' . ($i + 1) . '标题', $item['title']); ?>
        <?php lumiHomeAdminTextarea("home_content[services][items][$i][description]", '项目' . ($i + 1) . '说明', $item['description'], '', 2); ?>
    </div>
    <?php endforeach; ?>
    <?php lumiHomeAdminSectionEnd(); ?>

    <?php lumiHomeAdminSection('entry', '03 / 咨询入口', $lumiSignHome['entry']); ?>
    <div class="grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput('home_content[entry][eyebrow]', '英文眉题', $lumiSignHome['entry']['eyebrow']); ?>
        <?php lumiHomeAdminInput('home_content[entry][title]', '标题', $lumiSignHome['entry']['title']); ?>
        <?php lumiHomeAdminTextarea('home_content[entry][description]', '说明', $lumiSignHome['entry']['description']); ?>
        <?php lumiHomeAdminInput('home_content[entry][image]', '图片路径', $lumiSignHome['entry']['image'], 'text', '填写站内路径，例如 /images/example.jpg。'); ?>
        <?php lumiHomeAdminInput('home_content[entry][image_alt]', '图片说明', $lumiSignHome['entry']['image_alt']); ?>
        <?php lumiHomeAdminInput('home_content[entry][caption]', '图片主文案', $lumiSignHome['entry']['caption']); ?>
        <?php lumiHomeAdminInput('home_content[entry][note]', '图片补充文案', $lumiSignHome['entry']['note']); ?>
    </div>
    <?php foreach ($lumiSignHome['entry']['links'] as $i => $link): ?>
    <div class="border-t border-gray-100 pt-4 grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput("home_content[entry][links][$i][label]", '入口' . ($i + 1) . '名称', $link['label']); ?>
        <?php lumiHomeAdminInput("home_content[entry][links][$i][url]", '入口' . ($i + 1) . '链接', $link['url']); ?>
    </div>
    <?php endforeach; ?>
    <?php lumiHomeAdminSectionEnd(); ?>

    <?php lumiHomeAdminSection('cases', '04 / 加工事例', $lumiSignHome['cases']); ?>
    <div class="grid md:grid-cols-3 gap-4">
        <?php lumiHomeAdminInput('home_content[cases][title]', '标题', $lumiSignHome['cases']['title']); ?>
        <?php lumiHomeAdminInput('home_content[cases][limit]', '显示数量', $lumiSignHome['cases']['limit'], 'number', '从 CMS 加工事例中读取最新内容。'); ?>
        <?php lumiHomeAdminTextarea('home_content[cases][description]', '说明', $lumiSignHome['cases']['description']); ?>
    </div>
    <?php lumiHomeAdminSectionEnd(); ?>

    <?php lumiHomeAdminSection('materials', '05 / 对应素材', $lumiSignHome['materials']); ?>
    <?php lumiHomeAdminInput('home_content[materials][title]', '标题', $lumiSignHome['materials']['title']); ?>
    <?php foreach ($lumiSignHome['materials']['items'] as $i => $item): ?>
    <div class="border-t border-gray-100 pt-4 grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput("home_content[materials][items][$i][name]", '素材' . ($i + 1) . '名称', $item['name']); ?>
        <?php lumiHomeAdminInput("home_content[materials][items][$i][process]", '素材' . ($i + 1) . '加工内容', $item['process']); ?>
    </div>
    <?php endforeach; ?>
    <?php lumiHomeAdminSectionEnd(); ?>

    <?php lumiHomeAdminSection('reasons', '06 / 选择理由', $lumiSignHome['reasons']); ?>
    <?php lumiHomeAdminInput('home_content[reasons][title]', '标题', $lumiSignHome['reasons']['title']); ?>
    <?php foreach ($lumiSignHome['reasons']['items'] as $i => $item): ?>
    <?php lumiHomeAdminInput("home_content[reasons][items][$i]", '理由' . ($i + 1), $item); ?>
    <?php endforeach; ?>
    <?php lumiHomeAdminSectionEnd(); ?>

    <?php lumiHomeAdminSection('pricing', '07 / 参考价格', $lumiSignHome['pricing']); ?>
    <div class="grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput('home_content[pricing][title]', '标题', $lumiSignHome['pricing']['title']); ?>
        <?php lumiHomeAdminTextarea('home_content[pricing][description]', '说明', $lumiSignHome['pricing']['description']); ?>
    </div>
    <?php foreach ($lumiSignHome['pricing']['items'] as $i => $item): ?>
    <div class="border-t border-gray-100 pt-4 grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput("home_content[pricing][items][$i][name]", '价格项目' . ($i + 1), $item['name']); ?>
        <?php lumiHomeAdminInput("home_content[pricing][items][$i][price]", '价格' . ($i + 1), $item['price']); ?>
    </div>
    <?php endforeach; ?>
    <div class="grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput('home_content[pricing][link_label]', '详细页链接文字', $lumiSignHome['pricing']['link_label']); ?>
        <?php lumiHomeAdminInput('home_content[pricing][link_url]', '详细页链接', $lumiSignHome['pricing']['link_url']); ?>
    </div>
    <?php lumiHomeAdminSectionEnd(); ?>

    <?php lumiHomeAdminSection('flow', '08 / 委托加工流程', $lumiSignHome['flow']); ?>
    <?php lumiHomeAdminInput('home_content[flow][title]', '标题', $lumiSignHome['flow']['title']); ?>
    <?php foreach ($lumiSignHome['flow']['steps'] as $i => $step): ?>
    <?php lumiHomeAdminInput("home_content[flow][steps][$i]", '步骤' . ($i + 1), $step); ?>
    <?php endforeach; ?>
    <?php lumiHomeAdminSectionEnd(); ?>

    <?php lumiHomeAdminSection('machines', '09 / レーザー加工機', $lumiSignHome['machines']); ?>
    <?php lumiHomeAdminInput('home_content[machines][title]', '标题', $lumiSignHome['machines']['title']); ?>
    <?php lumiHomeAdminTextarea('home_content[machines][description]', '说明', $lumiSignHome['machines']['description']); ?>
    <?php foreach ($lumiSignHome['machines']['items'] as $i => $item): ?>
    <div class="border-t border-gray-100 pt-4 grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput("home_content[machines][items][$i][title]", '机器' . ($i + 1) . '名称', $item['title']); ?>
        <?php lumiHomeAdminTextarea("home_content[machines][items][$i][description]", '机器' . ($i + 1) . '说明', $item['description'], '', 2); ?>
    </div>
    <?php endforeach; ?>
    <div class="grid md:grid-cols-2 gap-4">
        <?php foreach ($lumiSignHome['machines']['features'] as $i => $feature): ?>
            <?php lumiHomeAdminInput("home_content[machines][features][$i]", '设备特点' . ($i + 1), $feature); ?>
        <?php endforeach; ?>
        <?php lumiHomeAdminInput('home_content[machines][list_label]', '设备页按钮', $lumiSignHome['machines']['list_label']); ?>
        <?php lumiHomeAdminInput('home_content[machines][list_url]', '设备页链接', $lumiSignHome['machines']['list_url']); ?>
        <?php lumiHomeAdminInput('home_content[machines][contact_label]', '咨询按钮', $lumiSignHome['machines']['contact_label']); ?>
        <?php lumiHomeAdminInput('home_content[machines][contact_url]', '咨询链接', $lumiSignHome['machines']['contact_url']); ?>
    </div>
    <?php lumiHomeAdminSectionEnd(); ?>

    <?php lumiHomeAdminSection('faq', '10 / FAQ', $lumiSignHome['faq']); ?>
    <?php lumiHomeAdminInput('home_content[faq][title]', '标题', $lumiSignHome['faq']['title']); ?>
    <?php foreach ($lumiSignHome['faq']['items'] as $i => $item): ?>
    <div class="border-t border-gray-100 pt-4 grid gap-3">
        <?php lumiHomeAdminInput("home_content[faq][items][$i][question]", '问题' . ($i + 1), $item['question']); ?>
        <?php lumiHomeAdminTextarea("home_content[faq][items][$i][answer]", '回答' . ($i + 1), $item['answer'], '', 2); ?>
    </div>
    <?php endforeach; ?>
    <div class="grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput('home_content[faq][link_label]', 'FAQ 链接文字', $lumiSignHome['faq']['link_label']); ?>
        <?php lumiHomeAdminInput('home_content[faq][link_url]', 'FAQ 链接', $lumiSignHome['faq']['link_url']); ?>
    </div>
    <?php lumiHomeAdminSectionEnd(); ?>

    <?php lumiHomeAdminSection('cta', '11 / 最终 CTA', $lumiSignHome['cta']); ?>
    <div class="grid md:grid-cols-2 gap-4">
        <?php lumiHomeAdminInput('home_content[cta][title]', '标题', $lumiSignHome['cta']['title']); ?>
        <?php lumiHomeAdminTextarea('home_content[cta][description]', '说明', $lumiSignHome['cta']['description']); ?>
        <?php lumiHomeAdminInput('home_content[cta][estimate_label]', '加工估价按钮', $lumiSignHome['cta']['estimate_label']); ?>
        <?php lumiHomeAdminInput('home_content[cta][estimate_url]', '加工估价链接', $lumiSignHome['cta']['estimate_url']); ?>
        <?php lumiHomeAdminInput('home_content[cta][machine_label]', '设备咨询按钮', $lumiSignHome['cta']['machine_label']); ?>
        <?php lumiHomeAdminInput('home_content[cta][machine_url]', '设备咨询链接', $lumiSignHome['cta']['machine_url']); ?>
    </div>
    <?php lumiHomeAdminSectionEnd(); ?>
</div>