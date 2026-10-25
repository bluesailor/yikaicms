<?php

declare(strict_types=1);
/**
 * 命令面板（Ctrl+K / ⌘K，2.0.4，借鉴 Avada 7.16）：跳到页面里的元素、插入元素、常用操作、
 * 整页复制粘贴与文字查找替换。纯函数（匹配排序、整页格式、只改文字的替换）在
 * assets/js/yikay-command-palette.js，可单测；这里只组装命令清单并调用编辑器已有的方法。
 * 改动文档的命令都走 runCommand，进撤销历史。
 */
$commandPaletteText = [
    'placeholder' => __('blox_cmd_placeholder'),
    'groups' => [
        'actions' => __('blox_cmd_group_actions'),
        'elements' => __('blox_cmd_group_elements'),
        'insert' => __('blox_cmd_group_insert'),
        'page' => __('blox_cmd_group_page'),
    ],
    'save' => __('blox_cmd_save'),
    'publish' => __('blox_cmd_publish'),
    'undo' => __('blox_cmd_undo'),
    'redo' => __('blox_cmd_redo'),
    'preview' => __('blox_cmd_preview'),
    'library' => __('blox_cmd_library'),
    'templates' => __('blox_cmd_templates'),
    'design' => __('blox_cmd_design'),
    'insert' => __('blox_cmd_insert'),
    'copyPage' => __('blox_cmd_copy_page'),
    'pastePage' => __('blox_cmd_paste_page'),
    'replace' => __('blox_cmd_replace'),
    'copied' => __('blox_cmd_copied'),
    'pasteInvalid' => __('blox_cmd_paste_invalid'),
    'pasteDone' => __('blox_cmd_paste_done'),
    'find' => __('blox_cmd_find'),
    'replaceWith' => __('blox_cmd_replace_with'),
    'replaceCount' => __('blox_cmd_replace_count'),
    'replaceApply' => __('blox_cmd_replace_apply'),
    'replaceDone' => __('blox_cmd_replace_done'),
    'empty' => __('blox_cmd_empty'),
    'hint' => __('blox_cmd_hint'),
];

echo json_encode($commandPaletteText, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
