<?php

declare(strict_types=1);

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD extension is required.\n");
    exit(1);
}

const WIDTH = 1200;
const HEIGHT = 525;

$root = dirname(__DIR__);
$output = $root . '/assets/images/blox-templates';
$font = findFont(false);
$fontBold = findFont(true);

/** @return GdImage */
function canvas(string $background = '#ffffff'): GdImage
{
    $image = imagecreatetruecolor(WIDTH, HEIGHT);
    imageantialias($image, true);
    imagefill($image, 0, 0, color($image, $background));
    return $image;
}

function color(GdImage $image, string $hex): int
{
    $hex = ltrim($hex, '#');
    return imagecolorallocate(
        $image,
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2))
    );
}

function findFont(bool $bold): string
{
    $candidates = $bold
        ? ['C:/Windows/Fonts/msyhbd.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf']
        : ['C:/Windows/Fonts/msyh.ttc', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'];
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException('A TrueType font is required.');
}

function text(GdImage $image, string $value, int $x, int $y, int $size, string $hex, bool $bold = false): void
{
    global $font, $fontBold;
    imagettftext($image, $size, 0, $x, $y, color($image, $hex), $bold ? $fontBold : $font, $value);
}

function paragraph(GdImage $image, string $value, int $x, int $y, int $width, int $size = 16, int $lineHeight = 30): void
{
    global $font;
    $line = '';
    foreach ((array) preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) as $character) {
        $candidate = $line . $character;
        $box = imagettfbbox($size, 0, $font, $candidate);
        if ($line !== '' && ($box[2] - $box[0]) > $width) {
            text($image, $line, $x, $y, $size, '#64748b');
            $line = $character;
            $y += $lineHeight;
        } else {
            $line = $candidate;
        }
    }
    if ($line !== '') {
        text($image, $line, $x, $y, $size, '#64748b');
    }
}

function photo(GdImage $image, string $path, int $x, int $y, int $width, int $height): void
{
    $source = @imagecreatefromjpeg($path);
    if (!$source instanceof GdImage) {
        return;
    }
    $sourceWidth = imagesx($source);
    $sourceHeight = imagesy($source);
    $scale = max($width / $sourceWidth, $height / $sourceHeight);
    $cropWidth = (int) round($width / $scale);
    $cropHeight = (int) round($height / $scale);
    $sourceX = max(0, (int) (($sourceWidth - $cropWidth) / 2));
    $sourceY = max(0, (int) (($sourceHeight - $cropHeight) / 2));
    imagecopyresampled($image, $source, $x, $y, $sourceX, $sourceY, $width, $height, $cropWidth, $cropHeight);
    imagedestroy($source);
}

function save(GdImage $image, string $path): void
{
    imagepng($image, $path, 8);
    imagedestroy($image);
    echo basename($path) . PHP_EOL;
}

$image = canvas();
photo($image, $root . '/assets/images/demo/product-101.svg', 48, 64, 520, 397);
text($image, '把复杂问题说清楚', 638, 150, 31, '#0f172a', true);
imagefilledrectangle($image, 638, 174, 694, 178, color($image, '#2563eb'));
paragraph($image, '先说明客户面对的具体问题，再介绍您的解决方式和最关键的差异。', 638, 224, 480, 17, 32);
paragraph($image, '用可验证的数据和服务承诺支撑结论。', 638, 310, 480, 17, 32);
imagefilledrectangle($image, 638, 372, 796, 422, color($image, '#2563eb'));
text($image, '查看解决方案', 660, 405, 16, '#ffffff', true);
save($image, $output . '/section-image-text-reverse.png');

$image = canvas('#f8fafc');
$columnWidth = 252;
$titles = ['定位与目标', '核心能力', '交付方式', '长期支持'];
$bodies = ['说明服务对象、问题与客户最终获得的结果。', '列出技术、团队、设备或经验优势。', '交代项目节点、成果物和验收方式。', '写清质保、培训与持续响应范围。'];
foreach ($titles as $index => $title) {
    $x = 48 + $index * 288;
    imagefilledrectangle($image, $x, 94, $x + $columnWidth, 431, color($image, '#ffffff'));
    imagefilledrectangle($image, $x, 94, $x + $columnWidth, 100, color($image, $index === 0 ? '#2563eb' : '#cbd5e1'));
    text($image, $title, $x + 24, 176, 22, '#0f172a', true);
    paragraph($image, $bodies[$index], $x + 24, 228, $columnWidth - 48, 16, 31);
}
save($image, $output . '/section-text-columns.png');

$image = canvas();
text($image, '合作流程', 510, 82, 29, '#0f172a', true);
text($image, '四个清晰节点，让目标、进度与交付结果始终可确认', 386, 120, 15, '#64748b');
$stepTitles = ['需求确认', '方案与报价', '执行与验收', '交付后支持'];
$stepBodies = ['明确目标、范围和时间', '确认方案、清单与费用', '按节点推进并逐项验收', '提供文档、培训与响应'];
foreach ($stepTitles as $index => $title) {
    $x = 62 + $index * 286;
    imagefilledellipse($image, $x + 108, 224, 72, 72, color($image, '#eff6ff'));
    text($image, (string) ($index + 1), $x + 97, 235, 22, '#2563eb', true);
    if ($index < 3) {
        imagefilledrectangle($image, $x + 150, 222, $x + 284, 225, color($image, '#dbeafe'));
    }
    text($image, $title, $x + 58, 310, 20, '#0f172a', true);
    text($image, $stepBodies[$index], $x + 24, 354, 15, '#64748b');
}
save($image, $output . '/section-process-steps.png');

$image = canvas('#f8fafc');
text($image, '可核验的交付标准', 52, 160, 31, '#0f172a', true);
paragraph($image, '把资质、质量控制和服务承诺放在一起，让客户快速判断合作是否可靠。', 52, 216, 280, 16, 30);
$trustTitles = ['资质与认证', '质量可追溯', '响应有时限'];
foreach ($trustTitles as $index => $title) {
    $x = 386 + $index * 262;
    imagefilledrectangle($image, $x, 90, $x + 226, 421, color($image, '#ffffff'));
    imagefilledellipse($image, $x + 113, 174, 66, 66, color($image, '#eff6ff'));
    text($image, ['A', 'Q', '24h'][$index], $x + ($index === 2 ? 91 : 101), 186, 18, '#2563eb', true);
    text($image, $title, $x + 48, 268, 19, '#0f172a', true);
    paragraph($image, ['展示真实有效的行业认证。', '说明检查节点与记录机制。', '写明咨询与售后的响应承诺。'][$index], $x + 28, 316, 170, 14, 27);
}
save($image, $output . '/section-trust-grid.png');

$image = canvas('#f8fafc');
$caseImages = ['/images/case-demo.jpg', '/assets/images/demo/product-101.svg', '/images/cert-1.jpg'];
$caseTitles = ['生产流程数字化', '产品体系升级', '质量标准建设'];
foreach ($caseTitles as $index => $title) {
    $x = 42 + $index * 386;
    imagefilledrectangle($image, $x, 56, $x + 344, 469, color($image, '#ffffff'));
    photo($image, $root . $caseImages[$index], $x, 56, 344, 230);
    text($image, $title, $x + 24, 344, 20, '#0f172a', true);
    paragraph($image, ['交付周期缩短，关键节点在线追踪。', '重构展示逻辑，提升询盘质量。', '检验节点和交付结果可追溯。'][$index], $x + 24, 390, 296, 15, 28);
}
save($image, $output . '/section-case-grid.png');

$image = canvas('#eff6ff');
text($image, '让我们讨论您的项目', 72, 224, 34, '#0f172a', true);
paragraph($image, '告诉我们目标、时间和当前难点，我们会尽快给出明确的下一步建议。', 72, 278, 650, 17, 31);
imagefilledrectangle($image, 902, 210, 1115, 274, color($image, '#2563eb'));
text($image, '联系项目顾问', 939, 251, 18, '#ffffff', true);
save($image, $output . '/section-contact-strip.png');
