<?php
/**
 * 后台页面的前端错误上报（2.1.1）：在 <head> 里尽早引入，脚本错误（含 Alpine 表达式错误）写进「错误日志」。
 * 调用前可设 $clientErrorPage 标明来源（如 blox-editor）。实现见 assets/js/admin-error-report.js。
 * 接口用相对路径：后台页面都在 admin/ 下，子目录安装也不用拼前缀。
 */

declare(strict_types=1);

$clientErrorConfig = [
    'endpoint' => 'client_error_api.php',
    'token' => csrfToken(),
    'page' => (string) ($clientErrorPage ?? 'admin'),
];
?>
<script>window.YikaiErrorReportConfig = <?= json_encode($clientErrorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= e(assetVer('/assets/js/admin-error-report.js')) ?>"></script>
