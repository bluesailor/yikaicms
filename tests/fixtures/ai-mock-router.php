<?php
/**
 * AiService 的模拟上游（php -S 路由脚本，仅测试用）。
 *
 * 每次请求记一行到 AI_MOCK_LOG（路径、鉴权头、请求体），按路径应答：
 *   /fail/...     上游故障：HTTP 500
 *   /strict/...   不支持 JSON 输出：带 response_format 的请求回 400，去掉后正常应答
 *   .../messages  Anthropic 格式
 *   其余          OpenAI 兼容格式，回复里带上路径和模型，便于核对请求真正到了哪
 */

declare(strict_types=1);

$log = (string) getenv('AI_MOCK_LOG');
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
if ($log !== '') {
    file_put_contents($log, json_encode([
        'path' => $path,
        'auth' => (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
        'x_api_key' => (string) ($_SERVER['HTTP_X_API_KEY'] ?? ''),
        'body' => $body,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json');
$model = (string) ($body['model'] ?? '');
if (str_starts_with($path, '/fail/')) {
    http_response_code(500);
    echo json_encode(['error' => ['message' => 'mock upstream down']]);
    return true;
}
if (str_starts_with($path, '/strict/') && isset($body['response_format'])) {
    http_response_code(400);
    echo json_encode(['error' => ['message' => 'response_format json_object is not supported by this model']]);
    return true;
}
// 翻译请求（批量翻译 e2e）：按提示里的 Input JSON 逐键回 ASCII 译文；校对轮原样交回 translation
if (str_contains($path, '/translate/')) {
    $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
    $prompt = (string) (end($messages)['content'] ?? '');
    $input = json_decode(explode('Input: ', $prompt, 2)[1] ?? '', true);
    $input = is_array($input) ? $input : [];
    $reply = isset($input['translation']) && is_array($input['translation']) ? $input['translation']
        : array_map(static fn (mixed $text): mixed => is_string($text) && trim($text) !== '' ? 'Translated ' . crc32($text) : $text, $input);
    echo json_encode(['choices' => [['message' => ['content' => json_encode($reply)]]], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5]]);
    return true;
}
if (str_ends_with($path, '/messages')) {
    echo json_encode(['content' => [['type' => 'text', 'text' => "OK {$path} {$model}"]], 'usage' => ['input_tokens' => 3, 'output_tokens' => 2]]);
    return true;
}
echo json_encode([
    'choices' => [['message' => ['content' => "OK {$path} {$model}"]]],
    'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5],
]);
return true;
