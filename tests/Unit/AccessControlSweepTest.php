<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.0.4 权限排查：「校验没过却继续执行」一类漏洞修好后钉住，不许回退。
 */
final class AccessControlSweepTest extends TestCase
{
    /** 去掉注释后的源码，避免说明文字里的关键字让断言误过 */
    private function code(string $path): string
    {
        $src = '';
        foreach (token_get_all((string) file_get_contents(ROOT_PATH . '/' . $path)) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $src .= is_array($token) ? $token[1] : $token;
        }
        return $src;
    }

    /** 从锚点到下一个顶格 `}` 之间的代码块（顶层 if 分支） */
    private function block(string $path, string $anchor): string
    {
        $src = $this->code($path);
        $start = strpos($src, $anchor);
        self::assertNotFalse($start, "{$path} 找不到 {$anchor}");
        $end = strpos($src, "\n}", $start);
        return substr($src, $start, $end === false ? null : $end - $start);
    }

    /** 自建登录判断、不走 checkLogin() 的端点，必须自己刷新身份并套演示站限制 */
    public function testSelfAuthenticatingEndpointsApplyIdentityAndDemoRules(): void
    {
        $files = array_merge(glob(ROOT_PATH . '/admin/*.php') ?: [], glob(ROOT_PATH . '/plugins/*/*.php') ?: []);
        $checked = 0;
        foreach ($files as $file) {
            $rel = substr($file, strlen(ROOT_PATH) + 1);
            if ($rel === 'admin/login.php') {
                continue;
            }
            $src = $this->code($rel);
            if (!str_contains($src, "empty(\$_SESSION['admin_id'])") || str_contains($src, 'checkLogin()')) {
                continue;
            }
            $checked++;
            self::assertStringContainsString('refreshAdminIdentity();', $src, "{$rel} 没刷新身份，会沿用登录时的旧权限");
            self::assertStringContainsString('enforceDemoRestrictions();', $src, "{$rel} 绕开了演示站只读/受保护页限制");
        }
        self::assertGreaterThanOrEqual(7, $checked, '自建登录端点数量骤减，检查匹配条件是否失效');
    }

    public function testCheckLoginDelegatesDemoRulesToSharedHelper(): void
    {
        $src = $this->code('admin/includes/auth.php');
        self::assertMatchesRegularExpression('/function checkLogin\(\)[\s\S]*?enforceDemoRestrictions\(\);\s*\}/', $src);
        self::assertStringContainsString('function enforceDemoRestrictions(): void', $src);
    }

    public function testOnlineUpgradeActionsArePostOnly(): void
    {
        $src = $this->code('admin/upgrade_online.php');
        self::assertStringNotContainsString("\$_GET['action']", $src, 'GET 动作不校验 CSRF');
        self::assertStringContainsString(
            "\$action = (\$_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? (string) (\$_POST['action'] ?? '') : '';",
            $src
        );
    }

    public function testContentEditStopsOnMissingOrRecycledRow(): void
    {
        $src = $this->code('admin/content_edit.php');
        self::assertMatchesRegularExpression('/if \(\$id > 0 && !\$content\) \{[\s\S]{0,200}?error\(__\(\'error_content_not_found\'\)\)/', $src);
    }

    public function testPageActionsOnlyTouchPageChannels(): void
    {
        $src = $this->code('admin/page.php');
        self::assertSame(2, substr_count($src, "channelModel()->findWhere(['id' => \$id, 'type' => 'page'])"));
        self::assertStringNotContainsString('channelModel()->find($id)', $src);
    }

    public function testSingleContentDeleteUsesRowLevelCheck(): void
    {
        $src = $this->code('admin/content.php');
        self::assertMatchesRegularExpression(
            '/if \(!contentModel\(\)->find\(\$id\)\) \{\s*error\(__\(\'error_content_not_found\'\)\);\s*\}\s*if \(!canDeleteContentRow\(\$id\)\) \{\s*permissionDenied\(\);/',
            $src
        );
    }

    public function testSiteWideActionsRequireSuperAdmin(): void
    {
        self::assertStringContainsString("requirePermission('*');", $this->block('admin/upgrade.php', "=== 'run_auto_upgrade')"));
        self::assertStringContainsString("requirePermission('*');", $this->block('admin/index.php', "=== 'dismiss_onboard')"));
    }

    public function testDraftAbilityChecksPermissionForNonArticleTypes(): void
    {
        $src = $this->code('includes/abilities/cms_basics.php');
        self::assertStringContainsString("if (\$type !== 'article' && !hasPermission('edit_' . \$type)) {", $src);
        self::assertStringNotContainsString("'type'       => \$input['type'] ?? 'article'", $src);
    }
}
