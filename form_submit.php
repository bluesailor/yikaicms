<?php
/**
 * Yikai CMS - 短码表单提交处理
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['code' => 1, 'msg' => '无效请求']);
    exit;
}

// 表单提交频率限制
$clientIp = getClientIp();
$throttleRemain = checkFormThrottle($clientIp);
if ($throttleRemain > 0) {
    echo json_encode(['code' => 1, 'msg' => '提交过于频繁，请' . ceil($throttleRemain / 60) . '分钟后再试']);
    exit;
}

// 反垃圾 1：蜜罐 —— 正常用户看不到 hp_url，机器人填了就丢弃（假装成功，不报错以免被探测）
if (trim((string) post('hp_url', '')) !== '') {
    echo json_encode(['code' => 0, 'msg' => '提交成功，感谢您的反馈！']);
    exit;
}
// 反垃圾 2：签名时间戳 —— 校验时间戳未被伪造，且非「秒提交」（机器人特征）
$_fts  = (int) post('form_ts', 0);
$_fsig = (string) post('form_sig', '');
if ($_fts > 0 && $_fsig !== '' && defined('ENCRYPT_KEY')) {
    $_exp = substr(hash_hmac('sha256', (string) $_fts, ENCRYPT_KEY), 0, 16);
    if (hash_equals($_exp, $_fsig)) {
        $_elapsed = time() - $_fts;
        if ($_elapsed >= 0 && $_elapsed < 2) {
            echo json_encode(['code' => 1, 'msg' => '提交过快，请稍后再试']);
            exit;
        }
    }
}

$slug = trim(post('form_slug', ''));
if (empty($slug)) {
    echo json_encode(['code' => 1, 'msg' => '无效表单']);
    exit;
}

// 获取模板
$template = formTemplateModel()->findBySlug($slug);
if (!$template) {
    echo json_encode(['code' => 1, 'msg' => '表单不存在']);
    exit;
}

// 反垃圾 4：图形验证码（该表单模板「启用验证码」时才校验；与 captcha.php 共享 session，一次性）
if (!empty($template['captcha'])) {
    $_cap = strtolower(trim((string) post('captcha_code', '')));
    $_sess = strtolower((string) ($_SESSION['form_captcha'] ?? ''));
    unset($_SESSION['form_captcha']);
    if ($_sess === '' || $_cap === '' || $_cap !== $_sess) {
        echo json_encode(['code' => 1, 'msg' => __('form_captcha_error')]);
        exit;
    }
}

$fieldsRaw = $template['fields'] ?? '';

// 从模板解析字段定义（兼容旧 JSON 和新 CF7 模板）
if (isJsonFields($fieldsRaw)) {
    $fields = json_decode($fieldsRaw, true);
} else {
    $fields = parseFormTags($fieldsRaw);
}

// 验证必填字段
$formData = [];
foreach ($fields as $field) {
    $key = $field['key'] ?? $field['name'] ?? '';
    if ($key === '') continue;
    $type = $field['type'] ?? 'text';

    // checkbox 提交为数组
    if ($type === 'checkbox') {
        $arr = $_POST[$key] ?? [];
        if (!is_array($arr)) $arr = [$arr];
        $arr = array_map('trim', $arr);
        $arr = array_filter($arr, fn($v) => $v !== '');
        if (!empty($field['required']) && empty($arr)) {
            echo json_encode(['code' => 1, 'msg' => ($field['label'] ?? $key) . '不能为空']);
            exit;
        }
        $formData[$key] = implode(', ', $arr);
        continue;
    }

    $value = trim(post($key, ''));
    if (!empty($field['required']) && $value === '') {
        echo json_encode(['code' => 1, 'msg' => ($field['label'] ?? $key) . '不能为空']);
        exit;
    }
    // 邮箱格式验证
    if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['code' => 1, 'msg' => '邮箱格式不正确']);
        exit;
    }
    $formData[$key] = $value;
}

// 反垃圾 3：内容含过多链接 = 典型垃圾，丢弃（假装成功）
$_content = (string) ($formData['content'] ?? '');
if ($_content !== '' && preg_match_all('~https?://|www\.~i', $_content) > 3) {
    echo json_encode(['code' => 0, 'msg' => '提交成功，感谢您的反馈！']);
    exit;
}

// 产品询盘关联
$productId    = (int)post('product_id', '0');
$productTitle = trim(post('product_title', ''));
$source       = $productId > 0 ? 'product' : ($slug === 'product-inquiry' ? 'product' : 'contact');

// 存入 ik_forms
$data = [
    'type'          => $slug,
    'product_id'    => $productId,
    'product_title' => $productTitle,
    'source'        => $source,
    'name'          => $formData['name'] ?? '',
    'phone'         => $formData['phone'] ?? '',
    'email'         => $formData['email'] ?? '',
    'company'       => $formData['company'] ?? '',
    'content'       => $formData['content'] ?? '',
    'extra'         => json_encode($formData, JSON_UNESCAPED_UNICODE),
    'ip'            => getClientIp(),
    'user_agent'    => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'status'        => 0,
    'created_at'    => time(),
];

$data = apply_filters('before_save_form', $data);
$formId = (int)formModel()->create($data);

// 动作：表单提交后（邮件通知、CRM 同步等）
do_action('form_submitted', $formId, $data);

// 邮件通知
require_once __DIR__ . '/includes/mail_notify.php';
notifyNewInquiry($data);

// 记录提交频率
recordFormSubmit($clientIp);

// lang-aware：始终先查 success_message_<siteLang>，base 当语言无关 fallback。
// 不再用 `lang !== defaultLang` 门槛 — 那样在用户把默认语言改为 en/ja 后
// 两边相等就跳过翻译查找，前端永远拿到 legacy 中文 base。
$_smLang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
$msg = $template['success_message'] ?? '';
$langMsg = (string) ($template['success_message_' . $_smLang] ?? '');
if ($langMsg !== '') $msg = $langMsg;
if (!$msg) $msg = '提交成功，感谢您的反馈！';
echo json_encode(['code' => 0, 'msg' => $msg]);
