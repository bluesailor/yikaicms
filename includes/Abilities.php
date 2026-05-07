<?php
/**
 * Yikai CMS - Abilities 注册中心（参考 WordPress 6.9 Abilities API）
 *
 * 把 CMS 操作以 JSON Schema 描述并注册，使 AI 可经 function-calling 协议主动调用，
 * 同时统一了"action 路由 → 权限校验 → 输入校验 → 执行 → 日志"流程。
 *
 * 用法：
 *   register_ability('cms.publish_post', [
 *       'label'        => '发布文章',
 *       'description'  => '将草稿状态文章设为已发布',
 *       'input_schema' => ['type'=>'object','properties'=>['id'=>['type'=>'integer']],'required'=>['id']],
 *       'permission'   => fn() => !empty($_SESSION['admin_id']),
 *       'execute'      => fn(array $in) => contentModel()->publish($in['id']),
 *   ]);
 *
 *   $result = Abilities::execute('cms.publish_post', ['id' => 42]);
 *   $tools  = Abilities::asOpenAITools();   // 喂给 AiService::chatWithTools()
 */

declare(strict_types=1);

class Abilities
{
    /** @var array<string, array<string, mixed>> */
    private static array $registry = [];

    /**
     * 注册一个 ability。
     *
     * @param string $name      唯一标识（建议 namespace.action 风格，如 cms.translate）
     * @param array  $config    [label, description, input_schema, output_schema?, permission?, execute]
     */
    public static function register(string $name, array $config): void
    {
        // OpenAI / DeepSeek tools API 要求 function.name 匹配 ^[a-zA-Z0-9_-]+$，
        // 这里同步限制（不允许 . 等字符），避免运行时 API 报错。
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid ability name: {$name} (only [a-zA-Z0-9_-] allowed)");
        }
        foreach (['label', 'description', 'input_schema', 'execute'] as $req) {
            if (!array_key_exists($req, $config)) {
                throw new \InvalidArgumentException("Ability {$name} missing required key: {$req}");
            }
        }
        if (!is_callable($config['execute'])) {
            throw new \InvalidArgumentException("Ability {$name} 'execute' must be callable");
        }
        if (isset($config['permission']) && !is_callable($config['permission'])) {
            throw new \InvalidArgumentException("Ability {$name} 'permission' must be callable");
        }
        self::$registry[$name] = $config + [
            'output_schema' => null,
            'permission'    => null,
        ];
    }

    public static function has(string $name): bool
    {
        return isset(self::$registry[$name]);
    }

    public static function get(string $name): ?array
    {
        return self::$registry[$name] ?? null;
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        return self::$registry;
    }

    /**
     * 执行能力：权限 → 输入校验 → 执行 → 返回。
     *
     * @return array{success:bool,output?:mixed,error?:string}
     */
    public static function execute(string $name, array $input): array
    {
        $a = self::get($name);
        if (!$a) {
            return ['success' => false, 'error' => "Unknown ability: {$name}"];
        }
        // 权限
        if ($a['permission'] !== null) {
            $ok = (bool) call_user_func($a['permission']);
            if (!$ok) {
                return ['success' => false, 'error' => 'Permission denied'];
            }
        }
        // 输入校验
        $errors = self::validateAgainstSchema($input, $a['input_schema']);
        if ($errors !== []) {
            return ['success' => false, 'error' => 'Input invalid: ' . implode('; ', $errors)];
        }
        try {
            $output = call_user_func($a['execute'], $input);
            return ['success' => true, 'output' => $output];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * 转成 OpenAI / DeepSeek / Qwen / Zhipu 通用 tools 格式。
     * Anthropic Claude 用 asAnthropicTools()。
     */
    public static function asOpenAITools(?array $names = null): array
    {
        $tools = [];
        $set   = $names === null ? array_keys(self::$registry) : $names;
        foreach ($set as $n) {
            if (!isset(self::$registry[$n])) continue;
            $a = self::$registry[$n];
            $tools[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $n,
                    'description' => (string)$a['description'],
                    'parameters'  => $a['input_schema'],
                ],
            ];
        }
        return $tools;
    }

    /**
     * Anthropic Claude tool use 格式。
     */
    public static function asAnthropicTools(?array $names = null): array
    {
        $tools = [];
        $set   = $names === null ? array_keys(self::$registry) : $names;
        foreach ($set as $n) {
            if (!isset(self::$registry[$n])) continue;
            $a = self::$registry[$n];
            $tools[] = [
                'name'         => $n,
                'description'  => (string)$a['description'],
                'input_schema' => $a['input_schema'],
            ];
        }
        return $tools;
    }

    /**
     * 极简 JSON Schema 校验（type / required / enum / 基本属性）——
     * 不引入 PHP JSON Schema 库，覆盖 80% 实用场景。
     *
     * @return string[] 错误列表，空数组表示通过
     */
    private static function validateAgainstSchema(mixed $value, array $schema, string $path = '$'): array
    {
        $errors = [];
        $type   = $schema['type'] ?? null;

        if ($type === 'object') {
            if (!is_array($value)) {
                return ["{$path}: expected object"];
            }
            foreach (($schema['required'] ?? []) as $req) {
                if (!array_key_exists($req, $value)) {
                    $errors[] = "{$path}.{$req}: required";
                }
            }
            foreach (($schema['properties'] ?? []) as $prop => $sub) {
                if (array_key_exists($prop, $value)) {
                    $errors = array_merge(
                        $errors,
                        self::validateAgainstSchema($value[$prop], $sub, "{$path}.{$prop}")
                    );
                }
            }
        } elseif ($type === 'array') {
            if (!is_array($value)) return ["{$path}: expected array"];
            $itemSchema = $schema['items'] ?? null;
            if (is_array($itemSchema)) {
                foreach ($value as $i => $item) {
                    $errors = array_merge(
                        $errors,
                        self::validateAgainstSchema($item, $itemSchema, "{$path}[{$i}]")
                    );
                }
            }
        } elseif ($type === 'string') {
            if (!is_string($value)) $errors[] = "{$path}: expected string";
            elseif (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
                $errors[] = "{$path}: must be one of " . implode(',', $schema['enum']);
            }
        } elseif ($type === 'integer') {
            if (!is_int($value)) $errors[] = "{$path}: expected integer";
        } elseif ($type === 'number') {
            if (!is_int($value) && !is_float($value)) $errors[] = "{$path}: expected number";
        } elseif ($type === 'boolean') {
            if (!is_bool($value)) $errors[] = "{$path}: expected boolean";
        }
        return $errors;
    }
}

/**
 * 简便注册函数（与 WP 风格一致）。
 */
function register_ability(string $name, array $config): void
{
    Abilities::register($name, $config);
}
