<?php
/**
 * Smoke test for Abilities. CLI:
 *   php tools/test_abilities.php
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/Abilities.php';

// Register a few test abilities
register_ability('test_echo', [
    'label'        => 'Echo',
    'description'  => 'Returns input verbatim',
    'input_schema' => [
        'type'       => 'object',
        'properties' => ['msg' => ['type' => 'string']],
        'required'   => ['msg'],
    ],
    'execute'      => fn(array $in) => $in['msg'],
]);

register_ability('test_add', [
    'label'        => 'Add two integers',
    'description'  => 'a + b',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'a' => ['type' => 'integer'],
            'b' => ['type' => 'integer'],
        ],
        'required' => ['a', 'b'],
    ],
    'execute'      => fn(array $in) => $in['a'] + $in['b'],
]);

register_ability('test_strict_lang', [
    'label'        => 'Lang enum',
    'description'  => 'enum check',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'lang' => ['type' => 'string', 'enum' => ['zh', 'en']],
        ],
        'required' => ['lang'],
    ],
    'execute'      => fn(array $in) => "got {$in['lang']}",
]);

echo "=== Registered: ", count(Abilities::all()), " abilities\n\n";

// Tests
$cases = [
    ['test_echo',        ['msg' => 'hi'],       true],
    ['test_echo',        [],                    false], // missing required
    ['test_add',         ['a' => 3, 'b' => 4],  true],
    ['test_add',         ['a' => 3, 'b' => 'x'],false], // wrong type
    ['test_strict_lang', ['lang' => 'zh'],      true],
    ['test_strict_lang', ['lang' => 'fr'],      false], // bad enum
    ['test_unknown',     [],                    false], // not registered
];

foreach ($cases as [$name, $input, $expectSuccess]) {
    $r = Abilities::execute($name, $input);
    $pass = $r['success'] === $expectSuccess ? 'PASS' : 'FAIL';
    $detail = $r['success'] ? json_encode($r['output']) : $r['error'];
    echo sprintf("[%s] %s(%s) => %s\n", $pass, $name, json_encode($input), $detail);
}

echo "\n=== OpenAI tools format ===\n";
$tools = Abilities::asOpenAITools(['test_echo', 'test_add']);
echo json_encode($tools, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";

echo "\n=== Anthropic tools format ===\n";
$tools = Abilities::asAnthropicTools(['test_strict_lang']);
echo json_encode($tools, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
