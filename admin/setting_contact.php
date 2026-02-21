<?php
/**
 * Yikai CMS - 联系设置
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = $_POST['settings'] ?? [];
    settingModel()->saveBatch($settings);

    adminLog('setting', 'update', '更新联系设置');
    success();
}

// 获取 contact 分组设置
$allSettings = settingModel()->getByGroup('contact');

// 图标SVG路径映射（用于后台预览）
$iconSvgs = [
    'phone'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>',
    'email'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>',
    'location' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>',
    'clock'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
    'fax'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>',
    'wechat'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>',
    'building' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>',
    'globe'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>',
    'qq'       => '<g transform="scale(0.0234375)"><path fill="currentColor" stroke="none" d="M512.4096 77.7728c-232.0896 0-420.864 188.8256-420.864 420.864 0 232.0896 188.8256 420.864 420.864 420.864 232.0896 0 420.864-188.8256 420.864-420.864s-188.7744-420.864-420.864-420.864z m0 800.8192c-209.5104 0-379.904-170.4448-379.904-379.904 0-209.5104 170.4448-379.904 379.904-379.904 209.5104 0 379.904 170.4448 379.904 379.904 0 209.4592-170.3936 379.904-379.904 379.904z"></path><path fill="currentColor" stroke="none" d="M683.9296 485.376c-1.8944-2.048-3.1232-5.4272-3.2768-8.2432-0.4096-8.448 0.2048-16.9472-0.256-25.3952-0.5632-10.1376-2.7136-20.6336-10.0352-27.5456-6.144-5.7856-6.8608-11.9296-7.168-19.0464-0.1024-2.56-0.3584-5.0688-0.6144-7.6288-3.9424-34.3552-13.0048-66.9184-36.4032-93.44-36.4544-41.3184-83.1488-57.1392-136.9088-49.2032-47.0528 6.912-84.1216 30.464-107.1616 73.4208-13.5168 25.1904-19.0464 52.5312-20.736 80.7424-0.3072 5.4272-0.8192 10.0352-5.888 13.6704-2.7136 1.9456-4.352 5.6832-5.8368 8.9088-6.5024 13.9264-6.656 28.7744-5.376 43.6736 0.3584 4.5056-0.8192 7.8336-4.0448 11.3152-24.6784 26.4704-42.1888 56.9344-48.384 92.9792-2.5088 14.5408-1.2288 29.2864 4.608 43.1104 3.3792 7.9872 9.216 9.9328 15.616 4.3008 7.2704-6.3488 13.4144-13.9776 19.7632-21.3504 2.9696-3.4816 5.1712-7.5776 7.3216-10.8032 11.5712 21.0944 23.0912 42.0352 34.2528 62.3104-7.2704 5.376-16.2304 10.5472-23.296 17.5616-15.36 15.1552-20.8384 45.6704 10.8544 60.1088 2.8672 1.3312 5.8368 2.56 8.9088 3.4304 29.2864 8.3968 58.5728 7.9872 87.9616 0.2048 15.5136-4.096 30.5152-9.0624 42.1888-20.8896 5.8368-5.9392 18.688-5.3248 25.1904-0.1024 6.5024 5.2736 13.4144 10.6496 21.0432 13.8752 22.3232 9.5232 45.824 13.9776 70.144 12.9536 16.7936-0.7168 33.4848-2.4064 48.896-10.0352 15.2064-7.5264 22.6816-19.2 21.5552-33.5872-1.024-13.3632-7.5264-23.808-17.9712-31.6416-5.888-4.4032-12.3904-7.936-17.6128-11.264 11.4688-20.8896 22.9888-41.8304 34.56-62.8736 2.1504 3.2256 4.4032 7.3216 7.3216 10.8544 5.7856 6.9632 11.4176 14.336 18.176 20.2752 7.9872 7.0144 14.1312 4.9664 17.9712-5.0688 4.9152-12.9536 6.2976-26.7776 4.0448-40.1408-6.3488-36.9664-24.3712-68.096-49.408-95.4368z"></path></g>',
];

// 分成两个区块：信息展示（sort_order < 10）和留言表单（sort_order >= 10）
$infoSettings = [];
$formSettings = [];
foreach ($allSettings as $item) {
    if ((int)$item['sort_order'] >= 10) {
        $formSettings[] = $item;
    } else {
        $infoSettings[] = $item;
    }
}

$tab = $_GET['tab'] ?? 'info';
$pageTitle = '联系设置';
$currentMenu = 'setting_contact';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/setting_contact.php" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'info' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">联系信息</a>
        <a href="/admin/setting_contact.php?tab=form" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'form' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">表单设置</a>
    </div>
</div>

<form id="settingForm" class="space-y-6">
    <?php if ($tab === 'info'): ?>
    <!-- 联系信息 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">联系信息</h2>
        </div>
        <div class="p-6 space-y-4">
            <?php foreach ($infoSettings as $item): ?>

            <?php if ($item['type'] === 'contact_cards'): ?>
            <!-- 联系信息卡片编辑器 -->
            <?php $cardsData = json_decode($item['value'], true) ?: []; ?>
            <div>
                <label class="text-gray-700 font-medium block mb-1">
                    <?php echo e($item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm font-normal ml-2"><?php echo e($item['tip']); ?></span>
                    <?php endif; ?>
                </label>
                <input type="hidden" name="settings[contact_cards]" id="contactCardsJson">
                <div id="contactCardsEditor" class="space-y-3 mt-2">
                    <?php for ($ci = 0; $ci < 4; $ci++): ?>
                    <?php $card = $cardsData[$ci] ?? null; ?>
                    <div class="card-row p-3 border rounded-lg <?php echo $card ? 'bg-white' : 'bg-gray-50'; ?>" data-index="<?php echo $ci; ?>">
                        <div class="flex gap-3 items-start">
                            <span class="text-gray-300 text-sm pt-2 w-5 flex-shrink-0"><?php echo $ci + 1; ?></span>
                            <div class="flex-shrink-0">
                                <label class="text-xs text-gray-400 block mb-1">图标</label>
                                <div class="flex items-center gap-2">
                                    <select class="card-icon border rounded px-2 py-1.5 text-sm w-24" onchange="updateIconPreview(this)">
                                        <option value="">无</option>
                                        <option value="phone" <?php echo ($card['icon'] ?? '') === 'phone' ? 'selected' : ''; ?>>电话</option>
                                        <option value="email" <?php echo ($card['icon'] ?? '') === 'email' ? 'selected' : ''; ?>>邮箱</option>
                                        <option value="location" <?php echo ($card['icon'] ?? '') === 'location' ? 'selected' : ''; ?>>地址</option>
                                        <option value="clock" <?php echo ($card['icon'] ?? '') === 'clock' ? 'selected' : ''; ?>>时间</option>
                                        <option value="fax" <?php echo ($card['icon'] ?? '') === 'fax' ? 'selected' : ''; ?>>传真</option>
                                        <option value="wechat" <?php echo ($card['icon'] ?? '') === 'wechat' ? 'selected' : ''; ?>>微信</option>
                                        <option value="building" <?php echo ($card['icon'] ?? '') === 'building' ? 'selected' : ''; ?>>公司</option>
                                        <option value="globe" <?php echo ($card['icon'] ?? '') === 'globe' ? 'selected' : ''; ?>>网站</option>
                                        <option value="qq" <?php echo ($card['icon'] ?? '') === 'qq' ? 'selected' : ''; ?>>QQ</option>
                                    </select>
                                    <span class="icon-preview w-8 h-8 flex items-center justify-center text-primary"><?php
                                        $curIcon = $card['icon'] ?? '';
                                        if ($curIcon) echo '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">' . ($iconSvgs[$curIcon] ?? '') . '</svg>';
                                    ?></span>
                                </div>
                            </div>
                            <div class="flex-1">
                                <label class="text-xs text-gray-400 block mb-1">标题</label>
                                <input type="text" class="card-label w-full border rounded px-3 py-1.5 text-sm" placeholder="如：联系电话" value="<?php echo e($card['label'] ?? ''); ?>">
                            </div>
                            <div class="flex-1">
                                <label class="text-xs text-gray-400 block mb-1">内容</label>
                                <div class="flex gap-1">
                                    <textarea class="card-value w-full border rounded px-3 py-1.5 text-sm" rows="2" placeholder="文字或图片地址"><?php echo e($card['value'] ?? ''); ?></textarea>
                                    <button type="button" class="card-upload text-gray-400 hover:text-primary flex-shrink-0 pt-1" title="上传图片">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    </button>
                                </div>
                            </div>
                            <button type="button" class="card-clear text-gray-300 hover:text-red-400 pt-5 flex-shrink-0" title="清空此行">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                        <?php
                        $cardVal = $card['value'] ?? '';
                        $isImg = $cardVal && preg_match('/\.(jpg|jpeg|png|gif|webp|svg)(\?|$)/i', $cardVal);
                        ?>
                        <?php if ($isImg): ?>
                        <div class="card-img-preview mt-2 ml-8">
                            <img src="<?php echo e($cardVal); ?>" class="h-12 rounded">
                        </div>
                        <?php else: ?>
                        <div class="card-img-preview mt-2 ml-8 hidden"></div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <?php else: ?>
            <!-- 普通设置项 -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e($item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm block"><?php echo e($item['tip']); ?></span>
                    <?php endif; ?>
                </label>
                <div class="md:col-span-3">
                    <?php if ($item['type'] === 'textarea'): ?>
                    <textarea name="settings[<?php echo e($item['key']); ?>]" rows="3"
                              class="w-full border rounded px-4 py-2"><?php echo e($item['value']); ?></textarea>
                    <?php elseif ($item['type'] === 'image'): ?>
                    <div class="flex gap-2 items-center">
                        <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                               value="<?php echo e($item['value']); ?>"
                               id="input_<?php echo e($item['key']); ?>"
                               class="flex-1 border rounded px-4 py-2">
                        <button type="button" onclick="uploadImage('<?php echo e($item['key']); ?>')"
                                class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                            上传
                        </button>
                        <button type="button" onclick="pickFromMedia('<?php echo e($item['key']); ?>')"
                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            媒体库
                        </button>
                    </div>
                    <?php if ($item['value']): ?>
                    <img src="<?php echo e($item['value']); ?>" class="h-16 mt-2 rounded" id="preview_<?php echo e($item['key']); ?>">
                    <?php endif; ?>
                    <?php else: ?>
                    <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                           value="<?php echo e($item['value']); ?>"
                           class="w-full border rounded px-4 py-2">
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($tab === 'form'): ?>
    <!-- 表单短码提示 -->
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <svg class="w-5 h-5 text-blue-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <div class="text-sm text-blue-800">
                联系我们页面当前调用的表单短码：<code class="bg-blue-100 px-1.5 py-0.5 rounded font-mono">[form-contact]</code>
                <span class="text-blue-500 ml-1">— 可在「表单设计」中编辑模板和字段</span>
            </div>
        </div>
        <a href="/admin/form_design.php" class="text-sm text-blue-600 hover:text-blue-800 hover:underline flex-shrink-0">前往表单设计 &rarr;</a>
    </div>

    <!-- 表单设置 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">表单设置</h2>
        </div>
        <div class="p-6 space-y-4">
            <?php foreach ($formSettings as $item): ?>

            <?php if ($item['type'] === 'contact_form_fields'): ?>
            <!-- 表单字段编辑器 -->
            <?php $fieldsData = json_decode($item['value'], true) ?: []; ?>
            <?php
            $defaultFieldDefs = [
                ['key'=>'name','label'=>'您的姓名','type'=>'text'],
                ['key'=>'phone','label'=>'联系电话','type'=>'tel'],
                ['key'=>'email','label'=>'电子邮箱','type'=>'email'],
                ['key'=>'company','label'=>'公司名称','type'=>'text'],
                ['key'=>'content','label'=>'留言内容','type'=>'textarea'],
            ];
            $fieldMap = [];
            foreach ($fieldsData as $fd) { $fieldMap[$fd['key']] = $fd; }
            $fieldRows = [];
            foreach ($defaultFieldDefs as $def) {
                $saved = $fieldMap[$def['key']] ?? null;
                $fieldRows[] = [
                    'key' => $def['key'],
                    'label' => $saved['label'] ?? $def['label'],
                    'type' => $def['type'],
                    'required' => $saved ? ($saved['required'] ?? false) : in_array($def['key'], ['name','phone','content']),
                    'enabled' => $saved ? ($saved['enabled'] ?? true) : true,
                ];
            }
            ?>
            <div>
                <label class="text-gray-700 font-medium block mb-1">
                    <?php echo e($item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm font-normal ml-2"><?php echo e($item['tip']); ?></span>
                    <?php endif; ?>
                </label>
                <input type="hidden" name="settings[contact_form_fields]" id="contactFormFieldsJson">
                <div id="contactFormFieldsEditor" class="mt-2">
                    <div class="grid grid-cols-12 gap-2 text-xs text-gray-400 px-3 mb-1">
                        <div class="col-span-2">字段</div>
                        <div class="col-span-4">标签文字</div>
                        <div class="col-span-2">类型</div>
                        <div class="col-span-2 text-center">必填</div>
                        <div class="col-span-2 text-center">启用</div>
                    </div>
                    <?php foreach ($fieldRows as $fr): ?>
                    <div class="field-row grid grid-cols-12 gap-2 items-center p-3 border rounded-lg mb-2" data-key="<?php echo $fr['key']; ?>" data-type="<?php echo $fr['type']; ?>">
                        <div class="col-span-2 text-sm text-gray-500 font-mono"><?php echo $fr['key']; ?></div>
                        <div class="col-span-4">
                            <input type="text" class="field-label w-full border rounded px-3 py-1.5 text-sm" value="<?php echo e($fr['label']); ?>">
                        </div>
                        <div class="col-span-2 text-sm text-gray-400"><?php echo $fr['type']; ?></div>
                        <div class="col-span-2 text-center">
                            <input type="checkbox" class="field-required w-4 h-4" <?php echo $fr['required'] ? 'checked' : ''; ?>>
                        </div>
                        <div class="col-span-2 text-center">
                            <input type="checkbox" class="field-enabled w-4 h-4" <?php echo $fr['enabled'] ? 'checked' : ''; ?>>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php elseif ($item['type'] === 'textarea'): ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e($item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm block"><?php echo e($item['tip']); ?></span>
                    <?php endif; ?>
                </label>
                <div class="md:col-span-3">
                    <textarea name="settings[<?php echo e($item['key']); ?>]" rows="3"
                              class="w-full border rounded px-4 py-2"><?php echo e($item['value']); ?></textarea>
                </div>
            </div>

            <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e($item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm block"><?php echo e($item['tip']); ?></span>
                    <?php endif; ?>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                           value="<?php echo e($item['value']); ?>"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            保存设置
        </button>
    </div>
</form>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script>
let currentImageKey = '';

function uploadImage(key) {
    currentImageKey = key;
    document.getElementById('imageFileInput').click();
}

document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');

    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            document.getElementById('input_' + currentImageKey).value = data.data.url;
            let preview = document.getElementById('preview_' + currentImageKey);
            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'preview_' + currentImageKey;
                preview.className = 'h-16 mt-2 rounded';
                document.getElementById('input_' + currentImageKey).parentNode.appendChild(preview);
            }
            preview.src = data.data.url;
            showMessage('上传成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('上传失败', 'error');
    }

    this.value = '';
});

function pickFromMedia(key) {
    openMediaPicker(function(url) {
        document.getElementById('input_' + key).value = url;
        var preview = document.getElementById('preview_' + key);
        if (!preview) {
            preview = document.createElement('img');
            preview.id = 'preview_' + key;
            preview.className = 'h-16 mt-2 rounded';
            document.getElementById('input_' + key).parentNode.appendChild(preview);
        }
        preview.src = url;
    });
}

// 图标SVG映射
var iconSvgMap = <?php echo json_encode($iconSvgs, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

// 图标预览
function updateIconPreview(select) {
    var preview = select.parentNode.querySelector('.icon-preview');
    var val = select.value;
    if (val && iconSvgMap[val]) {
        preview.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">' + iconSvgMap[val] + '</svg>';
    } else {
        preview.innerHTML = '';
    }
}

// 卡片图片上传
var cardUploadInput = document.createElement('input');
cardUploadInput.type = 'file';
cardUploadInput.accept = 'image/*';
cardUploadInput.className = 'hidden';
document.body.appendChild(cardUploadInput);
var cardUploadTarget = null;

document.querySelectorAll('.card-upload').forEach(function(btn) {
    btn.addEventListener('click', function() {
        cardUploadTarget = this.closest('.card-row');
        cardUploadInput.click();
    });
});

cardUploadInput.addEventListener('change', async function() {
    if (!this.files[0] || !cardUploadTarget) return;
    var formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');
    try {
        var response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        var data = await safeJson(response);
        if (data.code === 0) {
            var textarea = cardUploadTarget.querySelector('.card-value');
            textarea.value = data.data.url;
            updateCardImgPreview(cardUploadTarget, data.data.url);
            showMessage('上传成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('上传失败', 'error');
    }
    this.value = '';
});

// 卡片图片预览更新
function updateCardImgPreview(row, url) {
    var preview = row.querySelector('.card-img-preview');
    if (url && /\.(jpg|jpeg|png|gif|webp|svg)(\?|$)/i.test(url)) {
        preview.innerHTML = '';
        const previewImg = document.createElement('img');
        previewImg.src = url;
        previewImg.className = 'h-12 rounded';
        preview.appendChild(previewImg);
        preview.classList.remove('hidden');
    } else {
        preview.innerHTML = '';
        preview.classList.add('hidden');
    }
}

// 联系卡片编辑器 - 收集JSON
function collectContactCards() {
    var editor = document.getElementById('contactCardsEditor');
    if (!editor) return;
    var rows = editor.querySelectorAll('.card-row');
    var cards = [];
    rows.forEach(function(row) {
        var icon = row.querySelector('.card-icon').value;
        var label = row.querySelector('.card-label').value.trim();
        var value = row.querySelector('.card-value').value.trim();
        if (label && value) {
            cards.push({ icon: icon, label: label, value: value });
        }
    });
    document.getElementById('contactCardsJson').value = JSON.stringify(cards);
}

// 清空按钮
document.querySelectorAll('.card-clear').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var row = this.closest('.card-row');
        row.querySelector('.card-icon').value = '';
        row.querySelector('.card-label').value = '';
        row.querySelector('.card-value').value = '';
        // 清空图标预览
        row.querySelector('.icon-preview').innerHTML = '';
        // 清空图片预览
        updateCardImgPreview(row, '');
    });
});

// 表单字段编辑器 - 收集JSON
function collectFormFields() {
    var editor = document.getElementById('contactFormFieldsEditor');
    if (!editor) return;
    var rows = editor.querySelectorAll('.field-row');
    var fields = [];
    rows.forEach(function(row) {
        fields.push({
            key: row.dataset.key,
            label: row.querySelector('.field-label').value.trim(),
            type: row.dataset.type,
            required: row.querySelector('.field-required').checked,
            enabled: row.querySelector('.field-enabled').checked
        });
    });
    document.getElementById('contactFormFieldsJson').value = JSON.stringify(fields);
}

document.getElementById('settingForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    collectContactCards();
    collectFormFields();

    const formData = new FormData(this);

    try {
        const response = await fetch(location.href, { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            showMessage('保存成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
