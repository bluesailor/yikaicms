<?php
/**
 * Yikai CMS - 短码表单提交处理
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/FormSpamGuard.php';
require_once __DIR__ . '/includes/FormUploadService.php';
require_once __DIR__ . '/includes/FormSubmissionLifecycle.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
if (formModerationModel()->isBlocked($clientIp)) rejectFormSpam('form_ip_denied', 403);
$securityVersion = max(1, (int) config('form_security_version', '1'));
// A foreign page cannot read the v2 nonce, but without this early boundary it could still
// poison the visitor IP's failed-attempt budget with no-cors/simple POST requests.
if ($securityVersion >= 2 && FormSpamGuard::isExplicitCrossSite($_SERVER, siteBaseUrl())) {
    rejectFormSpam('form_cross_site_denied', 403);
}
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
$secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
$signaturePresent = $_fts > 0 || $_fsig !== '';
if ($securityVersion >= 2 && !$signaturePresent) {
    echo json_encode(['code' => 1, 'msg' => '表单安全令牌缺失，请刷新页面后重试']);
    exit;
}
if ($signaturePresent) {
    $validSignature = FormSubmissionToken::verify(
        $slug,
        $_fts,
        $_fsig,
        $secret,
        $securityVersion < 2,
        max(0, (int) config('form_signature_max_age', '0'))
    );
    if (!$validSignature) {
        // Only an authentic expired token may be renewed; never save or replay this request.
        $maxAge = max(0, (int) config('form_signature_max_age', '0'));
        if ($maxAge > 0 && time() - $_fts > $maxAge
            && FormSubmissionToken::verify($slug, $_fts, $_fsig, $secret, $securityVersion < 2)
            && formTemplateModel()->findBySlug($slug) !== null) {
            $timestamp = time();
            echo json_encode(['code' => 1, 'msg' => __('form_token_refreshed'), 'refresh_token' => [
                'form_ts' => $timestamp, 'form_sig' => FormSubmissionToken::sign($slug, $timestamp, $secret),
            ]], JSON_UNESCAPED_UNICODE);
            exit;
        }
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

// 旧 JSON 和文本模板必须走同一规范化、校验路径，避免渲染与提交解释不同。
$fields = formFieldsFromStored($fieldsRaw);
if (!formFieldSetValid($fields)) rejectFormSpam('form_guard_field', 422, 0, ['field' => 'template']);

// 先验证所有非文件字段；附件在内容过滤通过后才落盘，垃圾请求不会制造文件。
$formData = [];
$fingerprintData = [];
$fileFields = [];
foreach ($fields as $field) {
    if (array_key_exists('enabled', $field) && empty($field['enabled'])) {
        continue;
    }
    $key = $field['key'] ?? $field['name'] ?? '';
    if ($key === '') continue;
    $type = $field['type'] ?? 'text';

    if ($type === 'file') {
        $fileFields[] = $field;
        continue;
    }

    try {
        $value = FormSpamGuard::fieldValue($field, $_POST[$key] ?? ($type === 'checkbox' ? [] : ''));
    } catch (InvalidArgumentException) {
        rejectFormSpam('form_guard_field', 422, 0, ['field' => (string) ($field['label'] ?? $key)]);
    }
    $formData[$key] = $value;
    $fingerprintData[$key] = $value;
}

// v2: 页面缓存中不放一次性值；浏览器从 no-store 端点按当前 session 动态领取。
// PHP 的 session 文件锁把查找+删除串行化，跨进程并发也只能有一个请求消费成功。
if ($securityVersion >= 2) {
    $nonce = (string) post('form_nonce', '');
    if (!FormSubmissionNonce::consume($slug, $nonce, $secret)) {
        rejectFormSpam('form_nonce_invalid', 409);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

// 反垃圾 3：内容过滤 —— 链接过多或命中屏蔽关键词 = 典型垃圾，丢弃（假装成功）。规则在「询盘管理 › 防垃圾设置」
$_content = FormFieldContract::contentForSpamScan($fields, $formData);
$_maxLinks = max(0, min(20, (int) config('form_max_links', '3')));
if (FormSpamGuard::blockedContent($_content, FormSpamGuard::keywordList((string) config('form_spam_keywords', '')), $_maxLinks)) {
    echo json_encode(['code' => 0, 'msg' => '提交成功，感谢您的反馈！']);
    exit;
}

$uploadService = new FormUploadService();
$uploadedReferences = [];
$cleanupUploads = static function () use ($uploadService, &$uploadedReferences): void {
    foreach ($uploadedReferences as $reference) $uploadService->remove($reference);
    $uploadedReferences = [];
};
$uploadTotal = 0;
foreach ($fileFields as $field) {
    $key = (string) ($field['name'] ?? $field['key'] ?? '');
    $file = $_FILES[$key] ?? null;
    $missing = !is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
    if ($missing) {
        if (!empty($field['required'])) {
            $cleanupUploads();
            rejectFormSpam('form_upload_required', 422, 0, ['field' => $key]);
        }
        $formData[$key] = '';
        $fingerprintData[$key] = '';
        continue;
    }
    try {
        $stored = $uploadService->store($file, $field, $uploadTotal);
    } catch (Throwable $error) {
        $cleanupUploads();
        $reason = $error->getMessage();
        $allowedReasons = ['form_upload_failed', 'form_upload_name', 'form_upload_type', 'form_upload_size',
            'form_upload_total', 'form_upload_unavailable', 'form_upload_mime'];
        if ($error instanceof RuntimeException && in_array($reason, $allowedReasons, true)) {
            rejectFormSpam($reason, 422, 0, ['field' => $key]);
        }
        error_log('Form upload failed: ' . get_class($error));
        rejectFormSpam('form_upload_failed', 503, 0, ['field' => $key]);
    }
    $uploadedReferences[] = $stored['reference'];
    $formData[$key] = $stored['reference'];
    // 重试去重使用文件内容摘要，不使用每次都不同的随机存储名。
    $fingerprintData[$key] = 'file:' . $stored['fingerprint'];
}

try {
    $extra = json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable) {
    $cleanupUploads();
    rejectFormSpam('form_guard_payload', 422);
}
if (strlen($extra) > 60000) {
    $cleanupUploads();
    rejectFormSpam('form_guard_payload', 422);
}

// 产品关联是服务端合同：客户端只能提交由页面签发的 ID，标题始终回查数据库。
$productId = 0;
$productTitle = '';
try {
    if ($slug === 'product-inquiry') {
        $candidateId = (int) post('product_id', '0');
        $productSignature = (string) post('product_sig', '');
        if (!FormSubmissionToken::contextVerify($slug, $candidateId, $productSignature, $secret)) {
            $cleanupUploads();
            rejectFormSpam('form_guard_field', 422, 0, ['field' => 'product_id']);
        }
        $product = productModel()->find($candidateId);
        if (!$product) {
            $cleanupUploads();
            rejectFormSpam('form_guard_field', 422, 0, ['field' => 'product_id']);
        }
        $productId = $candidateId;
        $productTitle = (string) ($product['title'] ?? '');
    }
    $source = $slug === 'product-inquiry' ? 'product' : 'contact';

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

    // Equal content from the same IP is shared across contact/inquiry forms and languages.
    $accepted = FormSubmissionLifecycle::persist($spamGuard, $clientIp, $fingerprintData, $maxSubmits, $windowSeconds,
        static function () use (&$data): int {
            $data = apply_filters('before_save_form', $data);
            return (int) formModel()->create($data);
        },
        static fn(): bool => db()->beginTransaction(),
        static function (): bool {
            try {
                return db()->commit();
            } catch (Throwable $error) {
                // Database::commit runs observers after PDO committed. Their failure is not a DB failure.
                if (!db()->getPdo()->inTransaction()) {
                    error_log('Form transaction observer failed: ' . get_class($error));
                    return true;
                }
                throw $error;
            }
        },
        static function (): void { if (db()->getPdo()->inTransaction()) db()->rollback(); });
} catch (Throwable $error) {
    if (db()->getPdo()->inTransaction()) {
        try {
            db()->rollback();
        } catch (Throwable $rollbackError) {
            error_log('Form transaction rollback failed: ' . get_class($rollbackError));
        }
    }
    $cleanupUploads();
    error_log('Form submission failed: ' . get_class($error));
    rejectFormSpam('form_guard_unavailable', 503);
}
if ($accepted['reason'] !== '') {
    $cleanupUploads();
    rejectFormSpam($accepted['reason'] === 'duplicate' ? 'form_guard_duplicate' : 'form_guard_throttle',
        $accepted['reason'] === 'duplicate' ? 409 : 429, $accepted['retry']);
}
$formId = $accepted['id'];

// 动作/通知都在提交后执行；任何集成故障不得把已入库请求伪装成失败。
FormSubmissionLifecycle::afterCommit(
    $formId,
    $data,
    static function (int $id, array $saved): void { do_action('form_submitted', $id, $saved); },
    static function (array $saved): void {
        require_once __DIR__ . '/includes/mail_notify.php';
        notifyNewInquiry($saved);
    }
);

// lang-aware：始终先查 success_message_<siteLang>，base 当语言无关 fallback。
// 不再用 `lang !== defaultLang` 门槛 — 那样在用户把默认语言改为 en/ja 后
// 两边相等就跳过翻译查找，前端永远拿到 legacy 中文 base。
$_smLang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
$msg = $template['success_message'] ?? '';
$langMsg = (string) ($template['success_message_' . $_smLang] ?? '');
if ($langMsg !== '') $msg = $langMsg;
if (!$msg) $msg = '提交成功，感谢您的反馈！';
echo json_encode(['code' => 0, 'msg' => $msg]);
