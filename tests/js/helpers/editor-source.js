const fs = require("node:fs");
const path = require("node:path");

const ROOT = path.join(__dirname, "..", "..", "..");
const INCLUDE = /^ {12}<\?php require __DIR__ \. '\/blox_editor\/partials\/([a-z0-9-]+\.php)'; \?>$/gm;

/**
 * 编辑器入口的源码，展开其中的方法 partial。
 *
 * 按名字从源码里取 Alpine 方法的测试必须看到展开后的全文：入口有 500KB 预算，
 * 方法会被陆续抽进 partial，只读入口文件的话方法会"消失"，测试报的是
 * method not found 而不是真的回归。这里从源码里认出方法 partial，不维护白名单。
 *
 * 认的是缩进：方法 partial 的 include 在 Alpine 组件对象里（12 空格），
 * 页面结构 partial（header/workspace/overlays）在 HTML 体里（4 空格）。
 * 结构 partial 不能展开——按方法名切片时，`foo()` 会先命中模板里的
 * `:style="foo()"` 属性，切出来的是标记而不是方法体。
 */
function bloxEditorSource() {
    const entry = path.join(ROOT, "admin", "blox_editor.php");
    return fs.readFileSync(entry, "utf8").replace(INCLUDE, function (match, name) {
        return fs.readFileSync(path.join(ROOT, "admin", "blox_editor", "partials", name), "utf8");
    });
}

module.exports = { bloxEditorSource };
