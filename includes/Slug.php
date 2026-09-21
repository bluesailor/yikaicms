<?php
/**
 * URL 别名（slug）的生成与净化——**唯一**净化口径。
 *
 * 独立成文件的理由（与 security.php 同惯例）：纯函数、只依赖 Pinyin，
 * 可被单测直接加载；净化规则散落在各编辑页正是 2026-09-21 客户站
 * 中文 URL 事故的成因，规则必须有一个看得见的家。
 *
 * 落库别名只允许 `a-z0-9-`：中文等非 ASCII 进 URL 会被百分号编码
 * （`/商业保险.html` → `/%E5%95%86%E4%B8%9A%E4%BF%9D%E9%99%A9.html`），
 * 链接不可读、分享易截断、部分渠道与统计工具二次转义，SEO 也吃亏。
 *
 * 带 db() 依赖的去重入口 resolveSlug() 仍在 functions.php。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/Pinyin.php';

/**
 * 根据中文标题生成 slug（取前6个字的拼音）
 */
function generateSlug(string $title, int $maxChars = 6): string
{
    $title = trim($title);
    if ($title === '') {
        return '';
    }
    // 纯 ASCII 标题（英文/数字）按词切分，保留到 60 字符——比拼音路径的 N 字上限宽，
    // 英文标题本来就该多留信息量。
    if (preg_match('/^[\x20-\x7E]+$/', $title) === 1) {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', mb_substr($title, 0, 60)));
        return trim($slug, '-');
    }
    // 词库缺失时返回空串，由 resolveSlug 兜底成 item-<time>；这里不会抛异常，
    // 也不再依赖 vendor——v1.13.0 曾因 overtrue/pinyin v6 需 PHP8.1 而在 8.0 主机上
    // 炸掉文章/产品保存（见 v1.13.1 修复），换成自建词库后该风险不复存在。
    return Pinyin::slug(mb_substr($title, 0, $maxChars));
}

/**
 * 把任意输入净化成合法 slug（URL 别名的**唯一**净化口径）。
 *
 * 落库的 slug 只允许 `a-z0-9-`：中文等非 ASCII 字符进 URL 后会被百分号编码
 * （`/商业保险.html` → `/%E5%95%86%E4%B8%9A%E4%BF%9D%E9%99%A9.html`），链接不可读、
 * 分享易截断、部分渠道与统计工具会二次转义，SEO 上也吃亏。
 * 含非 ASCII 时转拼音而不是**把字符删光**——删光会退化成无意义的 `item-<时间戳>`，
 * 用户手输「商业保险」本应得到 `shang-ye-bao-xian`。
 * 纯 ASCII 输入保持既有白名单行为（只留字母数字与连字符），不改存量语义。
 *
 * @param string $input   用户输入或待净化的别名
 * @param int    $maxChars 非 ASCII 转拼音时取的字数上限
 */
function normalizeSlugInput(string $input, int $maxChars = 30): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (preg_match('/[^\x20-\x7E]/', $input) === 1) {
        return generateSlug($input, $maxChars);
    }
    return strtolower(trim((string) preg_replace('/[^a-zA-Z0-9\-]/', '', $input), '-'));
}

