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

// 存入 ik_forms
$data = [
    'type'       => $slug,
    'name'       => $formData['name'] ?? '',
    'phone'      => $formData['phone'] ?? '',
    'email'      => $formData['email'] ?? '',
    'company'    => $formData['company'] ?? '',
    'content'    => $formData['content'] ?? '',
    'extra'      => json_encode($formData, JSON_UNESCAPED_UNICODE),
    'ip'         => getClientIp(),
    'user_agent' => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'status'     => 0,
    'created_at' => time(),
];

formModel()->create($data);

$msg = $template['success_message'] ?: '提交成功，感谢您的反馈！';
echo json_encode(['code' => 0, 'msg' => $msg]);
