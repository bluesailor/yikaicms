<?php
/**
 * 安装器收下的管理员凭据，登录端必须能用（2026-09-18 复审 R04）。
 *
 * 登录页读参数走 post()，它对用户名和密码都 trim，再用 empty() 判空。
 * 安装器若原样收下空白用户名、字符串 "0"、或首尾带空格的密码，装完就登不进后台。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/install/validation.php';

final class InstallerCredentialContractTest extends TestCase
{
    /** 与 includes/functions.php 的 post() 同构：登录端看到的就是这个值。 */
    private function asLoginSees(string $raw): string
    {
        return trim($raw);
    }

    /** @return array<string, array{string}> */
    public static function unusableUsernames(): array
    {
        return [
            '空串'       => [''],
            '纯空格'     => ['   '],
            '制表符'     => ["\t"],
            '字符串零'   => ['0'],
            '首尾空格'   => [' admin '],
            '尾部空格'   => ['admin '],
            '换行'       => ["admin\n"],
        ];
    }

    /** @dataProvider unusableUsernames */
    public function testUsernamesThatCannotLogInAreRejectedAtInstall(string $username): void
    {
        self::assertFalse(
            \installerAdminUsernameValid($username),
            '安装器接受了登录端用不了的用户名：' . var_export($username, true)
        );
    }

    /** @return array<string, array{string}> */
    public static function usableUsernames(): array
    {
        return [
            '普通'     => ['admin'],
            '中文'     => ['站长'],
            '带点'     => ['site.admin'],
            '带邮箱'   => ['admin@example.com'],
            '带数字'   => ['admin2026'],
        ];
    }

    /** @dataProvider usableUsernames */
    public function testOrdinaryUsernamesStillPass(string $username): void
    {
        self::assertTrue(\installerAdminUsernameValid($username));
        self::assertSame($username, $this->asLoginSees($username), '登录端的 trim 不该改变它');
        self::assertNotEmpty($this->asLoginSees($username), 'empty() 不该判它为空');
    }

    /** @return array<string, array{string}> */
    public static function unusablePasswords(): array
    {
        return [
            '太短'       => ['12345'],
            '六个空格'   => ['      '],
            '首部空格'   => [' secret123'],
            '尾部空格'   => ['secret123 '],
            '仅换行'     => ["\n\n\n\n\n\n"],
        ];
    }

    /** @dataProvider unusablePasswords */
    public function testPasswordsThatCannotLogInAreRejectedAtInstall(string $password): void
    {
        self::assertFalse(
            \installerAdminPasswordValid($password),
            '安装器接受了登录端用不了的密码：' . var_export($password, true)
        );
    }

    /** @return array<string, array{string}> */
    public static function usablePasswords(): array
    {
        return [
            '普通'         => ['secret123'],
            '含空格但不在首尾' => ['pass word 123'],
            '中文'         => ['密码密码强度够'],
            '引号反斜杠'   => ['a"b\'c\\d1'],
            '正好六位'     => ['abc123'],
        ];
    }

    /** @dataProvider usablePasswords */
    public function testOrdinaryPasswordsStillPassAndSurviveLogin(string $password): void
    {
        self::assertTrue(\installerAdminPasswordValid($password));

        // 安装时 bcrypt 的是原值，登录端拿到的是 trim 后的值：两者必须一致
        $hash = password_hash($password, PASSWORD_BCRYPT);
        self::assertTrue(
            password_verify($this->asLoginSees($password), $hash),
            '登录端 trim 之后就对不上安装时的哈希了'
        );
    }

    /** 安装器必须在连库、导 SQL、写安装锁之前就拒绝，而不是装完才发现登不进去。 */
    public function testInstallerRejectsBeforeTouchingTheDatabase(): void
    {
        $installer = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/install/index.php'));

        $usernameCheck = strpos($installer, 'installerAdminUsernameValid(');
        $passwordCheck = strpos($installer, 'installerAdminPasswordValid(');
        // 安装动作里真正动数据库和写锁的两处：校验必须排在它们前面
        $importSeed = strpos($installer, '$pdo->exec($sql);');
        $writeLock = strpos($installer, "file_put_contents(ROOT_PATH . '/installed.lock'");
        self::assertIsInt($usernameCheck);
        self::assertIsInt($passwordCheck);
        self::assertIsInt($importSeed);
        self::assertIsInt($writeLock);
        foreach (['用户名' => $usernameCheck, '密码' => $passwordCheck] as $label => $check) {
            self::assertLessThan($importSeed, $check, $label . '校验要排在导入种子 SQL 之前');
            self::assertLessThan($writeLock, $check, $label . '校验要排在写安装锁之前');
        }

        foreach (['zh', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/install/lang/' . $lang . '.php';
            self::assertArrayHasKey('error_admin_user_invalid', $strings, "install/lang/{$lang}.php 缺少提示文案");
            self::assertNotSame('', trim((string) $strings['error_admin_user_invalid']));
        }
    }
}
