# 组件整站包往返验收

在独立工作区执行，需要 PHP 8.2+、pdo_sqlite、curl、ZipArchive、DOM、mbstring 和 Git；MySQL 模式还需 pdo_mysql。不是对已有站点执行导入的脚本。

```powershell
php tools/component-site-roundtrip.php --report=component-roundtrip.json
```

默认端口 `18907`，可用 `--port=18095` 指定其他空闲端口。报告父目录必须已经存在；`--keep` 保留成功现场。失败现场自动保留，路径写入 JSON，供复核使用。

脚本从当前工作树的已跟踪运行文件建立两个全新临时站，均通过回环 HTTP 安装器安装 SQLite；仅复制本门禁自己的 CLI fixture。管理员密码随机生成，不输出、不进入报告，不读取实际站点配置。就绪随机标记核对服务归属，绝不终止占用端口的其他进程。

源站建立 3 个组件母版的 v1/v2、7 种实例的中英文页面：旧版锁定、最新版、嵌套锁定、嵌套最新版、变体与实例覆盖、语言默认值、外层浅锁定/内层跟随最新。已发布状态仅是临时安装中的固定渲染对照，没有向用户站点发布内容。

实际执行：源站渲染 → 导出 ZIP → 全新目标安装 → 目标站自己的 SiteTemplateService prepare/stage/apply → 新进程读取 → CLI 渲染、匿名 HTTP DOM、链接/SVG/CSS 字节与用量索引比较。另测源站不变、重复导出稳定、相同冻结母版去重、冲突 UUID 拒绝、草稿与密钥标记不入包、安装与导入后迁移状态。

仅允许这些差异：

- 旧版引用沿用现有组件包策略，改为 `BloxComponents::frozenUuid(original, version)` 的独立母版，版本为 1，实例移除 pin；最新母版仍保留原 UUID 与发布版本。依照报告中的精确映射比较，而不是忽略所有 UUID。
- 媒体/主题路径使用导入器生成的 `sitepack-*` 命名空间。
- JSON 对象键顺序；数组顺序、值和类型仍严格比较。

每次报告保存运行源码 SHA-256、ZIP SHA-256、检查清单、源/目标公开文档与定义、样式摘要及允许映射。成功时清理自建临时目录，失败时保留；临时配置只含本门禁的随机测试凭据，勿将整个现场目录作为公开发行文件。

CI 的 PHP 8.2/zh-CN 作业执行此门禁并保留 JSON 证据，fixture 本身同时验证 zh-CN/en。新增范围不能仅通过重生成期望文件放行，必须检查源站独立对照和实际差异。

默认模式使用 SQLite 与 PHP 内置服务器；MySQL 需运行下述独立模式，nginx/Apache 需显式选择下述服务器模式。HTTP DOM 与资源检查不替代像素级浏览器验收或全量发布检查；查询循环、完整设计 token、高级字段及所有插件组合还需各自补充场景。

## MySQL 5.7 / 8.0 / 8.4 LTS

Windows 推荐使用自带包装脚本。它只读取指定目录的 MySQL 可执行文件，启动独立 mysqld、数据目录和端口，无需现有数据库密码，也不读取面板配置。

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests\smoke\run-component-mysql.ps1 -MysqlHome D:\yikai\soft\mysql\5.7 -Version 5.7 -Port 33087 -HttpPort 18917 -OutDir D:\test-results\mysql57
powershell -NoProfile -ExecutionPolicy Bypass -File tests\smoke\run-component-mysql.ps1 -MysqlHome D:\yikai\soft\mysql\8.0 -Version 8.0 -Port 33088 -HttpPort 18918 -OutDir D:\test-results\mysql80
powershell -NoProfile -ExecutionPolicy Bypass -File tests\smoke\run-component-mysql.ps1 -MysqlHome D:\test-runtimes\mysql-8.4 -Version 8.4 -Port 33094 -HttpPort 18924 -OutDir D:\test-results\mysql84
```

包装脚本会运行数据目录不匹配的拒绝对照，创建独立控制库验证不会误改旁库，执行门禁后核对源/目标随机库均已删除，再停止自己启动的 mysqld。成功时删除已核对绝对路径及重解析点的自有数据目录；失败时停止服务并保留诊断。环境变量恢复到运行前状态。

8.4 模式使用其支持的 `innodb_redo_log_capacity`，创建带随机非空密码的临时账号，核对默认 `caching_sha2_password`，并由 PHP PDO 首次连接执行错误目录拒绝对照，再执行安装与往返。保留 `mysql_native_password` 默认禁用和非标准外键默认限制，不通过开启旧认证或放宽约束让测试通过。临时账号只存在于独立实例，密码不写入结果报告。旧版 5.7/8.0 的启动参数保持原有路径。

Linux CI 或已准备好的专用测试服务可直接运行 `php tools/component-site-roundtrip.php --db=mysql --report=result.json`，必须显式设置：

- `YK_COMPONENT_MYSQL_PORT`、`YK_COMPONENT_MYSQL_USER`、`YK_COMPONENT_MYSQL_PASS`
- `YK_COMPONENT_MYSQL_DATADIR`：与服务 `@@datadir` 精确对应；Windows 仅归一化路径分隔符和大小写。
- `YK_COMPONENT_MYSQL_VERSION`：`5.7`、`8.0` 或 `8.4`，检查实际 `VERSION()`，不把 MariaDB 当作 MySQL 结果。

只连接 127.0.0.1；先验证数据目录、端口、服务 UUID 和版本，再创建 `yk_cmp_rt_<20位随机十六进制>_source/target`，不复用已有库。安装后在加载应用之前核对配置中的 DB_DRIVER/库名/端口/前缀；每次删除库前重新核对服务身份，只删除本次成功创建的确切名称。`--keep` 在 MySQL 模式仅保留站点文件，临时库仍清理。

报告记录真实服务器版本、SQL mode、字符集、随机库清理结果；包装脚本的 `local-instance.json` 另记录服务归属、错误目录拒绝、控制库不变、服务停止和数据目录清理。fixture 包括真实中文及四字节 Unicode 字符，验证 utf8mb4 数据保存和 HTTP 输出。

CI 组件往返矩阵为 MySQL 5.7/8.0/8.4，真实安装及后台 CRUD/页面冒烟矩阵为 5.7/8.4；是否通过以远端实际运行记录为准。本地测试不代表远端作业已经运行。低配服务器的内存/并发压测不属于此功能门禁。

业务 SQL 继续兼容 5.7，不为支持 8.4 引入 8.x 专属别名或排序规则。`ON DUPLICATE KEY UPDATE ... VALUES(column)` 在 8.4 仍受支持但已弃用，不能直接替换为会破坏 5.7 的新版行别名写法。CMS 全新安装/模板导入验收也不等于 MySQL 服务数据目录的原地版本升级验收。

### 真实发行包后台与升级验收

在同一自有 MySQL 实例保护下，可用 `-Suite runtime` 运行 `tools/mysql-package-acceptance.php`：

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File tests\smoke\run-component-mysql.ps1 -MysqlHome D:\test-runtimes\mysql-8.4 -Version 8.4 -Suite runtime -FromPackage D:\test-packages\yikaicms-v2.1.2.zip -ToPackage D:\test-packages\yikaicms-v2.1.3-local84.zip -FromVersion 2.1.2 -ToVersion 2.1.3 -Port 33096 -HttpPort 18926 -OutDir D:\test-results\mysql84-runtime
```

必须先核对已发布基线包的官方 SHA-256，并用干净提交构建当前 `--local=local84` 候选；不会改变产品版本号或自动发布。默认测试候选 ZIP 的正式 HTTP 全新安装，以及基线正式包经 `apply_prepare`、`apply_batch`、`apply_finalize`、`migrate:run` 后的站点。两种站点各运行 MySQL 迁移/事务检查、十类新建及列表回读、栏目图保留、后台页面扫描，另做文章草稿的真实 HTTP 创建、读取、修改与软删除；标题、摘要、正文、SEO 和四字节字符回读一致且始终为草稿。

升级用 `package_upgrade.php --verify-preservation`，在覆盖前插入独立草稿夹具，核对实际备份 SQL 包含该夹具，升级后逐字段比较并验证配置哈希和上传文件字节不变。只向测试站自己的 `tests/smoke` 复制三份客户端，fixtures.json 不会写进源码树或开发站。升级门禁的 `--keep` 仅供外层接管后续检查；外层在成功后清理站点与所建确切库名，失败保留诊断并停止独立实例。

仅补测另一基线升级时加 `-UpgradeOnly`，并传正确的 `-FromVersion`，跳过已经通过的候选全新安装。runtime 模式当前只允许内置 HTTP 服务器，传 nginx/Apache 会在启动 MySQL 前拒绝，避免误标服务器覆盖范围。`HttpPort` 保留为包装器的空闲端口检查；runtime 各站实际 HTTP 端口动态分配，web 元数据记录进程归属。

该门禁使用已校验的本地 ZIP 暂存，验证真实后台应用管道；不验证远端下载、签名/更新通道，也不等于 MySQL 服务原地升级、增量包或最终发行验收。所有数据均为临时实例内的演示/合成夹具，真实站点与凭据不参与测试。

## 独立 nginx / Apache

`--server=nginx|apache` 直接启动指定的服务器二进制和独立 PHP CGI FastCGI 进程；没有代理到 PHP 内置服务器。默认 `--php-cgi` 为当前 PHP CLI 同目录的 `php-cgi.exe`（非 Windows 为 `php-cgi`），也可显式指定。就绪检查要求 CGI 的随机进程环境标记、实际文档根、SAPI 和 PHP 版本全部匹配当前 CLI；不符合则在安装前停止。

```powershell
php tools/component-site-roundtrip.php --server=nginx --server-bin=D:/yikai/soft/nginx/nginx.exe --port=18921 --report=D:/test-results/nginx-sqlite.json
php tools/component-site-roundtrip.php --server=apache --server-bin=D:/yikai/soft/apache/2.4.39/bin/httpd.exe --port=18922 --report=D:/test-results/apache-sqlite.json
powershell -NoProfile -ExecutionPolicy Bypass -File tests\smoke\run-component-mysql.ps1 -MysqlHome D:\yikai\soft\mysql\8.0 -Version 8.0 -Port 33092 -HttpPort 18932 -WebServer nginx -ServerBinary D:\yikai\soft\nginx\nginx.exe -OutDir D:\test-results\nginx-mysql80
powershell -NoProfile -ExecutionPolicy Bypass -File tests\smoke\run-component-mysql.ps1 -MysqlHome D:\yikai\soft\mysql\5.7 -Version 5.7 -Port 33093 -HttpPort 18933 -WebServer apache -ServerBinary D:\yikai\soft\apache\2.4.39\bin\httpd.exe -OutDir D:\test-results\apache-mysql57
```

报告目录必须预先存在；MySQL 包装脚本会建立自己的结果目录。上述路径只是本机示例，不读取该目录下的面板/站点配置：

- nginx：独立 prefix、PID、日志、临时目录与 loopback HTTP 端口；仅借用发行版 `mime.types` / `fastcgi_params`。站点 include 使用本仓库 `deploy/nginx-server.conf`，仅把 PHP upstream `127.0.0.1:9000` 换成本次自有 FastCGI 端口。
- Apache：独立 ServerRoot / PID / 日志 / 监听，`-X` 前台单进程；加载指定发行版模块，`AllowOverride All` 执行站点原有 `.htaccess` 和 `uploads/.htaccess`。Windows 的 CGI 脚本路径用 `ProxyFCGISetEnvIf` 去掉盘符前的额外 `/`，不修改真实 Apache 配置。配置需 Apache 2.4.26+ 的 `mod_proxy_fcgi` 指令。
- 两者先通过各自配置语法检查，再启动；PHP 独立 session/error 路径。停止仅操作自有进程资源；nginx 的 quit 明确携带自有 prefix / config，绝不按进程名称终止已有服务。JSON 包含实际服务器/PHP 版本、配置摘要、拥有的 PID 和 CGI 端口，供清理核对。

服务器模式在既有组件断言外，增加全部 14 页的 `.html` / 英文前缀路由对照、携带查询串的语言路由、未知页 404、CSS/JS/SVG 的 MIME 与字节摘要，以及配置/存储目录拒绝、上传 PHP 禁止执行和下载、安装锁后拒绝。默认内置服务器不具备 `.htaccess`/nginx rewrite，明确跳过这些服务器专属检查。

本机已测 Windows nginx 1.30.4、Apache 2.4.39、PHP 8.2.33 CGI，根目录 HTTP 安装；这不是指定旧版本的生产选型建议。Linux/PHP-FPM、TLS、子目录、反向代理、宝塔改写片段及浏览器像素效果未由本门禁验证，CI 当前仍只配置内置服务器，不声称服务器矩阵已在远端运行。
