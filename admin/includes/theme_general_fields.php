<?php
/** Default 主题 general 区试点：只消费核心声明，不执行主题包提供的表达式。 */
declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition Direct access does not load the parent page. */
if (!defined('ROOT_PATH')) { http_response_code(403); exit; }

$generalFields = ThemeSettings::generalFields();
$generalValues = $themeStyle['general'] ?? ThemeSettings::defaults()['general'];
$messageType = $messageType ?? '';
$themeGeneralErrors = $themeGeneralErrors ?? [];
// 错误时保留安全可呈现的输入，用户不用把同一区域再填一遍；校验错误仍显示在字段旁。
if ($messageType === 'error' && is_array($_POST['theme_style']['general'] ?? null)) {
    foreach ($generalFields as $fieldKey => $field) {
        $submitted = $_POST['theme_style']['general'][$fieldKey] ?? null;
        if (is_scalar($submitted) && ($field['type'] !== 'color' || preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $submitted) === 1)) {
            $generalValues[$fieldKey] = (string) $submitted;
        }
    }
}
$generalState = json_encode(['values' => $generalValues], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
?>
<fieldset class="min-w-0 md:col-span-2 rounded-lg border border-gray-200 p-4" x-data="<?php echo e($generalState); ?>" data-testid="theme-general-schema">
    <legend class="px-2 font-medium text-gray-800"><?php echo e(__('theme_schema_title')); ?></legend>
    <p class="text-sm text-gray-500 mb-4"><?php echo e(__('theme_schema_scope')); ?></p>
    <?php foreach ($themeGeneralErrors as $errorKey => $errorLabel): ?>
        <p class="text-sm text-red-600 mb-3" role="alert"><?php echo e(isset($generalFields[$errorKey]) ? __($generalFields[$errorKey]['label']) . ': ' : ''); ?><?php echo e(__($errorLabel)); ?></p>
    <?php endforeach; ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <?php foreach ($generalFields as $fieldKey => $field):
        $fieldId = 'theme_general_' . $fieldKey;
        $enabled = [];
        foreach ($field['depends_on'] as $dependency => $allowed) {
            $enabled[] = json_encode($allowed, JSON_THROW_ON_ERROR) . '.includes(values.' . $dependency . ')';
        }
        $enabledExpression = $enabled === [] ? 'true' : implode(' && ', $enabled);
        $fieldError = $themeGeneralErrors[$fieldKey] ?? '';
    ?>
        <div x-show="<?php echo e($enabledExpression); ?>">
            <label for="<?php echo e($fieldId); ?>" class="block text-sm font-medium text-gray-700 mb-2"><?php echo e(__($field['label'])); ?></label>
            <?php if ($field['type'] === 'select'): ?>
                <select id="<?php echo e($fieldId); ?>" name="theme_style[general][<?php echo e($fieldKey); ?>]"
                        x-model="values.<?php echo e($fieldKey); ?>" :disabled="!(<?php echo e($enabledExpression); ?>)"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2" aria-describedby="<?php echo e($fieldId); ?>_hint" aria-invalid="<?php echo $fieldError !== '' ? 'true' : 'false'; ?>">
                    <?php foreach ($field['options'] as $option => $label): ?>
                        <option value="<?php echo e($option); ?>" <?php echo $generalValues[$fieldKey] === $option ? 'selected' : ''; ?>><?php echo e(__($label)); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <input id="<?php echo e($fieldId); ?>" type="<?php echo e($field['type']); ?>" name="theme_style[general][<?php echo e($fieldKey); ?>]"
                       value="<?php echo e((string) $generalValues[$fieldKey]); ?>" x-model="values.<?php echo e($fieldKey); ?>"
                       :disabled="!(<?php echo e($enabledExpression); ?>)" required
                       <?php if ($field['type'] === 'number'): ?>min="<?php echo (int) $field['min']; ?>" max="<?php echo (int) $field['max']; ?>" step="<?php echo (int) $field['step']; ?>"<?php endif; ?>
                       class="<?php echo $field['type'] === 'color' ? 'w-11 h-10 p-1 border border-gray-200 rounded-lg' : 'w-full border border-gray-200 rounded-lg px-3 py-2'; ?>"
                       aria-describedby="<?php echo e($fieldId); ?>_hint" aria-invalid="<?php echo $fieldError !== '' ? 'true' : 'false'; ?>">
            <?php endif; ?>
            <p id="<?php echo e($fieldId); ?>_hint" class="mt-1 text-xs <?php echo $fieldError !== '' ? 'text-red-600' : 'text-gray-500'; ?>">
                <?php if ($fieldError !== ''): ?><?php echo e(__($fieldError)); ?>
                <?php elseif ($field['type'] === 'number'): ?><?php echo e(__('theme_schema_range', ['min' => (string) $field['min'], 'max' => (string) $field['max'], 'unit' => $field['unit']])); ?>
                <?php else: ?><?php echo e(__('theme_schema_default', ['value' => $field['type'] === 'select' ? __($field['options'][$field['default']]) : (string) $field['default']])); ?><?php endif; ?>
            </p>
        </div>
    <?php endforeach; ?>
    </div>
    <p x-show="values.color_mode === 'dark'" class="mt-3 text-sm text-gray-500" data-testid="theme-general-dependency"><?php echo e(__('theme_schema_dark_hint')); ?></p>
    <p class="mt-4 text-sm text-gray-500"><?php echo e(__('theme_schema_preview_hint')); ?> <a href="/" target="_blank" rel="noopener" class="text-primary hover:underline"><?php echo e(__('theme_schema_view_saved')); ?></a></p>
</fieldset>
