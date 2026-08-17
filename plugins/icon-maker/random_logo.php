<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

final class IconMakerRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed !== 0 ? $seed : 1;
    }

    public function int(int $min, int $max): int
    {
        $this->state ^= ($this->state << 13) & 0x7fffffff;
        $this->state ^= ($this->state >> 17);
        $this->state ^= ($this->state << 5) & 0x7fffffff;
        return $min + (abs($this->state) % ($max - $min + 1));
    }

    /**
     * @template T
     * @param array<int,T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->int(0, count($items) - 1)];
    }
}

function iconMakerRandomColorSchemes(): array
{
    return [
        'cobalt' => ['label' => '钴蓝科技', 'colors' => ['#1D4ED8', '#22D3EE', '#0F172A']],
        'royal' => ['label' => '皇家靛紫', 'colors' => ['#4338CA', '#A78BFA', '#111827']],
        'azure' => ['label' => '深海天青', 'colors' => ['#0369A1', '#38BDF8', '#082F49']],
        'emerald' => ['label' => '翡翠青绿', 'colors' => ['#047857', '#5EEAD4', '#052E2B']],
        'teal_coral' => ['label' => '青碧珊瑚', 'colors' => ['#0F766E', '#FB7185', '#042F2E']],
        'navy_gold' => ['label' => '海军蓝金', 'colors' => ['#16324F', '#D4A72C', '#091A2A']],
        'graphite_gold' => ['label' => '石墨鎏金', 'colors' => ['#374151', '#F59E0B', '#111827']],
        'steel_orange' => ['label' => '钢灰活力橙', 'colors' => ['#475569', '#F97316', '#17191C']],
        'forest_lime' => ['label' => '森林青柠', 'colors' => ['#166534', '#A3E635', '#052E16']],
        'burgundy' => ['label' => '勃艮第金', 'colors' => ['#9F1239', '#FBBF24', '#3F0717']],
        'rose_champagne' => ['label' => '玫瑰香槟', 'colors' => ['#BE185D', '#F9A8D4', '#500724']],
        'charcoal_silver' => ['label' => '炭黑银灰', 'colors' => ['#3F3F46', '#D4D4D8', '#18181B']],
        'arctic_flame' => ['label' => '冰海赤焰', 'colors' => ['#0F4C5C', '#F97316', '#172554']],
        'mint_plum' => ['label' => '薄荷李紫', 'colors' => ['#0F766E', '#A21CAF', '#1F2937']],
        'violet_lime' => ['label' => '紫晶青柠', 'colors' => ['#5B21B6', '#84CC16', '#292524']],
        'cerulean_yellow' => ['label' => '蔚蓝明黄', 'colors' => ['#075985', '#FACC15', '#172554']],
        'coral_ink' => ['label' => '珊瑚墨黑', 'colors' => ['#E11D48', '#111827', '#FDA4AF']],
        'copper_teal' => ['label' => '赤铜青碧', 'colors' => ['#B45309', '#0F766E', '#292524']],
        'jade_clay' => ['label' => '翡翠陶红', 'colors' => ['#047857', '#C2410C', '#1C1917']],
        'ultramarine_pink' => ['label' => '群青桃红', 'colors' => ['#1E40AF', '#EC4899', '#111827']],
        'ruby_cyan' => ['label' => '宝石红青', 'colors' => ['#BE123C', '#06B6D4', '#3F0717']],
        'moss_lilac' => ['label' => '苔绿丁香', 'colors' => ['#3F6212', '#A78BFA', '#1C1917']],
        'cyan_navy' => ['label' => '亮青深蓝', 'colors' => ['#0891B2', '#1E3A8A', '#083344']],
        'saffron_blue' => ['label' => '藏红花蓝', 'colors' => ['#CA8A04', '#2563EB', '#422006']],
        'grape_mint' => ['label' => '葡萄薄荷', 'colors' => ['#7E22CE', '#2DD4BF', '#2E1065']],
        'brick_sky' => ['label' => '砖红天青', 'colors' => ['#B91C1C', '#38BDF8', '#450A0A']],
        'marine_coral' => ['label' => '海松珊瑚', 'colors' => ['#155E75', '#FB7185', '#164E63']],
        'black_yellow' => ['label' => '曜黑明黄', 'colors' => ['#18181B', '#FACC15', '#52525B']],
        'cobalt_orange' => ['label' => '钴蓝橙光', 'colors' => ['#1D4ED8', '#F97316', '#172554']],
        'emerald_rose' => ['label' => '祖母绿玫红', 'colors' => ['#047857', '#F43F5E', '#022C22']],
        'violet_amber' => ['label' => '紫罗兰琥珀', 'colors' => ['#6D28D9', '#F59E0B', '#2E1065']],
        'cyan_magenta' => ['label' => '电青洋红', 'colors' => ['#0891B2', '#C026D3', '#164E63']],
        'tangerine_blue' => ['label' => '橘光蓝', 'colors' => ['#F97316', '#1D4ED8', '#431407']],
        'turquoise_crimson' => ['label' => '绿松石绯红', 'colors' => ['#0D9488', '#DC2626', '#134E4A']],
        'indigo_mustard' => ['label' => '靛青芥黄', 'colors' => ['#3730A3', '#EAB308', '#1E1B4B']],
        'pine_lavender' => ['label' => '松绿薰衣草', 'colors' => ['#115E59', '#C4B5FD', '#022C22']],
        'scarlet_aqua' => ['label' => '猩红水青', 'colors' => ['#DC2626', '#22D3EE', '#450A0A']],
        'denim_copper' => ['label' => '丹宁赤铜', 'colors' => ['#1E3A8A', '#B45309', '#172554']],
        'fuchsia_green' => ['label' => '紫红正绿', 'colors' => ['#C026D3', '#16A34A', '#4A044E']],
        'steel_coral' => ['label' => '钢蓝珊瑚', 'colors' => ['#475569', '#FB7185', '#0F172A']],
        'plum_gold' => ['label' => '梅紫金黄', 'colors' => ['#86198F', '#FBBF24', '#3B0764']],
        'ocean_lime' => ['label' => '海蓝青柠', 'colors' => ['#0E7490', '#84CC16', '#083344']],
        'maroon_sky' => ['label' => '栗红晴空', 'colors' => ['#881337', '#0EA5E9', '#4C0519']],
        'teal_sun' => ['label' => '青碧日光', 'colors' => ['#0F766E', '#F59E0B', '#042F2E']],
        'midnight_mint' => ['label' => '午夜薄荷', 'colors' => ['#172554', '#34D399', '#020617']],
        'vermilion_violet' => ['label' => '朱橙紫罗兰', 'colors' => ['#EA580C', '#7C3AED', '#431407']],
        'olive_cyan' => ['label' => '橄榄电青', 'colors' => ['#4D7C0F', '#06B6D4', '#1A2E05']],
        'blue_rose' => ['label' => '亮蓝玫红', 'colors' => ['#2563EB', '#E11D48', '#172554']],
    ];
}

function iconMakerRandomMonoColors(): array
{
    return [
        'mono_ink' => ['label' => '墨黑', 'color' => '#111827'],
        'mono_graphite' => ['label' => '石墨', 'color' => '#374151'],
        'mono_slate' => ['label' => '岩灰', 'color' => '#475569'],
        'mono_warm_gray' => ['label' => '暖灰', 'color' => '#57534E'],
        'mono_navy' => ['label' => '海军蓝', 'color' => '#1E3A8A'],
        'mono_deep_indigo' => ['label' => '深靛蓝', 'color' => '#312E81'],
        'mono_indigo' => ['label' => '靛蓝', 'color' => '#4F46E5'],
        'mono_cobalt' => ['label' => '钴蓝', 'color' => '#1D4ED8'],
        'mono_blue' => ['label' => '亮蓝', 'color' => '#2563EB'],
        'mono_sky' => ['label' => '天青', 'color' => '#0284C7'],
        'mono_ocean' => ['label' => '深海蓝', 'color' => '#0C4A6E'],
        'mono_cyan' => ['label' => '青蓝', 'color' => '#0891B2'],
        'mono_teal' => ['label' => '青碧', 'color' => '#0F766E'],
        'mono_pine' => ['label' => '松绿', 'color' => '#115E59'],
        'mono_emerald' => ['label' => '翡翠绿', 'color' => '#059669'],
        'mono_green' => ['label' => '正绿', 'color' => '#15803D'],
        'mono_forest' => ['label' => '森林绿', 'color' => '#166534'],
        'mono_olive' => ['label' => '橄榄绿', 'color' => '#4D7C0F'],
        'mono_lime' => ['label' => '青柠绿', 'color' => '#65A30D'],
        'mono_ochre' => ['label' => '赭金', 'color' => '#A16207'],
        'mono_amber' => ['label' => '琥珀', 'color' => '#D97706'],
        'mono_orange' => ['label' => '活力橙', 'color' => '#EA580C'],
        'mono_vermilion' => ['label' => '朱红', 'color' => '#DC2626'],
        'mono_wine' => ['label' => '酒红', 'color' => '#7F1D1D'],
        'mono_burgundy' => ['label' => '勃艮第', 'color' => '#9F1239'],
        'mono_crimson' => ['label' => '绯红', 'color' => '#BE123C'],
        'mono_rose' => ['label' => '玫红', 'color' => '#E11D48'],
        'mono_magenta' => ['label' => '洋红', 'color' => '#C026D3'],
        'mono_violet' => ['label' => '紫罗兰', 'color' => '#7C3AED'],
        'mono_purple' => ['label' => '深紫', 'color' => '#6D28D9'],
        'mono_royal' => ['label' => '皇家紫', 'color' => '#4338CA'],
        'mono_brown' => ['label' => '栗棕', 'color' => '#7C2D12'],
    ];
}

function iconMakerRandomMonoHexPalette(): array
{
    return [
        '#111827', '#374151', '#475569', '#57534E', '#1E3A8A', '#312E81', '#4338CA',
        '#4F46E5', '#1D4ED8', '#2563EB', '#0284C7', '#0891B2', '#0E7490', '#0F766E',
        '#115E59', '#059669', '#15803D', '#166534', '#4D7C0F', '#65A30D', '#84CC16',
        '#CA8A04', '#D97706', '#EA580C', '#F97316', '#DC2626', '#B91C1C', '#7F1D1D',
        '#9F1239', '#BE123C', '#E11D48', '#EC4899', '#C026D3', '#7C3AED', '#6D28D9',
        '#A78BFA', '#38BDF8', '#22D3EE', '#2DD4BF', '#34D399', '#FACC15', '#FB7185',
    ];
}

function iconMakerRandomNormalizeHexColor(string $color, string $fallback): string
{
    $color = trim($color);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1) {
        return strtoupper($color);
    }
    return strtoupper($fallback);
}

function iconMakerRandomColorModes(): array
{
    return [
        'mono' => ['label' => '单色', 'count' => 1],
        'duo' => ['label' => '双色', 'count' => 2],
        'trio' => ['label' => '三色', 'count' => 3],
    ];
}

/**
 * @param array{0:string,1:string,2:string} $colors
 * @return array{0:string,1:string,2:string}
 */
function iconMakerRandomApplyColorMode(array $colors, string $colorMode): array
{
    [$primary, $accent, $dark] = $colors;

    return match ($colorMode) {
        'mono' => [$primary, $primary, $primary],
        'duo' => [$primary, $accent, $primary],
        default => [$primary, $accent, $dark],
    };
}

function iconMakerRandomLetterStyles(): array
{
    return [
        'abstract' => ['label' => '抽象几何', 'tip' => '折面、编织与负形构成'],
        'spatial' => ['label' => '三维构成', 'tip' => '等距透视与空间折面造型'],
        'geometry' => ['label' => '行业图形', 'tip' => '使用行业几何符号'],
        'auto' => ['label' => '自动字标', 'tip' => '自动选择字母变形'],
        'cut' => ['label' => '几何切割', 'tip' => '缺口、切片与负形重构字母'],
        'split' => ['label' => '分层错位', 'tip' => '上下错位增强识别'],
        'outline' => ['label' => '轮廓字标', 'tip' => '描边结构轻盈现代'],
        'mirror' => ['label' => '镜像拼合', 'tip' => '镜像组合形成图腾'],
        'frame' => ['label' => '徽章框体', 'tip' => '字母与圆形、椭圆及几何框融合'],
        'stack' => ['label' => '双色叠印', 'tip' => '双色错位形成层次'],
    ];
}
function iconMakerRandomEffects(): array
{
    return [
        'auto' => ['label' => '自动混合', 'tip' => '候选中轮换全部效果'],
        'clean' => ['label' => '纯净结构', 'tip' => '保留原始几何结构'],
        'rotate' => ['label' => '旋转回声', 'tip' => '角度叠印与环形动势'],
        'warp' => ['label' => '流体扭曲', 'tip' => '噪声位移形成流动形变'],
        'polar' => ['label' => '极坐标', 'tip' => '径向重复与环形映射'],
        'particles' => ['label' => '粒子场', 'tip' => '点阵粒子与轨迹散布'],
        'kaleido' => ['label' => '万花镜', 'tip' => '镜像切面形成晶体图腾'],
        'scan' => ['label' => '扫描错位', 'tip' => '水平切片产生数字节奏'],
        'contour' => ['label' => '等高线', 'tip' => '层叠轮廓营造地形纵深'],
        'extrude' => ['label' => '立体挤出', 'tip' => '连续偏移形成空间厚度'],
        'halftone' => ['label' => '网点印刷', 'tip' => '渐变点阵呈现印刷质感'],
    ];
}
function iconMakerRandomBackgroundModes(): array
{
    return [
        'transparent' => ['label' => '透明底（推荐）'],
        'outline' => ['label' => '轮廓框'],
        'solid' => ['label' => '渐变色块'],
    ];
}
function iconMakerRandomIndustries(): array
{
    return [
        'technology' => [
            'label' => '科技 / 软件 / AI',
            'templates' => ['orbit', 'nodes', 'circuit', 'pulse', 'mesh', 'monogram', 'initial_cut', 'initial_ring', 'initial_axis', 'initial_tiles', 'initial_bracket', 'initial_negative'],
            'containers' => ['squircle', 'circle', 'hex', 'notch', 'rounded', 'diamond'],
            'schemes' => ['cobalt', 'royal', 'azure'],
        ],
        'manufacturing' => [
            'label' => '制造 / 工业 / 机械',
            'templates' => ['hex', 'blocks', 'ring_cut', 'gear', 'chevron', 'monogram', 'initial_cut', 'initial_ring', 'initial_axis', 'initial_tiles', 'initial_bracket', 'initial_negative'],
            'containers' => ['hex', 'notch', 'rounded', 'shield', 'squircle'],
            'schemes' => ['steel_orange', 'graphite_gold', 'navy_gold'],
        ],
        'construction' => [
            'label' => '建筑 / 工程 / 装修',
            'templates' => ['roof', 'grid', 'blocks', 'bridge', 'tower', 'monogram', 'initial_cut', 'initial_ring', 'initial_axis', 'initial_tiles', 'initial_bracket', 'initial_negative'],
            'containers' => ['arch', 'rounded', 'hex', 'shield', 'notch'],
            'schemes' => ['navy_gold', 'forest_lime', 'steel_orange'],
        ],
        'medical' => [
            'label' => '医疗 / 健康 / 生物',
            'templates' => ['cross', 'cell', 'wave', 'dna', 'heartpulse', 'monogram', 'initial_cut', 'initial_ring', 'initial_axis', 'initial_tiles', 'initial_bracket', 'initial_negative'],
            'containers' => ['circle', 'squircle', 'capsule', 'rounded', 'shield'],
            'schemes' => ['emerald', 'azure', 'teal_coral'],
        ],
        'education' => [
            'label' => '教育 / 培训 / 学校',
            'templates' => ['book', 'shield_mark', 'spark', 'pencil', 'columns', 'monogram', 'initial_cut', 'initial_ring', 'initial_axis', 'initial_tiles', 'initial_bracket', 'initial_negative'],
            'containers' => ['shield', 'arch', 'rounded', 'circle', 'notch'],
            'schemes' => ['royal', 'cobalt', 'forest_lime'],
        ],
        'finance' => [
            'label' => '金融 / 投资 / 会计',
            'templates' => ['bars', 'coin', 'trend', 'ledger', 'shield_mark', 'monogram', 'initial_cut', 'initial_ring', 'initial_axis', 'initial_tiles', 'initial_bracket', 'initial_negative'],
            'containers' => ['rounded', 'shield', 'hex', 'circle', 'notch'],
            'schemes' => ['navy_gold', 'emerald', 'graphite_gold'],
        ],
        'food' => [
            'label' => '餐饮 / 食品 / 农产品',
            'templates' => ['leaf', 'bowl', 'seed', 'sprout', 'wheat', 'monogram', 'initial_cut', 'initial_ring', 'initial_axis', 'initial_tiles', 'initial_bracket', 'initial_negative'],
            'containers' => ['circle', 'arch', 'squircle', 'rounded', 'capsule'],
            'schemes' => ['forest_lime', 'burgundy', 'steel_orange'],
        ],
        'beauty' => [
            'label' => '美业 / 护肤 / 时尚',
            'templates' => ['petal', 'ribbon', 'spark', 'gem', 'wave', 'monogram', 'initial_cut', 'initial_ring', 'initial_axis', 'initial_tiles', 'initial_bracket', 'initial_negative'],
            'containers' => ['diamond', 'circle', 'squircle', 'arch', 'capsule'],
            'schemes' => ['rose_champagne', 'royal', 'teal_coral'],
        ],
        'cafe' => [
            'label' => '咖啡 / 茶饮 / 烘焙',
            'templates' => ['bowl', 'seed', 'leaf', 'spark', 'monogram', 'initial_ring'],
            'containers' => ['circle', 'arch', 'squircle', 'rounded', 'capsule'],
            'schemes' => ['burgundy', 'forest_lime', 'navy_gold'],
        ],
        'barbecue' => [
            'label' => '烧烤 / 烤肉 / 炭火',
            'templates' => ['bowl', 'chevron', 'ring_cut', 'spark', 'monogram', 'initial_cut'],
            'containers' => ['shield', 'hex', 'circle', 'rounded', 'notch'],
            'schemes' => ['steel_orange', 'burgundy', 'graphite_gold'],
        ],
        'hair_salon' => [
            'label' => '美发 / 理发 / 造型',
            'templates' => ['ribbon', 'spark', 'gem', 'wave', 'monogram', 'initial_axis'],
            'containers' => ['circle', 'diamond', 'squircle', 'arch', 'capsule'],
            'schemes' => ['charcoal_silver', 'rose_champagne', 'teal_coral'],
        ],
    ];
}
function iconMakerRandomSeed(string $industry, string $name, int $seed, int $index): int
{
    return abs((int) sprintf('%u', crc32($industry . '|' . $name . '|' . $seed . '|' . $index)));
}

function iconMakerRandomLetters(string $name): string
{
    $letters = preg_replace('/[^\p{L}\p{N}]+/u', '', trim($name)) ?? '';
    if ($letters === '') {
        return 'Y';
    }
    return mb_strtoupper(mb_substr($letters, 0, 2, 'UTF-8'), 'UTF-8');
}

function iconMakerRandomSvg(
    string $industry,
    string $name,
    int $seed,
    int $index = 0,
    string $scheme = 'industry',
    string $letterStyle = 'abstract',
    string $effect = 'auto',
    string $backgroundMode = 'transparent',
    string $colorMode = 'trio',
    string $monoColor = 'industry',
    array $customColors = []
): string
{
    $presets = iconMakerRandomIndustries();
    $schemes = iconMakerRandomColorSchemes();
    $letterStyles = iconMakerRandomLetterStyles();
    $effects = iconMakerRandomEffects();
    $backgroundModes = iconMakerRandomBackgroundModes();
    $colorModes = iconMakerRandomColorModes();
    $monoColors = iconMakerRandomMonoColors();
    $preset = $presets[$industry] ?? $presets['technology'];
    $randomSeed = iconMakerRandomSeed($industry, $name, $seed, $index);
    $seriesSeed = iconMakerRandomSeed($industry, $name, $seed, 0);
    $random = new IconMakerRandom($randomSeed);
    $paletteRandom = new IconMakerRandom($randomSeed ^ 0x5F3759DF);
    $selectedScheme = $scheme === 'custom' ? 'custom' : (isset($schemes[$scheme]) ? $scheme : $paletteRandom->pick($preset['schemes']));
    $selectedLetterStyle = isset($letterStyles[$letterStyle]) ? $letterStyle : 'abstract';
    $selectedBackgroundMode = isset($backgroundModes[$backgroundMode]) ? $backgroundMode : 'transparent';
    $selectedColorMode = isset($colorModes[$colorMode]) ? $colorMode : 'trio';
    $selectedMonoColor = $monoColor === 'custom' ? 'custom' : (isset($monoColors[$monoColor]) ? $monoColor : 'industry');
    $customPalette = [
        iconMakerRandomNormalizeHexColor((string) ($customColors[0] ?? ''), '#1D4ED8'),
        iconMakerRandomNormalizeHexColor((string) ($customColors[1] ?? ''), '#22D3EE'),
        iconMakerRandomNormalizeHexColor((string) ($customColors[2] ?? ''), '#0F172A'),
    ];
    if ($selectedLetterStyle === 'auto') {
        $autoStyles = ['cut', 'split', 'outline', 'mirror', 'frame', 'stack'];
        $selectedLetterStyle = $autoStyles[abs(intdiv($seriesSeed, 7) + $index) % count($autoStyles)];
    }
    $selectedEffect = iconMakerRandomResolveEffect($industry, $name, $seed, $index, isset($effects[$effect]) ? $effect : 'auto');

    if ($selectedColorMode === 'mono' && $selectedMonoColor !== 'industry') {
        $monoHex = $selectedMonoColor === 'custom' ? $customPalette[0] : $monoColors[$selectedMonoColor]['color'];
        [$primary, $accent, $dark] = [$monoHex, $monoHex, $monoHex];
    } else {
        $activePalette = $selectedScheme === 'custom' ? $customPalette : $schemes[$selectedScheme]['colors'];
        [$primary, $accent, $dark] = iconMakerRandomApplyColorMode($activePalette, $selectedColorMode);
    }
    $templates = $preset['templates'];
    $containers = $preset['containers'];
    $treatments = ['clean', 'diagonal', 'split', 'horizon', 'bands', 'focus'];
    $template = $templates[abs($seriesSeed + $index) % count($templates)];
    $container = $containers[abs(intdiv($seriesSeed, 17) + $index) % count($containers)];
    $treatment = $treatments[abs(intdiv($seriesSeed, 31) + $index * 5) % count($treatments)];
    $letters = iconMakerRandomLetters($name);
    $uid = 'imr' . substr(md5($industry . $name . $seed . $index . $selectedScheme . $selectedLetterStyle . $selectedEffect . $selectedBackgroundMode . $selectedColorMode . $selectedMonoColor . implode('', $customPalette)), 0, 10);
    $radius = $random->pick([20, 26, 32, 38]);
    $shape = iconMakerRandomContainerShape($container, $radius);
    $background = match ($selectedBackgroundMode) {
        'solid' => '<g clip-path="url(#' . $uid . 'shape)"><rect width="128" height="128" fill="url(#' . $uid . 'g)"/>'
            . iconMakerRandomBackgroundTreatment($treatment, $random, $accent) . '</g>'
            . iconMakerRandomContainerShape($container, $radius, 'fill="none" stroke="#fff" stroke-width="2" opacity=".12"'),
        'outline' => iconMakerRandomContainerShape($container, $radius, 'fill="none" stroke="' . e($primary) . '" stroke-width="4" opacity=".88"'),
        default => '',
    };

    $mark = match ($selectedLetterStyle) {
        'abstract' => iconMakerRandomAbstractTemplate($index, $random, $primary, $accent, $dark),
        'spatial' => iconMakerRandomSpatialTemplate($index, $random, $primary, $accent, $dark),
        'geometry' => iconMakerRandomLibraryTemplate($industry, $index, $seriesSeed, $random, $primary, $accent, $dark, $template, $letters, $uid),
        default => iconMakerRandomLetterTemplate($selectedLetterStyle, $letters, $accent, $dark, $uid, $random, $index),
    };
    $markAngle = $random->int(-5, 5);
    $markScale = $random->pick(['.92', '.98', '1', '1.05']);
    $mark = '<g transform="translate(64 64) rotate(' . $markAngle . ') scale(' . $markScale . ') translate(-64 -64)">' . $mark . '</g>';
    $effectParts = iconMakerRandomEffectParts($selectedEffect, $mark, $random, $accent, $uid);
    if ($selectedBackgroundMode !== 'solid') {
        foreach (['background', 'mark', 'foreground'] as $part) {
            $effectParts[$part] = str_replace(['#fff', '#111827'], [e($primary), e($dark)], $effectParts[$part]);
        }
    }

    $defs = '<defs><linearGradient id="' . $uid . 'g" x1="18" y1="12" x2="112" y2="120"><stop offset="0" stop-color="' . e($primary) . '"/><stop offset="1" stop-color="' . e($dark) . '"/></linearGradient>'
        . '<mask id="' . $uid . 'cut"><rect width="128" height="128" fill="white"/><circle cx="64" cy="64" r="' . $random->int(10, 20) . '" fill="black" opacity=".9"/></mask>'
        . '<clipPath id="' . $uid . 'shape">' . $shape . '</clipPath>'
        . '<clipPath id="' . $uid . 'top"><rect width="128" height="64"/></clipPath><clipPath id="' . $uid . 'bottom"><rect y="64" width="128" height="64"/></clipPath>'
        . $effectParts['defs'] . '</defs>';

    return '<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128" role="img" data-effect="' . e($selectedEffect) . '" data-background="' . e($selectedBackgroundMode) . '" data-color-mode="' . e($selectedColorMode) . '" data-mono-color="' . e($selectedMonoColor) . '">'
        . $defs . $background . $effectParts['background'] . $effectParts['mark'] . $effectParts['foreground'] . '</svg>';
}

function iconMakerRandomAbstractTemplate(int $index, IconMakerRandom $random, string $primary, string $accent, string $dark): string
{
    $primary = e($primary);
    $accent = e($accent);
    $dark = e($dark);
    $tilt = $random->int(-8, 8);

    $bloom = '<g transform="rotate(' . $tilt . ' 64 64)">';
    for ($i = 0; $i < 6; $i++) {
        $bloom .= '<path d="M64 18 C79 30 80 48 64 62 C48 48 49 30 64 18Z" fill="' . ($i % 2 === 0 ? $primary : $accent) . '" opacity=".9" transform="rotate(' . ($i * 60) . ' 64 64)"/>';
    }
    $bloom .= '<circle cx="64" cy="64" r="11" fill="' . $dark . '"/></g>';

    $portal = '<g fill="none" stroke-linejoin="round">';
    foreach ([0, 1, 2, 3] as $i) {
        $size = 86 - $i * 17;
        $x = 64 - $size / 2;
        $portal .= '<rect x="' . $x . '" y="' . $x . '" width="' . $size . '" height="' . $size . '" rx="' . (8 - $i) . '" stroke="' . ($i % 2 === 0 ? $primary : $accent) . '" stroke-width="' . (8 - $i) . '" transform="rotate(' . ($tilt + $i * 12) . ' 64 64)" opacity="' . (1 - $i * .12) . '"/>';
    }
    $portal .= '</g>';

    $fan = '<g transform="rotate(' . $tilt . ' 64 64)">';
    for ($i = 0; $i < 8; $i++) {
        $fan .= '<path d="M64 16 L75 53 L64 65 L53 53Z" fill="' . ($i % 3 === 0 ? $accent : ($i % 2 === 0 ? $dark : $primary)) . '" opacity=".92" transform="rotate(' . ($i * 45) . ' 64 64)"/>';
    }
    $fan .= '<circle cx="64" cy="64" r="9" fill="' . $accent . '"/></g>';

    return match ($index % 12) {
        0 => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M64 9 L111 38 L102 92 L64 119 L24 98 L14 44Z M64 35 L87 51 L82 80 L62 94 L39 78 L41 50Z" fill="' . $primary . '" fill-rule="evenodd"/><path d="M14 44 L64 35 L41 50 L24 98Z" fill="' . $accent . '" opacity=".88"/><path d="M87 51 L111 38 L102 92 L82 80Z" fill="' . $dark . '" opacity=".84"/></g>',
        1 => '<g fill="none" stroke-linecap="round" stroke-linejoin="round" transform="rotate(' . $tilt . ' 64 64)"><path d="M23 76 C23 35 58 24 79 43 C98 60 91 94 62 101" stroke="' . $primary . '" stroke-width="15"/><path d="M105 52 C105 91 72 105 49 86 C28 69 36 35 66 27" stroke="' . $accent . '" stroke-width="15"/><path d="M35 44 L92 88" stroke="' . $dark . '" stroke-width="8" opacity=".72"/></g>',
        2 => '<g transform="rotate(' . $tilt . ' 64 64)" stroke-linejoin="round"><path d="M64 12 L111 47 L91 108 H37 L16 47Z" fill="' . $primary . '"/><path d="M64 12 V66 L16 47Z" fill="' . $accent . '"/><path d="M64 66 L111 47 L91 108Z" fill="' . $dark . '" opacity=".82"/><path d="M37 108 L64 66 L91 108Z" fill="' . $accent . '" opacity=".72"/><path d="M16 47 L64 66 L37 108Z" fill="' . $primary . '" opacity=".62"/></g>',
        3 => $bloom,
        4 => $portal,
        5 => '<g transform="rotate(' . $tilt . ' 64 64)" fill="none" stroke-linecap="round"><path d="M28 62 C28 26 70 18 88 45 C105 71 76 103 51 87 C32 75 40 46 64 45 C87 44 100 70 85 91" stroke="' . $primary . '" stroke-width="11"/><path d="M40 91 C25 69 40 39 66 40 C93 41 104 76 82 94 C62 111 31 97 28 72" stroke="' . $accent . '" stroke-width="7" opacity=".9"/></g>',
        6 => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M15 45 L54 18 L113 52 L74 79Z" fill="' . $primary . '"/><path d="M15 76 L54 49 L113 83 L74 110Z" fill="' . $accent . '"/><path d="M54 18 L74 30 V110 L54 98Z" fill="' . $dark . '" opacity=".78"/></g>',
        7 => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M20 35 L53 15 L53 50 L64 57 L75 50 V15 L108 35 V72 L64 111 L20 72Z" fill="' . $primary . '"/><path d="M20 35 L53 50 L64 57 L42 76 L20 72Z" fill="' . $accent . '"/><path d="M108 35 L75 50 L64 57 L86 76 L108 72Z" fill="' . $dark . '" opacity=".84"/></g>',
        8 => $fan,
        9 => '<g transform="rotate(' . $tilt . ' 64 64)"><rect x="18" y="34" width="29" height="61" rx="8" fill="' . $primary . '"/><rect x="50" y="17" width="29" height="94" rx="8" fill="' . $dark . '"/><rect x="82" y="34" width="29" height="61" rx="8" fill="' . $accent . '"/><path d="M31 64 H97" stroke="' . $accent . '" stroke-width="8" stroke-linecap="round" opacity=".7"/></g>',
        10 => '<g transform="rotate(' . $tilt . ' 64 64)" fill="none" stroke-linecap="round"><path d="M18 65 C30 31 51 31 64 65 C77 99 98 99 110 65" stroke="' . $primary . '" stroke-width="14"/><path d="M18 65 C30 99 51 99 64 65 C77 31 98 31 110 65" stroke="' . $accent . '" stroke-width="10"/><circle cx="64" cy="65" r="7" fill="' . $dark . '"/></g>',
        default => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M64 9 L78 43 L115 43 L86 66 L97 105 L64 83 L31 105 L42 66 L13 43 L50 43Z M64 35 L58 56 L38 57 L55 69 L49 90 L64 78 L79 90 L73 69 L90 57 L70 56Z" fill="' . $primary . '" fill-rule="evenodd"/><path d="M64 9 L78 43 L64 35 L50 43Z" fill="' . $accent . '"/><path d="M86 66 L97 105 L79 90 L73 69Z" fill="' . $dark . '" opacity=".82"/></g>',
    };
}

function iconMakerRandomSpatialTemplate(int $index, IconMakerRandom $random, string $primary, string $accent, string $dark): string
{
    $primary = e($primary);
    $accent = e($accent);
    $dark = e($dark);
    $tilt = $random->int(-4, 4);
    $cube = '<path d="M32 22 L55 35 L32 48 L9 35Z" fill="' . $accent . '"/><path d="M9 35 L32 48 V74 L9 61Z" fill="' . $primary . '"/><path d="M32 48 L55 35 V61 L32 74Z" fill="' . $dark . '"/>';

    return match ($index % 12) {
        0 => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M64 10 L111 37 L64 64 L17 37Z" fill="' . $accent . '"/><path d="M17 37 L64 64 V117 L17 90Z" fill="' . $primary . '"/><path d="M64 64 L111 37 V90 L64 117Z" fill="' . $dark . '"/><path d="M64 31 L91 46 L64 62 L37 46Z" fill="#fff" opacity=".72"/><path d="M64 62 L91 46 V62 L64 78Z" fill="' . $accent . '" opacity=".78"/></g>',
        1 => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M63 10 L108 36 L97 56 L63 37 L39 51 L18 39Z" fill="' . $accent . '"/><path d="M108 36 V88 L87 100 V61 L63 47 V22Z" fill="' . $dark . '"/><path d="M87 100 L42 118 L20 105 L54 91 V63 L75 51 V80 L108 99Z" fill="' . $primary . '"/><path d="M18 39 L39 51 V82 L54 91 L33 100 L12 88 V49Z" fill="' . $primary . '" opacity=".75"/></g>',
        2 => '<g transform="translate(7 11)">' . $cube . '</g><g transform="translate(49 30)">' . $cube . '</g><g transform="translate(28 52)">' . $cube . '</g><path d="M39 46 L81 70 L60 82 L18 58Z" fill="#fff" opacity=".16"/>',
        3 => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M64 7 L105 34 L92 96 L64 120 L36 96 L23 34Z" fill="' . $primary . '"/><path d="M64 7 L105 34 L64 51 L23 34Z" fill="' . $accent . '"/><path d="M64 51 L105 34 L92 96 L64 120Z" fill="' . $dark . '"/><path d="M23 34 L64 51 L36 96Z" fill="#fff" opacity=".22"/><path d="M36 96 L64 51 L64 120Z" fill="' . $accent . '" opacity=".7"/></g>',
        4 => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M17 89 L45 73 L69 87 L41 103Z" fill="' . $accent . '"/><path d="M41 103 L69 87 V103 L41 119Z" fill="' . $dark . '"/><path d="M17 89 L41 103 V119 L17 105Z" fill="' . $primary . '"/><path d="M34 64 L62 48 L86 62 L58 78Z" fill="' . $accent . '"/><path d="M58 78 L86 62 V87 L58 103Z" fill="' . $dark . '"/><path d="M34 64 L58 78 V103 L34 89Z" fill="' . $primary . '"/><path d="M51 35 L79 19 L103 33 L75 49Z" fill="' . $accent . '"/><path d="M75 49 L103 33 V62 L75 78Z" fill="' . $dark . '"/><path d="M51 35 L75 49 V78 L51 64Z" fill="' . $primary . '"/></g>',
        5 => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M64 9 L111 36 L111 91 L64 119 L17 91 L17 36Z M64 32 L91 48 V79 L64 95 L37 79 V48Z" fill="' . $primary . '" fill-rule="evenodd"/><path d="M64 9 L111 36 L91 48 L64 32 L37 48 L17 36Z" fill="' . $accent . '"/><path d="M111 36 V91 L64 119 V95 L91 79 V48Z" fill="' . $dark . '"/><path d="M17 91 L37 79 L64 95 V119Z" fill="' . $accent . '" opacity=".65"/></g>',
        6 => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M13 39 L49 18 L76 34 L40 55Z" fill="' . $accent . '"/><path d="M40 55 L76 34 L76 57 L53 70 L53 98 L28 113 V64Z" fill="' . $primary . '"/><path d="M76 34 L112 55 V105 L87 90 V69 L64 56Z" fill="' . $dark . '"/><path d="M53 70 L64 63 L87 76 V90 L64 77 L53 84Z" fill="#fff" opacity=".3"/></g>',
        7 => '<g transform="rotate(' . $tilt . ' 64 64)"><ellipse cx="64" cy="29" rx="42" ry="20" fill="' . $accent . '"/><path d="M22 29 V91 C22 102 41 111 64 111 C87 111 106 102 106 91 V29 C106 40 87 49 64 49 C41 49 22 40 22 29Z" fill="' . $primary . '"/><path d="M64 49 C87 49 106 40 106 29 V91 C106 102 87 111 64 111Z" fill="' . $dark . '"/><ellipse cx="64" cy="29" rx="23" ry="10" fill="#fff" opacity=".65"/><ellipse cx="64" cy="73" rx="27" ry="12" fill="none" stroke="' . $accent . '" stroke-width="5" opacity=".7"/></g>',
        8 => '<g transform="translate(7 3) scale(.78)">' . $cube . '</g><g transform="translate(59 3) scale(.78)">' . $cube . '</g><g transform="translate(33 43) scale(.78)">' . $cube . '</g><path d="M32 61 L64 80 L96 61 V88 L64 107 L32 88Z" fill="' . $primary . '" opacity=".26"/><path d="M64 80 L96 61 V88 L64 107Z" fill="' . $dark . '" opacity=".45"/>',
        9 => '<g transform="rotate(' . $tilt . ' 64 64)" fill="none"><ellipse cx="64" cy="64" rx="53" ry="22" transform="rotate(-26 64 64)" stroke="' . $primary . '" stroke-width="9"/><ellipse cx="64" cy="64" rx="53" ry="22" transform="rotate(32 64 64)" stroke="' . $accent . '" stroke-width="7"/><ellipse cx="64" cy="64" rx="43" ry="17" transform="rotate(90 64 64)" stroke="' . $dark . '" stroke-width="8"/><circle cx="64" cy="64" r="14" fill="' . $primary . '" stroke="#fff" stroke-width="5"/></g>',
        10 => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M14 33 L55 10 L113 44 L72 67Z" fill="' . $accent . '"/><path d="M14 33 L72 67 V91 L14 57Z" fill="' . $primary . '"/><path d="M72 67 L113 44 V68 L72 91Z" fill="' . $dark . '"/><path d="M14 69 L55 46 L113 80 L72 103Z" fill="' . $accent . '" opacity=".82"/><path d="M14 69 L72 103 V119 L14 85Z" fill="' . $primary . '" opacity=".82"/><path d="M72 103 L113 80 V96 L72 119Z" fill="' . $dark . '" opacity=".82"/></g>',
        default => '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M64 8 L79 42 L115 36 L91 65 L113 94 L77 87 L64 120 L51 87 L15 94 L37 65 L13 36 L49 42Z" fill="' . $primary . '"/><path d="M64 8 L79 42 L64 65 L49 42Z" fill="' . $accent . '"/><path d="M64 65 L91 65 L113 94 L77 87Z" fill="' . $dark . '"/><path d="M64 65 L51 87 L15 94 L37 65Z" fill="' . $accent . '" opacity=".72"/><path d="M64 65 L77 87 L64 120 L51 87Z" fill="#fff" opacity=".24"/></g>',
    };
}

function iconMakerRandomResolveEffect(string $industry, string $name, int $seed, int $index, string $effect): string
{
    if ($effect !== 'auto' && isset(iconMakerRandomEffects()[$effect])) {
        return $effect;
    }
    $order = ['clean', 'rotate', 'warp', 'polar', 'particles', 'kaleido', 'scan', 'contour', 'extrude', 'halftone'];
    $seriesSeed = iconMakerRandomSeed($industry, $name, $seed, 0);
    return $order[abs(intdiv($seriesSeed, 43) + $index) % count($order)];
}

/**
 * @return array{defs:string,background:string,mark:string,foreground:string}
 */
function iconMakerRandomEffectParts(string $effect, string $mark, IconMakerRandom $random, string $accent, string $uid): array
{
    $parts = ['defs' => '', 'background' => '', 'mark' => $mark, 'foreground' => ''];
    $accent = e($accent);

    if ($effect === 'rotate') {
        $direction = $random->pick([-1, 1]);
        $angle = $random->int(17, 31) * $direction;
        $parts['background'] = '<g clip-path="url(#' . $uid . 'shape)" fill="none" stroke="#fff" opacity=".22"><circle cx="64" cy="64" r="48" stroke-width="2" stroke-dasharray="18 9"/><circle cx="64" cy="64" r="39" stroke-width="3" stroke-dasharray="5 12" transform="rotate(' . ($angle * 2) . ' 64 64)"/></g>';
        $parts['mark'] = '<g opacity=".18" transform="rotate(' . (-$angle) . ' 64 64)">' . $mark . '</g><g opacity=".28" transform="rotate(' . $angle . ' 64 64)">' . $mark . '</g><g transform="rotate(' . round($angle * .32, 2) . ' 64 64)">' . $mark . '</g>';
        $parts['foreground'] = '<path d="M93 30 A44 44 0 0 ' . ($direction > 0 ? '1' : '0') . ' 104 52" fill="none" stroke="' . $accent . '" stroke-width="5" stroke-linecap="round"/><circle cx="96" cy="31" r="4" fill="#fff"/>';
        return $parts;
    }

    if ($effect === 'warp') {
        $scale = $random->int(11, 19);
        $seed = $random->int(1, 99);
        $parts['defs'] = '<filter id="' . $uid . 'warp" x="-25%" y="-25%" width="150%" height="150%" color-interpolation-filters="sRGB"><feTurbulence type="fractalNoise" baseFrequency=".018 .075" numOctaves="1" seed="' . $seed . '" result="noise"/><feDisplacementMap in="SourceGraphic" in2="noise" scale="' . $scale . '" xChannelSelector="R" yChannelSelector="B"/></filter>';
        $parts['background'] = '<g clip-path="url(#' . $uid . 'shape)" fill="none" stroke="' . $accent . '" opacity=".35"><path d="M-8 39 C22 18 44 60 72 36 S118 26 138 47" stroke-width="5"/><path d="M-8 91 C24 68 42 111 76 86 S116 74 138 95" stroke-width="3"/></g>';
        $parts['mark'] = '<g opacity=".22" transform="translate(5 -3)">' . $mark . '</g><g filter="url(#' . $uid . 'warp)">' . $mark . '</g>';
        return $parts;
    }

    if ($effect === 'polar') {
        $count = $random->pick([10, 12, 14]);
        $radial = '<g clip-path="url(#' . $uid . 'shape)">';
        for ($i = 0; $i < $count; $i++) {
            $angle = round(360 / $count * $i, 2);
            $radial .= '<g transform="rotate(' . $angle . ' 64 64)"><path d="M64 11 V28" stroke="' . ($i % 3 === 0 ? $accent : '#fff') . '" stroke-width="' . ($i % 2 === 0 ? '4' : '2') . '" stroke-linecap="round" opacity=".72"/><circle cx="64" cy="17" r="' . ($i % 3 === 0 ? '4' : '2') . '" fill="#fff"/></g>';
        }
        $parts['background'] = $radial . '<circle cx="64" cy="64" r="45" fill="none" stroke="#fff" stroke-width="2" stroke-dasharray="3 7" opacity=".45"/><circle cx="64" cy="64" r="34" fill="none" stroke="' . $accent . '" stroke-width="3" opacity=".34"/></g>';
        $parts['mark'] = '<g transform="translate(64 64) scale(.76) translate(-64 -64)">' . $mark . '</g>';
        $parts['foreground'] = '<circle cx="64" cy="64" r="7" fill="' . $accent . '" stroke="#fff" stroke-width="3"/>';
        return $parts;
    }

    if ($effect === 'particles') {
        $field = '<g clip-path="url(#' . $uid . 'shape)">';
        $front = '<g>';
        for ($i = 0; $i < 26; $i++) {
            $x = $random->int(13, 115);
            $y = $random->int(13, 115);
            $radius = $random->int(1, 4);
            $color = $i % 4 === 0 ? $accent : '#fff';
            $opacity = $random->int(35, 88) / 100;
            $dot = '<circle cx="' . $x . '" cy="' . $y . '" r="' . $radius . '" fill="' . $color . '" opacity="' . $opacity . '"/>';
            if ($i % 6 === 0) {
                $front .= $dot . '<path d="M' . $x . ' ' . $y . ' l' . $random->int(-10, 10) . ' ' . $random->int(-10, 10) . '" stroke="#fff" stroke-width="1.5" opacity=".45"/>';
            } else {
                $field .= $dot;
            }
        }
        $parts['background'] = $field . '</g>';
        $parts['mark'] = '<g transform="translate(64 64) scale(.84) translate(-64 -64)">' . $mark . '</g>';
        $parts['foreground'] = $front . '</g>';
        return $parts;
    }


    if ($effect === 'kaleido') {
        $count = $random->pick([6, 8]);
        $step = 360 / $count;
        $parts['defs'] = '<clipPath id="' . $uid . 'kaleido"><path d="M64 64 L64 5 L' . ($count === 6 ? '116 34' : '106 22') . 'Z"/></clipPath>';
        $slices = '<g opacity=".9">';
        for ($i = 0; $i < $count; $i++) {
            $angle = round($step * $i, 2);
            $reflection = $i % 2 === 0 ? '' : ' transform="translate(128 0) scale(-1 1)"';
            $slices .= '<g transform="rotate(' . $angle . ' 64 64)" clip-path="url(#' . $uid . 'kaleido)"><g' . $reflection . '>' . $mark . '</g></g>';
        }
        $parts['mark'] = $slices . '</g>';
        $parts['foreground'] = '<circle cx="64" cy="64" r="10" fill="' . $accent . '"/><circle cx="64" cy="64" r="4" fill="#fff"/>';
        return $parts;
    }

    if ($effect === 'scan') {
        $bands = [[12, 20], [32, 17], [51, 25], [78, 18], [98, 18]];
        $defs = '';
        $slices = '';
        foreach ($bands as $i => [$y, $height]) {
            $shift = ($i % 2 === 0 ? 1 : -1) * $random->int(3, 10);
            $defs .= '<clipPath id="' . $uid . 'scan' . $i . '"><rect x="5" y="' . $y . '" width="118" height="' . $height . '"/></clipPath>';
            $slices .= '<g clip-path="url(#' . $uid . 'scan' . $i . ')" transform="translate(' . $shift . ' 0)">' . $mark . '</g>';
        }
        $parts['defs'] = $defs;
        $parts['mark'] = $slices;
        $parts['foreground'] = '<g fill="' . $accent . '"><rect x="14" y="29" width="27" height="3"/><rect x="88" y="49" width="25" height="4"/><rect x="9" y="94" width="34" height="3"/></g>';
        return $parts;
    }

    if ($effect === 'contour') {
        $parts['background'] = '<g fill="none" stroke-linecap="round"><path d="M14 67 C14 34 34 15 65 15 C98 15 115 35 113 66 C111 98 94 114 62 113 C32 112 14 96 14 67Z" stroke="#fff" stroke-width="2" opacity=".28"/><path d="M25 66 C25 42 41 27 65 27 C90 27 103 43 102 67 C101 90 88 102 64 101 C41 100 25 90 25 66Z" stroke="' . $accent . '" stroke-width="3" opacity=".55"/><path d="M36 65 C36 49 47 39 65 39 C82 39 92 50 91 67 C90 83 81 91 64 90 C48 89 36 81 36 65Z" stroke="#fff" stroke-width="2" stroke-dasharray="5 5" opacity=".5"/></g>';
        $parts['mark'] = '<g transform="translate(64 64) scale(.72) translate(-64 -64)">' . $mark . '</g>';
        $parts['foreground'] = '<path d="M18 78 C34 69 44 81 57 74 S82 63 109 76" fill="none" stroke="' . $accent . '" stroke-width="2" opacity=".7"/>';
        return $parts;
    }

    if ($effect === 'extrude') {
        $direction = $random->pick([-1, 1]);
        $depth = $random->int(7, 11);
        $layers = '';
        for ($i = $depth; $i >= 1; $i--) {
            $offsetX = $i * $direction;
            $offsetY = $i;
            $opacity = round(.08 + ($depth - $i) * .018, 3);
            $layers .= '<g transform="translate(' . $offsetX . ' ' . $offsetY . ')" opacity="' . $opacity . '">' . $mark . '</g>';
        }
        $parts['mark'] = $layers . '<g transform="translate(' . (-$direction * 2) . ' -2)">' . $mark . '</g>';
        $parts['foreground'] = '<path d="M' . ($direction > 0 ? '91 104 L108 104 L108 87' : '37 104 L20 104 L20 87') . '" fill="none" stroke="' . $accent . '" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>';
        return $parts;
    }

    if ($effect === 'halftone') {
        $dot = $random->pick([5, 6, 7]);
        $parts['defs'] = '<pattern id="' . $uid . 'dots" width="' . $dot . '" height="' . $dot . '" patternUnits="userSpaceOnUse"><circle cx="1.7" cy="1.7" r="1.45" fill="white"/></pattern><linearGradient id="' . $uid . 'fade" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="white"/><stop offset="1" stop-color="black"/></linearGradient><mask id="' . $uid . 'halftone"><rect width="128" height="128" fill="url(#' . $uid . 'dots)"/><rect width="128" height="128" fill="url(#' . $uid . 'fade)" opacity=".35"/></mask>';
        $parts['background'] = '<g mask="url(#' . $uid . 'halftone)" opacity=".55" transform="translate(8 7)">' . $mark . '</g>';
        $parts['mark'] = '<g transform="translate(64 64) scale(.9) translate(-64 -64)">' . $mark . '</g>';
        $parts['foreground'] = '<g fill="' . $accent . '" opacity=".8"><circle cx="105" cy="25" r="5"/><circle cx="112" cy="37" r="3"/><circle cx="99" cy="39" r="2"/></g>';
        return $parts;
    }

    return $parts;
}
function iconMakerRandomLetterTemplate(
    string $style,
    string $letters,
    string $accent,
    string $dark,
    string $uid,
    IconMakerRandom $random,
    int $index
): string {
    $text = e($letters);
    $glyph = e(mb_substr($letters, 0, 1, 'UTF-8'));
    $fontSize = mb_strlen($letters, 'UTF-8') > 1 ? 44 : 62;
    $font = 'font-family="Arial,Microsoft YaHei,sans-serif" font-weight="900"';
    $shift = $random->int(4, 10);
    $slope = $random->int(-5, 7);
    $frameVariants = [
        [
            'key' => 'hexagon',
            'shape' => '<path d="M64 18 L105 41 V87 L64 110 L23 87 V41Z" fill="none" stroke="#fff" stroke-width="6"/>',
            'accent' => '<path d="M34 43 L64 26 L94 43" fill="none" stroke="' . e($accent) . '" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>',
            'font_adjust' => -8,
        ],
        [
            'key' => 'diamond',
            'shape' => '<path d="M64 14 L112 64 L64 114 L16 64Z" fill="none" stroke="#fff" stroke-width="6"/>',
            'accent' => '<path d="M35 43 L64 21 L93 43" fill="none" stroke="' . e($accent) . '" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>',
            'font_adjust' => -8,
        ],
        [
            'key' => 'rounded',
            'shape' => '<rect x="18" y="20" width="92" height="88" rx="22" fill="none" stroke="#fff" stroke-width="6"/>',
            'accent' => '<path d="M36 37 H91" fill="none" stroke="' . e($accent) . '" stroke-width="6" stroke-linecap="round"/>',
            'font_adjust' => -8,
        ],
        [
            'key' => 'circle',
            'shape' => '<circle cx="64" cy="64" r="49" fill="none" stroke="#fff" stroke-width="6"/><circle cx="64" cy="64" r="41" fill="none" stroke="#fff" stroke-width="2" opacity=".24"/>',
            'accent' => '<path d="M30 44 A40 40 0 0 1 93 35" fill="none" stroke="' . e($accent) . '" stroke-width="6" stroke-linecap="round"/>',
            'font_adjust' => -7,
        ],
        [
            'key' => 'oval-wide',
            'shape' => '<ellipse cx="64" cy="64" rx="54" ry="38" fill="none" stroke="#fff" stroke-width="6"/><ellipse cx="64" cy="64" rx="46" ry="30" fill="none" stroke="#fff" stroke-width="2" opacity=".24"/>',
            'accent' => '<path d="M22 49 C42 27 80 25 105 45" fill="none" stroke="' . e($accent) . '" stroke-width="6" stroke-linecap="round"/>',
            'font_adjust' => -10,
        ],
        [
            'key' => 'oval-tall',
            'shape' => '<ellipse cx="64" cy="64" rx="37" ry="54" fill="none" stroke="#fff" stroke-width="6"/><ellipse cx="64" cy="64" rx="29" ry="46" fill="none" stroke="#fff" stroke-width="2" opacity=".24"/>',
            'accent' => '<path d="M43 35 C53 17 76 14 88 33" fill="none" stroke="' . e($accent) . '" stroke-width="6" stroke-linecap="round"/>',
            'font_adjust' => -12,
        ],
    ];
    $frameVariant = $frameVariants[abs($index) % count($frameVariants)];
    $frameTextSize = max(30, $fontSize + $frameVariant['font_adjust']);

    return match ($style) {
        'cut' => iconMakerRandomCutTemplate($text, $accent, $dark, $uid, $fontSize, $font, $index, $random),
        'split' => '<text x="' . (64 - $shift) . '" y="82" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="#fff" clip-path="url(#' . $uid . 'top)">' . $text . '</text><text x="' . (64 + $shift) . '" y="82" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="' . e($accent) . '" clip-path="url(#' . $uid . 'bottom)">' . $text . '</text>',
        'outline' => '<text x="' . (64 + $shift) . '" y="' . (80 + $shift) . '" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="' . e($accent) . '" opacity=".62">' . $text . '</text><text x="' . (64 - intdiv($shift, 2)) . '" y="' . (80 - intdiv($shift, 2)) . '" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="none" stroke="#fff" stroke-width="3">' . $text . '</text>',
        'mirror' => '<text x="' . (64 + $shift + 7) . '" y="82" text-anchor="middle" font-size="54" ' . $font . ' fill="#fff">' . $glyph . '</text><text x="-' . (64 - $shift - 7) . '" y="82" text-anchor="middle" font-size="54" ' . $font . ' fill="' . e($accent) . '" transform="scale(-1 1)" opacity=".78">' . $glyph . '</text><path d="M64 30 V98" stroke="#fff" stroke-width="3" opacity=".35"/>',
        'frame' => '<g data-frame="' . $frameVariant['key'] . '">' . $frameVariant['shape'] . $frameVariant['accent'] . '<text x="64" y="82" text-anchor="middle" font-size="' . $frameTextSize . '" ' . $font . ' fill="#fff">' . $text . '</text></g>',
        'stack' => '<text x="' . (64 - $shift) . '" y="' . (80 - $shift) . '" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="' . e($accent) . '" opacity=".86">' . $text . '</text><text x="' . (64 + $shift) . '" y="' . (80 + $shift) . '" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="#fff" opacity=".94">' . $text . '</text>',
        default => '<text x="64" y="82" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="#fff">' . $text . '</text>',
    };
}
function iconMakerRandomCutTemplate(
    string $text,
    string $accent,
    string $dark,
    string $uid,
    int $fontSize,
    string $font,
    int $index,
    IconMakerRandom $random
): string {
    $accent = e($accent);
    $dark = e($dark);
    $offset = $random->int(3, 7);
    $variant = $index % 12;
    $baseText = '<text x="64" y="84" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="#fff">' . $text . '</text>';
    $maskOpen = '<defs><mask id="' . $uid . 'letter-cut"><rect width="128" height="128" fill="white"/>';

    return match ($variant) {
        0 => '<g data-cut="diagonal-offset"><defs>'
            . '<clipPath id="' . $uid . 'cut-a"><path d="M0 0 H128 V52 L0 77Z"/></clipPath>'
            . '<clipPath id="' . $uid . 'cut-b"><path d="M0 82 L128 57 V128 H0Z"/></clipPath>'
            . '</defs><text x="' . (64 - $offset) . '" y="84" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="#fff" clip-path="url(#' . $uid . 'cut-a)">' . $text . '</text>'
            . '<text x="' . (64 + $offset) . '" y="84" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="' . $accent . '" clip-path="url(#' . $uid . 'cut-b)">' . $text . '</text>'
            . '<path d="M94 49 l9 -2 l-2 9 l-9 2Z" fill="' . $accent . '"/></g>',
        1 => '<g data-cut="vertical-wedge">' . $maskOpen
            . '<path d="M58 24 L69 24 L61 101 L51 101Z" fill="black"/></mask></defs>'
            . '<g mask="url(#' . $uid . 'letter-cut)">' . $baseText . '</g>'
            . '<path d="M66 47 L73 43 L69 78 L62 83Z" fill="' . $accent . '"/></g>',
        2 => '<g data-cut="twin-slits">' . $maskOpen
            . '<path d="M22 61 L106 42 M24 76 L108 57" fill="none" stroke="black" stroke-width="4"/></mask></defs>'
            . '<g mask="url(#' . $uid . 'letter-cut)">' . $baseText . '</g>'
            . '<path d="M21 58 l13 -3 M94 63 l13 -3" stroke="' . $accent . '" stroke-width="3" stroke-linecap="round"/></g>',
        3 => '<g data-cut="chevron-break">' . $maskOpen
            . '<path d="M24 52 L63 74 L104 45" fill="none" stroke="black" stroke-width="6" stroke-linejoin="bevel"/></mask></defs>'
            . '<g mask="url(#' . $uid . 'letter-cut)">' . $baseText . '</g>'
            . '<path d="M48 70 L63 79 L79 67" fill="none" stroke="' . $accent . '" stroke-width="3" stroke-linecap="square" stroke-linejoin="bevel"/></g>',
        4 => '<g data-cut="staggered-bands"><defs>'
            . '<clipPath id="' . $uid . 'band-a"><rect x="0" y="20" width="128" height="32"/></clipPath>'
            . '<clipPath id="' . $uid . 'band-b"><rect x="0" y="57" width="128" height="18"/></clipPath>'
            . '<clipPath id="' . $uid . 'band-c"><rect x="0" y="80" width="128" height="28"/></clipPath>'
            . '</defs><text x="' . (64 - $offset) . '" y="84" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="#fff" clip-path="url(#' . $uid . 'band-a)">' . $text . '</text>'
            . '<text x="' . (64 + $offset) . '" y="84" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="' . $accent . '" clip-path="url(#' . $uid . 'band-b)">' . $text . '</text>'
            . '<text x="' . (64 - 2) . '" y="84" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="#fff" clip-path="url(#' . $uid . 'band-c)">' . $text . '</text></g>',
        5 => '<g data-cut="diamond-void">' . $maskOpen
            . '<path d="M64 49 L77 63 L64 77 L51 63Z" fill="black"/></mask></defs>'
            . '<g mask="url(#' . $uid . 'letter-cut)">' . $baseText . '</g>'
            . '<path d="M64 53 L73 63 L64 73 L55 63Z" fill="none" stroke="' . $accent . '" stroke-width="3"/></g>',
        6 => '<g data-cut="corner-chips">' . $maskOpen
            . '<g fill="black" transform="rotate(-16 64 64)"><rect x="82" y="39" width="21" height="9"/><rect x="25" y="72" width="18" height="9"/></g></mask></defs>'
            . '<g mask="url(#' . $uid . 'letter-cut)">' . $baseText . '</g>'
            . '<g fill="' . $accent . '" transform="rotate(-16 64 64)"><rect x="96" y="48" width="10" height="5"/><rect x="21" y="66" width="9" height="5"/></g></g>',
        7 => '<g data-cut="axis-split"><defs>'
            . '<clipPath id="' . $uid . 'axis-l"><rect width="60" height="128"/></clipPath>'
            . '<clipPath id="' . $uid . 'axis-r"><rect x="68" width="60" height="128"/></clipPath>'
            . '</defs><text x="' . (64 - $offset) . '" y="84" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="#fff" clip-path="url(#' . $uid . 'axis-l)">' . $text . '</text>'
            . '<text x="' . (64 + $offset) . '" y="84" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="' . $accent . '" clip-path="url(#' . $uid . 'axis-r)">' . $text . '</text>'
            . '<path d="M64 35 V51 M64 78 V94" stroke="' . $dark . '" stroke-width="3" stroke-linecap="square"/></g>',
        8 => '<g data-cut="arc-incision">' . $maskOpen
            . '<path d="M27 74 Q63 43 103 68" fill="none" stroke="black" stroke-width="6"/></mask></defs>'
            . '<g mask="url(#' . $uid . 'letter-cut)">' . $baseText . '</g>'
            . '<path d="M35 75 Q64 53 94 68" fill="none" stroke="' . $accent . '" stroke-width="2.5" stroke-linecap="round"/><circle cx="35" cy="75" r="3" fill="' . $accent . '"/></g>',
        9 => '<g data-cut="wedge-inlay"><defs>'
            . '<mask id="' . $uid . 'wedge-mask"><rect width="128" height="128" fill="white"/><path d="M55 56 L105 41 L72 78Z" fill="black"/></mask>'
            . '<clipPath id="' . $uid . 'wedge-piece"><path d="M55 56 L105 41 L72 78Z"/></clipPath>'
            . '</defs><g mask="url(#' . $uid . 'wedge-mask)">' . $baseText . '</g>'
            . '<g clip-path="url(#' . $uid . 'wedge-piece)" transform="translate(5 -3) rotate(5 72 60)"><text x="64" y="84" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="' . $accent . '">' . $text . '</text></g></g>',
        10 => '<g data-cut="perforated-step">' . $maskOpen
            . '<g fill="black" transform="rotate(-18 64 64)"><rect x="28" y="56" width="17" height="7"/><rect x="51" y="56" width="17" height="7"/><rect x="74" y="56" width="17" height="7"/></g></mask></defs>'
            . '<g mask="url(#' . $uid . 'letter-cut)">' . $baseText . '</g>'
            . '<g fill="' . $accent . '" transform="rotate(-18 64 64)"><rect x="34" y="66" width="7" height="4"/><rect x="80" y="66" width="7" height="4"/></g></g>',
        default => '<g data-cut="four-way-shift"><defs>'
            . '<clipPath id="' . $uid . 'quad-a"><rect x="0" y="0" width="61" height="61"/></clipPath>'
            . '<clipPath id="' . $uid . 'quad-b"><rect x="67" y="0" width="61" height="61"/></clipPath>'
            . '<clipPath id="' . $uid . 'quad-c"><rect x="0" y="67" width="61" height="61"/></clipPath>'
            . '<clipPath id="' . $uid . 'quad-d"><rect x="67" y="67" width="61" height="61"/></clipPath>'
            . '</defs><text x="' . (64 - $offset) . '" y="' . (84 - $offset) . '" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="#fff" clip-path="url(#' . $uid . 'quad-a)">' . $text . '</text>'
            . '<text x="' . (64 + $offset) . '" y="' . (84 - $offset) . '" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="' . $accent . '" clip-path="url(#' . $uid . 'quad-b)">' . $text . '</text>'
            . '<text x="' . (64 - $offset) . '" y="' . (84 + $offset) . '" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="' . $accent . '" clip-path="url(#' . $uid . 'quad-c)">' . $text . '</text>'
            . '<text x="' . (64 + $offset) . '" y="' . (84 + $offset) . '" text-anchor="middle" font-size="' . $fontSize . '" ' . $font . ' fill="#fff" clip-path="url(#' . $uid . 'quad-d)">' . $text . '</text></g>',
    };
}

function iconMakerRandomContainerShape(string $container, int $radius, string $attributes = ''): string
{
    $attr = $attributes !== '' ? ' ' . $attributes : '';
    return match ($container) {
        'circle' => '<circle cx="64" cy="64" r="58"' . $attr . '/>',
        'squircle' => '<path d="M64 6 C102 6 122 26 122 64 C122 102 102 122 64 122 C26 122 6 102 6 64 C6 26 26 6 64 6Z"' . $attr . '/>',
        'hex' => '<path d="M64 5 L115 34 V94 L64 123 L13 94 V34Z"' . $attr . '/>',
        'diamond' => '<path d="M64 4 L124 64 L64 124 L4 64Z"' . $attr . '/>',
        'arch' => '<path d="M14 116 V60 C14 27 34 10 64 10 C94 10 114 27 114 60 V116Z"' . $attr . '/>',
        'shield' => '<path d="M64 6 L116 25 V65 C116 94 96 113 64 123 C32 113 12 94 12 65 V25Z"' . $attr . '/>',
        'capsule' => '<rect x="7" y="22" width="114" height="84" rx="42"' . $attr . '/>',
        'notch' => '<path d="M25 7 H103 L121 25 V103 L103 121 H25 L7 103 V25Z"' . $attr . '/>',
        default => '<rect x="8" y="8" width="112" height="112" rx="' . $radius . '"' . $attr . '/>',
    };
}

function iconMakerRandomBackgroundTreatment(string $treatment, IconMakerRandom $random, string $accent): string
{
    $accent = e($accent);
    return match ($treatment) {
        'diagonal' => '<path d="M8 124 L76 0 H132 L64 128Z" fill="' . $accent . '" opacity=".20"/>',
        'split' => '<rect x="68" width="60" height="128" fill="' . $accent . '" opacity=".18"/><path d="M68 0 L48 128" stroke="#fff" stroke-width="3" opacity=".10"/>',
        'horizon' => '<path d="M-8 94 Q48 45 136 76 V136 H-8Z" fill="' . $accent . '" opacity=".22"/>',
        'bands' => '<g fill="none" stroke="#fff" stroke-width="5" opacity=".10"><path d="M-10 40 L138 10"/><path d="M-10 70 L138 40"/><path d="M-10 100 L138 70"/><path d="M-10 130 L138 100"/></g>',
        'focus' => '<circle cx="' . $random->int(24, 104) . '" cy="' . $random->int(22, 106) . '" r="' . $random->int(35, 58) . '" fill="' . $accent . '" opacity=".20"/>',
        default => '',
    };
}

/**
 * @return array<string,array<int,string>>
 */
function iconMakerRandomIndustryIconMap(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }

    $file = __DIR__ . '/assets/icon-library/industry-map.php';
    if (!is_file($file)) {
        return $map = [];
    }
    $loaded = require $file;

    return $map = is_array($loaded) ? $loaded : [];
}

function iconMakerRandomLibraryTemplate(
    string $industry,
    int $index,
    int $seriesSeed,
    IconMakerRandom $random,
    string $primary,
    string $accent,
    string $dark,
    string $fallbackTemplate,
    string $letters,
    string $uid
): string {
    $map = iconMakerRandomIndustryIconMap();
    $items = $map[$industry] ?? [];
    if ($items === []) {
        return iconMakerRandomTemplate($fallbackTemplate, $random, $accent, $letters, $uid);
    }

    $count = count($items);
    $step = 5;
    while ($count > 1 && iconMakerRandomGreatestCommonDivisor($step, $count) !== 1) {
        $step += 2;
    }
    $offset = $seriesSeed % $count;
    $id = (string) $items[($offset + $index * $step) % $count];
    $parts = explode(':', $id, 3);
    if (count($parts) !== 3) {
        return iconMakerRandomTemplate($fallbackTemplate, $random, $accent, $letters, $uid);
    }
    [$source, $style, $name] = $parts;
    $allowed = [
        'phosphor' => ['bold', 'duotone'],
        'tabler' => ['outline'],
    ];
    if (
        !isset($allowed[$source])
        || !in_array($style, $allowed[$source], true)
        || preg_match('/^[a-z0-9-]+$/', $name) !== 1
    ) {
        return iconMakerRandomTemplate($fallbackTemplate, $random, $accent, $letters, $uid);
    }

    $file = __DIR__ . '/assets/icon-library/' . $source . '/' . $style . '/' . $name . '.svg';
    if (!is_file($file)) {
        return iconMakerRandomTemplate($fallbackTemplate, $random, $accent, $letters, $uid);
    }
    $svg = file_get_contents($file);
    if ($svg === false || !str_contains($svg, '<svg')) {
        return iconMakerRandomTemplate($fallbackTemplate, $random, $accent, $letters, $uid);
    }

    $primaryColor = e($primary);
    $accentColor = e($accent);
    $darkColor = e($dark);
    $size = [84, 78, 88, 82][$index % 4];
    $position = round((128 - $size) / 2, 2);
    $nested = preg_replace(
        '/<svg\b/',
        '<svg x="' . $position . '" y="' . $position . '" width="' . $size . '" height="' . $size . '"',
        trim($svg),
        1
    );
    if (!is_string($nested)) {
        return iconMakerRandomTemplate($fallbackTemplate, $random, $accent, $letters, $uid);
    }
    $nested = str_replace('currentColor', $primaryColor, $nested);
    if ($source === 'phosphor' && $style === 'duotone') {
        $nested = preg_replace('/<path([^>]*opacity="[^"]+"[^>]*?)(\/?)>/', '<path$1 fill="' . $accentColor . '"$2>', $nested, 1) ?? $nested;
    }

    $variant = $index % 4;
    $background = match ($variant) {
        0 => '<circle cx="99" cy="29" r="10" fill="' . $accentColor . '" opacity=".92"/>',
        1 => '<path d="M20 45 V24 H41 M87 104 H108 V83" fill="none" stroke="' . $accentColor . '" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>',
        2 => '<path d="M26 101 L101 26" stroke="' . $accentColor . '" stroke-width="12" stroke-linecap="round" opacity=".2"/>',
        default => '<path d="M22 87 Q64 116 106 87" fill="none" stroke="' . $accentColor . '" stroke-width="7" stroke-linecap="round" opacity=".72"/>',
    };
    $foreground = match ($variant) {
        0 => '<circle cx="99" cy="29" r="4" fill="' . $darkColor . '"/>',
        1 => '<circle cx="28" cy="96" r="5" fill="' . $accentColor . '"/>',
        2 => '<path d="M90 24 L107 41" stroke="' . $darkColor . '" stroke-width="5" stroke-linecap="round"/>',
        default => '<circle cx="64" cy="102" r="5" fill="' . $accentColor . '"/>',
    };

    return '<g data-library-icon="' . e($id) . '">' . $background . $nested . $foreground . '</g>';
}

function iconMakerRandomGreatestCommonDivisor(int $left, int $right): int
{
    while ($right !== 0) {
        [$left, $right] = [$right, $left % $right];
    }

    return abs($left);
}

function iconMakerRandomTemplate(string $template, IconMakerRandom $random, string $accent, string $letters, string $uid): string
{
    $letterSize = mb_strlen($letters, 'UTF-8') > 1 ? 34 : 50;
    return match ($template) {
        'orbit' => iconMakerRandomOrbit($random, $accent),
        'nodes' => iconMakerRandomNodes($random, $accent),
        'circuit' => iconMakerRandomCircuit($random, $accent),
        'pulse' => iconMakerRandomPulse($random, $accent),
        'mesh' => iconMakerRandomMesh($random, $accent),
        'hex' => iconMakerRandomHex($random, $accent, $uid),
        'blocks' => iconMakerRandomBlocks($random, $accent),
        'ring_cut' => iconMakerRandomRingCut($random, $accent),
        'gear' => iconMakerRandomGear($random, $accent),
        'chevron' => iconMakerRandomChevron($random, $accent),
        'roof' => iconMakerRandomRoof($random, $accent),
        'grid' => iconMakerRandomGrid($random, $accent),
        'bridge' => iconMakerRandomBridge($random, $accent),
        'tower' => iconMakerRandomTower($random, $accent),
        'cross' => iconMakerRandomCross($random, $accent),
        'cell' => iconMakerRandomCell($random, $accent),
        'wave' => iconMakerRandomWave($random, $accent),
        'dna' => iconMakerRandomDna($random, $accent),
        'heartpulse' => iconMakerRandomHeartPulse($random, $accent),
        'book' => iconMakerRandomBook($random, $accent),
        'shield_mark' => iconMakerRandomShieldMark($random, $accent),
        'spark' => iconMakerRandomSpark($random, $accent),
        'pencil' => iconMakerRandomPencil($random, $accent),
        'columns' => iconMakerRandomColumns($random, $accent),
        'bars' => iconMakerRandomBars($random, $accent),
        'coin' => iconMakerRandomCoin($random, $accent),
        'trend' => iconMakerRandomTrend($random, $accent),
        'ledger' => iconMakerRandomLedger($random, $accent),
        'leaf' => iconMakerRandomLeaf($random, $accent),
        'bowl' => iconMakerRandomBowl($random, $accent),
        'seed' => iconMakerRandomSeedMark($random, $accent),
        'sprout' => iconMakerRandomSprout($random, $accent),
        'wheat' => iconMakerRandomWheat($random, $accent),
        'petal' => iconMakerRandomPetal($random, $accent),
        'ribbon' => iconMakerRandomRibbon($random, $accent),
        'gem' => iconMakerRandomGem($random, $accent),
        'initial_cut' => iconMakerRandomInitialCut($random, $accent, $letters),
        'initial_ring' => iconMakerRandomInitialRing($random, $accent, $letters),
        'initial_axis' => iconMakerRandomInitialAxis($random, $accent, $letters),
        'initial_tiles' => iconMakerRandomInitialTiles($random, $accent, $letters),
        'initial_bracket' => iconMakerRandomInitialBracket($random, $accent, $letters),
        'initial_negative' => iconMakerRandomInitialNegative($random, $accent, $letters),
        default => '<circle cx="64" cy="64" r="38" fill="#fff" opacity=".94"/><circle cx="' . $random->int(82, 94) . '" cy="' . $random->int(31, 43) . '" r="' . $random->int(8, 12) . '" fill="' . e($accent) . '"/><text x="64" y="78" text-anchor="middle" font-family="Arial,Microsoft YaHei,sans-serif" font-size="' . $letterSize . '" font-weight="800" fill="#111827">' . e($letters) . '</text>',
    };
}

function iconMakerRandomInitialCut(IconMakerRandom $random, string $accent, string $letters): string
{
    $size = mb_strlen($letters, 'UTF-8') > 1 ? 43 : 61;
    $y = $random->int(60, 72);
    return '<text x="64" y="83" text-anchor="middle" font-family="Arial,Microsoft YaHei,sans-serif" font-size="' . $size . '" font-weight="900" fill="#fff">' . e($letters) . '</text><path d="M23 ' . ($y + 15) . ' L105 ' . ($y - 19) . '" stroke="' . e($accent) . '" stroke-width="10"/><path d="M25 ' . ($y + 10) . ' L103 ' . ($y - 22) . '" stroke="#fff" stroke-width="3" opacity=".8"/>';
}

function iconMakerRandomInitialRing(IconMakerRandom $random, string $accent, string $letters): string
{
    $size = mb_strlen($letters, 'UTF-8') > 1 ? 31 : 43;
    $dash = $random->int(70, 105) . ' ' . $random->int(20, 38);
    return '<circle cx="64" cy="64" r="38" fill="none" stroke="#fff" stroke-width="8" stroke-dasharray="' . $dash . '"/><circle cx="64" cy="64" r="28" fill="' . e($accent) . '" opacity=".88"/><text x="64" y="76" text-anchor="middle" font-family="Arial,Microsoft YaHei,sans-serif" font-size="' . $size . '" font-weight="900" fill="#fff">' . e($letters) . '</text><circle cx="95" cy="37" r="7" fill="#fff"/>';
}

function iconMakerRandomInitialAxis(IconMakerRandom $random, string $accent, string $letters): string
{
    $glyphA = e(mb_substr($letters, 0, 1, 'UTF-8'));
    $glyphB = e(mb_substr($letters, 1, 1, 'UTF-8')) ?: $glyphA;
    $gap = $random->int(5, 11);
    return '<path d="M64 24 V104" stroke="#fff" stroke-width="5" opacity=".42"/><text x="' . (61 - $gap) . '" y="82" text-anchor="middle" font-family="Arial,Microsoft YaHei,sans-serif" font-size="54" font-weight="900" fill="#fff">' . $glyphA . '</text><text x="' . (67 + $gap) . '" y="82" text-anchor="middle" font-family="Arial,Microsoft YaHei,sans-serif" font-size="54" font-weight="900" fill="' . e($accent) . '" opacity=".88">' . $glyphB . '</text>';
}

function iconMakerRandomInitialTiles(IconMakerRandom $random, string $accent, string $letters): string
{
    $glyphA = e(mb_substr($letters, 0, 1, 'UTF-8'));
    $glyphB = e(mb_substr($letters, 1, 1, 'UTF-8')) ?: $glyphA;
    $round = $random->pick([4, 9, 15]);
    return '<rect x="24" y="31" width="45" height="55" rx="' . $round . '" fill="#fff"/><rect x="59" y="43" width="45" height="55" rx="' . $round . '" fill="' . e($accent) . '"/><text x="46" y="72" text-anchor="middle" font-family="Arial,Microsoft YaHei,sans-serif" font-size="31" font-weight="900" fill="#111827">' . $glyphA . '</text><text x="82" y="83" text-anchor="middle" font-family="Arial,Microsoft YaHei,sans-serif" font-size="31" font-weight="900" fill="#fff">' . $glyphB . '</text>';
}

function iconMakerRandomInitialBracket(IconMakerRandom $random, string $accent, string $letters): string
{
    $size = mb_strlen($letters, 'UTF-8') > 1 ? 40 : 58;
    $length = $random->int(18, 28);
    return '<path d="M28 ' . (28 + $length) . ' V28 H' . (28 + $length) . ' M100 ' . (28 + $length) . ' V28 H' . (100 - $length) . ' M28 ' . (100 - $length) . ' V100 H' . (28 + $length) . ' M100 ' . (100 - $length) . ' V100 H' . (100 - $length) . '" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round"/><text x="64" y="82" text-anchor="middle" font-family="Arial,Microsoft YaHei,sans-serif" font-size="' . $size . '" font-weight="900" fill="' . e($accent) . '">' . e($letters) . '</text>';
}

function iconMakerRandomInitialNegative(IconMakerRandom $random, string $accent, string $letters): string
{
    $size = mb_strlen($letters, 'UTF-8') > 1 ? 38 : 54;
    $direction = $random->pick([1, -1]);
    return '<g transform="translate(64 64) scale(' . $direction . ' 1) translate(-64 -64)"><path d="M23 36 Q23 24 35 24 H105 L78 104 H35 Q23 104 23 92Z" fill="#fff"/><path d="M73 24 H105 L78 104 H47Z" fill="' . e($accent) . '" opacity=".9"/></g><text x="57" y="82" text-anchor="middle" font-family="Arial,Microsoft YaHei,sans-serif" font-size="' . $size . '" font-weight="900" fill="#111827">' . e($letters) . '</text>';
}
function iconMakerRandomOrbit(IconMakerRandom $random, string $accent): string
{
    $angle = $random->int(-42, 42);
    $dash = $random->int(54, 98) . ' ' . $random->int(18, 42);
    return '<g fill="none" stroke-linecap="round"><circle cx="64" cy="64" r="34" stroke="#fff" stroke-width="9" stroke-dasharray="' . $dash . '"/><ellipse cx="64" cy="64" rx="43" ry="19" stroke="' . e($accent) . '" stroke-width="6" transform="rotate(' . $angle . ' 64 64)"/></g><circle cx="' . $random->int(84, 98) . '" cy="' . $random->int(30, 45) . '" r="7" fill="#fff"/>';
}

function iconMakerRandomNodes(IconMakerRandom $random, string $accent): string
{
    $sets = [
        [[27, 78], [45, 44], [67, 62], [94, 32], [91, 91]],
        [[30, 38], [52, 58], [38, 91], [77, 82], [99, 49]],
        [[25, 67], [49, 31], [70, 48], [98, 72], [63, 96]],
    ];
    $points = $random->pick($sets);
    $path = 'M' . $points[0][0] . ' ' . $points[0][1];
    foreach (array_slice($points, 1) as $point) {
        $path .= ' L' . $point[0] . ' ' . $point[1];
    }
    $svg = '<path d="' . $path . '" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>';
    foreach ($points as $i => $point) {
        $svg .= '<circle cx="' . $point[0] . '" cy="' . $point[1] . '" r="' . ($i % 2 === 0 ? 7 : 5) . '" fill="' . ($i % 2 === 0 ? e($accent) : '#fff') . '" stroke="#fff" stroke-width="2"/>';
    }
    return $svg;
}

function iconMakerRandomCircuit(IconMakerRandom $random, string $accent): string
{
    $flip = $random->pick([1, -1]);
    return '<g transform="translate(64 64) scale(' . $flip . ' 1) translate(-64 -64)" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M25 40 H49 V58 H76 V31 H101" stroke="#fff" stroke-width="7"/><path d="M27 88 H56 V72 H84 V94 H102" stroke="' . e($accent) . '" stroke-width="7"/><circle cx="25" cy="40" r="6" fill="#fff"/><circle cx="101" cy="31" r="6" fill="' . e($accent) . '"/><circle cx="102" cy="94" r="6" fill="#fff"/></g>';
}

function iconMakerRandomPulse(IconMakerRandom $random, string $accent): string
{
    $peak = $random->int(28, 40);
    return '<path d="M22 67 H41 L50 ' . $peak . ' L64 94 L76 49 L85 67 H106" fill="none" stroke="#fff" stroke-width="9" stroke-linecap="round" stroke-linejoin="round"/><circle cx="50" cy="' . $peak . '" r="7" fill="' . e($accent) . '"/><path d="M30 94 H98" stroke="' . e($accent) . '" stroke-width="6" stroke-linecap="round" opacity=".8"/>';
}

function iconMakerRandomMesh(IconMakerRandom $random, string $accent): string
{
    $offset = $random->int(-6, 6);
    return '<g fill="none" stroke-linejoin="round"><path d="M64 24 L101 45 L96 86 L61 105 L28 83 L31 43Z" stroke="#fff" stroke-width="6"/><path d="M31 43 L96 86 M101 45 L28 83 M64 24 L61 105" stroke="' . e($accent) . '" stroke-width="5" opacity=".9"/><circle cx="' . (64 + $offset) . '" cy="64" r="9" fill="#fff" stroke="' . e($accent) . '" stroke-width="4"/></g>';
}

function iconMakerRandomHex(IconMakerRandom $random, string $accent, string $uid): string
{
    $inset = $random->int(36, 43);
    return '<g mask="url(#' . $uid . 'cut)"><path d="M64 22 L101 43 V85 L64 106 L27 85 V43Z" fill="#fff"/><path d="M64 ' . $inset . ' L88 51 V77 L64 91 L40 77 V51Z" fill="' . e($accent) . '"/></g><path d="M27 43 L64 22 L101 43" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" opacity=".65"/>';
}

function iconMakerRandomBlocks(IconMakerRandom $random, string $accent): string
{
    $round = $random->pick([3, 7, 12]);
    $svg = '<g transform="rotate(' . $random->int(-18, 18) . ' 64 64)">';
    foreach ([[27, 27, 31, 31], [63, 27, 38, 20], [27, 63, 38, 38], [70, 55, 31, 46]] as $i => $box) {
        [$x, $y, $w, $h] = $box;
        $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w . '" height="' . $h . '" rx="' . $round . '" fill="' . ($i % 2 === 0 ? '#fff' : e($accent)) . '" opacity=".94"/>';
    }
    return $svg . '</g>';
}

function iconMakerRandomRingCut(IconMakerRandom $random, string $accent): string
{
    $rotation = $random->int(-50, 50);
    return '<g transform="rotate(' . $rotation . ' 64 64)" fill="none" stroke-linecap="round"><circle cx="64" cy="64" r="37" stroke="#fff" stroke-width="13" stroke-dasharray="' . $random->int(80, 120) . ' 34"/><circle cx="64" cy="64" r="22" stroke="' . e($accent) . '" stroke-width="7" stroke-dasharray="48 20"/><path d="M35 92 L93 34" stroke="#fff" stroke-width="7"/></g>';
}

function iconMakerRandomGear(IconMakerRandom $random, string $accent): string
{
    $svg = '<g transform="rotate(' . $random->int(0, 22) . ' 64 64)">';
    for ($i = 0; $i < 8; $i++) {
        $svg .= '<rect x="59" y="21" width="10" height="22" rx="3" fill="' . ($i % 2 === 0 ? '#fff' : e($accent)) . '" transform="rotate(' . ($i * 45) . ' 64 64)"/>';
    }
    return $svg . '<circle cx="64" cy="64" r="31" fill="#fff"/><circle cx="64" cy="64" r="15" fill="' . e($accent) . '"/><circle cx="64" cy="64" r="6" fill="#fff"/></g>';
}

function iconMakerRandomChevron(IconMakerRandom $random, string $accent): string
{
    $direction = $random->pick([1, -1]);
    return '<g transform="translate(64 64) scale(' . $direction . ' 1) translate(-64 -64)" fill="none" stroke-linecap="round" stroke-linejoin="round"><path d="M27 35 L55 64 L27 93" stroke="#fff" stroke-width="12"/><path d="M52 35 L80 64 L52 93" stroke="' . e($accent) . '" stroke-width="12"/><path d="M77 35 L105 64 L77 93" stroke="#fff" stroke-width="12" opacity=".72"/></g>';
}

function iconMakerRandomRoof(IconMakerRandom $random, string $accent): string
{
    $apex = $random->int(27, 39);
    return '<path d="M23 62 L64 ' . $apex . ' L105 62" fill="none" stroke="#fff" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M34 59 V99 H94 V59" fill="none" stroke="#fff" stroke-width="8" stroke-linejoin="round"/><rect x="56" y="70" width="17" height="29" rx="3" fill="' . e($accent) . '"/><circle cx="86" cy="45" r="7" fill="' . e($accent) . '"/>';
}

function iconMakerRandomGrid(IconMakerRandom $random, string $accent): string
{
    $gap = $random->pick([5, 8]);
    $size = intdiv(70 - $gap * 2, 3);
    $svg = '<g transform="translate(29 29) rotate(' . $random->int(-8, 8) . ' 35 35)">';
    for ($row = 0; $row < 3; $row++) {
        for ($col = 0; $col < 3; $col++) {
            $x = $col * ($size + $gap);
            $y = $row * ($size + $gap);
            $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $size . '" height="' . $size . '" rx="4" fill="' . (($row + $col) % 3 === 0 ? e($accent) : '#fff') . '" opacity=".94"/>';
        }
    }
    return $svg . '</g>';
}

function iconMakerRandomBridge(IconMakerRandom $random, string $accent): string
{
    $rise = $random->int(33, 45);
    return '<path d="M22 89 H106" stroke="#fff" stroke-width="9" stroke-linecap="round"/><path d="M27 82 Q64 ' . $rise . ' 101 82" fill="none" stroke="' . e($accent) . '" stroke-width="9"/><path d="M37 73 V91 M52 59 V91 M76 59 V91 M91 73 V91" stroke="#fff" stroke-width="5"/><circle cx="64" cy="' . ($rise + 6) . '" r="7" fill="#fff"/>';
}

function iconMakerRandomTower(IconMakerRandom $random, string $accent): string
{
    $middle = $random->int(27, 38);
    return '<rect x="27" y="57" width="23" height="43" rx="4" fill="#fff"/><rect x="53" y="' . $middle . '" width="27" height="73" rx="4" fill="' . e($accent) . '"/><rect x="83" y="47" width="18" height="53" rx="4" fill="#fff"/><path d="M20 101 H108" stroke="#fff" stroke-width="7" stroke-linecap="round"/><path d="M60 43 H73 M60 58 H73 M60 73 H73" stroke="#fff" stroke-width="5" stroke-linecap="round" opacity=".75"/>';
}

function iconMakerRandomCross(IconMakerRandom $random, string $accent): string
{
    $size = $random->int(18, 23);
    return '<rect x="' . (64 - intdiv($size, 2)) . '" y="26" width="' . $size . '" height="76" rx="' . intdiv($size, 2) . '" fill="#fff"/><rect x="26" y="' . (64 - intdiv($size, 2)) . '" width="76" height="' . $size . '" rx="' . intdiv($size, 2) . '" fill="#fff"/><circle cx="' . $random->int(88, 98) . '" cy="' . $random->int(29, 40) . '" r="8" fill="' . e($accent) . '"/>';
}

function iconMakerRandomCell(IconMakerRandom $random, string $accent): string
{
    $nucleusX = $random->int(55, 73);
    $nucleusY = $random->int(52, 72);
    return '<circle cx="64" cy="64" r="39" fill="none" stroke="#fff" stroke-width="8"/><circle cx="' . $nucleusX . '" cy="' . $nucleusY . '" r="15" fill="' . e($accent) . '"/><circle cx="42" cy="43" r="7" fill="#fff"/><circle cx="88" cy="79" r="6" fill="#fff"/><path d="M35 83 Q64 99 94 76" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" opacity=".72"/>';
}

function iconMakerRandomWave(IconMakerRandom $random, string $accent): string
{
    $bend = $random->int(33, 44);
    return '<path d="M23 67 C38 ' . $bend . ' 52 94 68 63 C82 36 95 52 106 35" fill="none" stroke="#fff" stroke-width="9" stroke-linecap="round"/><path d="M27 91 C43 72 63 108 101 74" fill="none" stroke="' . e($accent) . '" stroke-width="7" stroke-linecap="round"/><circle cx="102" cy="35" r="6" fill="' . e($accent) . '"/>';
}

function iconMakerRandomDna(IconMakerRandom $random, string $accent): string
{
    $rotation = $random->int(-15, 15);
    return '<g transform="rotate(' . $rotation . ' 64 64)" fill="none" stroke-linecap="round"><path d="M38 25 C91 42 37 83 90 103" stroke="#fff" stroke-width="7"/><path d="M90 25 C37 42 91 83 38 103" stroke="' . e($accent) . '" stroke-width="7"/><path d="M46 37 H82 M43 53 H85 M43 75 H85 M46 91 H82" stroke="#fff" stroke-width="4" opacity=".75"/></g>';
}

function iconMakerRandomHeartPulse(IconMakerRandom $random, string $accent): string
{
    return '<path d="M64 102 C29 82 22 57 35 42 C45 31 58 35 64 46 C70 35 83 31 93 42 C106 57 99 82 64 102Z" fill="#fff" opacity=".95"/><path d="M27 67 H47 L54 ' . $random->int(48, 57) . ' L64 80 L73 55 L81 67 H102" fill="none" stroke="' . e($accent) . '" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>';
}

function iconMakerRandomBook(IconMakerRandom $random, string $accent): string
{
    $tilt = $random->int(-4, 4);
    return '<g transform="rotate(' . $tilt . ' 64 64)"><path d="M25 39 C41 33 53 36 64 47 V99 C52 87 40 84 25 90Z" fill="#fff"/><path d="M103 39 C87 33 75 36 64 47 V99 C76 87 88 84 103 90Z" fill="' . e($accent) . '"/><path d="M64 47 V101" stroke="#fff" stroke-width="5" stroke-linecap="round" opacity=".6"/></g>';
}

function iconMakerRandomShieldMark(IconMakerRandom $random, string $accent): string
{
    $inset = $random->int(42, 49);
    return '<path d="M64 22 L101 37 V63 C101 86 86 101 64 111 C42 101 27 86 27 63 V37Z" fill="#fff"/><path d="M64 38 L86 47 V64 C86 78 78 89 64 97 C50 89 42 78 42 64 V47Z" fill="' . e($accent) . '"/><path d="M' . $inset . ' 65 L59 76 L82 52" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>';
}

function iconMakerRandomSpark(IconMakerRandom $random, string $accent): string
{
    $inner = $random->int(8, 13);
    return '<path d="M64 20 L74 53 L108 64 L74 75 L64 108 L54 75 L20 64 L54 53Z" fill="#fff"/><path d="M64 ' . (64 - $inner) . ' L' . (64 + $inner) . ' 64 L64 ' . (64 + $inner) . ' L' . (64 - $inner) . ' 64Z" fill="' . e($accent) . '"/><circle cx="94" cy="34" r="7" fill="' . e($accent) . '"/>';
}

function iconMakerRandomPencil(IconMakerRandom $random, string $accent): string
{
    $rotation = $random->int(-10, 10);
    return '<g transform="rotate(' . $rotation . ' 64 64)"><path d="M31 89 L81 39 L97 55 L47 105 L27 109Z" fill="#fff"/><path d="M81 39 L91 29 Q96 24 101 29 L107 35 Q112 40 107 45 L97 55Z" fill="' . e($accent) . '"/><path d="M31 89 L47 105 L27 109Z" fill="' . e($accent) . '"/></g>';
}

function iconMakerRandomColumns(IconMakerRandom $random, string $accent): string
{
    $gap = $random->int(18, 23);
    $svg = '<path d="M24 43 L64 22 L104 43Z" fill="#fff"/><path d="M23 98 H105" stroke="#fff" stroke-width="8" stroke-linecap="round"/>';
    for ($i = 0; $i < 3; $i++) {
        $x = 37 + $i * $gap;
        $svg .= '<rect x="' . $x . '" y="47" width="11" height="45" rx="5" fill="' . ($i === 1 ? e($accent) : '#fff') . '"/>';
    }
    return $svg;
}

function iconMakerRandomBars(IconMakerRandom $random, string $accent): string
{
    $heights = [$random->int(22, 34), $random->int(38, 50), $random->int(55, 69)];
    $svg = '';
    foreach ($heights as $i => $height) {
        $svg .= '<rect x="' . (29 + $i * 25) . '" y="' . (100 - $height) . '" width="16" height="' . $height . '" rx="8" fill="' . ($i === 1 ? e($accent) : '#fff') . '"/>';
    }
    return $svg . '<path d="M27 61 L58 47 L85 27 L101 33" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" opacity=".75"/>';
}

function iconMakerRandomCoin(IconMakerRandom $random, string $accent): string
{
    $rotation = $random->int(-20, 20);
    return '<g transform="rotate(' . $rotation . ' 64 64)"><circle cx="64" cy="64" r="40" fill="#fff"/><circle cx="64" cy="64" r="29" fill="none" stroke="' . e($accent) . '" stroke-width="8"/><path d="M45 75 L60 60 L70 69 L88 47" fill="none" stroke="' . e($accent) . '" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/><path d="M76 47 H88 V59" fill="none" stroke="' . e($accent) . '" stroke-width="6" stroke-linecap="round"/></g>';
}

function iconMakerRandomTrend(IconMakerRandom $random, string $accent): string
{
    $mid = $random->int(51, 68);
    return '<path d="M25 94 H103" stroke="#fff" stroke-width="7" stroke-linecap="round" opacity=".55"/><path d="M29 82 L49 ' . $mid . ' L65 70 L94 35" fill="none" stroke="#fff" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M79 35 H94 V50" fill="none" stroke="' . e($accent) . '" stroke-width="8" stroke-linecap="round"/><circle cx="49" cy="' . $mid . '" r="7" fill="' . e($accent) . '"/>';
}

function iconMakerRandomLedger(IconMakerRandom $random, string $accent): string
{
    $rows = $random->int(3, 4);
    $svg = '<rect x="27" y="25" width="74" height="78" rx="12" fill="#fff"/><rect x="38" y="38" width="23" height="14" rx="5" fill="' . e($accent) . '"/>';
    for ($i = 0; $i < $rows; $i++) {
        $y = 63 + $i * 10;
        $svg .= '<path d="M39 ' . $y . ' H88" stroke="' . ($i % 2 === 0 ? e($accent) : '#111827') . '" stroke-width="5" stroke-linecap="round" opacity=".8"/>';
    }
    return $svg;
}

function iconMakerRandomLeaf(IconMakerRandom $random, string $accent): string
{
    $flip = $random->pick([1, -1]);
    return '<g transform="translate(64 64) scale(' . $flip . ' 1) translate(-64 -64)"><path d="M29 78 C38 34 81 25 103 29 C101 70 77 101 34 99 C51 84 70 62 88 40" fill="#fff"/><path d="M34 96 C53 78 70 59 89 38" fill="none" stroke="' . e($accent) . '" stroke-width="7" stroke-linecap="round"/><path d="M54 77 L48 57 M70 60 L67 43" stroke="' . e($accent) . '" stroke-width="4" stroke-linecap="round"/></g>';
}

function iconMakerRandomBowl(IconMakerRandom $random, string $accent): string
{
    $steam = $random->int(-5, 5);
    return '<path d="M24 62 H104 C101 88 87 102 64 102 C41 102 27 88 24 62Z" fill="#fff"/><path d="M27 62 H101" stroke="' . e($accent) . '" stroke-width="8" stroke-linecap="round"/><path d="M45 51 C35 ' . (39 + $steam) . ' 52 34 44 22 M65 51 C55 39 72 34 64 22 M84 51 C74 39 91 34 83 22" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round"/>';
}

function iconMakerRandomSeedMark(IconMakerRandom $random, string $accent): string
{
    $rotation = $random->int(-18, 18);
    return '<g transform="rotate(' . $rotation . ' 64 64)"><ellipse cx="48" cy="56" rx="17" ry="27" transform="rotate(-35 48 56)" fill="#fff"/><ellipse cx="79" cy="48" rx="14" ry="23" transform="rotate(32 79 48)" fill="' . e($accent) . '"/><ellipse cx="70" cy="83" rx="16" ry="24" transform="rotate(18 70 83)" fill="#fff"/><circle cx="64" cy="64" r="7" fill="' . e($accent) . '"/></g>';
}

function iconMakerRandomSprout(IconMakerRandom $random, string $accent): string
{
    $bend = $random->int(58, 70);
    return '<path d="M64 104 C61 82 ' . $bend . ' 65 63 47" fill="none" stroke="#fff" stroke-width="8" stroke-linecap="round"/><path d="M62 55 C38 56 27 42 28 25 C49 25 63 36 62 55Z" fill="#fff"/><path d="M66 69 C86 68 99 55 99 39 C81 39 68 49 66 69Z" fill="' . e($accent) . '"/><path d="M39 100 H90" stroke="#fff" stroke-width="8" stroke-linecap="round"/>';
}

function iconMakerRandomWheat(IconMakerRandom $random, string $accent): string
{
    $rotation = $random->int(-10, 10);
    $svg = '<g transform="rotate(' . $rotation . ' 64 64)"><path d="M64 106 V30" stroke="#fff" stroke-width="7" stroke-linecap="round"/>';
    foreach ([39, 53, 67, 81] as $i => $y) {
        $svg .= '<ellipse cx="' . (51 - $i % 2 * 2) . '" cy="' . $y . '" rx="9" ry="15" transform="rotate(-43 ' . (51 - $i % 2 * 2) . ' ' . $y . ')" fill="' . ($i % 2 === 0 ? '#fff' : e($accent)) . '"/><ellipse cx="' . (77 + $i % 2 * 2) . '" cy="' . ($y + 6) . '" rx="9" ry="15" transform="rotate(43 ' . (77 + $i % 2 * 2) . ' ' . ($y + 6) . ')" fill="' . ($i % 2 === 0 ? e($accent) : '#fff') . '"/>';
    }
    return $svg . '</g>';
}

function iconMakerRandomPetal(IconMakerRandom $random, string $accent): string
{
    $count = $random->pick([4, 5, 6]);
    $svg = '<g>';
    for ($i = 0; $i < $count; $i++) {
        $angle = intdiv(360, $count) * $i;
        $svg .= '<path d="M64 22 C82 37 81 56 64 70 C47 56 46 37 64 22Z" fill="' . ($i % 3 === 0 ? e($accent) : '#fff') . '" opacity=".92" transform="rotate(' . $angle . ' 64 64)"/>';
    }
    return $svg . '<circle cx="64" cy="64" r="11" fill="#fff"/></g>';
}

function iconMakerRandomRibbon(IconMakerRandom $random, string $accent): string
{
    $twist = $random->int(40, 51);
    return '<path d="M29 91 C47 79 43 54 64 ' . $twist . ' C85 38 84 24 99 25 C99 47 86 57 68 65 C50 73 49 91 29 101Z" fill="#fff"/><path d="M99 25 C83 37 84 53 65 62 C48 70 42 84 29 91" fill="none" stroke="' . e($accent) . '" stroke-width="8" stroke-linecap="round"/><circle cx="94" cy="91" r="9" fill="' . e($accent) . '"/>';
}

function iconMakerRandomGem(IconMakerRandom $random, string $accent): string
{
    $top = $random->int(31, 40);
    return '<path d="M31 ' . $top . ' H97 L110 56 L64 106 L18 56Z" fill="#fff"/><path d="M31 ' . $top . ' L48 56 L64 ' . $top . ' L80 56 L97 ' . $top . ' M18 56 H110 M48 56 L64 106 L80 56" fill="none" stroke="' . e($accent) . '" stroke-width="6" stroke-linejoin="round"/>';
}







