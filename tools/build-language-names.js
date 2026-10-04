#!/usr/bin/env node
/**
 * 生成 includes/i18n/language-names.php：每种界面语言下，各注册语言的名称（CLDR，经 Node 自带的完整 ICU）。
 * 后台「语言设置」用它把「Bahasa Melayu」显示成「马来语 · Bahasa Melayu」，主机不必装 intl 扩展。
 *
 * 用法：node tools/build-language-names.js
 * 语言注册表（includes/i18n/LanguageRegistry.php）增删语言后重跑一次并提交产物。
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const php = process.env.PHP_BINARY || 'php';
// 从注册表取语言代码与 hreflang（单一来源）
const registry = JSON.parse(execFileSync(php, ['-r',
    'require "includes/i18n/LanguageRegistry.php"; $o = []; foreach (LanguageRegistry::all() as $c => $l) $o[$c] = $l["hreflang"]; echo json_encode($o);'],
    { cwd: root, encoding: 'utf8' }));
// 简体 / 繁体按书写系统取名（CLDR 的 zh-CN 名称是「中文（中国）」，不是用户要看的）
const tag = code => ({ 'zh-CN': 'zh-Hans', 'zh-TW': 'zh-Hant' }[code] || registry[code]);

const table = {};
for (const ui of Object.keys(registry)) {
    let names;
    try {
        names = new Intl.DisplayNames([tag(ui)], { type: 'language', languageDisplay: 'standard', fallback: 'none' });
    } catch (e) {
        continue;
    }
    table[ui] = {};
    for (const code of Object.keys(registry)) {
        const name = names.of(tag(code));
        if (name && name !== tag(code)) table[ui][code] = name;
    }
}

const esc = value => "'" + String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
let out = "<?php\n\ndeclare(strict_types=1);\n\n"
    + "// 由 tools/build-language-names.js 生成（CLDR 语言名称），勿手改；注册表增删语言后重跑。\n"
    + "// 界面语言 => [语言代码 => 该界面语言里的名称]\n"
    + "return [\n";
for (const ui of Object.keys(table)) {
    out += '    ' + esc(ui) + ' => [';
    out += Object.keys(table[ui]).map(code => esc(code) + ' => ' + esc(table[ui][code])).join(', ');
    out += "],\n";
}
out += "];\n";
fs.writeFileSync(path.join(root, 'includes/i18n/language-names.php'), out);
console.log('language-names.php: ' + Object.keys(table).length + ' UI languages × ' + Object.keys(registry).length + ' languages');
