<?php
/**
 * Yikai CMS - 统一 AI 调用服务
 *
 * 支持供应商：OpenAI、Claude、DeepSeek、Qwen（通义千问）、智谱AI (GLM)
 * 所有供应商均使用 OpenAI 兼容 API 格式（Claude 除外）
 *
 * PHP 8.0+
 */

declare(strict_types=1);

class AiService
{
    private string $provider;
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    public static string $action = '';

    // 供应商配置表
    private const PROVIDERS = [
        'deepseek' => [
            'name'     => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com/v1',
            'models'   => ['deepseek-v4-flash', 'deepseek-v4-pro', 'deepseek-chat', 'deepseek-reasoner'],
            'default'  => 'deepseek-v4-flash',
            'format'   => 'openai',
        ],
        'openai' => [
            'name'     => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'models'   => ['gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo'],
            'default'  => 'gpt-4o-mini',
            'format'   => 'openai',
        ],
        'claude' => [
            'name'     => 'Claude (Anthropic)',
            'base_url' => 'https://api.anthropic.com/v1',
            'models'   => ['claude-sonnet-4-20250514', 'claude-haiku-4-20250414', 'claude-3-5-sonnet-20241022'],
            'default'  => 'claude-sonnet-4-20250514',
            'format'   => 'anthropic',
        ],
        'qwen' => [
            'name'     => '通义千问 (Qwen)',
            'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'models'   => ['qwen-plus', 'qwen-turbo', 'qwen-max', 'qwen-long'],
            'default'  => 'qwen-plus',
            'format'   => 'openai',
        ],
        'zhipu' => [
            'name'     => '智谱AI (GLM)',
            'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
            'models'   => ['glm-4-flash', 'glm-4', 'glm-4-plus'],
            'default'  => 'glm-4-flash',
            'format'   => 'openai',
        ],
    ];

    public function __construct(?string $provider = null, ?string $apiKey = null, ?string $model = null)
    {
        $this->provider = $provider ?: config('ai_provider', 'deepseek');
        $this->apiKey   = $apiKey ?: self::decryptKey(config('ai_api_key', ''));
        $this->model    = $model ?: config('ai_model', '');
        $this->baseUrl  = config('ai_base_url', '');

        // 使用供应商默认值
        $cfg = self::PROVIDERS[$this->provider] ?? self::PROVIDERS['openai'];
        if (!$this->model) {
            $this->model = $cfg['default'];
        }
        if (!$this->baseUrl) {
            $this->baseUrl = $cfg['base_url'];
        }
    }

    /**
     * 获取供应商列表（用于设置页面下拉框）
     */
    public static function getProviders(): array
    {
        return self::PROVIDERS;
    }

    /**
     * 检测是否已配置
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * 发送聊天请求
     *
     * @param string $prompt  用户提示词
     * @param string $system  系统提示词
     * @param float  $temperature 随机性 0-1
     * @return array ['success' => bool, 'content' => string, 'error' => string]
     */
    public function chat(string $prompt, string $system = '', float $temperature = 0.7): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'content' => '', 'error' => 'AI 未配置，请先在设置中填写 API Key'];
        }

        $cfg = self::PROVIDERS[$this->provider] ?? self::PROVIDERS['openai'];

        try {
            if ($cfg['format'] === 'anthropic') {
                $result = $this->callAnthropic($prompt, $system, $temperature);
            } else {
                $result = $this->callOpenAI($prompt, $system, $temperature);
            }
            $this->logUsage($result);
            return $result;
        } catch (\Throwable $e) {
            $result = ['success' => false, 'content' => '', 'error' => $e->getMessage()];
            $this->logUsage($result);
            return $result;
        }
    }

    /**
     * 带工具调用（function-calling）的 agent 循环。
     * 让 AI 自主决定调用哪个 ability，自动执行并把结果回灌，直到 AI 给出最终回复或达到 maxIter。
     *
     * @param string $prompt        用户原始提问
     * @param array  $abilityNames  允许使用的 ability 名列表；空数组 = 全部
     * @param string $system        系统提示词
     * @param int    $maxIter       最大工具循环次数
     * @return array ['success', 'content', 'tool_calls' => [...], 'error']
     */
    public function chatWithTools(string $prompt, array $abilityNames = [], string $system = '', float $temperature = 0.7, int $maxIter = 5): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'content' => '', 'tool_calls' => [], 'error' => 'AI 未配置'];
        }
        if (!class_exists('Abilities')) {
            return ['success' => false, 'content' => '', 'tool_calls' => [], 'error' => 'Abilities 注册中心未加载'];
        }
        $cfg = self::PROVIDERS[$this->provider] ?? self::PROVIDERS['openai'];
        if ($cfg['format'] !== 'openai') {
            return ['success' => false, 'content' => '', 'tool_calls' => [], 'error' => '当前供应商暂不支持 tool-calling（仅 OpenAI 兼容协议）'];
        }

        $tools = Abilities::asOpenAITools($abilityNames ?: null);
        if ($tools === []) {
            return ['success' => false, 'content' => '', 'tool_calls' => [], 'error' => '没有可用的 ability'];
        }

        $messages = [];
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $callsLog = [];

        for ($iter = 0; $iter < $maxIter; $iter++) {
            $payload = [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => $temperature,
                'tools'       => $tools,
                'tool_choice' => 'auto',
                'max_tokens'  => 4096,
            ];

            $resp = $this->httpPost(
                rtrim($this->baseUrl, '/') . '/chat/completions',
                $payload,
                ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json']
            );
            if (!$resp['success']) {
                return ['success' => false, 'content' => '', 'tool_calls' => $callsLog, 'error' => $resp['error']];
            }

            $data = json_decode($resp['body'], true);
            $msg  = $data['choices'][0]['message'] ?? null;
            if (!$msg) {
                return ['success' => false, 'content' => '', 'tool_calls' => $callsLog, 'error' => 'Empty AI response'];
            }

            $toolCalls = $msg['tool_calls'] ?? [];
            if (empty($toolCalls)) {
                // 终态：返回最终内容
                $usage = $data['usage'] ?? [];
                $this->logUsage([
                    'success' => true,
                    'prompt_tokens' => (int)($usage['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
                    'total_tokens' => (int)($usage['total_tokens'] ?? 0),
                ]);
                return [
                    'success'    => true,
                    'content'    => trim((string)($msg['content'] ?? '')),
                    'tool_calls' => $callsLog,
                    'error'      => '',
                ];
            }

            // 把 assistant 的 tool_calls 消息加入历史
            $messages[] = $msg;

            // 逐个执行
            foreach ($toolCalls as $call) {
                $fnName = $call['function']['name'] ?? '';
                $argRaw = $call['function']['arguments'] ?? '{}';
                $args   = json_decode($argRaw, true);
                if (!is_array($args)) $args = [];

                $exec = Abilities::execute($fnName, $args);
                $callsLog[] = ['name' => $fnName, 'args' => $args, 'result' => $exec];

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $call['id'] ?? '',
                    'content'      => json_encode($exec, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return [
            'success'    => false,
            'content'    => '',
            'tool_calls' => $callsLog,
            'error'      => "Reached max iterations ({$maxIter}) without final answer",
        ];
    }

    /**
     * OpenAI 兼容格式调用（OpenAI / DeepSeek / Qwen / 智谱）
     */
    private function callOpenAI(string $prompt, string $system, float $temperature): array
    {
        $messages = [];
        if ($system) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => 4096,
        ];

        $response = $this->httpPost(
            rtrim($this->baseUrl, '/') . '/chat/completions',
            $payload,
            [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ]
        );

        if (!$response['success']) {
            return $response;
        }

        $data = json_decode($response['body'], true);
        if (empty($data['choices'][0]['message']['content'])) {
            $error = $data['error']['message'] ?? '未知错误';
            return ['success' => false, 'content' => '', 'error' => $error];
        }

        $usage = $data['usage'] ?? [];
        return [
            'success'           => true,
            'content'           => trim($data['choices'][0]['message']['content']),
            'error'             => '',
            'prompt_tokens'     => (int)($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int)($usage['completion_tokens'] ?? 0),
            'total_tokens'      => (int)($usage['total_tokens'] ?? 0),
        ];
    }

    /**
     * Anthropic Claude API 调用
     */
    private function callAnthropic(string $prompt, string $system, float $temperature): array
    {
        $payload = [
            'model'       => $this->model,
            'max_tokens'  => 4096,
            'temperature' => $temperature,
            'messages'    => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
        if ($system) {
            $payload['system'] = $system;
        }

        $response = $this->httpPost(
            rtrim($this->baseUrl, '/') . '/messages',
            $payload,
            [
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
                'Content-Type: application/json',
            ]
        );

        if (!$response['success']) {
            return $response;
        }

        $data = json_decode($response['body'], true);
        if (!empty($data['error'])) {
            return ['success' => false, 'content' => '', 'error' => $data['error']['message'] ?? '未知错误'];
        }

        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        if (!$text) {
            return ['success' => false, 'content' => '', 'error' => '未获取到 AI 回复'];
        }

        $usage = $data['usage'] ?? [];
        return [
            'success'           => true,
            'content'           => trim($text),
            'error'             => '',
            'prompt_tokens'     => (int)($usage['input_tokens'] ?? 0),
            'completion_tokens' => (int)($usage['output_tokens'] ?? 0),
            'total_tokens'      => (int)(($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0)),
        ];
    }

    /**
     * 记录 AI 调用日志
     */
    private function logUsage(array $result): void
    {
        try {
            $table = (defined('DB_PREFIX') ? DB_PREFIX : 'yikai_') . 'ai_logs';
            db()->execute(
                "INSERT INTO {$table} (provider, model, action, prompt_tokens, completion_tokens, total_tokens, success, error_msg, admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $this->provider,
                    $this->model,
                    self::$action,
                    (int)($result['prompt_tokens'] ?? 0),
                    (int)($result['completion_tokens'] ?? 0),
                    (int)($result['total_tokens'] ?? 0),
                    $result['success'] ? 1 : 0,
                    mb_substr($result['error'] ?? '', 0, 500),
                    (int)($_SESSION['admin_id'] ?? 0),
                ]
            );
        } catch (\Throwable $e) {
            // 日志记录失败不影响主流程
        }
    }

    /**
     * 加密 API Key
     */
    public static function encryptKey(string $plaintext): string
    {
        if (!$plaintext) return '';
        $key = defined('ENCRYPT_KEY') ? ENCRYPT_KEY : 'yikaicms_default_key';
        $iv = substr(md5($key), 0, 16);
        $encrypted = openssl_encrypt($plaintext, 'AES-128-CBC', $key, 0, $iv);
        return $encrypted ?: $plaintext;
    }

    /**
     * 解密 API Key
     */
    public static function decryptKey(string $ciphertext): string
    {
        if (!$ciphertext) return '';
        $key = defined('ENCRYPT_KEY') ? ENCRYPT_KEY : 'yikaicms_default_key';
        $iv = substr(md5($key), 0, 16);
        $decrypted = openssl_decrypt($ciphertext, 'AES-128-CBC', $key, 0, $iv);
        // 如果解密失败，可能是旧的明文 key，直接返回
        return $decrypted ?: $ciphertext;
    }

    /**
     * HTTP POST 请求
     */
    private function httpPost(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['success' => false, 'content' => '', 'error' => 'cURL 错误: ' . $error, 'body' => ''];
        }

        if ($httpCode >= 400) {
            $data = json_decode($body, true);
            $msg = $data['error']['message'] ?? $data['error']['type'] ?? "HTTP {$httpCode}";
            return ['success' => false, 'content' => '', 'error' => "API 错误: {$msg}", 'body' => $body];
        }

        return ['success' => true, 'body' => $body, 'content' => '', 'error' => ''];
    }
}

/**
 * 获取 AI 服务实例
 */
function aiService(): AiService
{
    static $instance = null;
    return $instance ??= new AiService();
}
