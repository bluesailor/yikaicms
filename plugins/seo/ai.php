<?php
/**
 * SEO 工坊 - AI 一键优化 meta 端点（专业版）
 *
 * 供内容编辑页的 SEO 分析面板 AJAX 调用：据标题+正文生成 SEO 标题 / 描述 / 关键词。
 * 复用站内 AiService（同「AI 对话改站」的 API 配置）。license_has_module('seo-pro') 闸。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// 全程 JSON 响应
set_error_handler(function ($no, $str, $file, $line) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => "{$str} in {$file}:{$line}"]);
    exit;
});
set_exception_handler(function ($e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
if (!class_exists('AiService')) {
    require_once ROOT_PATH . '/includes/AiService.php';
}

header('Content-Type: application/json; charset=utf-8');

// 登录（AJAX，不 redirect）
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => '请先登录']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
verifyCsrf();

// Pro 闸
if (!function_exists('license_has_module') || !license_has_module('seo-pro')) {
    echo json_encode(['success' => false, 'error' => '该功能需要 SEO 工坊专业版']);
    exit;
}

$ai = aiService();
if (!$ai->isConfigured()) {
    echo json_encode(['success' => false, 'error' => '请先在「系统设置 → AI 设置」配置 API Key']);
    exit;
}

$field   = (string) ($_POST['field'] ?? '');
$title   = trim((string) ($_POST['title'] ?? ''));
$content = trim(strip_tags((string) ($_POST['content'] ?? '')));
$keyword = trim((string) ($_POST['keyword'] ?? ''));

// 控制 token：正文截断
$content = mb_substr($content, 0, 1500);
$base = "内容标题：{$title}\n";
if ($content !== '') {
    $base .= "内容正文（节选）：{$content}\n";
}
if ($keyword !== '') {
    $base .= "焦点关键词：{$keyword}\n";
}
if ($title === '' && $content === '') {
    echo json_encode(['success' => false, 'error' => '请先填写标题或正文，AI 才能据此优化']);
    exit;
}

$system = '你是资深中文 SEO 优化助手，为网站内容生成 SEO 元数据。严格只输出所要求的内容本身，'
        . '不要任何解释、前后缀、引号或 Markdown。';

switch ($field) {
    case 'seo_title':
        $prompt = $base . "\n请生成一个 SEO 标题：20–30 个汉字（≤60 字符），自然包含核心关键词，"
                . "概括主题并吸引点击。只输出标题本身。";
        break;
    case 'seo_description':
        $prompt = $base . "\n请生成一段 SEO 描述（meta description）：70–150 字，概括内容要点、"
                . "自然融入关键词、引导点击。只输出描述文本，不换行。";
        break;
    case 'seo_keywords':
        $prompt = $base . "\n请提取 5–8 个最相关的 SEO 关键词，用中文逗号「，」分隔，按相关度排序。"
                . "只输出关键词，不要编号或其它文字。";
        break;
    default:
        echo json_encode(['success' => false, 'error' => '未知的优化字段']);
        exit;
}

AiService::$action = 'seo_' . $field;
$result = $ai->chat($prompt, $system, 0.7);

if (empty($result['success'])) {
    echo json_encode(['success' => false, 'error' => $result['error'] ?? 'AI 调用失败']);
    exit;
}

// 清洗：去首尾引号/空白/可能的 "标题：" 前缀
$text = trim((string) ($result['content'] ?? ''));
$text = preg_replace('/^(SEO\s*)?(标题|描述|关键词)\s*[:：]\s*/u', '', $text);
$text = trim($text, " \t\n\r\0\x0B\"'“”「」");

echo json_encode(['success' => true, 'content' => $text], JSON_UNESCAPED_UNICODE);
