<?php
declare(strict_types=1);
/**
 * LOGO 制作 - 管理页面
 * 由 /admin/plugin_page.php?plugin=logo-maker 加载（已 checkLogin + CSRF）。
 *
 * 分工：浏览器端 canvas 负责渲染（能用客户端本地字体，中文字体无需服务端内置），
 *       输出 512px PNG 母版；服务端 GD 负责生成并应用站内图标。
 *
 * 免费：文字/图片生成 favicon.ico 并直接应用为站点图标（写入 uploads/brand，不提供下载）、
 *       LOGO 一键设为站点 LOGO。
 * 站内图标能力：一键应用 favicon、iOS、Android、PWA 图标并注入前台 head。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

loadPluginLang('logo-maker');
require_once __DIR__ . '/icon-lib.php'; // 纯 GD 图像输出，不依赖其他插件
require_once __DIR__ . '/random_logo.php';


// 本地 LOGO、favicon 和图标包能力全部开放；保留变量兼容既有模板分支。
$imHasPro = true;

// ============================================================
// 服务端：请求解析与打包
// ============================================================

/** 解析前端提交的 PNG dataURL 母版为 GD 图像（带大小/格式校验）。 */
function im_master(): GdImage
{
    $raw = (string) ($_POST['master'] ?? '');
    if ($raw === '' || strlen($raw) > 4_000_000) {
        error('图像数据缺失或过大');
    }
    if (!preg_match('#^data:image/png;base64,(.+)$#s', $raw, $m)) {
        error('图像格式不正确');
    }
    $bin = base64_decode($m[1], true);
    if ($bin === false) {
        error('图像数据解析失败');
    }
    $img = @imagecreatefromstring($bin);
    if (!$img instanceof GdImage) {
        error('无法识别的图像');
    }
    return $img;
}

/** 全套图标包文件清单：文件名 => 字节内容。 */
function im_pack_files(GdImage $master): array
{
    $files = ['favicon.ico' => im_ico($master)];
    foreach ([16 => 'favicon-16x16.png', 32 => 'favicon-32x32.png', 180 => 'apple-touch-icon.png',
              192 => 'android-chrome-192x192.png', 512 => 'android-chrome-512x512.png'] as $size => $name) {
        $img = im_scaled($master, $size);
        $files[$name] = im_png($img);
        imagedestroy($img);
    }
    $siteName = (string) configRawLang('site_name', 'My Site');
    $files['site.webmanifest'] = (string) json_encode([
        'name'             => $siteName,
        'short_name'       => mb_substr($siteName, 0, 12),
        'icons'            => [
            ['src' => '/android-chrome-192x192.png', 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => '/android-chrome-512x512.png', 'sizes' => '512x512', 'type' => 'image/png'],
        ],
        'theme_color'      => '#ffffff',
        'background_color' => '#ffffff',
        'display'          => 'standalone',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return $files;
}

// ============================================================
// POST 动作
// ============================================================
$imRandomIndustries = logoMakerRandomIndustries();
$imRandomSchemes = logoMakerRandomColorSchemes();
$imRandomMonoColors = logoMakerRandomMonoColors();
$imRandomColorModes = logoMakerRandomColorModes();
$imRandomLetterStyles = logoMakerRandomLetterStyles();
$imRandomEffects = logoMakerRandomEffects();
$imRandomBackgroundModes = logoMakerRandomBackgroundModes();
$imRandomIndustryInput = (string) ($_GET['industry'] ?? $_GET['random_industry'] ?? 'technology');
$imRandomIndustry = isset($imRandomIndustries[$imRandomIndustryInput]) ? $imRandomIndustryInput : 'technology';
$imRandomScheme = (string) ($_GET['scheme'] ?? $_GET['random_scheme'] ?? 'industry');
if ($imRandomScheme !== 'industry' && $imRandomScheme !== 'custom' && !isset($imRandomSchemes[$imRandomScheme])) {
    $imRandomScheme = 'industry';
}
$imRandomColorMode = (string) ($_GET['color_mode'] ?? 'trio');
if (!isset($imRandomColorModes[$imRandomColorMode])) {
    $imRandomColorMode = 'trio';
}
$imRandomMonoColor = (string) ($_GET['mono_color'] ?? 'industry');
if ($imRandomMonoColor !== 'industry' && $imRandomMonoColor !== 'custom' && !isset($imRandomMonoColors[$imRandomMonoColor])) {
    $imRandomMonoColor = 'industry';
}
$imRandomCustomColors = [
    logoMakerRandomNormalizeHexColor((string) ($_GET['custom_color1'] ?? ''), '#1D4ED8'),
    logoMakerRandomNormalizeHexColor((string) ($_GET['custom_color2'] ?? ''), '#22D3EE'),
    logoMakerRandomNormalizeHexColor((string) ($_GET['custom_color3'] ?? ''), '#0F172A'),
];
$imRandomLetterStyle = (string) ($_GET['letter_style'] ?? $_GET['random_letter_style'] ?? 'abstract');
if (!isset($imRandomLetterStyles[$imRandomLetterStyle])) {
    $imRandomLetterStyle = 'abstract';
}
$imRandomEffect = (string) ($_GET['effect'] ?? 'auto');
if (!isset($imRandomEffects[$imRandomEffect])) {
    $imRandomEffect = 'auto';
}
$imRandomBackgroundMode = (string) ($_GET['background'] ?? 'transparent');
if (!isset($imRandomBackgroundModes[$imRandomBackgroundMode])) {
    $imRandomBackgroundMode = 'transparent';
}
$imRandomRecommendedSchemes = $imRandomIndustries[$imRandomIndustry]['schemes'];
$imRandomName = trim((string) ($_GET['mark'] ?? $_GET['random_name'] ?? configRawLang('site_name', 'Yikai')));
$imRandomName = mb_substr($imRandomName !== '' ? $imRandomName : 'YK', 0, 24, 'UTF-8');
$imRandomSeed = max(1, min(2_147_483_647, (int) ($_GET['seed'] ?? $_GET['random_seed'] ?? random_int(10_000, 9_999_999))));

if (($_GET['im_action'] ?? '') === 'random_svg') {
    $industry = isset($_GET['industry'], $imRandomIndustries[(string) $_GET['industry']]) ? (string) $_GET['industry'] : 'technology';
    $name = mb_substr(trim((string) ($_GET['mark'] ?? $_GET['name'] ?? configRawLang('site_name', 'Yikai'))), 0, 24, 'UTF-8');
    $schemeInput = (string) ($_GET['scheme'] ?? 'industry');
    $scheme = $schemeInput === 'custom' || isset($imRandomSchemes[$schemeInput]) ? $schemeInput : 'industry';
    $letterStyle = isset($_GET['letter_style'], $imRandomLetterStyles[(string) $_GET['letter_style']]) ? (string) $_GET['letter_style'] : 'abstract';
    $effect = isset($_GET['effect'], $imRandomEffects[(string) $_GET['effect']]) ? (string) $_GET['effect'] : 'auto';
    $background = isset($_GET['background'], $imRandomBackgroundModes[(string) $_GET['background']]) ? (string) $_GET['background'] : 'transparent';
    $colorMode = isset($_GET['color_mode'], $imRandomColorModes[(string) $_GET['color_mode']]) ? (string) $_GET['color_mode'] : 'trio';
    $monoColorInput = (string) ($_GET['mono_color'] ?? 'industry');
    $monoColor = $monoColorInput === 'custom' || isset($imRandomMonoColors[$monoColorInput]) ? $monoColorInput : 'industry';
    $customColors = [
        logoMakerRandomNormalizeHexColor((string) ($_GET['custom_color1'] ?? ''), '#1D4ED8'),
        logoMakerRandomNormalizeHexColor((string) ($_GET['custom_color2'] ?? ''), '#22D3EE'),
        logoMakerRandomNormalizeHexColor((string) ($_GET['custom_color3'] ?? ''), '#0F172A'),
    ];
    $seed = max(1, min(2_147_483_647, (int) ($_GET['seed'] ?? 1)));
    $index = max(0, min(99, (int) ($_GET['i'] ?? 0)));
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: private, max-age=86400');
    header('Content-Disposition: inline; filename="logo-icon-' . ($index + 1) . '.svg"');
    echo logoMakerRandomSvg($industry, $name, $seed, $index, $scheme, $letterStyle, $effect, $background, $colorMode, $monoColor, $customColors);
    exit;
}

$imAction = (string) ($_POST['im_action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $imAction !== '') {
    if (!function_exists('imagecreatetruecolor')) {
        error('服务器缺少 GD 扩展，无法生成图标');
    }

    // 通过 site_favicon 统一由前台 <head> 调用，不覆盖网站根目录的旧 favicon.ico。
    if ($imAction === 'apply_ico') {
        $master = im_master();
        $ico = im_ico($master);
        imagedestroy($master);
        $dir = ROOT_PATH . '/uploads/brand';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            error('无法创建 uploads/brand 目录，无法应用网站图标');
        }
        $relative = '/uploads/brand/favicon-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.ico';
        if (@file_put_contents(ROOT_PATH . $relative, $ico, LOCK_EX) === false) {
            error('网站图标保存失败，请检查 uploads/brand 目录权限');
        }
        $url = $relative . '?v=' . time();
        settingModel()->set('site_favicon', $url, 'basic');
        adminLog('plugin', 'favicon_apply', 'LOGO 制作：通过前台 head 应用 favicon.ico ' . $relative);
        success(['path' => $relative], '已应用为网站图标，前台将通过 head 调用新图标');
    }

    // 免费：LOGO 直接保存并设为站点 LOGO（制作 → LOGO 位一步到位）
    if ($imAction === 'apply_logo') {
        $master = im_master();   // 校验确实是可解析的 PNG dataURL
        imagedestroy($master);
        $bin = base64_decode(explode(',', (string) $_POST['master'], 2)[1], true);
        $dir = ROOT_PATH . '/uploads/brand';
        @mkdir($dir, 0755, true);
        $rel = '/uploads/brand/logo-' . date('YmdHis') . '.png';
        if (@file_put_contents(ROOT_PATH . $rel, $bin) === false) {
            error('LOGO 保存失败（uploads 不可写？）');
        }
        settingModel()->set('site_logo', $rel, 'basic');
        adminLog('plugin', 'logo_apply', 'LOGO 制作：设为站点 LOGO ' . $rel);
        success(['path' => $rel], '已设为站点 LOGO');
    }

    // 专业版动作统一闸门
    if (!$imHasPro) {
        error('该功能需要专业版授权，请前往「授权管理」激活');
    }

    // Pro：一键应用到本站（写根目录 + 打开前台 head 注入）
    if ($imAction === 'apply_site') {
        if (!is_writable(ROOT_PATH)) {
            error('网站根目录不可写，无法应用，请检查目录权限');
        }
        $master = im_master();
        $files = im_pack_files($master);
        imagedestroy($master);
        $written = [];
        foreach ($files as $name => $bytes) {
            if (@file_put_contents(ROOT_PATH . '/' . $name, $bytes) === false) {
                error("写入 {$name} 失败，请检查根目录权限（已写入：" . (implode('、', $written) ?: '无') . '）');
            }
            $written[] = $name;
        }
        settingModel()->set('logo_maker_applied', '1', 'plugin');
        settingModel()->set('logo_maker_applied_at', (string) time(), 'plugin');
        adminLog('plugin', 'logo_apply_site', 'LOGO 制作：全套图标应用到本站（' . count($written) . ' 个文件）');
        success([], '已应用：' . implode('、', $written) . '。前台已自动注入图标链接。');
    }

    error('未知操作');
}

$imApplied   = (string) config('logo_maker_applied', '') === '1';
$imAppliedAt = (int) config('logo_maker_applied_at', 0);

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-6">

    <!-- Tab 导航 -->
    <div class="bg-white rounded-lg shadow">
        <div class="flex border-b text-sm font-medium" id="imTabs">
            <button data-tab="random" class="im-tab px-6 py-3 border-b-2 border-primary text-primary"><i class="ti ti-sparkles mr-1"></i>随机图标</button>
            <button data-tab="draw" class="im-tab px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700"><i class="ti ti-pencil mr-1"></i>绘制图标</button>
            <button data-tab="logo"  class="im-tab px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700"><i class="ti ti-badge mr-1"></i>LOGO 排版</button>
            <button data-tab="text"  class="im-tab px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700"><i class="ti ti-typography mr-1"></i>图标(favicon)</button>
            <button data-tab="image" class="im-tab px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700"><i class="ti ti-photo mr-1"></i>图片转图标(favicon)</button>
        </div>

        <div class="p-6">
            <!-- 文字图标 -->
            <div id="im-pane-text" class="im-pane hidden">
                <div class="grid md:grid-cols-2 gap-8">
                <div class="space-y-4">
                    <div>
                        <label class="block font-medium text-gray-700 mb-1.5">图标文字（1-2 字）</label>
                        <input type="text" id="imText" maxlength="2" value="易" class="w-32 border rounded px-3 py-2 text-lg text-center">
                    </div>
                    <div>
                        <label class="block font-medium text-gray-700 mb-1.5">字体</label>
                        <select id="imFont" class="w-full md:w-64 border rounded px-3 py-2 text-sm">
                            <option value='"Microsoft YaHei","PingFang SC",sans-serif'>黑体（雅黑 / 苹方）</option>
                            <option value='"SimSun","Songti SC",serif'>宋体</option>
                            <option value='"KaiTi","Kaiti SC",serif'>楷体</option>
                            <option value='Georgia,"Times New Roman",serif'>衬线（西文）</option>
                            <option value='"Arial Black",Arial,sans-serif'>粗黑（西文）</option>
                            <option value='"Courier New",monospace'>等宽</option>
                        </select>
                        <label class="inline-flex items-center gap-1.5 ml-3 text-sm text-gray-600"><input type="checkbox" id="imBold" checked>加粗</label>
                    </div>
                    <div class="flex gap-6">
                        <div>
                            <label class="block font-medium text-gray-700 mb-1.5">文字颜色</label>
                            <input type="color" id="imFg" value="#ffffff" class="w-14 h-9 border rounded cursor-pointer">
                        </div>
                        <div>
                            <label class="block font-medium text-gray-700 mb-1.5">背景颜色</label>
                            <input type="color" id="imBg" value="#2563eb" class="w-14 h-9 border rounded cursor-pointer">
                        </div>
                        <div class="flex-1">
                            <label class="block font-medium text-gray-700 mb-1.5">字号 <span id="imSizeVal" class="text-gray-400 text-xs"></span></label>
                            <input type="range" id="imSize" min="40" max="90" value="62" class="w-full">
                        </div>
                    </div>
                    <div>
                        <label class="block font-medium text-gray-700 mb-1.5">配色方案 <span class="text-gray-400 text-xs font-normal">点击套用（文字色 + 背景色）</span></label>
                        <div class="flex flex-wrap gap-2" id="imCombos">
                            <?php
                            // [文字色, 背景色, 名称]；按钮本身即效果预览
                            $imCombos = [
                                ['#ffffff', '#2563eb', '白字蓝底'],
                                ['#ffffff', '#1e3a8a', '白字深蓝'],
                                ['#ffffff', '#dc2626', '白字红底'],
                                ['#ffffff', '#ea580c', '白字橙底'],
                                ['#ffffff', '#16a34a', '白字绿底'],
                                ['#ffffff', '#0d9488', '白字青底'],
                                ['#ffffff', '#7c3aed', '白字紫底'],
                                ['#ffffff', '#111827', '白字黑底'],
                                ['#111827', '#facc15', '黑字黄底'],
                                ['#1f2937', '#ffffff', '黑字白底'],
                                ['#1d4ed8', '#dbeafe', '蓝字浅蓝'],
                                ['#15803d', '#dcfce7', '绿字浅绿'],
                                ['#f59e0b', '#111827', '金字黑底'],
                            ];
                            foreach ($imCombos as $co): ?>
                            <button type="button" data-fg="<?php echo $co[0]; ?>" data-bg="<?php echo $co[1]; ?>" title="<?php echo $co[2]; ?>"
                                    class="w-9 h-9 rounded-lg border border-gray-300 text-sm font-bold hover:scale-110 transition"
                                    style="background:<?php echo $co[1]; ?>;color:<?php echo $co[0]; ?>">字</button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <label class="block font-medium text-gray-700 mb-1.5">背景形状</label>
                        <div class="flex gap-2" id="imShape">
                            <button data-shape="square"  class="im-shape px-4 py-1.5 border rounded text-sm">方形</button>
                            <button data-shape="rounded" class="im-shape px-4 py-1.5 border rounded text-sm bg-primary text-white border-primary">圆角</button>
                            <button data-shape="circle"  class="im-shape px-4 py-1.5 border rounded text-sm">圆形</button>
                        </div>
                    </div>
                </div>
                <div><!-- 预览 + 下载在下方公共区 --></div>
                </div>
            </div>

            <!-- 图片转图标 -->
            <div id="im-pane-image" class="im-pane hidden">
                <div id="imDrop" class="border-2 border-dashed border-gray-300 hover:border-primary rounded-lg p-10 text-center cursor-pointer transition">
                    <i class="ti ti-cloud-upload text-3xl text-gray-400"></i>
                    <p class="text-sm text-gray-600 mt-2">拖放图片到这里，或点击上传（PNG / JPG / GIF，建议正方形、512px 以上）</p>
                    <p id="imDropName" class="text-xs text-primary mt-1"></p>
                    <input type="file" id="imFile" accept="image/png,image/jpeg,image/gif" class="hidden">
                </div>
            </div>

            <!-- 随机 SVG 图标 -->
            <div id="im-pane-random" class="im-pane">
                <?php require __DIR__ . '/random_ui.php'; ?>
            </div>

            <!-- 图标绘制：先完成独立图标，再送入 LOGO 排版 -->
            <div id="im-pane-draw" class="im-pane hidden">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">图标绘制</h2>
                        <p class="text-sm text-gray-500">在 512 × 512 画布中组合形状、线条和文字，再用于 LOGO。</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" id="imDrawUndo" class="px-3 py-1.5 bg-white border rounded text-sm text-gray-600 disabled:opacity-40" title="撤销"><i class="ti ti-arrow-back-up mr-1"></i>撤销</button>
                        <button type="button" id="imDrawRedo" class="px-3 py-1.5 bg-white border rounded text-sm text-gray-600 disabled:opacity-40" title="重做"><i class="ti ti-arrow-forward-up mr-1"></i>重做</button>
                        <button type="button" id="imDrawClear" class="px-3 py-1.5 bg-white border rounded text-sm text-gray-600 hover:text-red-600"><i class="ti ti-trash mr-1"></i>清空</button>
                    </div>
                </div>
                <div class="grid lg:grid-cols-[minmax(0,1fr)_280px] gap-5">
                    <div>
                        <div class="flex flex-wrap items-center gap-2 mb-3" role="toolbar" aria-label="图标绘制工具">
                            <?php foreach ([
                                ['select', 'pointer', '选择'], ['rect', 'square', '矩形'], ['ellipse', 'circle', '椭圆'],
                                ['triangle', 'triangle', '三角'], ['star', 'star', '星形'], ['line', 'slash', '直线'],
                                ['brush', 'pencil', '画笔'], ['text', 'typography', '文字'],
                            ] as [$tool, $icon, $label]): ?>
                                <button type="button" class="im-draw-tool <?php echo $tool === 'select' ? 'is-active' : ''; ?>" data-im-draw-tool="<?php echo e($tool); ?>" title="<?php echo e($label); ?>" aria-label="<?php echo e($label); ?>"><i class="ti ti-<?php echo e($icon); ?>"></i><span><?php echo e($label); ?></span></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="im-draw-stage-wrap">
                            <canvas id="imDrawStage" width="512" height="512" aria-label="512 × 512 图标绘制画布"></canvas>
                        </div>
                        <p id="imDrawStatus" class="text-xs text-gray-400 mt-2">选择工具后在画布上操作。参考网格只用于绘制，不会导出。</p>
                    </div>
                    <aside class="border rounded-lg bg-gray-50 p-4 space-y-4">
                        <div>
                            <h3 class="font-medium text-gray-800">绘制属性</h3>
                            <p class="text-xs text-gray-500 mt-1">当前工具会使用下面的颜色和尺寸。</p>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block text-sm text-gray-700">填充颜色
                                <input type="color" id="imDrawFill" value="#2563eb" class="block mt-1 w-14 h-9 border rounded cursor-pointer">
                            </label>
                            <label class="block text-sm text-gray-700">描边颜色
                                <input type="color" id="imDrawStroke" value="#17202a" class="block mt-1 w-14 h-9 border rounded cursor-pointer">
                            </label>
                        </div>
                        <label class="block text-sm text-gray-700">描边宽度 <span id="imDrawStrokeValue" class="text-gray-400">8px</span>
                            <input type="range" id="imDrawStrokeWidth" min="1" max="40" value="8" class="block mt-2 w-full">
                        </label>
                        <p class="text-xs text-gray-500"><i class="ti ti-wand mr-1"></i>画笔路径会自动平滑，送入 LOGO 后保持平滑曲线。</p>
                        <label class="block text-sm text-gray-700">文字内容
                            <input type="text" id="imDrawText" value="YK" maxlength="12" class="block mt-1 w-full border rounded px-3 py-2 text-sm">
                        </label>
                        <div class="border-t pt-4 space-y-2">
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" id="imDrawUseLogo" class="px-3 py-2 bg-primary hover:bg-secondary text-white rounded text-sm"><i class="ti ti-arrow-right mr-1"></i>用于 LOGO 排版</button>
                                <button type="button" id="imDrawUseFavicon" class="px-3 py-2 bg-gray-800 hover:bg-gray-900 text-white rounded text-sm"><i class="ti ti-world mr-1"></i>用于网站 favicon.ico</button>
                            </div>
                        </div>
                    </aside>
                </div>
            </div>

            <!-- 文字 LOGO：画布式编辑器（多文字元素，独立样式，拖动定位） -->
            <div id="im-pane-logo" class="im-pane space-y-4">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <button id="imLAdd" class="px-3 py-1.5 bg-primary hover:bg-secondary text-white rounded transition"><i class="ti ti-plus mr-0.5"></i>添加文字</button>
                    <button id="imLDel" class="px-3 py-1.5 bg-white border text-gray-600 hover:text-red-500 hover:border-red-300 rounded transition"><i class="ti ti-trash mr-0.5"></i>删除选中</button>
                    <button id="imLReset" class="px-3 py-1.5 bg-white border text-gray-600 hover:text-primary hover:border-primary rounded transition" title="恢复 站名 + 网址 两行示例"><i class="ti ti-restore mr-0.5"></i>恢复默认</button>
                    <button id="imLIconRemove" class="hidden px-3 py-1.5 bg-white border text-gray-600 hover:text-red-500 hover:border-red-300 rounded transition" title="移除左侧图标"><i class="ti ti-photo-off mr-0.5"></i>移除图标</button>
                    <label id="imLIconSizeWrap" class="hidden inline-flex items-center gap-2 text-gray-600">
                        图标大小 <input type="range" id="imLIconSize" min="24" max="160" value="112" class="w-24">
                        <span id="imLIconSizeVal" class="w-10 text-xs text-gray-400">112px</span>
                    </label>
                    <label class="inline-flex items-center gap-1.5 text-gray-600 ml-2">
                        <input type="checkbox" id="imLBgOn">背景色
                        <input type="color" id="imLBg" value="#ffffff" class="w-9 h-7 border rounded cursor-pointer">
                    </label>
                    <span class="text-xs text-gray-400">不勾选 = 透明底</span>
                </div>
                <div class="grid md:grid-cols-3 gap-6">
                    <div class="md:col-span-2">
                        <canvas id="imLogoStage" width="960" height="320" class="w-full border-2 border-gray-200 rounded-lg cursor-move touch-none"
                                style="background-image:conic-gradient(#f1f5f9 25%,#fff 0 50%,#f1f5f9 0 75%,#fff 0);background-size:16px 16px"></canvas>
                        <p class="text-xs text-gray-400 mt-1.5"><i class="ti ti-hand-move"></i> 点击图案或文字即可选中；拖动图案可定位，拖动四角控制点可等比缩放，导出时自动裁掉四周空白</p>
                    </div>
                    <div id="imLPanel" class="space-y-3 text-sm bg-gray-50 border rounded-lg p-4">
                        <div>
                            <label class="block font-medium text-gray-700 mb-1">文字内容</label>
                            <input type="text" id="imLText" maxlength="30" class="w-full border rounded px-3 py-1.5">
                        </div>
                        <div>
                            <label class="block font-medium text-gray-700 mb-1">字体</label>
                            <select id="imLFont" class="w-full border rounded px-2 py-1.5">
                                <option value='"Microsoft YaHei","PingFang SC",sans-serif'>黑体（雅黑 / 苹方）</option>
                                <option value='"SimSun","Songti SC",serif'>宋体</option>
                                <option value='"KaiTi","Kaiti SC",serif'>楷体</option>
                                <option value='Georgia,"Times New Roman",serif'>衬线（西文）</option>
                                <option value='"Arial Black",Arial,sans-serif'>粗黑（西文）</option>
                                <option value='"Courier New",monospace'>等宽</option>
                            </select>
                        </div>
                        <div>
                            <label class="block font-medium text-gray-700 mb-1">字号 <span id="imLSizeVal" class="text-gray-400 text-xs"></span></label>
                            <input type="range" id="imLSize" min="12" max="120" class="w-full">
                        </div>
                        <div>
                            <label class="block font-medium text-gray-700 mb-1">字间距 <span id="imLSpaceVal" class="text-gray-400 text-xs"></span></label>
                            <input type="range" id="imLSpace" min="0.8" max="2" step="0.1" class="w-full">
                        </div>
                        <div>
                            <label class="block font-medium text-gray-700 mb-1"><?= e(__('logo_maker_text_direction')) ?></label>
                            <div id="imLDirection" class="inline-flex rounded border overflow-hidden" role="group" aria-label="<?= e(__('logo_maker_text_direction')) ?>">
                                <button type="button" data-orientation="horizontal" data-testid="logomaker-text-horizontal"
                                        class="px-3 py-1.5 bg-white text-gray-700 hover:bg-blue-50 hover:text-primary inline-flex items-center gap-1.5">
                                    <i class="ti ti-arrows-horizontal" aria-hidden="true"></i><?= e(__('logo_maker_text_horizontal')) ?>
                                </button>
                                <button type="button" data-orientation="vertical" data-testid="logomaker-text-vertical"
                                        class="px-3 py-1.5 bg-white text-gray-700 hover:bg-blue-50 hover:text-primary border-l inline-flex items-center gap-1.5">
                                    <i class="ti ti-arrows-vertical" aria-hidden="true"></i><?= e(__('logo_maker_text_vertical')) ?>
                                </button>
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center gap-4 mb-1.5">
                                <label class="font-medium text-gray-700">颜色 <input type="color" id="imLColor" class="w-9 h-7 border rounded cursor-pointer align-middle ml-1"></label>
                                <label class="inline-flex items-center gap-1.5 text-gray-600"><input type="checkbox" id="imLBold">加粗</label>
                            </div>
                            <div class="flex flex-wrap gap-1.5" id="imLSwatches">
                                <?php foreach (['#1f2937', '#4b5563', '#9ca3af', '#ffffff', '#2563eb', '#1e40af', '#dc2626', '#ea580c', '#d97706', '#16a34a', '#0d9488', '#7c3aed', '#db2777'] as $c): ?>
                                <button type="button" data-color="<?php echo $c; ?>" class="w-6 h-6 rounded-full border border-gray-300 hover:scale-110 transition" style="background:<?php echo $c; ?>" title="<?php echo $c; ?>"></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <label class="block font-medium text-gray-700 mb-1">快速定位</label>
                            <div class="flex items-center gap-3">
                                <div class="inline-flex rounded border overflow-hidden" title="水平对齐">
                                    <button type="button" data-align-h="left"   class="im-align px-3 py-2 bg-white hover:bg-blue-50 hover:text-primary text-gray-700" title="左对齐"><i class="ti ti-align-left text-xl"></i></button>
                                    <button type="button" data-align-h="center" class="im-align px-3 py-2 bg-white hover:bg-blue-50 hover:text-primary text-gray-700 border-l" title="水平居中"><i class="ti ti-align-center text-xl"></i></button>
                                    <button type="button" data-align-h="right"  class="im-align px-3 py-2 bg-white hover:bg-blue-50 hover:text-primary text-gray-700 border-l" title="右对齐"><i class="ti ti-align-right text-xl"></i></button>
                                </div>
                                <div class="inline-flex rounded border overflow-hidden" title="垂直对齐">
                                    <button type="button" data-align-v="top"    class="im-align px-3 py-2 bg-white hover:bg-blue-50 hover:text-primary text-gray-700" title="顶部"><i class="ti ti-layout-align-top text-xl"></i></button>
                                    <button type="button" data-align-v="middle" class="im-align px-3 py-2 bg-white hover:bg-blue-50 hover:text-primary text-gray-700 border-l" title="垂直居中"><i class="ti ti-layout-align-middle text-xl"></i></button>
                                    <button type="button" data-align-v="bottom" class="im-align px-3 py-2 bg-white hover:bg-blue-50 hover:text-primary text-gray-700 border-l" title="底部"><i class="ti ti-layout-align-bottom text-xl"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <button id="imLogoApply" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white rounded text-sm transition"><i class="ti ti-check mr-1"></i>一键设为站点 LOGO</button>
                </div>
            </div>

            <!-- 公共：多尺寸预览 + 生成（文字图标 / 图片转图标 共用） -->
            <div id="imIconOut" class="hidden mt-8 pt-6 border-t">
                <h3 class="text-sm font-bold text-gray-700 mb-3">多尺寸预览</h3>
                <div class="flex items-end gap-6 flex-wrap" id="imPreviews">
                    <?php foreach ([16, 32, 48, 64, 180] as $s): ?>
                        <div class="text-center">
                            <canvas data-size="<?php echo $s; ?>" width="<?php echo $s; ?>" height="<?php echo $s; ?>" class="border border-gray-200 bg-white" style="image-rendering:pixelated"></canvas>
                            <p class="text-xs text-gray-400 mt-1"><?php echo $s; ?>px</p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="flex flex-wrap gap-3 mt-5">
                    <button id="imIcoApply" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white rounded text-sm transition"><i class="ti ti-check mr-1"></i>直接用作站点图标</button>
                    <?php if ($imHasPro): ?>
                        <button id="imApply" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white rounded text-sm transition"><i class="ti ti-rocket mr-1"></i>一键应用到本站</button>
                    <?php endif; ?>
                </div>
                <?php if ($imApplied): ?>
                    <p class="text-xs text-green-600 mt-3"><i class="ti ti-circle-check mr-1"></i>本站已应用全套图标（<?php echo e(date('Y-m-d H:i', $imAppliedAt ?: time())); ?>），再次应用会覆盖。</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (!$imHasPro): ?>
    <!-- 授权闸门保留，当前本地能力默认开放 -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center gap-2 mb-3">
            <h2 class="font-bold text-gray-800">站点图标应用</h2>
            <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700">当前可用</span>
        </div>
        <div class="bg-gray-50 border border-dashed rounded-lg p-5 text-center">
            <p class="text-sm text-gray-600 mb-1">当前账号可用功能：</p>
            <p class="text-sm text-gray-600 mb-3">将 favicon、iOS、Android、PWA 图标一键应用到本站，并自动注入前台链接。</p>
            <a href="/admin/license.php" class="inline-flex items-center gap-1 bg-primary hover:bg-secondary text-white px-5 py-2 rounded text-sm transition">前往授权管理 →</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    const TOKEN = <?php echo json_encode(csrfToken()); ?>;
    const $ = id => document.getElementById(id);
    const master = document.createElement('canvas');
    master.width = master.height = 512;
    const mctx = master.getContext('2d');
    let mode = 'text';          // text | image
    let uploadedImg = null;     // 图片模式的 Image 对象

    // ---------- 渲染母版 ----------
    function shapePath(ctx, s, shape) {
        ctx.beginPath();
        if (shape === 'circle') ctx.arc(s / 2, s / 2, s / 2, 0, Math.PI * 2);
        else if (shape === 'rounded') ctx.roundRect(0, 0, s, s, s * 0.18);
        else ctx.rect(0, 0, s, s);
    }
    function renderMaster() {
        mctx.clearRect(0, 0, 512, 512);
        if (mode === 'image') {
            if (!uploadedImg) return;
            const sc = Math.min(512 / uploadedImg.width, 512 / uploadedImg.height);
            const w = uploadedImg.width * sc, h = uploadedImg.height * sc;
            mctx.drawImage(uploadedImg, (512 - w) / 2, (512 - h) / 2, w, h);
        } else {
            const shape = document.querySelector('.im-shape.bg-primary')?.dataset.shape || 'rounded';
            mctx.fillStyle = $('imBg').value;
            shapePath(mctx, 512, shape);
            mctx.fill();
            const size = Math.round(512 * $('imSize').value / 100);
            $('imSizeVal').textContent = $('imSize').value + '%';
            mctx.fillStyle = $('imFg').value;
            mctx.font = ($('imBold').checked ? 'bold ' : '') + size + 'px ' + $('imFont').value;
            mctx.textAlign = 'center';
            mctx.textBaseline = 'middle';
            mctx.fillText($('imText').value || '易', 256, 256 + size * 0.04);
        }
        document.querySelectorAll('#imPreviews canvas').forEach(c => {
            const s = +c.dataset.size, ctx = c.getContext('2d');
            ctx.clearRect(0, 0, s, s);
            ctx.drawImage(master, 0, 0, s, s);
        });
    }

    // ---------- LOGO 画布编辑器：多文字图层，独立样式，拖动定位 ----------
    // 逻辑坐标恒为 480×160；画布实际 960×320（2 倍超采样，全宽拉伸下依然清晰，导出即 2 倍图）
    const stage = $('imLogoStage');
    const sctx = stage.getContext('2d');
    const VIEW_W = 480, VIEW_H = 160, DPR = 2;
    const LOGO = {
        icon: null, iconSvg: '', iconUrl: '', iconX: 16, iconY: 24, iconSize: 112,
        layers: [
            { text: <?php echo json_encode(configRawLang('site_name', 'Yikai CMS')); ?>, x: 24, y: 84, size: 56, color: '#1f2937', font: '"Microsoft YaHei","PingFang SC",sans-serif', bold: true, spacing: 1, orientation: 'horizontal' },
            <?php
            // 第二行默认放站点网址：site_url 优先，取不到用当前访问域名
            $imHost = (string) parse_url((string) config('site_url', ''), PHP_URL_HOST) ?: (string) ($_SERVER['HTTP_HOST'] ?? 'www.example.com');
            ?>
            { text: <?php echo json_encode($imHost); ?>, x: 26, y: 126, size: 20, color: '#9ca3af', font: '"Microsoft YaHei","PingFang SC",sans-serif', bold: false, spacing: 1, orientation: 'horizontal' }
        ],
        sel: 0, active: 'text', drag: null
    };
    const LOGO_DEFAULTS = JSON.parse(JSON.stringify(LOGO.layers));   // 「恢复默认」用的初始两行快照
    function lyFont(ly) { return (ly.bold ? 'bold ' : '') + ly.size + 'px ' + ly.font; }
    // 字间距：倍率 1 = 正常；每 +0.1 增加 0.1 字号的字符间隙（逐字绘制实现，兼容所有浏览器）
    function lySpacing(ly) { return Math.round(((ly.spacing || 1) - 1) * ly.size * 10) / 10; }
    function lyIsVertical(ly) { return ly.orientation === 'vertical'; }
    function lyChars(ly) { return Array.from(ly.text || ''); }
    function lyTextWidth(ctx, ly) {
        ctx.font = lyFont(ly);
        const extra = lySpacing(ly);
        if (!extra) return ctx.measureText(ly.text).width;
        let w = 0, n = 0;
        for (const ch of ly.text) { w += ctx.measureText(ch).width + extra; n++; }
        return n ? w - extra : 0;
    }
    function lyMetrics(ctx, ly) {
        ctx.font = lyFont(ly);
        if (!lyIsVertical(ly)) {
            return { w: lyTextWidth(ctx, ly), h: ly.size * 1.05, advance: 0 };
        }
        const chars = lyChars(ly);
        let width = 0;
        chars.forEach(ch => { width = Math.max(width, ctx.measureText(ch).width); });
        const advance = ly.size + lySpacing(ly);
        return {
            w: width,
            h: chars.length ? ly.size * 1.05 + (chars.length - 1) * advance : 0,
            advance
        };
    }
    function lyDrawText(ctx, ly) {
        ctx.save();
        ctx.font = lyFont(ly);
        ctx.fillStyle = ly.color;
        ctx.textBaseline = 'alphabetic';
        ctx.textAlign = 'left';
        const extra = lySpacing(ly);
        if (lyIsVertical(ly)) {
            const metrics = lyMetrics(ctx, ly);
            lyChars(ly).forEach((ch, index) => {
                const glyphWidth = ctx.measureText(ch).width;
                ctx.fillText(ch, ly.x + (metrics.w - glyphWidth) / 2, ly.y + index * metrics.advance);
            });
        } else if (!extra) {
            ctx.fillText(ly.text, ly.x, ly.y);
        } else {
            let x = ly.x;
            for (const ch of lyChars(ly)) { ctx.fillText(ch, x, ly.y); x += ctx.measureText(ch).width + extra; }
        }
        ctx.restore();
    }
    // 图层包围盒（近似：基线上方 0.8 字号，下方 0.25 字号容纳降部）
    function lyBounds(ctx, ly) {
        const metrics = lyMetrics(ctx, ly);
        return { x: ly.x, y: ly.y - ly.size * 0.8, w: metrics.w, h: metrics.h };
    }
    function iconBounds() {
        return { x: LOGO.iconX, y: LOGO.iconY, w: LOGO.iconSize, h: LOGO.iconSize };
    }
    function iconHandles() {
        const b = iconBounds();
        return {
            nw: { x: b.x, y: b.y, cursor: 'nwse-resize' },
            ne: { x: b.x + b.w, y: b.y, cursor: 'nesw-resize' },
            sw: { x: b.x, y: b.y + b.h, cursor: 'nesw-resize' },
            se: { x: b.x + b.w, y: b.y + b.h, cursor: 'nwse-resize' }
        };
    }
    function hitIconHandle(p) {
        if (!LOGO.icon || LOGO.active !== 'icon') return null;
        const handles = iconHandles();
        for (const [corner, handle] of Object.entries(handles)) {
            if (Math.abs(p.x - handle.x) <= 7 && Math.abs(p.y - handle.y) <= 7) return { corner, ...handle };
        }
        return null;
    }
    function drawLayers(ctx, w, h, ui) {
        ctx.clearRect(0, 0, w, h);
        if ($('imLBgOn').checked) { ctx.fillStyle = $('imLBg').value; ctx.fillRect(0, 0, w, h); }
        if (LOGO.icon) {
            ctx.drawImage(LOGO.icon, LOGO.iconX, LOGO.iconY, LOGO.iconSize, LOGO.iconSize);
            if (ui && LOGO.active === 'icon') {
                const b = iconBounds();
                ctx.save();
                ctx.strokeStyle = '#2563eb';
                ctx.lineWidth = 1.5;
                ctx.setLineDash([4, 3]);
                ctx.strokeRect(b.x - 4, b.y - 4, b.w + 8, b.h + 8);
                ctx.setLineDash([]);
                Object.values(iconHandles()).forEach(handle => {
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(handle.x - 4, handle.y - 4, 8, 8);
                    ctx.strokeStyle = '#2563eb';
                    ctx.strokeRect(handle.x - 4, handle.y - 4, 8, 8);
                });
                ctx.restore();
            }
        }
        if (ui && !LOGO.layers.length && !LOGO.icon) {
            // 空画布引导：告诉用户怎么加回来
            ctx.save();
            ctx.font = '15px "Microsoft YaHei",sans-serif';
            ctx.fillStyle = '#9ca3af';
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillText('画布为空 — 点击任意位置新建文字，或点「恢复默认」', w / 2, h / 2);
            ctx.restore();
            return;
        }
        LOGO.layers.forEach((ly, i) => {
            lyDrawText(ctx, ly);
            if (ui && LOGO.active === 'text' && i === LOGO.sel) {
                const b = lyBounds(ctx, ly);
                ctx.save();
                ctx.strokeStyle = '#2563eb';
                ctx.setLineDash([4, 3]);
                ctx.strokeRect(b.x - 5, b.y - 5, b.w + 10, b.h + 10);
                // 右上角 × 删除钮（点击即删本行）
                const hx = b.x + b.w + 5, hy = b.y - 5;
                ctx.setLineDash([]);
                ctx.fillStyle = '#dc2626';
                ctx.beginPath();
                ctx.arc(hx, hy, 9, 0, Math.PI * 2);
                ctx.fill();
                ctx.strokeStyle = '#fff';
                ctx.lineWidth = 2;
                ctx.beginPath();
                ctx.moveTo(hx - 3.5, hy - 3.5); ctx.lineTo(hx + 3.5, hy + 3.5);
                ctx.moveTo(hx + 3.5, hy - 3.5); ctx.lineTo(hx - 3.5, hy + 3.5);
                ctx.stroke();
                ctx.restore();
            }
        });
    }
    function renderLogo() { sctx.setTransform(DPR, 0, 0, DPR, 0, 0); drawLayers(sctx, VIEW_W, VIEW_H, true); }
    function syncIconControls() {
        const visible = !!LOGO.icon;
        $('imLIconRemove').classList.toggle('hidden', !visible);
        $('imLIconSizeWrap').classList.toggle('hidden', !visible);
        $('imLIconSize').value = LOGO.iconSize;
        $('imLIconSizeVal').textContent = LOGO.iconSize + 'px';
    }
    function syncPanel() {
        const ly = LOGO.active === 'text' ? LOGO.layers[LOGO.sel] : null;
        ['imLText', 'imLFont', 'imLSize', 'imLSpace', 'imLColor', 'imLBold'].forEach(id => $(id).disabled = !ly);
        document.querySelectorAll('#imLSwatches button, .im-align, #imLDirection button').forEach(control => { control.disabled = !ly; });
        $('imLPanel').classList.toggle('opacity-40', !ly);
        if (!ly) return;
        $('imLText').value = ly.text;
        $('imLFont').value = ly.font;
        $('imLSize').value = ly.size;
        $('imLSizeVal').textContent = ly.size + 'px';
        $('imLSpace').value = ly.spacing || 1;
        $('imLSpaceVal').textContent = '×' + (ly.spacing || 1).toFixed(1);
        $('imLColor').value = ly.color;
        $('imLBold').checked = ly.bold;
        document.querySelectorAll('#imLDirection [data-orientation]').forEach(control => {
            const active = control.dataset.orientation === (ly.orientation || 'horizontal');
            control.classList.toggle('bg-blue-600', active);
            control.classList.toggle('text-white', active);
            control.classList.toggle('bg-white', !active);
            control.classList.toggle('text-gray-700', !active);
            control.classList.toggle('hover:bg-blue-50', !active);
            control.classList.toggle('hover:text-primary', !active);
            control.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        stage.dataset.textOrientation = ly.orientation || 'horizontal';
    }
    // 导出：按图层包围盒并集裁剪（四周留 12px 边距），不带选中框
    function logoTrimBox() {
        let x1 = 1e9, y1 = 1e9, x2 = -1e9, y2 = -1e9;
        if (LOGO.icon) {
            x1 = LOGO.iconX; y1 = LOGO.iconY;
            x2 = LOGO.iconX + LOGO.iconSize; y2 = LOGO.iconY + LOGO.iconSize;
        }
        LOGO.layers.forEach(ly => {
            const b = lyBounds(sctx, ly);
            x1 = Math.min(x1, b.x); y1 = Math.min(y1, b.y);
            x2 = Math.max(x2, b.x + b.w); y2 = Math.max(y2, b.y + b.h);
        });
        const pad = 12;
        x1 = Math.max(0, x1 - pad); y1 = Math.max(0, y1 - pad);
        x2 = Math.min(VIEW_W, x2 + pad); y2 = Math.min(VIEW_H, y2 + pad);
        return { x: x1, y: y1, w: Math.max(1, Math.round(x2 - x1)), h: Math.max(1, Math.round(y2 - y1)) };
    }
    function logoExportCanvas() {
        if (!LOGO.layers.length && !LOGO.icon) return null;
        const box = logoTrimBox();
        const c = document.createElement('canvas');
        c.width = box.w * DPR; c.height = box.h * DPR;   // 2 倍图导出，高清屏不发虚
        const ctx = c.getContext('2d');
        ctx.scale(DPR, DPR);
        if ($('imLBgOn').checked) { ctx.fillStyle = $('imLBg').value; ctx.fillRect(0, 0, box.w, box.h); }
        ctx.translate(-box.x, -box.y);
        if (LOGO.icon) {
            ctx.drawImage(LOGO.icon, LOGO.iconX, LOGO.iconY, LOGO.iconSize, LOGO.iconSize);
        }
        LOGO.layers.forEach(ly => lyDrawText(ctx, ly));
        return c;
    }
    // 拖动（pointer 事件，CSS 缩放坐标换算）
    function stagePos(e) {
        const r = stage.getBoundingClientRect();
        return { x: (e.clientX - r.left) * VIEW_W / r.width, y: (e.clientY - r.top) * VIEW_H / r.height };
    }
    function removeIcon() {
        if (!LOGO.icon) return;
        if (LOGO.iconUrl) URL.revokeObjectURL(LOGO.iconUrl);
        LOGO.icon = null; LOGO.iconSvg = ''; LOGO.iconUrl = '';
        LOGO.drag = null;
        LOGO.active = LOGO.layers.length ? 'text' : null;
        syncIconControls(); syncPanel(); renderLogo();
    }
    function deleteSelected() {
        if (LOGO.active === 'icon') { removeIcon(); return; }
        if (!LOGO.layers.length) return;
        LOGO.layers.splice(LOGO.sel, 1);
        LOGO.sel = Math.max(0, LOGO.sel - 1);
        LOGO.active = LOGO.layers.length ? 'text' : (LOGO.icon ? 'icon' : null);
        syncPanel(); renderLogo();
    }
    stage.addEventListener('pointerdown', e => {
        const p = stagePos(e);
        LOGO.drag = null;
        const iconHandle = hitIconHandle(p);
        if (iconHandle) {
            const b = iconBounds();
            const anchors = {
                nw: { x: b.x + b.w, y: b.y + b.h },
                ne: { x: b.x, y: b.y + b.h },
                sw: { x: b.x + b.w, y: b.y },
                se: { x: b.x, y: b.y }
            };
            LOGO.drag = { type: 'icon-resize', corner: iconHandle.corner, anchor: anchors[iconHandle.corner] };
            stage.setPointerCapture(e.pointerId);
            return;
        }
        // 先判断是否点在选中框右上角的 × 删除钮上
        const selLy = LOGO.active === 'text' ? LOGO.layers[LOGO.sel] : null;
        if (selLy) {
            const b = lyBounds(sctx, selLy);
            if (Math.hypot(p.x - (b.x + b.w + 5), p.y - (b.y - 5)) <= 11) { deleteSelected(); return; }
        }
        let hit = false;
        for (let i = LOGO.layers.length - 1; i >= 0; i--) {
            const b = lyBounds(sctx, LOGO.layers[i]);
            if (p.x >= b.x - 5 && p.x <= b.x + b.w + 5 && p.y >= b.y - 5 && p.y <= b.y + b.h + 5) {
                LOGO.sel = i;
                LOGO.active = 'text';
                LOGO.drag = { type: 'text-move', dx: p.x - LOGO.layers[i].x, dy: p.y - LOGO.layers[i].y };
                hit = true;
                break;
            }
        }
        if (!hit && LOGO.icon) {
            const b = iconBounds();
            if (p.x >= b.x && p.x <= b.x + b.w && p.y >= b.y && p.y <= b.y + b.h) {
                LOGO.active = 'icon';
                LOGO.drag = { type: 'icon-move', dx: p.x - LOGO.iconX, dy: p.y - LOGO.iconY };
                hit = true;
            }
        }
        // 空画布：点哪里就在哪里新建一行文字
        if (!hit && !LOGO.layers.length && !LOGO.icon) {
            LOGO.layers.push({ text: '新文字', x: Math.round(p.x), y: Math.round(p.y), size: 28, color: '#4b5563', font: '"Microsoft YaHei","PingFang SC",sans-serif', bold: false, spacing: 1, orientation: 'horizontal' });
            LOGO.sel = 0;
            LOGO.active = 'text';
        }
        syncPanel(); renderLogo();
        stage.setPointerCapture(e.pointerId);
    });
    stage.addEventListener('pointermove', e => {
        const p = stagePos(e);
        if (!LOGO.drag) {
            const handle = hitIconHandle(p);
            if (handle) { stage.style.cursor = handle.cursor; return; }
            const b = LOGO.icon ? iconBounds() : null;
            stage.style.cursor = b && p.x >= b.x && p.x <= b.x + b.w && p.y >= b.y && p.y <= b.y + b.h ? 'move' : 'default';
            return;
        }
        if (LOGO.drag.type === 'text-move') {
            const ly = LOGO.layers[LOGO.sel];
            if (!ly) return;
            ly.x = Math.round(p.x - LOGO.drag.dx);
            ly.y = Math.round(p.y - LOGO.drag.dy);
        }
        if (LOGO.drag.type === 'icon-move') {
            LOGO.iconX = Math.round(Math.max(0, Math.min(VIEW_W - LOGO.iconSize, p.x - LOGO.drag.dx)));
            LOGO.iconY = Math.round(Math.max(0, Math.min(VIEW_H - LOGO.iconSize, p.y - LOGO.drag.dy)));
        }
        if (LOGO.drag.type === 'icon-resize') {
            const { corner, anchor } = LOGO.drag;
            const horizontal = corner.includes('w') ? anchor.x - p.x : p.x - anchor.x;
            const vertical = corner.includes('n') ? anchor.y - p.y : p.y - anchor.y;
            const maxSize = Math.min(
                corner.includes('w') ? anchor.x : VIEW_W - anchor.x,
                corner.includes('n') ? anchor.y : VIEW_H - anchor.y
            );
            const size = Math.round(Math.max(24, Math.min(maxSize, (horizontal + vertical) / 2)));
            LOGO.iconSize = size;
            LOGO.iconX = corner.includes('w') ? anchor.x - size : anchor.x;
            LOGO.iconY = corner.includes('n') ? anchor.y - size : anchor.y;
            syncIconControls();
        }
        renderLogo();
    });
    ['pointerup', 'pointercancel'].forEach(type => stage.addEventListener(type, () => { LOGO.drag = null; }));
    // 属性面板 → 选中图层
    $('imLText').addEventListener('input', () => { const ly = LOGO.layers[LOGO.sel]; if (ly) { ly.text = $('imLText').value; renderLogo(); } });
    $('imLFont').addEventListener('input', () => { const ly = LOGO.layers[LOGO.sel]; if (ly) { ly.font = $('imLFont').value; renderLogo(); } });
    $('imLSize').addEventListener('input', () => { const ly = LOGO.layers[LOGO.sel]; if (ly) { ly.size = +$('imLSize').value; $('imLSizeVal').textContent = ly.size + 'px'; renderLogo(); } });
    $('imLSpace').addEventListener('input', () => { const ly = LOGO.layers[LOGO.sel]; if (ly) { ly.spacing = +$('imLSpace').value; $('imLSpaceVal').textContent = '×' + ly.spacing.toFixed(1); renderLogo(); } });
    $('imLColor').addEventListener('input', () => { const ly = LOGO.layers[LOGO.sel]; if (ly) { ly.color = $('imLColor').value; renderLogo(); } });
    document.querySelectorAll('#imLSwatches [data-color]').forEach(b => b.onclick = () => {
        const ly = LOGO.layers[LOGO.sel];
        if (!ly) return;
        ly.color = b.dataset.color;
        $('imLColor').value = ly.color;
        renderLogo();
    });
    $('imLBold').addEventListener('input', () => { const ly = LOGO.layers[LOGO.sel]; if (ly) { ly.bold = $('imLBold').checked; renderLogo(); } });
    document.querySelectorAll('#imLDirection [data-orientation]').forEach(control => control.addEventListener('click', () => {
        const ly = LOGO.layers[LOGO.sel];
        if (!ly) return;
        ly.orientation = control.dataset.orientation === 'vertical' ? 'vertical' : 'horizontal';
        syncPanel(); renderLogo();
    }));
    ['imLBgOn', 'imLBg'].forEach(id => $(id).addEventListener('input', renderLogo));
    $('imLIconSize').addEventListener('input', () => {
        const oldSize = LOGO.iconSize;
        const centerX = LOGO.iconX + oldSize / 2, centerY = LOGO.iconY + oldSize / 2;
        LOGO.iconSize = +$('imLIconSize').value;
        LOGO.iconX = Math.round(Math.max(0, Math.min(VIEW_W - LOGO.iconSize, centerX - LOGO.iconSize / 2)));
        LOGO.iconY = Math.round(Math.max(0, Math.min(VIEW_H - LOGO.iconSize, centerY - LOGO.iconSize / 2)));
        LOGO.active = 'icon';
        $('imLIconSizeVal').textContent = LOGO.iconSize + 'px';
        syncPanel(); renderLogo();
    });
    $('imLIconRemove').onclick = removeIcon;
    // 快速定位：按包围盒对齐到画布（12px 边距；垂直中线取包围盒中心）
    document.querySelectorAll('.im-align').forEach(b => b.onclick = () => {
        const ly = LOGO.layers[LOGO.sel];
        if (!ly) return;
        const bd = lyBounds(sctx, ly), pad = 12;
        const h = b.dataset.alignH, v = b.dataset.alignV;
        if (h === 'left')   ly.x += (LOGO.icon ? LOGO.iconX + LOGO.iconSize + 16 : pad) - bd.x;
        if (h === 'center') ly.x += VIEW_W / 2 - (bd.x + bd.w / 2);
        if (h === 'right')  ly.x += VIEW_W - pad - (bd.x + bd.w);
        if (v === 'top')    ly.y += pad - bd.y;
        if (v === 'middle') ly.y += VIEW_H / 2 - (bd.y + bd.h / 2);
        if (v === 'bottom') ly.y += VIEW_H - pad - (bd.y + bd.h);
        ly.x = Math.round(ly.x); ly.y = Math.round(ly.y);
        renderLogo();
    });
    // 添加 / 删除图层
    $('imLAdd').onclick = () => {
        LOGO.layers.push({ text: '新文字', x: LOGO.icon ? LOGO.iconX + LOGO.iconSize + 16 : 24, y: Math.min(150, 40 + LOGO.layers.length * 44), size: 28, color: '#4b5563', font: '"Microsoft YaHei","PingFang SC",sans-serif', bold: false, spacing: 1, orientation: 'horizontal' });
        LOGO.sel = LOGO.layers.length - 1;
        LOGO.active = 'text';
        syncPanel(); renderLogo();
    };
    $('imLDel').onclick = deleteSelected;
    $('imLReset').onclick = () => {
        LOGO.layers = JSON.parse(JSON.stringify(LOGO_DEFAULTS));
        if (LOGO.icon) {
            const minTextX = LOGO.iconX + LOGO.iconSize + 16;
            LOGO.layers.forEach((layer, index) => {
                layer.x = minTextX;
                if (index === 0) layer.y = 84;
                if (index === 1) layer.y = 126;
            });
        }
        LOGO.sel = 0;
        LOGO.active = 'text';
        syncPanel(); renderLogo();
    };
    // Delete / Backspace 删除选中文字（输入框聚焦时不触发）
    document.addEventListener('keydown', e => {
        if (e.key !== 'Delete' && e.key !== 'Backspace') return;
        if ($('im-pane-logo').classList.contains('hidden')) return;
        if (/^(INPUT|SELECT|TEXTAREA)$/.test(document.activeElement && document.activeElement.tagName || '')) return;
        e.preventDefault();
        deleteSelected();
    });

    // ---------- 工具 ----------
    async function post(action, extra) {
        const fd = new FormData();
        fd.set('_token', TOKEN);
        fd.set('im_action', action);
        fd.set('master', (extra && extra.canvas ? extra.canvas : master).toDataURL('image/png'));
        if (extra) for (const k in extra) { if (k !== 'canvas') fd.set(k, extra[k]); }
        const r = await fetch(location.href, { method: 'POST', body: fd });
        let j;
        try { j = await r.json(); } catch (e) { return alert('服务器返回异常（HTTP ' + r.status + '）'), null; }
        if (j.code !== 0) { alert(j.msg || '操作失败'); return null; }
        return j;
    }
    function busy(btn, on) {
        btn.disabled = on;
        btn.classList.toggle('opacity-50', on);
    }

    // ---------- 事件 ----------
    document.querySelectorAll('#imTabs .im-tab').forEach(b => b.onclick = () => {
        document.querySelectorAll('#imTabs .im-tab').forEach(x => x.className = 'im-tab px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700');
        b.className = 'im-tab px-6 py-3 border-b-2 border-primary text-primary';
        document.querySelectorAll('.im-pane').forEach(p => p.classList.add('hidden'));
        $('im-pane-' + b.dataset.tab).classList.remove('hidden');
        const isStandalone = b.dataset.tab === 'logo' || b.dataset.tab === 'random' || b.dataset.tab === 'draw';
        $('imIconOut').classList.toggle('hidden', isStandalone);
        if (b.dataset.tab === 'logo') renderLogo();
        else if (!isStandalone) { mode = b.dataset.tab; renderMaster(); }
    });
    document.querySelectorAll('.im-shape').forEach(b => b.onclick = () => {
        document.querySelectorAll('.im-shape').forEach(x => x.className = 'im-shape px-4 py-1.5 border rounded text-sm');
        b.className = 'im-shape px-4 py-1.5 border rounded text-sm bg-primary text-white border-primary';
        renderMaster();
    });
    ['imText', 'imFont', 'imBold', 'imFg', 'imBg', 'imSize'].forEach(id => $(id).addEventListener('input', renderMaster));
    // 配色方案：一键套用 文字色 + 背景色
    document.querySelectorAll('#imCombos [data-fg]').forEach(b => b.onclick = () => {
        $('imFg').value = b.dataset.fg;
        $('imBg').value = b.dataset.bg;
        renderMaster();
    });

    // 上传
    const drop = $('imDrop');
    drop.onclick = () => $('imFile').click();
    drop.ondragover = e => { e.preventDefault(); drop.classList.add('border-primary'); };
    drop.ondragleave = () => drop.classList.remove('border-primary');
    drop.ondrop = e => { e.preventDefault(); drop.classList.remove('border-primary'); loadFile(e.dataTransfer.files[0]); };
    $('imFile').onchange = e => loadFile(e.target.files[0]);
    function loadFile(f) {
        if (!f || !/^image\/(png|jpeg|gif)$/.test(f.type)) return alert('请选择 PNG / JPG / GIF 图片');
        const img = new Image();
        img.onload = () => { uploadedImg = img; $('imDropName').textContent = f.name + '（' + img.width + '×' + img.height + '）'; renderMaster(); };
        img.src = URL.createObjectURL(f);
    }

    // 站内应用
    $('imIcoApply').onclick = async function () {
        if (mode === 'image' && !uploadedImg) return alert('请先上传图片');
        if (!confirm('将生成 favicon.ico（16/32/48 三档）保存到 uploads/brand，并通过前台 head 启用。继续？')) return;
        busy(this, true);
        const j = await post('apply_ico');
        busy(this, false);
        if (j) alert(j.msg || '已应用');
    };
    const applyBtn = $('imApply');
    if (applyBtn) applyBtn.onclick = async function () {
        if (mode === 'image' && !uploadedImg) return alert('请先上传图片');
        if (!confirm('将把 favicon.ico、apple-touch-icon.png 等图标文件写入网站根目录（覆盖同名文件），并在前台自动注入图标链接。继续？')) return;
        busy(this, true);
        const j = await post('apply_site');
        busy(this, false);
        if (j) { alert(j.msg || '已应用'); location.reload(); }
    };
    const logoApply = $('imLogoApply');
    if (logoApply) logoApply.onclick = async function () {
        const c = logoExportCanvas();
        if (!c) return alert('画布为空，请先添加图标或文字');
        if (!confirm('将把当前 LOGO 保存到 uploads/brand/ 并设为站点 LOGO。继续？')) return;
        busy(this, true);
        const j = await post('apply_logo', { canvas: c });
        busy(this, false);
        if (j) alert(j.msg + '（' + j.data.path + '）');
    };

    document.querySelectorAll('.im-random-use').forEach(btn => {
        btn.onclick = async function () {
            busy(this, true);
            try {
                const response = await fetch(this.dataset.src, { credentials: 'same-origin' });
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const svg = await response.text();
                const objectUrl = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml' }));
                const image = new Image();
                await new Promise((resolve, reject) => {
                    image.onload = resolve;
                    image.onerror = () => { URL.revokeObjectURL(objectUrl); reject(); };
                    image.src = objectUrl;
                });
                if (LOGO.iconUrl) URL.revokeObjectURL(LOGO.iconUrl);
                LOGO.icon = image;
                LOGO.iconSvg = svg;
                LOGO.iconUrl = objectUrl;
                LOGO.iconSize = 112;
                LOGO.iconX = 16;
                LOGO.iconY = Math.round((VIEW_H - LOGO.iconSize) / 2);
                if (!LOGO.layers.length) {
                    LOGO.layers.push({ text: <?php echo json_encode($imRandomName, JSON_UNESCAPED_UNICODE); ?>, x: 150, y: 92, size: 52, color: '#1f2937', font: '"Microsoft YaHei","PingFang SC",sans-serif', bold: true, spacing: 1, orientation: 'horizontal' });
                }
                LOGO.layers.forEach((layer, index) => {
                    layer.x = Math.max(150, layer.x);
                    if (index === 0) layer.y = 84;
                    if (index === 1) layer.y = 126;
                });
                LOGO.sel = 0;
                LOGO.active = 'icon';
                syncIconControls();
                syncPanel();
                document.querySelector('#imTabs .im-tab[data-tab="logo"]').click();
                renderLogo();
                stage.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (error) {
                alert('图标载入失败，请重新生成后再试');
            }
            busy(this, false);
        };
    });

    // 绘制画布与 LOGO 排版共用同一条“图标送入 LOGO”通道。
    window.logoMakerUseSvg = function (svg) {
        if (typeof svg !== 'string' || !svg.includes('<svg')) return;
        const button = document.querySelector('.im-random-use');
        if (!button) return alert('请先打开随机图标区域');
        const url = URL.createObjectURL(new Blob([svg], {type: 'image/svg+xml'}));
        button.dataset.src = url;
        button.click();
    };

    window.logoMakerApplyFaviconFromSvg = async function (svg) {
        if (typeof svg !== 'string' || !svg.includes('<svg')) return;
        if (!confirm('将把当前绘制图标生成 favicon.ico 保存到 uploads/brand，并通过前台 head 启用，继续吗？')) return;
        const image = new Image();
        const source = URL.createObjectURL(new Blob([svg], {type: 'image/svg+xml'}));
        try {
            await new Promise((resolve, reject) => {
                image.onload = resolve;
                image.onerror = reject;
                image.src = source;
            });
            const output = document.createElement('canvas');
            output.width = output.height = 512;
            const context = output.getContext('2d');
            context.clearRect(0, 0, 512, 512);
            context.drawImage(image, 0, 0, 512, 512);
            const result = await post('apply_ico', {canvas: output});
            if (result) alert(result.msg || '已应用为网站图标');
        } catch (error) {
            alert('图标生成失败，请重试');
        } finally {
            URL.revokeObjectURL(source);
        }
    };

    renderMaster();
    syncPanel();
    syncIconControls();
    renderLogo();
    // 支持 #logo / #image 锚点直达对应标签（站点设置页的制作链接会带锚点跳转）
    const hashTab = document.querySelector('#imTabs .im-tab[data-tab="' + location.hash.slice(1) + '"]');
    if (hashTab) hashTab.click();
    if (new URLSearchParams(location.search).has('random_tab')) {
        const randomTab = document.querySelector('#imTabs .im-tab[data-tab="random"]');
        if (randomTab) randomTab.click();
    }
})();
</script>
<script src="/assets/sortable/Sortable.min.js"></script>
<link rel="stylesheet" href="/plugins/logo-maker/draw-maker.css?v=20260818a">
<script src="/plugins/logo-maker/random-order.js?v=20260812a"></script>
<script src="/plugins/logo-maker/random-logo.js?v=20260817a1"></script>
<script src="/plugins/logo-maker/draw-maker.js?v=20260818a"></script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
