<?php
declare(strict_types=1);

/**
 * 编辑器入口的源码，展开其中的**方法 partial**（不执行任何编辑器 PHP）。
 *
 * 按名字从源码里取方法的契约测试必须看到展开后的全文。入口有 500KB 预算，
 * 方法会被陆续抽进 partial——此前这里维护的是一份白名单，新增 partial 忘了登记，
 * 测试就会把「方法搬家了」报成「方法不存在」。改为从源码里认出它们，不再维护名单。
 *
 * 认的是缩进：方法 partial 的 include 写在 Alpine 组件对象里（12 空格），
 * 页面结构 partial（header/workspace/overlays）写在 HTML 体里（4 空格）。
 * 两者必须分开——按方法名切片的测试若把结构 partial 也读进来，`foo()` 会先命中
 * 模板里的 `:style="foo()"` 属性，切出来的是一段标记而不是方法体。
 */
function bloxEditorSourceForTest(): string
{
    $source = file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
    if ($source === false) {
        throw new RuntimeException('Cannot read editor source');
    }

    // \r? ：入口文件是 CRLF，少了它 `$` 永远匹配不上，展开会静默地什么都不做。
    $pattern = "~^            <\?php require __DIR__ \. '/blox_editor/partials/([a-z0-9-]+\.php)'; \?>\r?$~m";
    $expanded = preg_replace_callback($pattern, static function (array $match): string {
        $partial = file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/' . $match[1]);
        if ($partial === false) {
            throw new RuntimeException('Cannot read editor method partial: ' . $match[1]);
        }
        return $partial;
    }, $source);
    if (!is_string($expanded)) {
        throw new RuntimeException('Cannot expand editor method partials');
    }
    return $expanded;
}
