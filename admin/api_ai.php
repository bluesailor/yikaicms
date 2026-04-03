<?php
/**
 * Yikai CMS - AI API 接口
 *
 * 供后台编辑器调用的 AJAX 端点
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/AiService.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';
$ai = aiService();

if (!$ai->isConfigured()) {
    echo json_encode(['success' => false, 'error' => '请先在 AI 设置中配置 API Key']);
    exit;
}

$siteName = config('site_name', 'Yikai CMS');
$siteDesc = config('site_description', '');

AiService::$action = $action;

switch ($action) {
    // 根据标题生成文章内容
    case 'generate_article':
        $title = trim($_POST['title'] ?? '');
        if (!$title) {
            echo json_encode(['success' => false, 'error' => '请先填写文章标题']);
            exit;
        }

        $result = $ai->chat(
            "请为以下标题撰写一篇企业网站文章：\n\n标题：{$title}\n\n要求：\n1. 输出纯 HTML 格式（使用 h2/h3/p/ul/li 等标签）\n2. 内容专业、正式，适合企业官网发布\n3. 字数 500-800 字\n4. 不要输出标题本身，直接输出正文内容\n5. 不要使用 markdown 格式",
            "你是「{$siteName}」的内容编辑。{$siteDesc}。请用中文撰写。"
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    // 生成文章摘要
    case 'generate_summary':
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $text = $content ? strip_tags($content) : $title;
        if (!$text) {
            echo json_encode(['success' => false, 'error' => '请先填写标题或内容']);
            exit;
        }

        $input = mb_substr($text, 0, 2000);
        $result = $ai->chat(
            "请为以下内容生成一段 SEO 友好的摘要（50-120字）：\n\n{$input}\n\n要求：纯文本，不要 HTML 标签，不要引号包裹。",
            "你是 SEO 内容优化专家。用中文输出。"
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    // 生成 SEO 关键词
    case 'generate_seo':
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $text = $title . "\n" . mb_substr(strip_tags($content), 0, 1000);

        $result = $ai->chat(
            "分析以下内容，输出 JSON 格式的 SEO 优化建议：\n\n{$text}\n\n要求输出格式：\n{\"seo_title\": \"优化后的标题（不超过60字符）\", \"seo_keywords\": \"关键词1,关键词2,关键词3（3-5个）\", \"seo_description\": \"SEO 描述（120-160字符）\"}\n\n只输出 JSON，不要其他内容。",
            "你是 SEO 优化专家。用中文输出。"
        );

        if ($result['success']) {
            // 尝试解析 JSON
            $content = $result['content'];
            // 提取 JSON 部分
            if (preg_match('/\{[^}]+\}/s', $content, $m)) {
                $seo = json_decode($m[0], true);
                if ($seo) {
                    $result['seo'] = $seo;
                }
            }
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    // 内容润色/改写
    case 'polish':
        $content = trim($_POST['content'] ?? '');
        if (!$content) {
            echo json_encode(['success' => false, 'error' => '请先选择要润色的内容']);
            exit;
        }
        $input = mb_substr(strip_tags($content), 0, 3000);
        $result = $ai->chat(
            "请润色以下文字，使其更加专业流畅，适合企业官网发布：\n\n{$input}\n\n要求：\n1. 输出 HTML 格式\n2. 保持原意不变\n3. 优化表达和结构\n4. 不要添加与原文无关的内容",
            "你是专业的企业内容编辑。用中文输出。"
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    // 翻译
    case 'translate':
        $content = trim($_POST['content'] ?? '');
        $targetLang = $_POST['target_lang'] ?? 'en';
        if (!$content) {
            echo json_encode(['success' => false, 'error' => '请提供需要翻译的内容']);
            exit;
        }
        $input = mb_substr(strip_tags($content), 0, 3000);
        $langMap = ['en' => '英文', 'ja' => '日文', 'ko' => '韩文', 'zh-CN' => '简体中文'];
        $langName = $langMap[$targetLang] ?? $targetLang;

        $result = $ai->chat(
            "将以下内容翻译为{$langName}：\n\n{$input}\n\n要求：翻译准确自然，保持 HTML 标签结构。",
            "你是专业翻译员。直接输出翻译结果，不要附加说明。"
        );
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['success' => false, 'error' => '未知操作: ' . $action]);
}
