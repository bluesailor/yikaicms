# 无 Fileinfo 的上传兼容验收

只在新建的独立测试工作树运行。先确认没有实际站点配置/数据库，再用 `tests/smoke/setup.php` 生成 loopback SQLite 测试站；本脚本拒绝没有 smoke 备份标记、非 SQLite 或非 loopback 的环境。不要在真实站点运行 setup。

```powershell
$env:SMOKE_SITE_URL='http://127.0.0.1:18945'
$env:SMOKE_BLOX_ADVANCED='0'
php tests/smoke/setup.php
php tests/smoke/upload_fileinfo.php --report=D:/test-results/upload-fileinfo.json
php tests/smoke/setup.php --restore
```

结果目录须已存在；端口须空闲。Windows PHP 8.2+ 发行版需包含 mbstring、pdo_sqlite、openssl、gd、curl、zip 的 DLL，父 CLI 需已启用这些扩展及 Fileinfo。脚本依次启动四个自有 PHP 内置服务器：正常、仅禁用 mime_content_type、禁用 finfo 函数、`-n` 后显式加载必要扩展但不加载 Fileinfo。就绪 HTTP 检查核实实际 PHP 版本及扩展/函数可用性，而不是用 mock 声称模拟成功。

通过真实登录、CSRF 和 multipart 请求验证 `admin/upload.php` 与 `admin/media_api.php?action=upload`：

- PNG/JPEG/GIF/WebP 上传、图片尺寸与媒体 MIME 入库；故意发送错误的客户端 MIME，检测仍以实际文件为准。
- 正常及单个函数被禁用时 PDF/SVG 可校验并上传；完全无 Fileinfo 时在落盘前明确拒绝这些文件，不仅凭扩展名放行文档/压缩包。
- PHP 文本伪装 JPG、PNG 改名 JPG 被拒绝，上传目录文件与媒体表保持原状；登录与 CSRF 校验保留。
- 检查各轮新增 PHP/应用日志无 warning/error；已有日志不删除，以本轮偏移量判断。读取 SQLite 对照后及时关闭游标，避免测试客户端自己的读锁阻塞服务器写入。

脚本仅停止自己的进程，删除自有 HTTP 探针、会话/cookie 和本次新增上传文件/媒体行。JSON 保存能力实测、检查清单和运行文件 SHA-256，诊断目录保留合成文件和服务器访问日志；站点配置/数据库由 setup --restore 还原。本工具限 Windows 发行版，HTTP 内置服务器结果不等同于 Linux/PHP-FPM、客户 PHP 8.3 或全量发布验收。

另有 `OptionalFileWarningsTest` 在捕捉所有警告（包括 @ 抑制警告）的处理器下验证语言清单缺失/损坏、主题回执缺失/损坏/正常，以及首次 WebP 目标不存在和现有目标复用。未更改全局错误记录策略。

2026-11-05 追加实测：Windows PHP 8.5.9，在仅测试站配置 `DEBUG=true` / `E_ALL` 下四种 HTTP 能力环境 89/89，相关单测 56/382 无弃用提示；PHP 8.2.33 同组单测回归也通过。Fileinfo、GD 与 cURL 对象采用自动释放，相关路径不调用 PHP 8.5 已弃用的关闭函数。此结论限该上传与可选文件处理范围，不表示全站 PHP 8.5 兼容门禁已完成。
