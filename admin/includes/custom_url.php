<?php
/**
 * 编辑页的「自定义网址」输入框（文章、通用内容、单页、栏目、相册、产品标签；产品与产品分类见 saveEntity）。
 *
 * 用法：保存前 customUrlPrecheck() —— 网址不合法或被占用就直接报错、条目不落库；
 *       条目保存拿到 id 后 customUrlSave() 登记；表单里 echo customUrlField()。
 * 表单没提交 custom_url（老主题、批量接口）时两个函数都不动登记表。
 */

declare(strict_types=1);

function customUrlPrecheck(string $kind, int $id, string $lang): ?string
{
    $raw = $_POST['custom_url'] ?? null;
    if (!is_string($raw)) return null;
    try {
        productRouteModel()->validate($kind, $id, $raw, $lang);
    } catch (InvalidArgumentException $e) {
        error(__($e->getMessage()));
    }
    return $raw;
}

function customUrlSave(string $kind, int $id, ?string $input, string $lang): void
{
    if ($input === null || $id < 1) return;
    try {
        productRouteModel()->assign($kind, $id, $input, $lang);
    } catch (InvalidArgumentException $e) {
        error(__($e->getMessage()));
    }
}

function customUrlField(string $kind, int $id, string $placeholder, string $class = 'w-full border rounded px-4 py-2'): string
{
    $value = $id > 0 ? productRouteModel()->pathFor($kind, $id) : '';
    $domId = 'customUrl' . ucfirst(str_replace('_', '', $kind));
    return '<label for="' . e($domId) . '" class="block text-gray-700 mt-4 mb-1">' . e(__('product_url_label')) . '</label>'
        . '<input type="text" id="' . e($domId) . '" name="custom_url" value="' . e($value) . '" class="' . e($class) . '"'
        . ' placeholder="' . e($placeholder) . '" maxlength="1500" data-testid="custom-url">'
        . '<p class="text-xs text-gray-500 mt-1">' . e(__('product_url_hint')) . '</p>';
}
