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
        // 官方中转：不需要用户自备 Key，凭本站授权码调用，额度由授权等级决定。
        // 预留 api.yikaicms.com，真实上游 Key 只存在于服务端。
        'yikai' => [
            'name_key' => 'ai_provider_yikai_name',
            'name'     => 'YikaiCMS',
            'base_url' => 'https://api.yikaicms.com/v1/',
            // 可用模型由服务端下发（「同步最新模型」拉取），此处不写死型号，
            // 免得客户端与服务端上游调整脱节、用户配到已下线的模型。
            'models'   => [],
            'default'  => '',
            'format'   => 'openai',
        ],
        // 内置推荐清单核对于 2026-10-24；旧型号仍可手工输入。
        'custom_openai' => [
            'name' => 'OpenAI Compatible',
            'base_url' => '',
            'models' => [],
            'default' => '',
            'format' => 'openai',
        ],
        'custom_anthropic' => [
            'name' => 'Anthropic Compatible',
            'base_url' => '',
            'models' => [],
            'default' => '',
            'format' => 'anthropic',
        ],
        'deepseek' => [
            'name'     => 'DeepSeek',
            'base_url' => 'https://api.deepseek.com/v1',
            'models'   => ['deepseek-flash'],
            'default'  => 'deepseek-flash',
            'format'   => 'openai',
        ],
        'openai' => [
            'name'     => 'OpenAI',
            'base_url' => 'https://api.openai.com/v1',
            'models'   => ['gpt-6.1-sol', 'gpt-6-astra', 'gpt-6-luna', 'gpt-6-sol'],
            'default'  => 'gpt-6.1-sol',
            'format'   => 'openai',
        ],
        'claude' => [
            'name'     => 'Claude (Anthropic)',
            'base_url' => 'https://api.anthropic.com/v1',
            'models'   => ['claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-opus-5-5', 'claude-fable-5-1'],
            'default'  => 'claude-sonnet-5-5',
            'format'   => 'anthropic',
        ],
        'siliconflow' => [
            'name_key' => 'ai_provider_siliconflow_name',
            'name' => 'SiliconFlow',
            'base_url' => 'https://api.siliconflow.cn/v1',
            'models' => ['deepseek-ai/DeepSeek-V4.1-Flash', 'zai-org/GLM-5.3', 'Qwen/Qwen3.8-27B', 'moonshotai/Kimi-K2.7-Code'],
            'default' => 'deepseek-ai/DeepSeek-V4.1-Flash',
            'format' => 'openai',
        ],
        'kimi' => [
            'name_key' => 'ai_provider_kimi_name',
            'name' => 'Kimi',
            'base_url' => 'https://api.moonshot.cn/v1',
            'models' => ['kimi-k3.1-pro', 'kimi-k3.1-pro-highspeed', 'kimi-k3'],
            'default' => 'kimi-k3.1-pro',
            'format' => 'openai',
        ],
        'mimo' => [
            'name_key' => 'ai_provider_mimo_name',
            'name' => 'Xiaomi MiMo',
            'base_url' => 'https://api.xiaomimimo.com/v1',
            'models' => ['mimo-v2.6-pro', 'mimo-v2.6-flash'],
            'default' => 'mimo-v2.6-pro',
            'format' => 'openai',
        ],
        'hunyuan' => [
            'name_key' => 'ai_provider_hunyuan_name',
            'name' => 'Tencent Hunyuan (TokenHub)',
            'base_url' => 'https://tokenhub.tencentmaas.com/v1',
            'models' => ['hy4-preview', 'hy3'],
            'default' => 'hy4-preview',
            'format' => 'openai',
        ],
        'qwen' => [
            'name_key' => 'ai_provider_qwen_name',
            'name'     => 'Qwen',
            'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
            'models'   => ['qwen3.7-plus', 'qwen3.7-flash', 'qwen3.7-max', 'qwen-plus'],
            'default'  => 'qwen-plus',
            'format'   => 'openai',
        ],
        'zhipu' => [
            'name_key' => 'ai_provider_zhipu_name',
            'name'     => 'Zhipu AI',
            'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
            'models'   => ['glm-5.2', 'glm-5-turbo', 'glm-4.5-flash', 'glm-4-flash'],
            'default'  => 'glm-4-flash',
            'format'   => 'openai',
        ],
    ];

    public function __construct(?string $provider = null, ?string $apiKey = null, ?string $model = null, ?string $baseUrl = null)
    {
        $this->provider = $provider ?: config('ai_provider', 'deepseek');
        $this->apiKey   = $apiKey ?: self::decryptKey(config('ai_api_key', ''));

        // 官方中转用授权码作凭据，用户无需自备 Key；未填授权码时留空，
        // 调用会被服务端以「需要授权」拒绝，前台给出可读提示。
        if ($this->provider === 'yikai' && $this->apiKey === '') {
            $this->apiKey = function_exists('license_key') ? license_key() : '';
        }
        $isCurrent = $this->provider === config('ai_provider', 'deepseek');
        $this->model    = $model ?? ($isCurrent ? config('ai_model', '') : '');
        $this->baseUrl  = $baseUrl ?? ($isCurrent ? config('ai_base_url', '') : '');

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
        $providers = self::PROVIDERS;
        // 带 name_key 的供应商按后台语言取显示名（const 不能调 __()，只能在此投影）
        foreach ($providers as $k => $p) {
            if (!empty($p['name_key'])) {
                $providers[$k]['name'] = __($p['name_key']);
            }
        }
        // 叠加「同步」覆盖（来自 update.yikaicms 中心源，存 setting: ai_models_override）
        $ov = json_decode((string) config('ai_models_override', ''), true);
        if (is_array($ov)) {
            foreach ($ov as $key => $p) {
                if (!is_array($p)) continue;
                $models = (!empty($p['models']) && is_array($p['models']))
                    ? array_values(array_filter(array_map('strval', $p['models'])))
                    : [];
                if (isset($providers[$key])) {
                    if ($models) $providers[$key]['models'] = array_values(array_unique(array_merge($providers[$key]['models'], $models)));
                    if ($providers[$key]['default'] === '' && !empty($p['default'])) $providers[$key]['default'] = (string) $p['default'];
                    if (!empty($p['name']))     $providers[$key]['name'] = (string) $p['name'];
                    if (!empty($p['base_url'])) $providers[$key]['base_url'] = (string) $p['base_url'];
                } elseif ($models && !empty($p['base_url'])) {
                    $providers[$key] = [
                        'name'     => (string) ($p['name'] ?? $key),
                        'base_url' => (string) $p['base_url'],
                        'models'   => $models,
                        'default'  => (string) ($p['default'] ?? $models[0]),
                        'format'   => in_array(($p['format'] ?? 'openai'), ['openai', 'anthropic'], true) ? $p['format'] : 'openai',
                    ];
                }
            }
        }

        // 官方中转尚未开放（服务端接口与上游未定），默认隐藏，不进供应商下拉。
        // 开启方式：设置 ai_official_enabled = 1（届时服务端下发的模型清单也会一并生效）。
        if ((string) config('ai_official_enabled', '0') !== '1') {
            unset($providers['yikai']);
        }

        return $providers;
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
    public function chat(string $prompt, string $system = '', float $temperature = 0.7, bool $jsonObject = false): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'content' => '', 'error' => 'AI 未配置，请先在设置中填写 API Key'];
        }

        $cfg = self::PROVIDERS[$this->provider] ?? self::PROVIDERS['openai'];

        try {
            if ($cfg['format'] === 'anthropic') {
                $result = $this->callAnthropic($prompt, $system, $temperature);
            } else {
                $result = $this->callOpenAI($prompt, $system, $temperature, $jsonObject);
                if ($jsonObject && empty($result['success']) && preg_match('/response_format|json_object/i', (string) ($result['error'] ?? ''))) {
                    $result = $this->callOpenAI($prompt, $system, $temperature);
                }
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
     * 让模型看一张图并回答（2.1.0：媒体库用它生成替代文字）。模型不支持图片时供应商会报错，原样返回给调用方。
     *
     * @param string $bytes 图片原始字节（调用方负责只传本站文件、控制大小）
     * @return array ['success' => bool, 'content' => string, 'error' => string]
     */
    public function describeImage(string $bytes, string $mime, string $instruction, string $system = '', float $temperature = 0.3): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'content' => '', 'error' => 'AI 未配置，请先在设置中填写 API Key'];
        }
        $parts = [
            ['type' => 'image', 'mime' => $mime, 'data' => base64_encode($bytes)],
            ['type' => 'text', 'text' => $instruction],
        ];
        $cfg = self::PROVIDERS[$this->provider] ?? self::PROVIDERS['openai'];
        try {
            $result = $cfg['format'] === 'anthropic'
                ? $this->callAnthropic($parts, $system, $temperature)
                : $this->callOpenAI($parts, $system, $temperature);
            $this->logUsage($result);
            return $result;
        } catch (\Throwable $e) {
            $result = ['success' => false, 'content' => '', 'error' => $e->getMessage()];
            $this->logUsage($result);
            return $result;
        }
    }

    /**
     * 中性的消息内容（文字串，或 [{type:text,text} | {type:image,mime,data}] 列表）→ 各供应商格式。
     *
     * @param string|list<array<string,string>> $prompt
     * @return string|list<array<string,mixed>>
     */
    private static function messageContent(string|array $prompt, string $format): string|array
    {
        if (is_string($prompt)) {
            return $prompt;
        }
        $out = [];
        foreach ($prompt as $part) {
            if (($part['type'] ?? '') === 'image') {
                $out[] = $format === 'anthropic'
                    ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => (string) $part['mime'], 'data' => (string) $part['data']]]
                    : ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $part['mime'] . ';base64,' . $part['data']]];
            } else {
                $out[] = ['type' => 'text', 'text' => (string) ($part['text'] ?? '')];
            }
        }
        return $out;
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
    public function chatWithTools(string $prompt, array $abilityNames = [], string $system = '', float $temperature = 0.7, int $maxIter = 5, bool $stageMutations = false): array
    {
        if ($stageMutations && !class_exists('AiStaging')) {
            require_once __DIR__ . '/AiStaging.php';
        }
        $proposals   = [];   // 暂存的写操作提案（stageMutations 时）
        $stageSetId  = null;
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

            $resp = $this->requestOpenAI($payload);
            if (!$resp['success']) {
                return [
                    'success'         => false,
                    'content'         => '',
                    'tool_calls'      => $callsLog,
                    'proposals'       => $proposals,
                    'proposal_set_id' => $stageSetId,
                    'error'           => $resp['error'],
                ];
            }

            $data = json_decode($resp['body'], true);
            $msg  = $data['choices'][0]['message'] ?? null;
            if (!$msg) {
                return [
                    'success'         => false,
                    'content'         => '',
                    'tool_calls'      => $callsLog,
                    'proposals'       => $proposals,
                    'proposal_set_id' => $stageSetId,
                    'error'           => 'Empty AI response',
                ];
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
                    'success'          => true,
                    'content'          => trim((string)($msg['content'] ?? '')),
                    'tool_calls'       => $callsLog,
                    'proposals'        => $proposals,
                    'proposal_set_id'  => $stageSetId,
                    'error'            => '',
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

                if ($stageMutations && Abilities::isMutating($fnName)) {
                    // 写操作：不执行，转为「提案」暂存，待用户确认
                    $prev = Abilities::previewChange($fnName, $args);
                    if ($prev['success']) {
                        $stageSetId ??= AiStaging::newSet();
                        $pv  = $prev['preview'];
                        // 预览可固定入参（如 AI 生成的标签）：确认时执行的是用户看到的那一份
                        $stagedArgs = is_array($pv['input'] ?? null) ? $pv['input'] : $args;
                        unset($pv['input']);
                        $pid = AiStaging::add($stageSetId, $fnName, $stagedArgs, $pv);
                        $ab  = Abilities::get($fnName);
                        $proposals[] = [
                            'id'      => $pid,
                            'ability' => $fnName,
                            'label'   => (string) ($ab['label'] ?? $fnName),
                            'summary' => (string) ($pv['summary'] ?? ''),
                            'before'  => $pv['before'] ?? null,
                            'after'   => $pv['after'] ?? null,
                        ];
                        $exec = [
                            'success' => true,
                            'staged' => true,
                            'proposal_id' => $pid,
                            'proposal_set_id' => $stageSetId,
                            'note' => '已暂存，待用户在界面确认后才会真正应用',
                            'summary' => (string) ($pv['summary'] ?? ''),
                        ];
                    } else {
                        $exec = ['success' => false, 'error' => $prev['error'] ?? '提案失败'];
                    }
                } else {
                    $exec = Abilities::execute($fnName, $args);
                }
                $callsLog[] = ['name' => $fnName, 'args' => $args, 'result' => $exec];

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $call['id'] ?? '',
                    'content'      => json_encode($exec, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return [
            'success'         => false,
            'content'         => '',
            'tool_calls'      => $callsLog,
            'proposals'       => $proposals,
            'proposal_set_id' => $stageSetId,
            'error'           => "Reached max iterations ({$maxIter}) without final answer",
        ];
    }

    /**
     * OpenAI 兼容格式调用（OpenAI / DeepSeek / Qwen / 智谱）
     */
    /** @param string|list<array<string,string>> $prompt */
    private function callOpenAI(string|array $prompt, string $system, float $temperature, bool $jsonObject = false): array
    {
        $messages = [];
        if ($system) {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => self::messageContent($prompt, 'openai')];

        $payload = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => $temperature,
            'max_tokens'  => 4096,
        ];
        if ($jsonObject) $payload['response_format'] = ['type' => 'json_object'];

        $response = $this->requestOpenAI($payload);

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

    /** 新模型的参数约束同时应用于普通对话和工具调用。 */
    private function openAIPayload(array $payload): array
    {
        if ($this->provider === 'openai' && str_starts_with($this->model, 'gpt-6')) {
            unset($payload['max_tokens']);
            $payload['max_completion_tokens'] = 16384;
            $noThinking = in_array($this->model, ['gpt-6-sol', 'gpt-6-luna'], true);
            $payload['reasoning_effort'] = $noThinking ? 'none' : 'low';
            if (!$noThinking) unset($payload['temperature']);
        }
        if ($this->provider === 'kimi' && str_starts_with($this->model, 'kimi-k3')) {
            unset($payload['temperature'], $payload['max_tokens']);
            $payload['max_completion_tokens'] = 16384;
            $payload['reasoning_effort'] = 'low';
        }
        if ($this->provider === 'mimo' && str_starts_with($this->model, 'mimo-v2.6')) {
            unset($payload['max_tokens']);
            $payload['max_completion_tokens'] = 4096;
            // CMS 文本任务使用非思考模式，避免输出预算被思考占满。
            $payload['thinking'] = ['type' => 'disabled'];
        }
        return $payload;
    }

    private function openAIHeaders(): array
    {
        return [
            ($this->provider === 'mimo' ? 'api-key: ' : 'Authorization: Bearer ') . $this->apiKey,
            'Content-Type: application/json',
        ];
    }

    private function requestOpenAI(array $payload): array
    {
        $payload = $this->openAIPayload($payload);
        // Sol 6.1 / Astra 的工具调用仅支持 Responses；其他兼容商继续走原协议。
        $responses = $this->provider === 'openai' && isset($payload['tools'])
            && preg_match('/^gpt-6(?:\.1-sol|-astra)(?:-|$)/', $this->model);
        if (!$responses) {
            return $this->httpPost(rtrim($this->baseUrl, '/') . '/chat/completions', $payload, $this->openAIHeaders());
        }
        $response = $this->httpPost(rtrim($this->baseUrl, '/') . '/responses', $this->responsesPayload($payload), $this->openAIHeaders());
        if (!$response['success']) return $response;
        $data = json_decode($response['body'], true);
        if (!is_array($data) || !empty($data['error']) || ($data['status'] ?? '') !== 'completed') {
            return ['success' => false, 'error' => (string) ($data['error']['message'] ?? 'Incomplete AI response')];
        }
        $response['body'] = json_encode($this->responsesCompletion($data), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return $response;
    }

    /** 回传完整原生输出（含加密 reasoning），保留多轮工具调用的上下文。 */
    private function responsesPayload(array $payload): array
    {
        $input = [];
        foreach ($payload['messages'] as $message) {
            if (isset($message['_responses_output'])) {
                foreach ($message['_responses_output'] as $item) $input[] = $item;
            } elseif ($message['role'] === 'tool') {
                $input[] = ['type' => 'function_call_output', 'call_id' => $message['tool_call_id'], 'output' => $message['content']];
            } else {
                $input[] = $message;
            }
        }
        $tools = [];
        foreach ($payload['tools'] as $tool) {
            // 现有能力 schema 含可选字段，不隐式启用 Responses 默认的 strict。
            $tools[] = array_merge($tool['function'], ['type' => 'function', 'strict' => false]);
        }
        return [
            'model' => $this->model, 'input' => $input, 'tools' => $tools,
            'tool_choice' => 'auto', 'max_output_tokens' => 16384,
            'reasoning' => ['effort' => 'low'], 'store' => false,
            'include' => ['reasoning.encrypted_content'],
        ];
    }

    private function responsesCompletion(array $data): array
    {
        $message = ['role' => 'assistant', 'content' => '', 'tool_calls' => [], '_responses_output' => $data['output'] ?? []];
        foreach (($data['output'] ?? []) as $item) {
            if (($item['type'] ?? '') === 'function_call') {
                $message['tool_calls'][] = ['id' => $item['call_id'], 'type' => 'function',
                    'function' => ['name' => $item['name'], 'arguments' => $item['arguments']]];
            } elseif (($item['type'] ?? '') === 'message') {
                foreach (($item['content'] ?? []) as $part) {
                    if (($part['type'] ?? '') === 'output_text') $message['content'] .= $part['text'];
                }
            }
        }
        $usage = $data['usage'] ?? [];
        return ['choices' => [['message' => $message]], 'usage' => [
            'prompt_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
        ]];
    }

    /**
     * Anthropic Claude API 调用
     */
    /** @param string|list<array<string,string>> $prompt */
    private function callAnthropic(string|array $prompt, string $system, float $temperature): array
    {
        $payload = [
            'model'       => $this->model,
            'max_tokens'  => 4096,
            'temperature' => $temperature,
            'messages'    => [
                ['role' => 'user', 'content' => self::messageContent($prompt, 'anthropic')],
            ],
        ];
        // Claude 5.5 已移除采样参数，思考与最终文字共享输出预算。
        if (preg_match('/^claude-(?:sonnet|opus|haiku)-5-5(?:-|$)|^claude-fable-5-1(?:-|$)/', $this->model)) {
            unset($payload['temperature']);
            $payload['max_tokens'] = 16384;
        }
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
        // v2.1 起走统一密钥存储（AES-256-GCM 信封）；旧的固定 IV 格式只读兼容
        return SecretStore::seal($plaintext);
    }

    /**
     * 解密 API Key
     */
    public static function decryptKey(string $ciphertext): string
    {
        // 信封 / 旧 CBC / 旧明文都能读出
        return SecretStore::open($ciphertext, true);
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
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
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
