<?php
/**
 * 图标工坊 - 管理页面
 * 由 /admin/plugin_page.php?plugin=icon-maker 加载（已 checkLogin + CSRF）。
 *
 * 分工：浏览器端 canvas 负责渲染（能用客户端本地字体，中文字体无需服务端内置），
 *       输出 512px PNG 母版；服务端 GD 负责缩放各尺寸并打包 ICO / 全套图标包。
 *
 * 免费：文字/图片生成 favicon.ico 并直接应用为站点图标（写主机根目录，不提供下载）、
 *       LOGO PNG 下载、LOGO 一键设为站点 LOGO。
 * 全套能力（自 2026-08-04 起免费）：全套图标包（iOS/Android/PWA）、
 *       一键应用到本站（写根目录 + 前台 head 注入）、LOGO SVG 导出。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once __DIR__ . '/lib.php';   // im_scaled / im_png / im_ico（纯 GD，无 CMS 依赖）

// 2026-08-04：图标工坊全部功能免费开放，不再按授权分层（保留变量以免大改模板分支）
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
$imAction = (string) ($_POST['im_action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $imAction !== '') {
    if (!function_exists('imagecreatetruecolor')) {
        error('服务器缺少 GD 扩展，无法生成图标');
    }

    // 免费：下载 16×16 的 favicon.ico（本站使用走「直接用作站点图标」，此下载给要拿文件的用户）
    if ($imAction === 'build_ico') {
        $master = im_master();
        $ico = im_ico($master, [16]);
        imagedestroy($master);
        success(['file' => base64_encode($ico), 'name' => 'favicon.ico']);
    }

    // 免费：favicon.ico 直接写入站点根目录并启用（生成即上线）
    if ($imAction === 'apply_ico') {
        if (!is_writable(ROOT_PATH) && !is_writable(ROOT_PATH . '/favicon.ico')) {
            error('网站根目录不可写，无法直接应用，请下载后手动上传');
        }
        $master = im_master();
        $ico = im_ico($master);
        imagedestroy($master);
        if (@file_put_contents(ROOT_PATH . '/favicon.ico', $ico) === false) {
            error('favicon.ico 写入失败，请检查根目录权限');
        }
        // 带版本参数刷新浏览器的 favicon 缓存
        settingModel()->set('site_favicon', '/favicon.ico?v=' . time(), 'basic');
        adminLog('plugin', 'favicon_apply', '图标工坊：favicon.ico 应用到本站');
        success([], '已生成并应用为站点图标（浏览器标签页图标可能要过一会儿才刷新）');
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
        adminLog('plugin', 'logo_apply', '图标工坊：设为站点 LOGO ' . $rel);
        success(['path' => $rel], '已设为站点 LOGO');
    }

    // 专业版动作统一闸门
    if (!$imHasPro) {
        error('该功能需要专业版授权，请前往「授权管理」激活');
    }

    // Pro：全套图标包 zip
    if ($imAction === 'build_pack') {
        if (!class_exists('ZipArchive')) {
            error('服务器缺少 ZipArchive 扩展');
        }
        $master = im_master();
        $files = im_pack_files($master);
        imagedestroy($master);
        $files['安装说明.txt'] = "全套图标包使用说明\n===================\n\n1. 把本压缩包内所有文件上传到网站根目录；\n2. 在页面 <head> 中加入：\n\n"
            . '<link rel="icon" href="/favicon.ico">' . "\n"
            . '<link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">' . "\n"
            . '<link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">' . "\n"
            . '<link rel="manifest" href="/site.webmanifest">' . "\n\n"
            . "YikaiCMS 用户可直接用「一键应用到本站」，无需手动操作。\n";
        @mkdir(ROOT_PATH . '/storage/tmp', 0755, true);
        $tmp = ROOT_PATH . '/storage/tmp/iconpack-' . bin2hex(random_bytes(6)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            error('临时压缩包创建失败（storage/tmp 不可写？）');
        }
        foreach ($files as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        success(['file' => base64_encode($bytes), 'name' => 'icon-pack.zip']);
    }

    // Pro：一键应用到本站（写根目录 + 打开前台 head 注入）
    if ($imAction === 'apply_site') {
        if (!is_writable(ROOT_PATH)) {
            error('网站根目录不可写，无法应用，请下载图标包后手动上传');
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
        settingModel()->set('iconmaker_applied', '1', 'plugin');
        settingModel()->set('iconmaker_applied_at', (string) time(), 'plugin');
        adminLog('plugin', 'icon_apply', '图标工坊：全套图标应用到本站（' . count($written) . ' 个文件）');
        success([], '已应用：' . implode('、', $written) . '。前台已自动注入图标链接。');
    }

    error('未知操作');
}

$imApplied   = (string) config('iconmaker_applied', '') === '1';
$imAppliedAt = (int) config('iconmaker_applied_at', 0);

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="space-y-6">

    <!-- Tab 导航 -->
    <div class="bg-white rounded-lg shadow">
        <div class="flex border-b text-sm font-medium" id="imTabs">
            <button data-tab="logo"  class="im-tab px-6 py-3 border-b-2 border-primary text-primary"><i class="ti ti-badge mr-1"></i>LOGO 制作</button>
            <button data-tab="text"  class="im-tab px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700"><i class="ti ti-typography mr-1"></i>图标(favicon)</button>
            <?php /* 「图片转图标」暂不开放：入口隐藏，面板与上传逻辑保留，放开时恢复此按钮即可
            <button data-tab="image" class="im-tab px-6 py-3 border-b-2 border-transparent text-gray-500 hover:text-gray-700"><i class="ti ti-photo mr-1"></i>图片转图标(favicon)</button>
            */ ?>
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

            <!-- 文字 LOGO：画布式编辑器（多文字元素，独立样式，拖动定位） -->
            <div id="im-pane-logo" class="im-pane space-y-4">
                <div class="flex flex-wrap items-center gap-3 text-sm">
                    <button id="imLAdd" class="px-3 py-1.5 bg-primary hover:bg-secondary text-white rounded transition"><i class="ti ti-plus mr-0.5"></i>添加文字</button>
                    <button id="imLDel" class="px-3 py-1.5 bg-white border text-gray-600 hover:text-red-500 hover:border-red-300 rounded transition"><i class="ti ti-trash mr-0.5"></i>删除选中</button>
                    <button id="imLReset" class="px-3 py-1.5 bg-white border text-gray-600 hover:text-primary hover:border-primary rounded transition" title="恢复 站名 + 网址 两行示例"><i class="ti ti-restore mr-0.5"></i>恢复默认</button>
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
                        <p class="text-xs text-gray-400 mt-1.5"><i class="ti ti-hand-move"></i> 点击文字选中，按住拖动调整位置；导出时自动裁掉四周空白</p>
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
                    <button id="imLogoDl" class="px-5 py-2 bg-primary hover:bg-secondary text-white rounded text-sm transition"><i class="ti ti-download mr-1"></i>下载 LOGO PNG</button>
                    <button id="imLogoApply" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white rounded text-sm transition"><i class="ti ti-check mr-1"></i>一键设为站点 LOGO</button>
                    <?php if ($imHasPro): ?>
                        <button id="imLogoSvg" class="px-5 py-2 bg-white border border-primary text-primary hover:bg-blue-50 rounded text-sm transition"><i class="ti ti-file-vector mr-1"></i>导出 SVG</button>
                    <?php endif; ?>
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
                    <button id="imIcoDl" class="px-5 py-2 bg-white border border-primary text-primary hover:bg-blue-50 rounded text-sm transition"><i class="ti ti-download mr-1"></i>下载 favicon.ico（16×16）</button>
                    <?php if ($imHasPro): ?>
                        <button id="imPackDl" class="px-5 py-2 bg-white border border-primary text-primary hover:bg-blue-50 rounded text-sm transition"><i class="ti ti-packages mr-1"></i>下载全套图标包</button>
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
    <!-- 专业版引导（参照 stats 插件模式） -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center gap-2 mb-3">
            <h2 class="font-bold text-gray-800">全套图标包 &amp; 一键应用</h2>
            <span class="text-xs px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">专业版</span>
        </div>
        <div class="bg-gray-50 border border-dashed rounded-lg p-5 text-center">
            <p class="text-sm text-gray-600 mb-1">升级<b>专业版</b>解锁：</p>
            <p class="text-sm text-gray-600 mb-3">全套图标包（favicon + iOS + Android + PWA manifest）· 一键应用到本站并自动注入前台链接 · LOGO SVG 导出</p>
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
        layers: [
            { text: <?php echo json_encode(configRawLang('site_name', 'Yikai CMS')); ?>, x: 24, y: 84, size: 56, color: '#1f2937', font: '"Microsoft YaHei","PingFang SC",sans-serif', bold: true, spacing: 1 },
            <?php
            // 第二行默认放站点网址：site_url 优先，取不到用当前访问域名
            $imHost = (string) parse_url((string) config('site_url', ''), PHP_URL_HOST) ?: (string) ($_SERVER['HTTP_HOST'] ?? 'www.example.com');
            ?>
            { text: <?php echo json_encode($imHost); ?>, x: 26, y: 126, size: 20, color: '#9ca3af', font: '"Microsoft YaHei","PingFang SC",sans-serif', bold: false, spacing: 1 }
        ],
        sel: 0, drag: null
    };
    const LOGO_DEFAULTS = JSON.parse(JSON.stringify(LOGO.layers));   // 「恢复默认」用的初始两行快照
    function lyFont(ly) { return (ly.bold ? 'bold ' : '') + ly.size + 'px ' + ly.font; }
    // 字间距：倍率 1 = 正常；每 +0.1 增加 0.1 字号的字符间隙（逐字绘制实现，兼容所有浏览器）
    function lySpacing(ly) { return Math.round(((ly.spacing || 1) - 1) * ly.size * 10) / 10; }
    function lyTextWidth(ctx, ly) {
        ctx.font = lyFont(ly);
        const extra = lySpacing(ly);
        if (!extra) return ctx.measureText(ly.text).width;
        let w = 0, n = 0;
        for (const ch of ly.text) { w += ctx.measureText(ch).width + extra; n++; }
        return n ? w - extra : 0;
    }
    function lyDrawText(ctx, ly) {
        ctx.font = lyFont(ly);
        ctx.fillStyle = ly.color;
        ctx.textBaseline = 'alphabetic';
        const extra = lySpacing(ly);
        if (!extra) { ctx.fillText(ly.text, ly.x, ly.y); return; }   // 正常间距走整串绘制，保留字距微调
        let x = ly.x;
        for (const ch of ly.text) { ctx.fillText(ch, x, ly.y); x += ctx.measureText(ch).width + extra; }
    }
    // 图层包围盒（近似：基线上方 0.8 字号，下方 0.25 字号容纳降部）
    function lyBounds(ctx, ly) {
        return { x: ly.x, y: ly.y - ly.size * 0.8, w: lyTextWidth(ctx, ly), h: ly.size * 1.05 };
    }
    function drawLayers(ctx, w, h, ui) {
        ctx.clearRect(0, 0, w, h);
        if ($('imLBgOn').checked) { ctx.fillStyle = $('imLBg').value; ctx.fillRect(0, 0, w, h); }
        if (ui && !LOGO.layers.length) {
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
            if (ui && i === LOGO.sel) {
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
    function syncPanel() {
        const ly = LOGO.layers[LOGO.sel];
        ['imLText', 'imLFont', 'imLSize', 'imLSpace', 'imLColor', 'imLBold'].forEach(id => $(id).disabled = !ly);
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
    }
    // 导出：按图层包围盒并集裁剪（四周留 12px 边距），不带选中框
    function logoTrimBox() {
        let x1 = 1e9, y1 = 1e9, x2 = -1e9, y2 = -1e9;
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
        if (!LOGO.layers.length) return null;
        const box = logoTrimBox();
        const c = document.createElement('canvas');
        c.width = box.w * DPR; c.height = box.h * DPR;   // 2 倍图导出，高清屏不发虚
        const ctx = c.getContext('2d');
        ctx.scale(DPR, DPR);
        if ($('imLBgOn').checked) { ctx.fillStyle = $('imLBg').value; ctx.fillRect(0, 0, box.w, box.h); }
        ctx.translate(-box.x, -box.y);
        LOGO.layers.forEach(ly => lyDrawText(ctx, ly));
        return c;
    }
    // 拖动（pointer 事件，CSS 缩放坐标换算）
    function stagePos(e) {
        const r = stage.getBoundingClientRect();
        return { x: (e.clientX - r.left) * VIEW_W / r.width, y: (e.clientY - r.top) * VIEW_H / r.height };
    }
    function deleteSelected() {
        if (!LOGO.layers.length) return;
        LOGO.layers.splice(LOGO.sel, 1);
        LOGO.sel = Math.max(0, LOGO.sel - 1);
        syncPanel(); renderLogo();
    }
    stage.addEventListener('pointerdown', e => {
        const p = stagePos(e);
        LOGO.drag = null;
        // 先判断是否点在选中框右上角的 × 删除钮上
        const selLy = LOGO.layers[LOGO.sel];
        if (selLy) {
            const b = lyBounds(sctx, selLy);
            if (Math.hypot(p.x - (b.x + b.w + 5), p.y - (b.y - 5)) <= 11) { deleteSelected(); return; }
        }
        for (let i = LOGO.layers.length - 1; i >= 0; i--) {
            const b = lyBounds(sctx, LOGO.layers[i]);
            if (p.x >= b.x - 5 && p.x <= b.x + b.w + 5 && p.y >= b.y - 5 && p.y <= b.y + b.h + 5) {
                LOGO.sel = i;
                LOGO.drag = { dx: p.x - LOGO.layers[i].x, dy: p.y - LOGO.layers[i].y };
                break;
            }
        }
        // 空画布：点哪里就在哪里新建一行文字
        if (!LOGO.layers.length) {
            LOGO.layers.push({ text: '新文字', x: Math.round(p.x), y: Math.round(p.y), size: 28, color: '#4b5563', font: '"Microsoft YaHei","PingFang SC",sans-serif', bold: false, spacing: 1 });
            LOGO.sel = 0;
        }
        syncPanel(); renderLogo();
        stage.setPointerCapture(e.pointerId);
    });
    stage.addEventListener('pointermove', e => {
        if (!LOGO.drag) return;
        const p = stagePos(e), ly = LOGO.layers[LOGO.sel];
        if (!ly) return;
        ly.x = Math.round(p.x - LOGO.drag.dx);
        ly.y = Math.round(p.y - LOGO.drag.dy);
        renderLogo();
    });
    stage.addEventListener('pointerup', () => { LOGO.drag = null; });
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
    ['imLBgOn', 'imLBg'].forEach(id => $(id).addEventListener('input', renderLogo));
    // 快速定位：按包围盒对齐到画布（12px 边距；垂直中线取包围盒中心）
    document.querySelectorAll('.im-align').forEach(b => b.onclick = () => {
        const ly = LOGO.layers[LOGO.sel];
        if (!ly) return;
        const bd = lyBounds(sctx, ly), pad = 12;
        const h = b.dataset.alignH, v = b.dataset.alignV;
        if (h === 'left')   ly.x = pad;
        if (h === 'center') ly.x = Math.round((VIEW_W - bd.w) / 2);
        if (h === 'right')  ly.x = Math.round(VIEW_W - pad - bd.w);
        if (v === 'top')    ly.y = Math.round(pad + ly.size * 0.8);
        if (v === 'middle') ly.y = Math.round(VIEW_H / 2 + ly.size * 0.275);
        if (v === 'bottom') ly.y = Math.round(VIEW_H - pad - ly.size * 0.25);
        renderLogo();
    });
    // 添加 / 删除图层
    $('imLAdd').onclick = () => {
        LOGO.layers.push({ text: '新文字', x: 24, y: Math.min(150, 40 + LOGO.layers.length * 44), size: 28, color: '#4b5563', font: '"Microsoft YaHei","PingFang SC",sans-serif', bold: false, spacing: 1 });
        LOGO.sel = LOGO.layers.length - 1;
        syncPanel(); renderLogo();
    };
    $('imLDel').onclick = deleteSelected;
    $('imLReset').onclick = () => {
        LOGO.layers = JSON.parse(JSON.stringify(LOGO_DEFAULTS));
        LOGO.sel = 0;
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
    function dlBlob(bytes, name, type) {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([bytes], { type: type }));
        a.download = name;
        a.click();
        URL.revokeObjectURL(a.href);
    }
    function b64bytes(b64) {
        const bin = atob(b64), arr = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        return arr;
    }
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
        const isLogo = b.dataset.tab === 'logo';
        $('imIconOut').classList.toggle('hidden', isLogo);
        if (!isLogo) { mode = b.dataset.tab; renderMaster(); } else renderLogo();
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

    // 下载 / 应用
    $('imIcoDl').onclick = async function () {
        if (mode === 'image' && !uploadedImg) return alert('请先上传图片');
        busy(this, true);
        const j = await post('build_ico');
        busy(this, false);
        if (j) dlBlob(b64bytes(j.data.file), j.data.name, 'image/x-icon');
    };
    $('imIcoApply').onclick = async function () {
        if (mode === 'image' && !uploadedImg) return alert('请先上传图片');
        if (!confirm('将生成 favicon.ico（16/32/48 三档）写入网站根目录并启用为站点图标。继续？')) return;
        busy(this, true);
        const j = await post('apply_ico');
        busy(this, false);
        if (j) alert(j.msg || '已应用');
    };
    const packBtn = $('imPackDl');
    if (packBtn) packBtn.onclick = async function () {
        if (mode === 'image' && !uploadedImg) return alert('请先上传图片');
        busy(this, true);
        const j = await post('build_pack');
        busy(this, false);
        if (j) dlBlob(b64bytes(j.data.file), j.data.name, 'application/zip');
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
    $('imLogoDl').onclick = () => {
        const c = logoExportCanvas();
        if (!c) return alert('画布为空，请先添加文字');
        c.toBlob(b => dlBlob(b, 'logo.png', 'image/png'));
    };
    const logoSvg = $('imLogoSvg');
    if (logoSvg) logoSvg.onclick = () => {
        if (!LOGO.layers.length) return alert('画布为空，请先添加文字');
        const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        const box = logoTrimBox();
        const texts = LOGO.layers.map(ly =>
            '<text x="' + (ly.x - box.x) + '" y="' + (ly.y - box.y) + '" font-size="' + ly.size + '"'
            + (ly.bold ? ' font-weight="bold"' : '')
            + (lySpacing(ly) ? ' letter-spacing="' + lySpacing(ly) + '"' : '')
            + ' font-family=\'' + esc(ly.font) + '\' fill="' + esc(ly.color) + '">' + esc(ly.text) + '</text>'
        ).join('');
        const bg = $('imLBgOn').checked ? '<rect width="100%" height="100%" fill="' + esc($('imLBg').value) + '"/>' : '';
        dlBlob('<svg xmlns="http://www.w3.org/2000/svg" width="' + box.w + '" height="' + box.h + '">' + bg + texts + '</svg>',
            'logo.svg', 'image/svg+xml');
    };
    const logoApply = $('imLogoApply');
    if (logoApply) logoApply.onclick = async function () {
        const c = logoExportCanvas();
        if (!c) return alert('画布为空，请先添加文字');
        if (!confirm('将把当前 LOGO 保存到 uploads/brand/ 并设为站点 LOGO。继续？')) return;
        busy(this, true);
        const j = await post('apply_logo', { canvas: c });
        busy(this, false);
        if (j) alert(j.msg + '（' + j.data.path + '）');
    };

    renderMaster();
    syncPanel();
    renderLogo();
    // 支持 #logo / #image 锚点直达对应标签（站点设置页的制作链接会带锚点跳转）
    const hashTab = document.querySelector('#imTabs .im-tab[data-tab="' + location.hash.slice(1) + '"]');
    if (hashTab) hashTab.click();
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
