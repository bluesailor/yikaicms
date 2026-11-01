# 高风险权限矩阵

## 第一批：预览、媒体、内容提案

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

清单不是覆盖率证明。完整 HTTP 矩阵已接入 CI 的 PHP 8.2 中文 smoke 独立步骤；远端 CI 是否通过需以实际运行记录为准。

## 第二批：整站包、安装门禁、公开查询

在同样的独立安装中，将上述命令的端口改为 `18094`，运行 `php tests/smoke/security_permission_matrix_phase2.php --out=matrix-phase2.json`，结束后仍须执行 `setup.php --restore`。脚本仅限 CLI，管理自己的 PHP 服务；不要同时运行其他 smoke 任务。

第二批使用真实本地 ZIP、被页面引用的 SVG、独立用户和 HTTP 会话。90 项断言覆盖：

- 整站包 prepare/stage/refresh_preview/apply/restore：匿名、文章编辑、维护角色、缺 CSRF、GET 动作无副作用；另一位超管不能消费包所有者的暂存令牌。
- 合法对照：实际导出并准备包、完成媒体暂存；拒绝未确认应用后保留暂存文件，随后合法替换并恢复原站点名称。
- 插件上传、市场安装、主题市场安装、在线升级入口的权限/CSRF 拒绝；本地无害插件 ZIP 实际安装 1.0.0、更新 1.1.0，全程禁用；GET 参数不能触发安装/升级。
- 文章、案例、产品、下载的公开搜索仅返回当前语言已发布内容，排除草稿/回收站/其他语言；伪造预览参数不能读取私有详情。
- 实际 AJAX 循环返回纯 JSON、公开计数与内容；普通/未匹配循环的页面仍返回 HTML；伪造 status/preview/type 不扩大访问范围；数组 keyword/type 返回 400。

拒绝路径比较整站包数据表、settings/plugins 与指定 uploads/themes/插件及暂存备份目录的摘要；认证会话、访问日志、缓存与锁文件不在业务摘要内。脚本只清理本次新建的文件和空目录，smoke restore 负责恢复测试安装。合法应用/恢复仅发生在一次性 SQLite 安装，没有对真实站点或测试 AI 内容执行发布。

该批不下载远程市场包，不执行插件激活，不等同于主题/核心升级的成功路径验收；未覆盖全部动态插件路由、所有筛选组合、MySQL 或生产服务器。当前核心内容表没有文章密码保护能力，伪造 `password` 参数测试不能当作该功能验收。组件锁定版本的「导出→全新安装→导入→渲染」仍是另一项门禁，本批没有完成它。
