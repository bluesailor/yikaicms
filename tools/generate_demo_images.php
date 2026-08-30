<?php
/**
 * 生成随包演示图（SVG）。
 *
 * 为什么不用外链图库：种子原本用 picsum.photos/...?random=N，`random` 是无效参数，
 * 每次请求都返回**不同的随机照片**——演示站图文永远对不上，且依赖两次外网往返。
 * 这里改成随包生成的品牌色几何图：确定、离线、零外网依赖，总体积 < 200KB。
 *
 * 用法：php tools/generate_demo_images.php [--out=assets/images/demo]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }

$out = 'assets/images/demo';
foreach ($argv as $a) { if (str_starts_with((string) $a, '--out=')) { $out = substr((string) $a, 6); } }
$root = dirname(__DIR__);
$dir  = $root . '/' . trim($out, '/');
if (!is_dir($dir) && !mkdir($dir, 0755, true)) { fwrite(STDERR, "无法创建 $dir\n"); exit(1); }

/** 品牌色阶（由主色 #0F766E 派生）＋琥珀点缀 */
const INK    = '#0F172A';   // 深墨（页脚/深色块）
const INK2   = '#172554';   // 深蓝墨（渐变落点、CTA 遮罩）
const BRAND9 = '#1E3A8A';
const BRAND7 = '#2563EB';   // = 主色 primary_color
const BRAND5 = '#3B82F6';
const BRAND3 = '#93C5FD';
const BRAND1 = '#DBEAFE';
const AMBER  = '#F59E0B';   // 唯一暖色点缀

/** 确定性伪随机：同一 seed 永远同一张图 */
function rnd(int &$seed): float
{
    $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
    return $seed / 0x7FFFFFFF;
}
function pick(array $a, int &$seed): string { return $a[(int) floor(rnd($seed) * count($a)) % count($a)]; }
function svg(int $w, int $h, string $body): string
{
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '" role="img">' . $body . '</svg>';
}

/** A. 横幅：深色渐变 + 等高线 + 光晕 */
function banner(int $w, int $h, int $seed, int $variant = 0): string
{
    $g = '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
       . '<stop offset="0" stop-color="' . INK . '"/><stop offset="0.55" stop-color="' . INK2 . '"/><stop offset="1" stop-color="' . BRAND9 . '"/>'
       . '</linearGradient><radialGradient id="r"><stop offset="0" stop-color="' . BRAND5 . '" stop-opacity="0.55"/><stop offset="1" stop-color="' . BRAND5 . '" stop-opacity="0"/></radialGradient></defs>';
    $b = $g . '<rect width="' . $w . '" height="' . $h . '" fill="url(#g)"/>';
    $b .= '<circle cx="' . (int) ($w * (0.62 + rnd($seed) * 0.22)) . '" cy="' . (int) ($h * 0.32) . '" r="' . (int) ($h * 0.78) . '" fill="url(#r)"/>';
    // 等高线
    for ($i = 0; $i < 7; $i++) {
        $y = (int) ($h * (0.30 + $i * 0.11));
        $amp = 26 + rnd($seed) * 30;
        $d = 'M0 ' . $y;
        for ($x = 0; $x <= $w; $x += (int) ($w / 6)) {
            $d .= ' Q' . ($x + $w / 12) . ' ' . (int) ($y - $amp * (rnd($seed) * 2 - 1)) . ' ' . ($x + $w / 6) . ' ' . $y;
        }
        $b .= '<path d="' . $d . '" fill="none" stroke="' . BRAND3 . '" stroke-opacity="' . number_format(0.30 - $i * 0.035, 3) . '" stroke-width="1.5"/>';
    }
    // 细网格
    $b = '<defs><pattern id="v" width="64" height="' . $h . '" patternUnits="userSpaceOnUse">'
       . '<line x1="0" y1="0" x2="0" y2="' . $h . '" stroke="#fff" stroke-opacity="0.045"/></pattern></defs>' . $b
       . '<rect width="' . $w . '" height="' . $h . '" fill="url(#v)"/>';
    return svg($w, $h, $b);
}

/** B. 产品：浅底 + 点阵 + 四种器件形态轮换（避免每张图长得一样） */
function product(int $w, int $h, int $seed, int $variant = 0): string
{
    $accent = pick([BRAND7, BRAND9, BRAND5], $seed);
    $variant %= 4;
    // 点阵用 <pattern>：逐个画 <circle> 会让单文件涨到 30KB
    $b = '<defs><pattern id="d" width="28" height="28" patternUnits="userSpaceOnUse">'
       . '<circle cx="14" cy="14" r="1.6" fill="' . BRAND9 . '" fill-opacity="0.13"/></pattern></defs>'
       . '<rect width="' . $w . '" height="' . $h . '" fill="#F8FAFC"/>'
       . '<rect width="' . $w . '" height="' . $h . '" fill="url(#d)"/>';
    $cx = $w / 2; $cy = $h / 2; $s = min($w, $h) * 0.26;

    if ($variant === 0) {            // 等距立方体（模块/网关）
        $top   = "$cx," . ($cy - $s) . " " . ($cx + $s * 1.15) . "," . ($cy - $s * 0.42) . " $cx,$cy " . ($cx - $s * 1.15) . "," . ($cy - $s * 0.42);
        $left  = ($cx - $s * 1.15) . "," . ($cy - $s * 0.42) . " $cx,$cy $cx," . ($cy + $s) . " " . ($cx - $s * 1.15) . "," . ($cy + $s * 0.58);
        $right = ($cx + $s * 1.15) . "," . ($cy - $s * 0.42) . " $cx,$cy $cx," . ($cy + $s) . " " . ($cx + $s * 1.15) . "," . ($cy + $s * 0.58);
        $b .= '<polygon points="' . $top . '" fill="' . BRAND3 . '"/>'
            . '<polygon points="' . $left . '" fill="' . $accent . '"/>'
            . '<polygon points="' . $right . '" fill="' . BRAND9 . '"/>';
    } elseif ($variant === 1) {      // 同心环（传感器）
        for ($i = 4; $i >= 1; $i--) {
            $b .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . (int) ($s * (0.42 + $i * 0.36)) . '" fill="none" stroke="'
                . [BRAND9, $accent, BRAND5, BRAND3][$i % 4] . '" stroke-width="' . (3 + $i) . '" stroke-opacity="' . number_format(0.35 + $i * 0.14, 2) . '"/>';
        }
        $b .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . (int) ($s * 0.34) . '" fill="' . BRAND9 . '"/>';
    } elseif ($variant === 2) {      // 层板堆叠（控制器）
        for ($i = 3; $i >= 0; $i--) {
            $y = $cy - $s * 0.85 + $i * $s * 0.52;
            $b .= '<rect x="' . (int) ($cx - $s * 1.12) . '" y="' . (int) $y . '" width="' . (int) ($s * 2.24) . '" height="' . (int) ($s * 0.40)
                . '" rx="' . (int) ($s * 0.10) . '" fill="' . [BRAND9, $accent, BRAND5, BRAND3][$i] . '"/>';
        }
    } else {                          // 面板 + 端口（网关/主机）
        $b .= '<rect x="' . (int) ($cx - $s * 1.25) . '" y="' . (int) ($cy - $s * 0.80) . '" width="' . (int) ($s * 2.5) . '" height="' . (int) ($s * 1.6)
            . '" rx="' . (int) ($s * 0.16) . '" fill="' . $accent . '"/>';
        for ($i = 0; $i < 4; $i++) {
            $b .= '<rect x="' . (int) ($cx - $s * 0.95 + $i * $s * 0.55) . '" y="' . (int) ($cy + $s * 0.10) . '" width="' . (int) ($s * 0.34)
                . '" height="' . (int) ($s * 0.34) . '" rx="3" fill="' . BRAND1 . '" fill-opacity="0.85"/>';
        }
        $b .= '<rect x="' . (int) ($cx - $s * 0.95) . '" y="' . (int) ($cy - $s * 0.48) . '" width="' . (int) ($s * 1.5) . '" height="' . (int) ($s * 0.22) . '" rx="3" fill="' . BRAND3 . '"/>';
    }
    $b .= '<circle cx="' . (int) ($cx + $s * 0.95) . '" cy="' . (int) ($cy - $s * 0.95) . '" r="' . (int) ($s * 0.19) . '" fill="' . AMBER . '"/>';
    return svg($w, $h, $b);
}

/** C. 文章/案例：深底 + 三种图形轮换 */
function article(int $w, int $h, int $seed, int $variant = 0): string
{
    $b = '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . INK . '"/><stop offset="1" stop-color="' . INK2 . '"/></linearGradient></defs>'
       . '<rect width="' . $w . '" height="' . $h . '" fill="url(#g)"/>';
    $variant %= 3;

    if ($variant === 0) {            // 节点网络
        $pts = [];
        for ($i = 0; $i < 13; $i++) { $pts[] = [(int) (rnd($seed) * $w), (int) (rnd($seed) * $h)]; }
        foreach ($pts as $i => $p) {
            foreach ($pts as $j => $q) {
                if ($j <= $i) { continue; }
                if (sqrt(($p[0] - $q[0]) ** 2 + ($p[1] - $q[1]) ** 2) < min($w, $h) * 0.46) {
                    $b .= '<line x1="' . $p[0] . '" y1="' . $p[1] . '" x2="' . $q[0] . '" y2="' . $q[1] . '" stroke="' . BRAND5 . '" stroke-opacity="0.26" stroke-width="1"/>';
                }
            }
        }
        foreach ($pts as $k => $p) {
            $b .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="' . ($k % 4 === 0 ? 7 : 4) . '" fill="' . ($k % 5 === 0 ? AMBER : BRAND3) . '" fill-opacity="0.9"/>';
        }
    } elseif ($variant === 1) {      // 数据柱 + 基线
        $n = 9; $bw = $w / ($n * 1.8);
        for ($i = 0; $i < $n; $i++) {
            $bh = $h * (0.16 + rnd($seed) * 0.52);
            $x = $w * 0.10 + $i * ($w * 0.80 / $n);
            $b .= '<rect x="' . (int) $x . '" y="' . (int) ($h * 0.80 - $bh) . '" width="' . (int) $bw . '" height="' . (int) $bh
                . '" rx="3" fill="' . ($i % 4 === 0 ? AMBER : BRAND5) . '" fill-opacity="' . number_format(0.45 + $i * 0.05, 2) . '"/>';
        }
        $b .= '<line x1="0" y1="' . (int) ($h * 0.80) . '" x2="' . $w . '" y2="' . (int) ($h * 0.80) . '" stroke="' . BRAND3 . '" stroke-opacity="0.35"/>';
    } else {                          // 斜向带状
        for ($i = 0; $i < 6; $i++) {
            $o = $i * $w * 0.19 - $w * 0.25;
            $b .= '<polygon points="' . (int) $o . ',' . $h . ' ' . (int) ($o + $w * 0.16) . ',' . $h . ' '
                . (int) ($o + $w * 0.46) . ',0 ' . (int) ($o + $w * 0.30) . ',0" fill="'
                . [BRAND9, BRAND7, BRAND5][$i % 3] . '" fill-opacity="' . number_format(0.20 + $i * 0.07, 2) . '"/>';
        }
        $b .= '<circle cx="' . (int) ($w * 0.78) . '" cy="' . (int) ($h * 0.26) . '" r="' . (int) ($h * 0.11) . '" fill="' . AMBER . '" fill-opacity="0.9"/>';
    }
    return svg($w, $h, $b);
}

/**
 * E. 关于我们备用构图（当前出厂用实拍照片 about-office.jpg，本函数保留备用）。
 * @psalm-suppress UnusedFunction
 */
function about(int $w, int $h, int $seed, int $variant = 0): string
{
    $b = '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
       . '<stop offset="0" stop-color="' . INK2 . '"/><stop offset="1" stop-color="' . BRAND9 . '"/></linearGradient>'
       . '<pattern id="d" width="26" height="26" patternUnits="userSpaceOnUse">'
       . '<circle cx="13" cy="13" r="1.4" fill="#fff" fill-opacity="0.10"/></pattern></defs>'
       . '<rect width="' . $w . '" height="' . $h . '" fill="url(#g)"/>'
       . '<rect width="' . $w . '" height="' . $h . '" fill="url(#d)"/>';
    // 斜切色块，铺满右下
    $b .= '<polygon points="' . (int) ($w * 0.42) . ',' . $h . ' ' . $w . ',' . (int) ($h * 0.18) . ' ' . $w . ',' . $h . '" fill="' . BRAND7 . '" fill-opacity="0.55"/>';
    $b .= '<polygon points="' . (int) ($w * 0.68) . ',' . $h . ' ' . $w . ',' . (int) ($h * 0.52) . ' ' . $w . ',' . $h . '" fill="' . BRAND5 . '" fill-opacity="0.45"/>';
    // 左上三条渐次的横条，暗示「层级/体系」
    for ($i = 0; $i < 3; $i++) {
        $b .= '<rect x="' . (int) ($w * 0.08) . '" y="' . (int) ($h * (0.16 + $i * 0.13)) . '" width="' . (int) ($w * (0.46 - $i * 0.10))
            . '" height="' . (int) ($h * 0.055) . '" rx="' . (int) ($h * 0.028) . '" fill="' . BRAND3 . '" fill-opacity="' . number_format(0.85 - $i * 0.22, 2) . '"/>';
    }
    // 环形与琥珀点，做视觉落点
    $b .= '<circle cx="' . (int) ($w * 0.30) . '" cy="' . (int) ($h * 0.70) . '" r="' . (int) ($h * 0.17) . '" fill="none" stroke="' . BRAND3 . '" stroke-width="4" stroke-opacity="0.75"/>';
    $b .= '<circle cx="' . (int) ($w * 0.30) . '" cy="' . (int) ($h * 0.70) . '" r="' . (int) ($h * 0.07) . '" fill="' . AMBER . '"/>';
    return svg($w, $h, $b);
}

/** D. 相册：层叠弧线 */
function album(int $w, int $h, int $seed, int $variant = 0): string
{
    $b = '<rect width="' . $w . '" height="' . $h . '" fill="' . BRAND1 . '"/>';
    $cx = (int) ($w * (0.24 + rnd($seed) * 0.5)); $cy = (int) ($h * 1.02);
    $cols = [BRAND9, BRAND7, BRAND5, BRAND3];
    for ($i = 5; $i >= 1; $i--) {
        $r = (int) (min($w, $h) * (0.28 + $i * 0.20));
        $b .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="' . $cols[$i % 4] . '" fill-opacity="' . number_format(0.20 + $i * 0.10, 2) . '"/>';
    }
    $b .= '<rect width="' . $w . '" height="' . $h . '" fill="none" stroke="' . BRAND9 . '" stroke-opacity="0.10" stroke-width="2"/>';
    return svg($w, $h, $b);
}

$specs = [];
// variant 按编号轮换，保证同一区块里相邻几张图形态不同
foreach ([1, 2, 3] as $i => $n)                  { $specs["banner-$n"]  = [1920, 600, 'banner',  $n * 7717, $i]; }
foreach (range(101, 106) as $i => $n)            { $specs["product-$n"] = [600, 600, 'product',  $n * 3313, $i]; }
foreach ([11,12,201,202,203,204,205,206,210,301] as $i => $n){ $specs["article-$n"] = [800, 500, 'article', $n * 5171, $i]; }
foreach (range(401, 406) as $i => $n)            { $specs["album-$n"]   = [600, 400, 'album',    $n * 9391, $i]; }
foreach (array_values(['smart-factory' => 4271, 'retail-chain' => 6133]) as $i => $sd) { $specs['case-' . ['smart-factory','retail-chain'][$i]] = [1200, 600, 'article', $sd, $i + 1]; }
$specs['stat-bg'] = [1920, 400, 'banner', 8087, 0];

$total = 0; $bytes = 0;
foreach ($specs as $name => [$w, $h, $fn, $seed, $variant]) {
    $svg = $fn($w, $h, $seed, $variant);
    $path = "$dir/$name.svg";
    if (file_put_contents($path, $svg) === false) { fwrite(STDERR, "写入失败 $path\n"); exit(1); }
    $total++; $bytes += strlen($svg);
}
printf("生成 %d 个 SVG，合计 %.1f KB → %s\n", $total, $bytes / 1024, $out);
