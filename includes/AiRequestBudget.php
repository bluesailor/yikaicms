<?php
/**
 * 按实际发出的模型请求计额度（2.3.1）。
 *
 * 一次翻译不止一次模型请求：每段正文一次、校对一次，修补与「不支持 JSON 输出」的兼容重试还会再发。
 * 批量翻译原来每领一项只占 1 个额度，额度说的「调用次数」与实际请求对不上。现在：
 *   - AiService 在每次 HTTP 请求发出之前调用 beforeRequest()，占不到额度就抛 AiRequestBudgetExhausted，这次请求不发；
 *   - 这一次生成里已经成功的模型回复按请求内容记下（replies），额度用尽暂停后再继续时原样重放，
 *     已经翻好的分块、首轮译文不再重发、不再计费。
 */

declare(strict_types=1);

/** 额度用尽：这次请求没有发出。调用方应保存 AiRequestContext::$replies 后暂停，不当作翻译失败。 */
final class AiRequestBudgetExhausted extends RuntimeException
{
}

final class AiRequestContext
{
    /** @var array<string,array{success:bool,content:string}> 键为 key() 的结果 */
    public array $replies;
    /** 本次实际发出的请求数 */
    public int $requests = 0;
    /** 本次从 $replies 重放、没有发出的请求数 */
    public int $replayed = 0;
    /**
     * 最近一次请求没拿到回复（服务商报错、网络中断、超时，或时间预算不够没发）。
     * 生成因此失败时，之前已成功的回复仍然可信，重试时接着重放；回复内容不合格导致的失败则整份作废。
     */
    public bool $interrupted = false;

    /**
     * @param null|Closure():bool $reserve 占一个额度；返回 false 表示用完了。null 表示不限
     * @param array<string,array{success:bool,content:string}> $replies 上次暂停前已成功的回复
     */
    public function __construct(private ?Closure $reserve = null, array $replies = [])
    {
        $this->replies = $replies;
    }

    /** 在每次真正发出请求之前调用 */
    public function beforeRequest(): void
    {
        if ($this->reserve !== null && !($this->reserve)()) {
            throw new AiRequestBudgetExhausted('ai_request_budget_exhausted');
        }
        $this->requests++;
    }

    /** 同一请求（提示、系统提示、温度、是否要求 JSON 都相同）得到同一个键 */
    public static function key(string $prompt, string $system, float $temperature, bool $jsonObject): string
    {
        return hash('sha256', json_encode([$prompt, $system, $temperature, $jsonObject], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * 从库里读回的回复清单；格式不对的整份丢掉（宁可重发，也不重放来路不明的内容）。
     * @return array<string,array{success:bool,content:string}>
     */
    public static function decodeReplies(?string $json): array
    {
        if ($json === null || $json === '') return [];
        $data = json_decode($json, true);
        if (!is_array($data)) return [];
        $out = [];
        foreach ($data as $key => $reply) {
            if (!is_string($key) || preg_match('/^[0-9a-f]{64}$/', $key) !== 1 || !is_array($reply)
                || ($reply['success'] ?? null) !== true || !is_string($reply['content'] ?? null)) {
                return [];
            }
            $out[$key] = ['success' => true, 'content' => $reply['content']];
        }
        return $out;
    }
}
