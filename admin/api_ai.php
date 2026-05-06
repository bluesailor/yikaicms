<?php
/**
 * ikaiCMS - AI API 接口
 *
 * 供后台编辑器调用的 AJAX 端点
 */

declare(strict_types=1);

// 捕获所有错误，确保返回 JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => "{$errstr} in {$errfile}:{$errline}"]);
    exit;
});
set_exception_handler(function($e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
// AiService 由 ai-assistant 插件加载，如未加载则回退
if (!class_exists('AiService')) {
    require_once ROOT_PATH . '/includes/AiService.php';
}

// AJAX 登录验证（不 redirect，返回 JSON）
if (empty($_SESSION['admin_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '请先登录']);
    exit;
}

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

$siteName = config('site_name', 'ikaiCMS');
$siteDesc = config('site_description', '');

AiService::$action = $action;

// 通用参数
$userPrompt = trim($_POST['prompt'] ?? '');
$industry = trim($_POST['industry'] ?? '');
$audience = trim($_POST['audience'] ?? '');
$keywords = trim($_POST['keywords'] ?? '');
$style    = trim($_POST['style'] ?? 'professional');
$length   = (int)($_POST['length'] ?? 800);
$extra    = trim($_POST['extra'] ?? '');

$styleMap = [
    'professional' => '专业严谨，用词精准，适合企业官网',
    'friendly'     => '通俗易懂，亲切自然，便于普通用户理解',
    'marketing'    => '营销推广风格，突出卖点和价值，有行动号召',
    'news'         => '新闻资讯风格，客观中立，简洁明了',
    'tutorial'     => '教程指南风格，步骤清晰，操作性强',
];
$styleDesc = $styleMap[$style] ?? $styleMap['professional'];

switch ($action) {
    // 一键生成全部（标题+摘要+标签+别名+内容）
    case 'generate_all':
        $topic = $userPrompt ?: trim($_POST['title'] ?? '');
        if (!$topic) {
            echo json_encode(['success' => false, 'error' => '请填写提示词或文章标题']);
            exit;
        }

        $systemAll = "你是「{$siteName}」的内容编辑。";
        if ($siteDesc) $systemAll .= "网站定位：{$siteDesc}。";
        if ($industry) $systemAll .= "所属行业：{$industry}。";
        if ($audience) $systemAll .= "目标读者：{$audience}。";
        $systemAll .= "写作风格：{$styleDesc}。用中文撰写。";

        $allPrompt = "根据以下主题，生成一篇完整的企业网站文章，输出 JSON 格式：\n\n";
        $allPrompt .= "主题：{$topic}\n";
        if ($keywords) $allPrompt .= "核心关键词：{$keywords}\n";
        if ($extra) $allPrompt .= "补充要求：{$extra}\n";
        $allPrompt .= "\n请严格按以下 JSON 格式输出（不要输出其他内容）：\n";
        $allPrompt .= "{\n";
        $allPrompt .= "  \"title\": \"文章标题（简洁有力，含关键词，30字以内）\",\n";
        $allPrompt .= "  \"slug\": \"url-friendly-english-slug\",\n";
        $allPrompt .= "  \"summary\": \"文章摘要（50-120字，SEO友好）\",\n";
        $allPrompt .= "  \"tags\": \"标签1,标签2,标签3（3-5个）\",\n";
        $allPrompt .= "  \"content\": \"文章正文HTML（使用h2/h3/p/ul/li标签，约{$length}字，不含标题）\"\n";
        $allPrompt .= "}";

        $result = $ai->chat($allPrompt, $systemAll, 0.7);

        if ($result['success']) {
            $json = $result['content'];
            // 提取 JSON
            if (preg_match('/\{[\s\S]*\}/u', $json, $m)) {
                $parsed = json_decode($m[0], true);
                if ($parsed) {
                    $result['fields'] = $parsed;
                }
            }
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        break;

    // 生成文章 / 改写润色 / 续写扩展
    case 'generate_article':
    case 'polish':
    case 'continue':
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');

        if (!$title && !$userPrompt && $action === 'generate_article') {
            echo json_encode(['success' => false, 'error' => '请填写提示词或文章标题']);
            exit;
        }
        if (!$content && in_array($action, ['polish', 'continue'])) {
            echo json_encode(['success' => false, 'error' => '请先编写内容']);
            exit;
        }

        // 构建系统提示词
        $systemParts = ["你是「{$siteName}」的内容编辑。"];
        if ($siteDesc) $systemParts[] = "网站定位：{$siteDesc}。";
        if ($industry) $systemParts[] = "所属行业：{$industry}。";
        if ($audience) $systemParts[] = "目标读者：{$audience}。";
        $systemParts[] = "写作风格：{$styleDesc}。";
        $systemParts[] = "请用中文撰写。";
        $system = implode('', $systemParts);

        // 构建用户提示词
        if ($action === 'generate_article') {
            $topic = $userPrompt ?: $title;
            $promptParts = ["请根据以下需求撰写一篇文章：\n\n{$topic}"];
            if ($keywords) $promptParts[] = "\n核心关键词：{$keywords}";
            $promptParts[] = "\n\n要求：";
            $promptParts[] = "1. 输出纯 HTML 格式（使用 h2/h3/p/ul/li 等标签）";
            $promptParts[] = "2. 字数约 {$length} 字";
            $promptParts[] = "3. 不要输出标题本身，直接输出正文内容";
            $promptParts[] = "4. 不要使用 markdown 格式";
            if ($keywords) $promptParts[] = "5. 自然融入关键词「{$keywords}」，有利于 SEO";
            if ($extra) $promptParts[] = "\n补充要求：{$extra}";
            $prompt = implode("\n", $promptParts);

        } elseif ($action === 'polish') {
            $inputText = mb_substr(strip_tags($content), 0, 3000);
            $promptParts = ["请改写润色以下内容，使其更加专业流畅：\n\n{$inputText}"];
            $promptParts[] = "\n\n要求：";
            $promptParts[] = "1. 输出 HTML 格式";
            $promptParts[] = "2. 保持原意不变，优化表达和结构";
            $promptParts[] = "3. 不要添加与原文无关的内容";
            if ($keywords) $promptParts[] = "4. 适当融入关键词「{$keywords}」";
            if ($extra) $promptParts[] = "\n补充要求：{$extra}";
            $prompt = implode("\n", $promptParts);

        } else { // continue
            $inputText = mb_substr(strip_tags($content), 0, 3000);
            $promptParts = ["以下是一篇文章的已有内容，请在此基础上续写扩展：\n\n{$inputText}"];
            $promptParts[] = "\n\n要求：";
            $promptParts[] = "1. 输出 HTML 格式";
            $promptParts[] = "2. 续写约 {$length} 字的新内容";
            $promptParts[] = "3. 保持风格一致，自然衔接";
            $promptParts[] = "4. 只输出新增的内容部分，不要重复已有内容";
            if ($keywords) $promptParts[] = "5. 围绕关键词「{$keywords}」展开";
            if ($extra) $promptParts[] = "\n补充要求：{$extra}";
            $prompt = implode("\n", $promptParts);
        }

        $result = $ai->chat($prompt, $system);

        // 续写模式：拼接到现有内容后面
        if ($action === 'continue' && $result['success']) {
            $result['content'] = $content . "\n" . $result['content'];
        }

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
