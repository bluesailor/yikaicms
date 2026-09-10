<?php
/**
 * Yikai CMS - 短码表单提交处理
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/FormSpamGuard.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['code' => 1, 'msg' => '无效请求']);
    exit;
}

function rejectFormSpam(string $key, int $status, int $retry = 0, array $params = []): void
{
    http_response_code($status);
    if ($retry > 0) header('Retry-After: ' . $retry);
    echo json_encode(['code' => 1, 'msg' => __($key, $params)], JSON_UNESCAPED_UNICODE);
    exit;
}

// Count attempts before token/field validation, using the existing IP policy and limits.
$clientIp = getClientIp();
$spamGuard = new FormSpamGuard(STORAGE_PATH . '/form_throttle', defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '');
$maxSubmits = max(1, min(100, (int) config('form_max_submits', 5)));
$windowSeconds = max(1, min(1440, (int) config('form_throttle_minutes', 5))) * 60;
try {
    $throttleRemain = $spamGuard->attempt($clientIp, max(20, $maxSubmits * 4), $windowSeconds);
} catch (Throwable $error) {
    error_log('Form spam guard unavailable: ' . get_class($error));
    rejectFormSpam('form_guard_unavailable', 503);
}
if ($throttleRemain > 0) rejectFormSpam('form_guard_throttle', 429, $throttleRemain);
if (!FormSpamGuard::validPayload($_POST)) rejectFormSpam('form_guard_payload', 422);

// 反垃圾 1：蜜罐 —— 正常用户看不到 hp_url，机器人填了就丢弃（假装成功，不报错以免被探测）
if (trim((string) post('hp_url', '')) !== '') {
    echo json_encode(['code' => 0, 'msg' => '提交成功，感谢您的反馈！']);
    exit;
}
$slug = trim(post('form_slug', ''));
if (empty($slug)) {
    echo json_encode(['code' => 1, 'msg' => '无效表单']);
    exit;
}
// 反垃圾 2：签名时间戳 —— 校验时间戳未被伪造，且非「秒提交」（机器人特征）
$_fts  = (int) post('form_ts', 0);
$_fsig = (string) post('form_sig', '');
$securityVersion = max(1, (int) config('form_security_version', '1'));
$signaturePresent = $_fts > 0 || $_fsig !== '';
if ($securityVersion >= 2 && !$signaturePresent) {
    echo json_encode(['code' => 1, 'msg' => '表单安全令牌缺失，请刷新页面后重试']);
    exit;
}
if ($signaturePresent) {
    $secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
    $validSignature = FormSubmissionToken::verify(
        $slug,
        $_fts,
        $_fsig,
        $secret,
        $securityVersion < 2,
        max(0, (int) config('form_signature_max_age', '0'))
    );
    if (!$validSignature) {
        echo json_encode(['code' => 1, 'msg' => '表单安全令牌无效，请刷新页面后重试']);
        exit;
    }
    if (time() - $_fts < 2) {
        echo json_encode(['code' => 1, 'msg' => '提交过快，请稍后再试']);
        exit;
    }
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

$fieldsLang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
$fieldsRaw = (string) ($template['fields'] ?? '');
$localizedFields = (string) ($template['fields_' . $fieldsLang] ?? '');
if (trim($localizedFields) !== '') {
    $fieldsRaw = $localizedFields;
}

// 从模板解析字段定义（兼容旧 JSON 和新 CF7 模板）
if (isJsonFields($fieldsRaw)) {
    $fields = json_decode($fieldsRaw, true);
} else {
    $fields = parseFormTags($fieldsRaw);
}

// 验证必填字段
$formData = [];
foreach ($fields as $field) {
    if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
        continue;
    }
    $key = $field['key'] ?? $field['name'] ?? '';
    if ($key === '') continue;
    $type = $field['type'] ?? 'text';

    try {
        $value = FormSpamGuard::fieldValue($field, $_POST[$key] ?? ($type === 'checkbox' ? [] : ''));
    } catch (InvalidArgumentException) {
        rejectFormSpam('form_guard_field', 422, 0, ['field' => (string) ($field['label'] ?? $key)]);
    }
    $formData[$key] = $value;
}
$extra = json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
if (strlen($extra) > 60000) rejectFormSpam('form_guard_payload', 422);

// 反垃圾 3：内容含过多链接 = 典型垃圾，丢弃（假装成功）
$_content = implode("\n", $formData);
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
    'extra'         => $extra,
    'ip'            => getClientIp(),
    'user_agent'    => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'status'        => 0,
    'created_at'    => time(),
];

try {
    // Equal content from the same IP is shared across contact/inquiry forms and languages.
    $accepted = $spamGuard->submit($clientIp, $formData, $maxSubmits, $windowSeconds,
        static function () use (&$data): int {
            $data = apply_filters('before_save_form', $data);
            return (int) formModel()->create($data);
        });
} catch (Throwable $error) {
    error_log('Form submission failed: ' . get_class($error));
    rejectFormSpam('form_guard_unavailable', 503);
}
if ($accepted['reason'] !== '') {
    rejectFormSpam($accepted['reason'] === 'duplicate' ? 'form_guard_duplicate' : 'form_guard_throttle',
        $accepted['reason'] === 'duplicate' ? 409 : 429, $accepted['retry']);
}
$formId = $accepted['id'];

// 动作：表单提交后（邮件通知、CRM 同步等）
do_action('form_submitted', $formId, $data);

// 邮件通知
require_once __DIR__ . '/includes/mail_notify.php';
notifyNewInquiry($data);

// lang-aware：始终先查 success_message_<siteLang>，base 当语言无关 fallback。
// 不再用 `lang !== defaultLang` 门槛 — 那样在用户把默认语言改为 en/ja 后
// 两边相等就跳过翻译查找，前端永远拿到 legacy 中文 base。
$_smLang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
$msg = $template['success_message'] ?? '';
$langMsg = (string) ($template['success_message_' . $_smLang] ?? '');
if ($langMsg !== '') $msg = $langMsg;
if (!$msg) $msg = '提交成功，感谢您的反馈！';
echo json_encode(['code' => 0, 'msg' => $msg]);
