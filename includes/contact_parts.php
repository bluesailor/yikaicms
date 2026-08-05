<?php
/**
 * 联系页三大组成部分的渲染函数（联系卡片 / 留言表单 / 地图）。
 *
 * 抽出来的目的：让这三块既能被固定版式的 contact.php 用，也能作为排版元素
 * （contact_cards / contact_form / contact_map）被拖进任意位置。
 * 标记与原模板逐字节一致——搬运时按行切片，未改动任何 HTML。
 */

declare(strict_types=1);

/** 内置图标 SVG 路径表。 */
function contactIconPaths(): array
{
$iconPaths = [
    'phone'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>',
    'email'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>',
    'location' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>',
    'clock'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
    'fax'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>',
    'wechat'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>',
    'building' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>',
    'globe'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path>',
    'qq'       => '<g transform="scale(0.0234375)"><path fill="currentColor" stroke="none" d="M512.4096 77.7728c-232.0896 0-420.864 188.8256-420.864 420.864 0 232.0896 188.8256 420.864 420.864 420.864 232.0896 0 420.864-188.8256 420.864-420.864s-188.7744-420.864-420.864-420.864z m0 800.8192c-209.5104 0-379.904-170.4448-379.904-379.904 0-209.5104 170.4448-379.904 379.904-379.904 209.5104 0 379.904 170.4448 379.904 379.904 0 209.4592-170.3936 379.904-379.904 379.904z"></path><path fill="currentColor" stroke="none" d="M683.9296 485.376c-1.8944-2.048-3.1232-5.4272-3.2768-8.2432-0.4096-8.448 0.2048-16.9472-0.256-25.3952-0.5632-10.1376-2.7136-20.6336-10.0352-27.5456-6.144-5.7856-6.8608-11.9296-7.168-19.0464-0.1024-2.56-0.3584-5.0688-0.6144-7.6288-3.9424-34.3552-13.0048-66.9184-36.4032-93.44-36.4544-41.3184-83.1488-57.1392-136.9088-49.2032-47.0528 6.912-84.1216 30.464-107.1616 73.4208-13.5168 25.1904-19.0464 52.5312-20.736 80.7424-0.3072 5.4272-0.8192 10.0352-5.888 13.6704-2.7136 1.9456-4.352 5.6832-5.8368 8.9088-6.5024 13.9264-6.656 28.7744-5.376 43.6736 0.3584 4.5056-0.8192 7.8336-4.0448 11.3152-24.6784 26.4704-42.1888 56.9344-48.384 92.9792-2.5088 14.5408-1.2288 29.2864 4.608 43.1104 3.3792 7.9872 9.216 9.9328 15.616 4.3008 7.2704-6.3488 13.4144-13.9776 19.7632-21.3504 2.9696-3.4816 5.1712-7.5776 7.3216-10.8032 11.5712 21.0944 23.0912 42.0352 34.2528 62.3104-7.2704 5.376-16.2304 10.5472-23.296 17.5616-15.36 15.1552-20.8384 45.6704 10.8544 60.1088 2.8672 1.3312 5.8368 2.56 8.9088 3.4304 29.2864 8.3968 58.5728 7.9872 87.9616 0.2048 15.5136-4.096 30.5152-9.0624 42.1888-20.8896 5.8368-5.9392 18.688-5.3248 25.1904-0.1024 6.5024 5.2736 13.4144 10.6496 21.0432 13.8752 22.3232 9.5232 45.824 13.9776 70.144 12.9536 16.7936-0.7168 33.4848-2.4064 48.896-10.0352 15.2064-7.5264 22.6816-19.2 21.5552-33.5872-1.024-13.3632-7.5264-23.808-17.9712-31.6416-5.888-4.4032-12.3904-7.936-17.6128-11.264 11.4688-20.8896 22.9888-41.8304 34.56-62.8736 2.1504 3.2256 4.4032 7.3216 7.3216 10.8544 5.7856 6.9632 11.4176 14.336 18.176 20.2752 7.9872 7.0144 14.1312 4.9664 17.9712-5.0688 4.9152-12.9536 6.2976-26.7776 4.0448-40.1408-6.3488-36.9664-24.3712-68.096-49.408-95.4368z"></path></g>',
    'user'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>',
    'users'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>',
    'mobile'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>',
    'message'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>',
];
    return $iconPaths;
}

/** 联系卡片数据（lang-aware）。 */
function contactCardsData(): array
{
    return json_decode(configJsonLang('contact_cards') ?: '[]', true) ?: [];
}

/** 按卡片数量决定列数。 */
function contactGridCols(int $count): string
{
    return match ($count) {
        1 => 'md:grid-cols-1',
        2 => 'md:grid-cols-2',
        4 => 'md:grid-cols-2 lg:grid-cols-4',
        default => 'md:grid-cols-3',
    };
}

/** 前台就地编辑标记；非管理员或不需要时返回空。 */
function contactNoEditAttr(): callable
{
    // 不声明参数：调用方会传 (url, label)，PHP 允许多传实参并忽略；
    // 声明后不用会被静态分析判为无用参数。
    return static function (): string { return ''; };
}

/** 联系信息卡片区。 */
function renderContactCardsHtml(?array $contactCards = null, ?string $gridCols = null, ?array $iconPaths = null, ?callable $__ykEdit = null, bool $withBottomMargin = true): string
{
    $contactCards = $contactCards ?? contactCardsData();
    $gridCols     = $gridCols ?? contactGridCols(count($contactCards));
    $iconPaths    = $iconPaths ?? contactIconPaths();
    $__ykEdit     = $__ykEdit ?? contactNoEditAttr();
    ob_start(); ?>
        <!-- 联系信息卡片 -->
        <?php if (!empty($contactCards)): ?>
        <div class="grid grid-cols-1 <?php echo $gridCols; ?> gap-6<?php echo $withBottomMargin ? ' mb-12' : ''; ?>"<?php echo $__ykEdit('/admin/setting_contact.php', '✎ 编辑联系信息'); ?>>
            <?php foreach ($contactCards as $card): ?>
            <?php
            $cardIconName = (string) ($card['icon'] ?? '');
            $cardValue = (string) ($card['value'] ?? '');
            $cardHref = '';
            if ($cardIconName === 'phone') {
                $phoneTarget = preg_replace('/[^\d+]/', '', $cardValue) ?? '';
                $cardHref = $phoneTarget !== '' ? 'tel:' . $phoneTarget : '';
            } elseif ($cardIconName === 'email' && filter_var($cardValue, FILTER_VALIDATE_EMAIL)) {
                $cardHref = 'mailto:' . $cardValue;
            }
            ?>
            <div class="bg-white rounded-lg shadow-sm border border-gray-100 p-5 md:p-6 flex items-center gap-4 text-left md:block md:text-center">
                <?php
                // 已填图标名但不在内置图标表时，兜底到通用图标，避免图标静默丢失
                $__cardIcon = $cardIconName !== '' ? ($iconPaths[$cardIconName] ?? $iconPaths['message']) : '';
                if ($__cardIcon !== ''):
                ?>
                <div class="w-12 h-12 md:w-16 md:h-16 bg-primary/10 rounded-full flex items-center justify-center shrink-0 md:mx-auto md:mb-4">
                    <svg class="w-6 h-6 md:w-8 md:h-8 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <?php echo $__cardIcon; ?>
                    </svg>
                </div>
                <?php endif; ?>
                <div class="min-w-0">
                    <h3 class="font-bold text-dark mb-1 md:mb-2"><?php echo e((string) ($card['label'] ?? '')); ?></h3>
                    <?php if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)(\?|$)/i', $cardValue)): ?>
                    <img loading="lazy" src="<?php echo e($cardValue); ?>" alt="<?php echo e((string) ($card['label'] ?? '')); ?>" class="max-h-24 md:mx-auto">
                    <?php elseif ($cardHref !== ''): ?>
                    <a href="<?php echo e($cardHref); ?>" class="text-gray-600 hover:text-primary transition break-all"><?php echo e($cardValue); ?></a>
                    <?php else: ?>
                    <p class="text-gray-600 break-words"><?php echo nl2br(e($cardValue)); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
<?php return (string) ob_get_clean();
}

/** 在线留言表单区。 */
function renderContactFormHtml(?callable $__ykEdit = null): string
{
    $__ykEdit = $__ykEdit ?? contactNoEditAttr();
    ob_start(); ?>
            <!-- 留言表单 -->
            <div class="bg-white rounded-lg shadow p-6 md:p-8 h-full"<?php echo $__ykEdit('/admin/form_design.php', '✎ 编辑留言表单'); ?>>
                <?php $formTitle = configLang('contact_form_title', 'contact_form_title'); ?>
                <h2 class="text-xl font-bold text-dark mb-2"><?php echo e($formTitle); ?></h2>
                <?php if ($formDesc = configRawLang('contact_form_desc')): ?>
                <p class="text-gray-500 text-sm mb-6"><?php echo e($formDesc); ?></p>
                <?php else: ?>
                <div class="mb-4"></div>
                <?php endif; ?>

                <?php echo renderFormTemplate('contact'); ?>
            </div>
<?php return (string) ob_get_clean();
}

/** 地图 / 二维码区。 */
function renderContactMapHtml(?callable $__ykEdit = null): string
{
    $__ykEdit = $__ykEdit ?? contactNoEditAttr();
    ob_start(); ?>
            <!-- 地图 / 二维码：交互地图按语言切服务商（中文 高德/百度，日英 Google），未配置则回退静态图/二维码/占位 -->
            <div class="bg-white rounded-lg shadow overflow-hidden h-full"<?php echo $__ykEdit('/admin/setting_contact.php#map', '✎ 编辑地图'); ?>>
                <?php
                $mLat  = trim((string) config('map_lat'));
                $mLng  = trim((string) config('map_lng'));
                $mZoom = (int) (config('map_zoom', '15') ?: 15);
                $mLang = function_exists('siteLang') ? siteLang() : 'zh-CN';
                $mapDone = false;
                if ($mLat !== '' && $mLng !== '' && is_numeric($mLat) && is_numeric($mLng)):
                    if ($mLang === 'zh-CN'):
                        $prov = (string) config('map_zh_provider', '');
                        if ($prov === 'amap' && ($amapKey = config('map_amap_key'))): $mapDone = true; ?>
                <div id="contactMap" class="w-full" style="min-height:400px"></div>
                <script src="https://webapi.amap.com/maps?v=2.0&key=<?php echo e($amapKey); ?>"></script>
                <script>(function(){function i(){if(typeof AMap==='undefined'){return setTimeout(i,200);}var c=[<?php echo (float)$mLng; ?>,<?php echo (float)$mLat; ?>];var m=new AMap.Map('contactMap',{zoom:<?php echo $mZoom; ?>,center:c});new AMap.Marker({position:c,map:m});}i();})();</script>
                        <?php elseif ($prov === 'baidu' && ($baiduAk = config('map_baidu_ak'))): $mapDone = true; ?>
                <div id="contactMap" class="w-full" style="min-height:400px"></div>
                <script>window._bmapInit=function(){var m=new BMap.Map('contactMap');var p=new BMap.Point(<?php echo (float)$mLng; ?>,<?php echo (float)$mLat; ?>);m.centerAndZoom(p,<?php echo $mZoom; ?>);m.addOverlay(new BMap.Marker(p));m.enableScrollWheelZoom(true);};</script>
                <script src="https://api.map.baidu.com/api?v=3.0&ak=<?php echo e($baiduAk); ?>&callback=_bmapInit"></script>
                        <?php endif;
                    else: // 日 / 英文版 → Google 地图嵌入（无需 Key）
                        $mapDone = true;
                        $hl = $mLang === 'ja' ? 'ja' : 'en'; ?>
                <iframe class="w-full" style="min-height:400px;border:0" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"
                        src="https://www.google.com/maps?q=<?php echo (float)$mLat; ?>,<?php echo (float)$mLng; ?>&z=<?php echo $mZoom; ?>&hl=<?php echo $hl; ?>&output=embed"></iframe>
                    <?php endif;
                endif;

                if (!$mapDone):
                    if ($mapImage = config('contact_map')): ?>
                <img loading="lazy" src="<?php echo e($mapImage); ?>" alt="地图" class="w-full h-full object-cover">
                <?php elseif ($qrcode = config('contact_qrcode')): ?>
                <div class="h-full flex flex-col items-center justify-center p-8">
                    <h3 class="text-xl font-bold text-dark mb-6"><?php echo __('contact_qr_title'); ?></h3>
                    <img loading="lazy" src="<?php echo e($qrcode); ?>" alt="QR Code" class="w-48 h-48">
                    <p class="text-gray-500 mt-4"><?php echo __('contact_wechat_scan'); ?></p>
                </div>
                <?php else:
                    $visitAddress = configRawLang('contact_address');
                    $visitHours = configRawLang('contact_hours');
                    $visitPhone = configRawLang('contact_phone');
                    $visitPhoneTarget = preg_replace('/[^\d+]/', '', $visitPhone) ?? '';
                ?>
                <div class="h-full min-h-[300px] md:min-h-[400px] bg-gray-100 p-7 md:p-10 flex flex-col justify-center">
                    <div class="w-12 h-12 bg-white text-primary rounded-lg shadow-sm flex items-center justify-center mb-6">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                    </div>
                    <h2 class="text-xl font-bold text-dark mb-2"><?php echo __('contact_visit_title'); ?></h2>
                    <p class="text-sm text-gray-500 mb-6"><?php echo __('contact_visit_hint'); ?></p>
                    <dl class="space-y-4 text-sm">
                        <?php if ($visitAddress !== ''): ?>
                        <div><dt class="font-medium text-gray-800 mb-1"><?php echo __('contact_address'); ?></dt><dd class="text-gray-600 leading-relaxed"><?php echo e($visitAddress); ?></dd></div>
                        <?php endif; ?>
                        <?php if ($visitHours !== ''): ?>
                        <div><dt class="font-medium text-gray-800 mb-1"><?php echo __('contact_hours_label'); ?></dt><dd class="text-gray-600"><?php echo e($visitHours); ?></dd></div>
                        <?php endif; ?>
                        <?php if ($visitPhone !== ''): ?>
                        <div><dt class="font-medium text-gray-800 mb-1"><?php echo __('contact_phone'); ?></dt><dd><a href="<?php echo e($visitPhoneTarget !== '' ? 'tel:' . $visitPhoneTarget : '#'); ?>" class="text-primary hover:underline"><?php echo e($visitPhone); ?></a></dd></div>
                        <?php endif; ?>
                    </dl>
                </div>
                <?php endif;
                endif; ?>
            </div>
<?php return (string) ob_get_clean();
}

/**
 * 联系页的默认排版：信息卡一行，表单与地图按 7:5 两列并排。
 * 用于排版编辑器在无排版数据时预置画布，让「打开即所见即所编」。
 */
function contactSeedSections(): array
{
    $uid = static function (string $p): string { return $p . '_' . substr(md5($p . microtime(false)), 0, 9); };
    return [
        ['id' => $uid('s'), 'settings' => [
            'padding' => 'md', 'max_width' => 'default', 'gap' => 'lg',
            'align_items' => 'stretch', 'justify_items' => 'stretch',
        ], 'columns' => [
            ['id' => $uid('c'), 'elements' => [
                ['id' => $uid('e'), 'type' => 'contact_cards', 'data' => ['cols' => 'auto']],
            ]],
        ]],
        ['id' => $uid('s'), 'settings' => [
            'padding' => 'sm', 'max_width' => 'default', 'gap' => 'lg',
            'align_items' => 'stretch', 'justify_items' => 'stretch',
        ], 'columns' => [
            ['id' => $uid('c'), 'span' => 7, 'elements' => [
                ['id' => $uid('e'), 'type' => 'contact_form', 'data' => []],
            ]],
            ['id' => $uid('c'), 'span' => 5, 'elements' => [
                ['id' => $uid('e'), 'type' => 'contact_map', 'data' => []],
            ]],
        ]],
    ];
}
