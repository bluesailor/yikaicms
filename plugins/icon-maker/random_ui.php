<?php
declare(strict_types=1);

/**
 * 随机 SVG 图标控制面板。
 *
 * 由 admin.php 引入，所需数据均在入口处完成白名单校验。
 */
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

$imRandomSvgUrl = static function (int $index) use (
    $imRandomIndustry,
    $imRandomName,
    $imRandomSeed,
    $imRandomScheme,
    $imRandomColorMode,
    $imRandomMonoColor,
    $imRandomCustomColors,
    $imRandomLetterStyle,
    $imRandomEffect,
    $imRandomBackgroundMode
): string {
    return '?' . http_build_query([
        'plugin' => 'icon-maker',
        'im_action' => 'random_svg',
        'industry' => $imRandomIndustry,
        'mark' => $imRandomName,
        'seed' => $imRandomSeed,
        'scheme' => $imRandomScheme,
        'color_mode' => $imRandomColorMode,
        'mono_color' => $imRandomMonoColor,
        'custom_color1' => $imRandomCustomColors[0],
        'custom_color2' => $imRandomCustomColors[1],
        'custom_color3' => $imRandomCustomColors[2],
        'letter_style' => $imRandomLetterStyle,
        'effect' => $imRandomEffect,
        'background' => $imRandomBackgroundMode,
        'i' => $index,
    ]);
};

$imIndustryColors = [];
foreach (array_slice($imRandomRecommendedSchemes, 0, 3) as $schemeKey) {
    $imIndustryColors[] = $imRandomSchemes[$schemeKey]['colors'][0] ?? '#64748B';
}
while (count($imIndustryColors) < 3) {
    $imIndustryColors[] = '#64748B';
}
?>
<link rel="stylesheet" href="/plugins/icon-maker/random-logo.css?v=20260812a">

<div class="im-random-lab">
    <div class="im-random-heading">
        <div>
            <h2>SVG 图标生成</h2>
            <p>先生成独立图标，再选入 LOGO 制作完成左图标、右文字排版。</p>
        </div>
        <div class="im-random-engine-actions">
            <span>12 个候选 · SVG 矢量</span>
            <button type="button" id="imRemoteGenerate" data-token="<?php echo e(csrfToken()); ?>" class="px-3 py-1.5 rounded border border-primary text-primary text-xs hover:bg-primary hover:text-white transition">使用 LOGO LAB 生成</button>
            <small id="imRemoteStatus" class="text-xs text-gray-400" role="status" aria-live="polite"></small>
        </div>
    </div>

    <div class="im-random-layout">
        <form method="get" id="imRandomForm" class="im-random-controls">
            <input type="hidden" name="plugin" value="icon-maker">
            <input type="hidden" name="random_tab" value="1">

            <div class="im-random-control-scroll" id="imRandomControlScroll">
                <section class="im-random-basic">
                    <h3>基础参数</h3>
                    <label class="im-random-field">
                        <span>图标字母 / 关键词</span>
                        <input id="imRandomMark" name="mark" value="<?php echo e($imRandomName); ?>" maxlength="24">
                    </label>
                    <label class="im-random-field">
                        <span>行业</span>
                        <select id="imRandomIndustry" name="industry">
                            <?php foreach ($imRandomIndustries as $key => $item): ?>
                                <option value="<?php echo e($key); ?>" <?php echo $key === $imRandomIndustry ? 'selected' : ''; ?>><?php echo e($item['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="im-random-field">
                        <span>图标底板</span>
                        <select id="imRandomBackground" name="background">
                            <?php foreach ($imRandomBackgroundModes as $key => $item): ?>
                                <option value="<?php echo e($key); ?>" <?php echo $key === $imRandomBackgroundMode ? 'selected' : ''; ?>><?php echo e($item['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="im-random-field">
                        <span>Seed</span>
                        <input id="imRandomSeed" name="seed" type="number" min="1" max="2147483647" value="<?php echo e((string) $imRandomSeed); ?>">
                    </label>
                </section>

                <nav class="im-random-control-nav" aria-label="生成设置分类">
                    <button type="button" data-im-control-target="imRandomRoute" aria-expanded="false">图形路线</button>
                    <button type="button" data-im-control-target="imRandomEffect" aria-expanded="false">效果通道</button>
                    <button type="button" data-im-control-target="imRandomColor" aria-expanded="false">专业配色</button>
                </nav>

                <section class="im-random-expand" id="imRandomRoute" hidden>
                    <h3>图形路线</h3>
                    <div class="im-random-option-grid">
                        <?php foreach ($imRandomLetterStyles as $key => $item): ?>
                            <label class="im-random-option" title="<?php echo e($item['tip']); ?>">
                                <input type="radio" name="letter_style" value="<?php echo e($key); ?>" data-tip="<?php echo e($item['tip']); ?>" <?php echo $key === $imRandomLetterStyle ? 'checked' : ''; ?>>
                                <span><?php echo e($item['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="im-random-summary"><?php echo e($imRandomLetterStyles[$imRandomLetterStyle]['tip']); ?></p>
                </section>

                <section class="im-random-expand" id="imRandomEffect" hidden>
                    <h3>效果通道</h3>
                    <div class="im-random-option-grid">
                        <?php foreach ($imRandomEffects as $key => $item): ?>
                            <label class="im-random-option" title="<?php echo e($item['tip']); ?>">
                                <input type="radio" name="effect" value="<?php echo e($key); ?>" data-tip="<?php echo e($item['tip']); ?>" <?php echo $key === $imRandomEffect ? 'checked' : ''; ?>>
                                <span><?php echo e($item['label']); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <p class="im-random-summary"><?php echo e($imRandomEffects[$imRandomEffect]['tip']); ?></p>
                </section>

                <section class="im-random-expand" id="imRandomColor" hidden>
                    <h3>专业配色</h3>
                    <div class="im-random-color-modes" role="radiogroup" aria-label="配色数量">
                        <?php foreach ($imRandomColorModes as $key => $item): ?>
                            <label>
                                <input type="radio" name="color_mode" value="<?php echo e($key); ?>" <?php echo $key === $imRandomColorMode ? 'checked' : ''; ?>>
                                <span><i data-bars="<?php echo e((string) $item['count']); ?>"></i><strong><?php echo e($item['label']); ?></strong></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="im-random-mono-launch" data-im-color-panel="mono" <?php echo $imRandomColorMode === 'mono' ? '' : 'hidden'; ?>>
                        <button type="button" id="imMonoPickerToggle" aria-haspopup="dialog" aria-controls="imMonoPicker" aria-expanded="false">
                            <i id="imMonoPickerTriggerSwatch"></i>
                            <strong>#<span id="imMonoPickerTriggerValue"><?php echo e(strtoupper(substr($imRandomCustomColors[0], 1))); ?></span></strong>
                            <span class="im-random-chevron"></span>
                        </button>
                    </div>

                    <dialog class="im-random-picker-dialog" id="imMonoPicker" aria-labelledby="imMonoPickerTitle">
                        <div class="im-random-picker-body">
                            <header>
                                <strong id="imMonoPickerTitle">自定义单色</strong>
                                <button type="button" id="imMonoPickerClose" aria-label="关闭">&times;</button>
                            </header>
                            <div class="im-random-picker-grid">
                                <div id="imMonoSvPicker" class="im-random-sv-picker" role="application" aria-label="饱和度与明度" tabindex="0"><span id="imMonoSvMarker"></span></div>
                                <div id="imMonoHuePicker" class="im-random-hue-picker" role="slider" aria-label="色相" aria-valuemin="0" aria-valuemax="360" aria-valuenow="0" tabindex="0"><span id="imMonoHueMarker"></span></div>
                            </div>
                            <div class="im-random-picker-meta">
                                <span id="imMonoPickerPreview"></span>
                                <label><span>#</span><input id="imMonoHexValue" value="<?php echo e(substr($imRandomCustomColors[0], 1)); ?>" maxlength="6" autocomplete="off" spellcheck="false"></label>
                            </div>
                        </div>
                    </dialog>

                    <div class="im-random-mono-list" data-im-color-panel="mono" role="radiogroup" aria-label="单色选择" <?php echo $imRandomColorMode === 'mono' ? '' : 'hidden'; ?>>
                        <label title="行业推荐">
                            <input type="radio" name="mono_color" value="industry" <?php echo $imRandomMonoColor === 'industry' ? 'checked' : ''; ?>>
                            <span class="im-random-mono-dot im-random-auto-dot" aria-label="行业推荐"></span>
                        </label>
                        <?php foreach ($imRandomMonoColors as $key => $item): ?>
                            <label title="<?php echo e($item['label']); ?>">
                                <input type="radio" name="mono_color" value="<?php echo e($key); ?>" <?php echo $imRandomMonoColor === $key ? 'checked' : ''; ?>>
                                <span class="im-random-mono-dot" style="--im-mono:<?php echo e($item['color']); ?>" aria-label="<?php echo e($item['label']); ?>"></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="radio" name="mono_color" value="custom" aria-label="自定义单色" <?php echo $imRandomMonoColor === 'custom' ? 'checked' : ''; ?> hidden>

                    <div class="im-random-scheme-list" data-im-color-panel="multi" data-mode="<?php echo e($imRandomColorMode); ?>" role="radiogroup" aria-label="双色与三色选择" <?php echo $imRandomColorMode === 'mono' ? 'hidden' : ''; ?>>
                        <label title="行业推荐">
                            <input type="radio" name="scheme" value="industry" <?php echo $imRandomScheme === 'industry' ? 'checked' : ''; ?>>
                            <span class="im-random-scheme-dot im-random-auto-dot" style="--im-c1:<?php echo e($imIndustryColors[0]); ?>;--im-c2:<?php echo e($imIndustryColors[1]); ?>;--im-c3:<?php echo e($imIndustryColors[2]); ?>"></span>
                        </label>
                        <label title="自定义颜色">
                            <input type="radio" name="scheme" value="custom" <?php echo $imRandomScheme === 'custom' ? 'checked' : ''; ?>>
                            <span class="im-random-scheme-dot im-random-custom-dot" id="imCustomSchemeDot" style="--im-c1:<?php echo e($imRandomCustomColors[0]); ?>;--im-c2:<?php echo e($imRandomCustomColors[1]); ?>;--im-c3:<?php echo e($imRandomCustomColors[2]); ?>"></span>
                        </label>
                        <?php $schemeNumber = 1; foreach ($imRandomSchemes as $key => $item): [$color1, $color2, $color3] = $item['colors']; ?>
                            <label title="配色 <?php echo str_pad((string) $schemeNumber, 2, '0', STR_PAD_LEFT); ?>">
                                <input type="radio" name="scheme" value="<?php echo e($key); ?>" <?php echo $key === $imRandomScheme ? 'checked' : ''; ?>>
                                <span class="im-random-scheme-dot" style="--im-c1:<?php echo e($color1); ?>;--im-c2:<?php echo e($color2); ?>;--im-c3:<?php echo e($color3); ?>"></span>
                            </label>
                        <?php $schemeNumber++; endforeach; ?>
                    </div>

                    <div class="im-random-custom-colors" id="imCustomColorControls" <?php echo $imRandomScheme === 'custom' && $imRandomColorMode !== 'mono' ? '' : 'hidden'; ?>>
                        <label><input id="imCustomColor1" name="custom_color1" type="color" value="<?php echo e($imRandomCustomColors[0]); ?>" aria-label="自定义颜色 1"></label>
                        <label data-im-custom-second><input id="imCustomColor2" name="custom_color2" type="color" value="<?php echo e($imRandomCustomColors[1]); ?>" aria-label="自定义颜色 2"></label>
                        <label data-im-custom-third <?php echo $imRandomColorMode === 'trio' ? '' : 'hidden'; ?>><input id="imCustomColor3" name="custom_color3" type="color" value="<?php echo e($imRandomCustomColors[2]); ?>" aria-label="自定义颜色 3"></label>
                    </div>
                </section>
            </div>

            <footer class="im-random-generate">
                <button type="submit"><i class="ti ti-sparkles"></i>生成 12 个候选</button>
                <button type="button" id="imRandomNewSeed" title="随机 Seed" aria-label="随机 Seed"><i class="ti ti-refresh"></i></button>
            </footer>
        </form>

        <div class="im-random-candidates" data-im-random-candidates data-im-order-message="<?php echo e(__('iconmaker_candidate_moved')); ?>" role="list">
            <?php for ($i = 0; $i < 12; $i++):
                $svgUrl = $imRandomSvgUrl($i);
                $resolvedEffect = iconMakerRandomResolveEffect($imRandomIndustry, $imRandomName, $imRandomSeed, $i, $imRandomEffect);
                $effectLabel = $imRandomEffects[$resolvedEffect]['label'];
            ?>
                <article class="im-random-candidate" data-im-random-candidate="<?php echo $i; ?>" role="listitem">
                    <div class="im-random-candidate-tools">
                        <span><?php echo e(__('iconmaker_candidate_label', ['n' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT)])); ?></span>
                        <button type="button" class="im-random-drag-handle" title="<?php echo e(__('iconmaker_candidate_drag')); ?>" aria-label="<?php echo e(__('iconmaker_candidate_drag_aria', ['n' => $i + 1])); ?>">
                            <i class="ti ti-grip-vertical" aria-hidden="true"></i>
                        </button>
                    </div>
                    <button type="button" class="im-random-preview im-random-use" data-src="<?php echo e($svgUrl); ?>" aria-label="使用候选 <?php echo $i + 1; ?>">
                        <img src="<?php echo e($svgUrl); ?>" alt="候选图标 <?php echo $i + 1; ?>" width="144" height="144">
                        <span><?php echo e($effectLabel); ?></span>
                    </button>
                    <div class="im-random-actions">
                        <a href="<?php echo e($svgUrl); ?>" download="logo-icon-<?php echo $i + 1; ?>.svg">SVG</a>
                        <button type="button" class="im-random-use" data-src="<?php echo e($svgUrl); ?>">用于 LOGO</button>
                    </div>
                </article>
            <?php endfor; ?>
        </div>
        <p class="sr-only" data-im-order-status aria-live="polite"></p>
    </div>
</div>
