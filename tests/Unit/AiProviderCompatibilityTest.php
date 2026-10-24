<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

require_once ROOT_PATH . '/includes/AiService.php';

final class AiProviderCompatibilityTest extends TestCase
{
    private function service(string $provider, string $model): AiService
    {
        $service = (new ReflectionClass(AiService::class))->newInstanceWithoutConstructor();
        foreach (['provider' => $provider, 'model' => $model, 'apiKey' => 'fixture-key'] as $key => $value) {
            $property = new ReflectionProperty(AiService::class, $key);
            $property->setAccessible(true);
            $property->setValue($service, $value);
        }
        return $service;
    }

    private function invoke(AiService $service, string $method, array $arguments = []): array
    {
        $reflection = new ReflectionMethod(AiService::class, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($service, $arguments);
    }

    private function payload(): array
    {
        return ['temperature' => 0.1, 'max_tokens' => 4096, 'messages' => [['role' => 'user', 'content' => 'OK']]];
    }

    public function testReasoningOnlyModelsDoNotReceiveUnsupportedSamplingOrLegacyLimits(): void
    {
        foreach (['openai' => 'gpt-6.1-sol', 'kimi' => 'kimi-k3.1-pro'] as $provider => $model) {
            $payload = $this->invoke($this->service($provider, $model), 'openAIPayload', [$this->payload()]);
            self::assertArrayNotHasKey('temperature', $payload);
            self::assertArrayNotHasKey('max_tokens', $payload);
            self::assertSame('low', $payload['reasoning_effort']);
            self::assertSame(16384, $payload['max_completion_tokens']);
        }
    }

    public function testLunaPreservesNonReasoningChatToolsAndSampling(): void
    {
        $payload = $this->invoke($this->service('openai', 'gpt-6-luna'), 'openAIPayload', [$this->payload()]);
        self::assertSame('none', $payload['reasoning_effort']);
        self::assertSame(0.1, $payload['temperature']);
    }

    public function testMiMoUsesItsOwnAuthHeaderAndExplicitTextMode(): void
    {
        $service = $this->service('mimo', 'mimo-v2.6-pro');
        self::assertSame(['api-key: fixture-key', 'Content-Type: application/json'], $this->invoke($service, 'openAIHeaders'));
        $payload = $this->invoke($service, 'openAIPayload', [$this->payload()]);
        self::assertSame(['type' => 'disabled'], $payload['thinking']);
        self::assertSame(4096, $payload['max_completion_tokens']);
        self::assertArrayNotHasKey('max_tokens', $payload);
    }

    public function testLegacyAndCustomCompatiblePayloadsStayUnchanged(): void
    {
        foreach (['deepseek' => 'deepseek-flash', 'custom_openai' => 'gpt-6.1-sol', 'siliconflow' => 'Qwen/Qwen2.5-72B-Instruct', 'hunyuan' => 'hy3'] as $provider => $model) {
            $service = $this->service($provider, $model);
            self::assertSame($this->payload(), $this->invoke($service, 'openAIPayload', [$this->payload()]));
            self::assertSame('Authorization: Bearer fixture-key', $this->invoke($service, 'openAIHeaders')[0]);
        }
    }

    public function testResponsesRoundTripPreservesToolIdsReasoningAndUsage(): void
    {
        $service = $this->service('openai', 'gpt-6.1-sol');
        $output = [
            ['id' => 'rs_1', 'type' => 'reasoning', 'summary' => [], 'encrypted_content' => 'opaque-fixture'],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'cms_search', 'arguments' => '{"query":"news"}'],
        ];
        $completion = $this->invoke($service, 'responsesCompletion', [['output' => $output, 'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15]]]);
        $message = $completion['choices'][0]['message'];
        self::assertSame('call_1', $message['tool_calls'][0]['id']);
        self::assertSame(15, $completion['usage']['total_tokens']);
        $payload = $this->payload();
        $payload['messages'][] = $message;
        $payload['messages'][] = ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '{"success":true}'];
        $payload['tools'] = [['type' => 'function', 'function' => ['name' => 'cms_search', 'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]]]]];
        $request = $this->invoke($service, 'responsesPayload', [$payload]);
        self::assertSame($output[0], $request['input'][1]);
        self::assertSame($output[1], $request['input'][2]);
        self::assertSame(['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"success":true}'], $request['input'][3]);
        self::assertFalse($request['store']);
        self::assertFalse($request['tools'][0]['strict']);
        self::assertArrayNotHasKey('temperature', $request);
        $final = $this->invoke($service, 'responsesCompletion', [['output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'Found'], ['type' => 'output_text', 'text' => ' it']]]]]]);
        self::assertSame('Found it', $final['choices'][0]['message']['content']);
        self::assertSame([], $final['choices'][0]['message']['tool_calls']);
    }

    public function testSelectingDefaultOfAnotherProviderDoesNotInheritSavedModelOrUrl(): void
    {
        $previous = $GLOBALS['_test_config'] ?? [];
        try {
            $GLOBALS['_test_config'] = ['ai_provider' => 'custom_openai', 'ai_model' => 'old-model', 'ai_base_url' => 'https://example.test/v1'];
            $service = new AiService('kimi', 'fixture-key', '', '');
            foreach (['model' => 'kimi-k3.1-pro', 'baseUrl' => 'https://api.moonshot.cn/v1'] as $key => $expected) {
                $property = new ReflectionProperty(AiService::class, $key);
                $property->setAccessible(true);
                self::assertSame($expected, $property->getValue($service));
            }
            self::assertSame('old-model', $GLOBALS['_test_config']['ai_model']);
        } finally {
            $GLOBALS['_test_config'] = $previous;
        }
    }
}
