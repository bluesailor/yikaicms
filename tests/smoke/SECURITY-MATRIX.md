# 高风险权限矩阵（第一批）

本批验证草稿/画布预览、图片与文档上传、图片编辑、Blox 模板导入、配方预览/应用门禁、他人 AI 提案读取与应用。使用匿名、无权限、文章编辑、页面编辑、媒体管理、另一位有 AI 权限的用户及超管会话。

拒绝断言同时核对响应、敏感内容标记、11 张业务表与 uploads、媒体备份/导入目录的文件摘要；不把正常的认证会话、访问记录及缓存变化当成业务写入。合法对照实际执行 PNG/SVG/PDF 上传、图片旋转与恢复、模板草稿导入及内容预览。页面编辑权限允许编辑全站相应类型，不按作者划分；只有 AI 提案另有 owner 边界。

需要 PHP 8.2+、pdo_sqlite、curl、gd、dom、mbstring、OpenSSL。在独立工作区运行，不能对真实站点执行；脚本拒绝非回环 SQLite、缺少 smoke 备份、已占用端口或指向工作区外的图片目录。

```powershell
$env:SMOKE_SITE_URL = 'http://127.0.0.1:18093'
try {
    php tests/smoke/setup.php
    if ($LASTEXITCODE -ne 0) { throw 'Setup failed' }
    php tests/smoke/security_permission_matrix.php --out=matrix-result.json
    if ($LASTEXITCODE -ne 0) { throw 'Permission checks failed' }
} finally {
    php tests/smoke/setup.php --restore
}
```

每轮必须重新准备独立安装，脚本管理自己的 PHP 服务并清理自己产生的媒体文件；不要在同一测试工作区并发运行。没有发布动作，也不访问模型服务。

`tools/check_permission_inventory.php` 检查 147 个当前入口候选文件及字面 action 候选，与 `tests/fixtures/security-entry-inventory.json` 对照。新增文件或被识别的新动作会使 PHPUnit 中的 PermissionEntryInventoryTest 失败。审查变更后才执行 `php tools/check_permission_inventory.php --update`，不能仅为消除失败而更新清单。动作发现是启发式的，动态 dispatch、插件注册路由仍需人工审查。

清单不是覆盖率证明。本轮仅验证上面这批动作，整站包暂存/替换、插件与主题安装升级、前台搜索/筛选/密码保护查询、其他插件入口等仍待分批覆盖。完整 HTTP 矩阵已接入 CI 的 PHP 8.2 中文 smoke 独立步骤；远端 CI 是否通过需以实际运行记录为准。
