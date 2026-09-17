<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * `fooModel()->method()` 调用的方法必须在模型类（或其父类）里真实存在。
 *
 * 2026-09-17 发现 admin/form_design.php（删除模板、保存翻译版）与 admin/form_spam.php（验证码开关）
 * 调用了不存在的 FormTemplateModel::findById()，点击即 500；前两处被 psalm-baseline.xml
 * 收编多时未被察觉。基线能吞掉 Psalm 报错，这条测试不走基线。
 */
final class ModelFactoryMethodCallsTest extends TestCase
{
    private const SKIP = '#/(vendor|node_modules|tests|\.deploytmp|docs|storage|uploads|test-results|playwright-report|releases|\.git)/#';

    public function testEveryModelFactoryCallTargetsAnExistingMethod(): void
    {
        $factories = $this->factories();
        self::assertGreaterThan(20, count($factories), 'autoload.php 工厂函数解析失败');

        $methods = [];
        $checked = 0;
        $missing = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(ROOT_PATH, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (substr($path, -4) !== '.php' || preg_match(self::SKIP, $path) === 1) {
                continue;
            }
            $code = $this->withoutComments((string) file_get_contents($path));
            if (preg_match_all('/\b(\w+)\(\)\s*->\s*(\w+)\s*\(/', $code, $calls, PREG_SET_ORDER) === 0) {
                continue;
            }
            foreach ($calls as [, $factory, $method]) {
                if (!isset($factories[$factory])) {
                    continue;
                }
                $class = $factories[$factory];
                $methods[$class] ??= $this->declaredMethods($class);
                $checked++;
                if (!in_array(strtolower($method), $methods[$class], true)) {
                    $missing[] = substr($path, strlen(str_replace('\\', '/', ROOT_PATH)) + 1) . ' ' . $factory . '()->' . $method . '()';
                }
            }
        }

        self::assertGreaterThan(500, $checked, '扫描范围异常，调用数过少');
        self::assertSame([], $missing, '调用了模型上不存在的方法');
    }

    /** @return array<string,string> 工厂函数名 => 模型类名 */
    private function factories(): array
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/models/autoload.php');
        preg_match_all('/function\s+(\w+)\s*\(\s*\)\s*:\s*\\\\?(\w+)/', $source, $matches, PREG_SET_ORDER);
        $map = [];
        foreach ($matches as [, $function, $class]) {
            $map[$function] = $class;
        }
        return $map;
    }

    /** @return list<string> 小写方法名，含父类链 */
    private function declaredMethods(string $class): array
    {
        $methods = [];
        while ($class !== '') {
            $file = ROOT_PATH . '/includes/models/' . $class . '.php';
            self::assertFileExists($file, '模型类文件缺失：' . $class);
            $source = (string) file_get_contents($file);
            self::assertStringNotContainsString('__call', $source, $class . ' 使用魔术方法，本测试需要改为反射');
            preg_match_all('/function\s+(\w+)\s*\(/', $source, $matches);
            $methods = array_merge($methods, array_map('strtolower', $matches[1]));
            $class = preg_match('/class\s+\w+\s+extends\s+(\w+)/', $source, $parent) === 1 ? $parent[1] : '';
        }
        return $methods;
    }

    private function withoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        return $code;
    }
}
