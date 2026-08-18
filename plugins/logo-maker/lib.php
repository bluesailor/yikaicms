<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

function logoMakerHexColor(string $value, string $fallback): string
{
    $value = strtoupper(trim($value));
    return preg_match('/^#[0-9A-F]{6}$/', $value) === 1 ? $value : $fallback;
}

function logoMakerRememberCandidates(array $candidates): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $token = bin2hex(random_bytes(24));
    $_SESSION['logo_maker_candidates'] = [
        'token' => $token,
        'expires' => time() + 600,
        'items' => array_values(array_slice($candidates, 0, 12)),
    ];
    return $token;
}

function logoMakerCandidateSvg(string $token, int $index): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $stored = $_SESSION['logo_maker_candidates'] ?? null;
    if (!is_array($stored) || !hash_equals((string) ($stored['token'] ?? ''), $token)
        || (int) ($stored['expires'] ?? 0) < time()) {
        throw new RuntimeException(__('logo_maker_candidate_expired'));
    }
    $candidate = $stored['items'][$index] ?? null;
    $svg = is_array($candidate) ? (string) ($candidate['svg'] ?? '') : '';
    if (!logoMakerIsSafeSvg($svg)) {
        throw new RuntimeException(__('logo_maker_svg_invalid'));
    }
    return $svg;
}

function logoMakerIsSafeSvg(string $svg): bool
{
    $svg = trim($svg);
    if ($svg === '' || strlen($svg) > 2_000_000 || !preg_match('/^<svg\b/i', $svg)
        || !preg_match('/<\/svg>\s*$/i', $svg)) {
        return false;
    }
    return preg_match('/<\s*(script|foreignObject)\b|\bon[a-z]+\s*=|javascript\s*:|data:text\/html/i', $svg) !== 1;
}

function logoMakerApplyCandidate(string $token, int $index): string
{
    $svg = logoMakerCandidateSvg($token, $index);
    $directory = ROOT_PATH . '/uploads/brand';
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException(__('logo_maker_upload_dir_failed'));
    }
    $relative = '/uploads/brand/logo-maker-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.svg';
    if (@file_put_contents(ROOT_PATH . $relative, $svg, LOCK_EX) === false) {
        throw new RuntimeException(__('logo_maker_save_failed'));
    }
    settingModel()->set('site_logo', $relative, 'basic');
    adminLog('plugin', 'logo_maker_apply', 'Logo Maker applied site logo: ' . $relative);
    return $relative;
}

function logoMakerPngFromDataUrl(string $dataUrl): GdImage
{
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException(__('logo_maker_gd_missing'));
    }
    if (strlen($dataUrl) > 8_000_000 || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $match)) {
        throw new InvalidArgumentException(__('logo_maker_png_invalid'));
    }
    $bytes = base64_decode($match[1], true);
    $image = $bytes === false ? false : @imagecreatefromstring($bytes);
    if (!$image instanceof GdImage) {
        throw new InvalidArgumentException(__('logo_maker_png_invalid'));
    }
    if (imagesx($image) > 4096 || imagesy($image) > 4096) {
        imagedestroy($image);
        throw new InvalidArgumentException(__('logo_maker_png_invalid'));
    }
    return $image;
}

function logoMakerBuildIco(string $dataUrl): string
{
    $master = logoMakerPngFromDataUrl($dataUrl);
    try {
        return logoMakerIco($master, [16, 32, 48]);
    } finally {
        imagedestroy($master);
    }
}

function logoMakerScaled(GdImage $source, int $size): GdImage
{
    $width = imagesx($source);
    $height = imagesy($source);
    $target = imagecreatetruecolor($size, $size);
    if (!$target instanceof GdImage) {
        throw new RuntimeException(__('logo_maker_gd_missing'));
    }
    imagealphablending($target, false);
    imagesavealpha($target, true);
    imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
    $scale = min($size / $width, $size / $height);
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    imagealphablending($target, true);
    imagecopyresampled(
        $target,
        $source,
        intdiv($size - $targetWidth, 2),
        intdiv($size - $targetHeight, 2),
        0,
        0,
        $targetWidth,
        $targetHeight,
        $width,
        $height
    );
    imagealphablending($target, false);
    return $target;
}

/** @param list<int> $sizes */
function logoMakerIco(GdImage $master, array $sizes): string
{
    $entries = [];
    foreach ($sizes as $size) {
        $image = logoMakerScaled($master, $size);
        $pixels = '';
        for ($y = $size - 1; $y >= 0; $y--) {
            for ($x = 0; $x < $size; $x++) {
                $color = imagecolorat($image, $x, $y);
                $alpha = ($color >> 24) & 0x7F;
                $pixels .= chr($color & 0xFF)
                    . chr(($color >> 8) & 0xFF)
                    . chr(($color >> 16) & 0xFF)
                    . chr(intdiv((127 - $alpha) * 255, 127));
            }
        }
        $maskRow = intdiv(intdiv($size + 7, 8) + 3, 4) * 4;
        $mask = str_repeat("\x00", $maskRow * $size);
        $bitmap = pack('VVVvvVVVVVV', 40, $size, $size * 2, 1, 32, 0, strlen($pixels) + strlen($mask), 0, 0, 0, 0)
            . $pixels . $mask;
        $entries[] = ['size' => $size, 'data' => $bitmap];
        imagedestroy($image);
    }

    $ico = pack('vvv', 0, 1, count($entries));
    $offset = 6 + 16 * count($entries);
    foreach ($entries as $entry) {
        $size = (int) $entry['size'];
        $data = (string) $entry['data'];
        $ico .= chr($size < 256 ? $size : 0) . chr($size < 256 ? $size : 0) . "\x00\x00"
            . pack('vvVV', 1, 32, strlen($data), $offset);
        $offset += strlen($data);
    }
    foreach ($entries as $entry) {
        $ico .= (string) $entry['data'];
    }
    return $ico;
}

function logoMakerApplyIco(string $dataUrl): string
{
    $ico = logoMakerBuildIco($dataUrl);
    $dir = ROOT_PATH . '/uploads/brand';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException(__('logo_maker_ico_save_failed'));
    }
    $relative = '/uploads/brand/favicon-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.ico';
    if (@file_put_contents(ROOT_PATH . $relative, $ico, LOCK_EX) === false) {
        throw new RuntimeException(__('logo_maker_ico_save_failed'));
    }
    settingModel()->set('site_favicon', $relative . '?v=' . time(), 'basic');
    adminLog('plugin', 'logo_maker_apply_ico', 'Logo Maker applied favicon.ico');
    return $relative;
}

/** @return array<string,string> */
function logoMakerNormalizeLocalOptions(array $input): array
{
    $mark = trim((string) ($input['mark'] ?? 'YK'));
    $mark = mb_substr($mark !== '' ? $mark : 'YK', 0, 24, 'UTF-8');
    $tagline = mb_substr(trim((string) ($input['tagline'] ?? '')), 0, 48, 'UTF-8');
    $layout = (string) ($input['layout'] ?? 'horizontal');
    if (!in_array($layout, ['horizontal', 'stacked', 'mark-only'], true)) {
        $layout = 'horizontal';
    }
    $symbol = (string) ($input['symbol'] ?? 'circle');
    if (!in_array($symbol, ['circle', 'diamond', 'square', 'spark', 'none'], true)) {
        $symbol = 'circle';
    }
    $background = strtoupper(trim((string) ($input['background'] ?? 'transparent')));
    if ($background !== 'TRANSPARENT' && !preg_match('/^#[0-9A-F]{6}$/', $background)) {
        $background = 'transparent';
    }
    return [
        'mark' => $mark,
        'tagline' => $tagline,
        'layout' => $layout,
        'symbol' => $symbol,
        'primary' => logoMakerHexColor((string) ($input['primary'] ?? ''), '#2563EB'),
        'secondary' => logoMakerHexColor((string) ($input['secondary'] ?? ''), '#0F172A'),
        'background' => $background === 'TRANSPARENT' ? 'transparent' : $background,
    ];
}

function logoMakerXml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function logoMakerSymbolSvg(string $symbol, string $primary, string $secondary, string $letter): string
{
    if ($symbol === 'none') {
        return '';
    }
    $letter = logoMakerXml(mb_substr($letter, 0, 2, 'UTF-8'));
    $shape = match ($symbol) {
        'diamond' => '<path d="M120 26 214 120 120 214 26 120Z" fill="' . $primary . '"/>',
        'square' => '<rect x="30" y="30" width="180" height="180" rx="38" fill="' . $primary . '"/>',
        'spark' => '<path d="m120 18 24 76 76 26-76 26-24 76-24-76-76-26 76-26 24-76Z" fill="' . $primary . '"/>',
        default => '<circle cx="120" cy="120" r="94" fill="' . $primary . '"/>',
    };
    return '<g>' . $shape
        . '<circle cx="120" cy="120" r="54" fill="' . $secondary . '" opacity=".94"/>'
        . '<text x="120" y="137" text-anchor="middle" font-family="Arial,Helvetica,Microsoft YaHei,sans-serif" font-size="48" font-weight="700" fill="#FFFFFF">' . $letter . '</text>'
        . '</g>';
}

function logoMakerLocalSvg(array $input): string
{
    $options = logoMakerNormalizeLocalOptions($input);
    $mark = logoMakerXml($options['mark']);
    $tagline = logoMakerXml($options['tagline']);
    $primary = $options['primary'];
    $secondary = $options['secondary'];
    $background = $options['background'];
    $symbol = logoMakerSymbolSvg($options['symbol'], $primary, $secondary, $options['mark']);
    $bg = $background === 'transparent' ? '' : '<rect width="100%" height="100%" fill="' . $background . '"/>';
    $font = 'font-family="Arial,Helvetica,Microsoft YaHei,sans-serif"';

    if ($options['layout'] === 'mark-only') {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="240" viewBox="0 0 240 240">'
            . $bg . $symbol . '</svg>';
    } elseif ($options['layout'] === 'stacked') {
        $taglineSvg = $tagline === '' ? '' : '<text x="210" y="326" text-anchor="middle" ' . $font . ' font-size="18" fill="' . $secondary . '">' . $tagline . '</text>';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="420" height="420" viewBox="0 0 420 420">'
            . $bg . '<g transform="translate(90 8)">' . $symbol . '</g>'
            . '<text x="210" y="280" text-anchor="middle" ' . $font . ' font-size="42" font-weight="700" fill="' . $secondary . '">' . $mark . '</text>'
            . $taglineSvg . '</svg>';
    } else {
        $taglineSvg = $tagline === '' ? '' : '<text x="252" y="150" ' . $font . ' font-size="18" fill="' . $secondary . '" opacity=".78">' . $tagline . '</text>';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="760" height="240" viewBox="0 0 760 240">'
            . $bg . '<g transform="translate(0 0)">' . $symbol . '</g>'
            . '<text x="252" y="112" ' . $font . ' font-size="48" font-weight="700" fill="' . $secondary . '">' . $mark . '</text>'
            . $taglineSvg . '</svg>';
    }
    if (!logoMakerIsSafeSvg($svg)) {
        throw new RuntimeException(__('logo_maker_svg_invalid'));
    }
    return $svg;
}
