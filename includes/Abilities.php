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
    /** 任一内容类型的编辑 / 删除权限（与 hasAnyContentPerm() 同义） */
    public const ANY_CONTENT = '@content';
    /**
     * 分级确认（路线图 §5.4）：read 直接执行；draft 写草稿、直接执行可撤销；confirm 先出提案、人确认后生效。
     * 未声明时按 mutating 推导（mutating → confirm，否则 read）。
     */
    public const TIERS = ['read', 'draft', 'confirm'];

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
        // v2.1 按能力授权：每个能力声明要求的权限键（全部满足才可用；'@content' = 任一内容权限），
        // 不再只看「已登录后台」。空数组要显式写出，表示不读写站点数据（如导航、纯文本翻译）。
        if (isset($config['permissions'])) {
            if (!is_array($config['permissions'])) {
                throw new \InvalidArgumentException("Ability {$name} 'permissions' must be a list of permission keys");
            }
            foreach ($config['permissions'] as $key) {
                if (!is_string($key) || ($key !== self::ANY_CONTENT && !preg_match('/^(?:\*|[a-z_]+)$/', $key))) {
                    throw new \InvalidArgumentException("Ability {$name} has an invalid permission key");
                }
            }
        }
        if (isset($config['tier']) && !in_array($config['tier'], self::TIERS, true)) {
            throw new \InvalidArgumentException("Ability {$name} has an invalid tier");
        }
        if (isset($config['preview']) && !is_callable($config['preview'])) {
            throw new \InvalidArgumentException("Ability {$name} 'preview' must be callable");
        }
        if (isset($config['revert']) && !is_callable($config['revert'])) {
            throw new \InvalidArgumentException("Ability {$name} 'revert' must be callable");
        }
        self::$registry[$name] = $config + [
            'output_schema' => null,
            'permissions'   => null,    // list<string>|null：权限键；null = 未声明（契约测试会拦下）
            'tier'          => !empty($config['mutating']) ? 'confirm' : 'read',
            'permission'    => null,
            'mutating'      => false,   // 写操作：true 时 agent 走「提案→确认→应用」而非直接执行
            'preview'       => null,    // fn($input) => ['summary','before','after']（只算不改）
            'revert'        => null,    // fn($before, $input) => void（撤销）
        ];
    }

    /** 是否为写操作（需走确认机制） */
    public static function isMutating(string $name): bool
    {
        $a = self::get($name);
        return $a ? (bool) ($a['mutating'] ?? false) : false;
    }

    /** 权限校验（供 apply/undo 复用） */
    public static function permitted(string $name): bool
    {
        $a = self::get($name);
        if (!$a) return false;
        if (!self::hasPermissions((array) ($a['permissions'] ?? []))) return false;
        if ($a['permission'] !== null) return (bool) call_user_func($a['permission']);
        // 既没声明权限键、也没有回调：只认已登录后台（旧插件注册的能力的兼容兜底）
        return $a['permissions'] !== null || !empty($_SESSION['admin_id']);
    }

    /** @param list<string> $keys 全部满足才算有权限 */
    public static function hasPermissions(array $keys): bool
    {
        if (empty($_SESSION['admin_id'])) {
            return false;
        }
        foreach ($keys as $key) {
            $ok = $key === self::ANY_CONTENT
                ? function_exists('hasAnyContentPerm') && hasAnyContentPerm()
                : function_exists('hasPermission') && hasPermission($key);
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    /**
     * 能力目录（v2.2 按用户 API 令牌勾选能力、MCP 工具清单用）：只给元数据，不含回调。
     *
     * @return list<array{name:string,label:string,tier:string,permissions:list<string>,mutating:bool}>
     * @psalm-suppress PossiblyUnusedMethod 消费方为契约测试；令牌与 MCP 界面随 v2.2
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::$registry as $name => $a) {
            $out[] = [
                'name' => $name,
                'label' => (string) $a['label'],
                'tier' => (string) $a['tier'],
                'permissions' => array_values((array) ($a['permissions'] ?? [])),
                'mutating' => (bool) $a['mutating'],
            ];
        }
        return $out;
    }

    /**
     * 只算不改：权限 → 校验 → preview。
     * @return array{success:bool, preview?:array{summary:string,before:mixed,after:mixed,input?:array<string,mixed>}, error?:string}
     */
    public static function previewChange(string $name, array $input): array
    {
        $a = self::get($name);
        if (!$a) return ['success' => false, 'error' => "Unknown ability: {$name}"];
        if (!self::permitted($name)) return ['success' => false, 'error' => 'Permission denied'];
        $errors = self::validateAgainstSchema($input, $a['input_schema']);
        if ($errors !== []) return ['success' => false, 'error' => 'Input invalid: ' . implode('; ', $errors)];
        try {
            $preview = $a['preview'] !== null
                ? (array) call_user_func($a['preview'], $input)
                : ['summary' => (string) ($a['label'] ?? $name), 'before' => null, 'after' => null];
            $preview += ['summary' => (string) ($a['label'] ?? $name), 'before' => null, 'after' => null];
            return ['success' => true, 'preview' => $preview];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** 撤销：调 revert(before, input) */
    public static function revertChange(string $name, mixed $before, array $input): array
    {
        $a = self::get($name);
        if (!$a) return ['success' => false, 'error' => "Unknown ability: {$name}"];
        if ($a['revert'] === null) return ['success' => false, 'error' => '该操作不支持撤销'];
        if (!self::permitted($name)) return ['success' => false, 'error' => 'Permission denied'];
        try {
            call_user_func($a['revert'], $before, $input);
            return ['success' => true];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
        // 权限：声明的权限键 + 可选回调（与 previewChange / revertChange 同一入口）
        if (!self::permitted($name)) {
            return ['success' => false, 'error' => 'Permission denied'];
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
    /**
     * 规范化 JSON Schema 供大模型 tools API 使用：
     * 确保是 object，且空 properties 编码为 {} 而非 []（否则 DeepSeek/OpenAI 报
     * "[] is not of type object"）。
     */
    public static function normalizeSchema(mixed $schema): array
    {
        if (!is_array($schema) || $schema === []) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        if (!isset($schema['type'])) {
            $schema['type'] = 'object';
        }
        if (!array_key_exists('properties', $schema) || $schema['properties'] === [] || $schema['properties'] === null) {
            $schema['properties'] = new \stdClass();
        }
        return $schema;
    }

    public static function asOpenAITools(?array $names = null): array
    {
        $tools = [];
        $set   = $names === null ? array_keys(self::$registry) : $names;
        foreach ($set as $n) {
            // 只把当前用户能用的能力交给模型：没权限的工具既是噪声，也会诱导模型反复撞「Permission denied」
            if (!isset(self::$registry[$n]) || !self::permitted($n)) continue;
            $a = self::$registry[$n];
            $tools[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $n,
                    'description' => (string)$a['description'],
                    'parameters'  => self::normalizeSchema($a['input_schema']),
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
            if (!isset(self::$registry[$n]) || !self::permitted($n)) continue;
            $a = self::$registry[$n];
            $tools[] = [
                'name'         => $n,
                'description'  => (string)$a['description'],
                'input_schema' => self::normalizeSchema($a['input_schema']),
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
